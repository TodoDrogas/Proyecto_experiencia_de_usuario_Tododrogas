<?php
/**
 * radicar-pqr.php v3 — Experiencia de Servicio al Cliente Tododrogas CIA SAS
 * ─────────────────────────────────────────────────────
 * 1. Recibe PQR del formulario (escrito / audio / canvas)
 * 2. Genera ticket TD-YYYYMMDD-XXXX
 * 3. Clasifica con GPT-4o-mini (sentimiento, prioridad, categoría, ley)
 * 4. Inserta en Supabase tabla correos
 * 5. Envía correo inteligente a pqrsfd@tododrogas.com.co vía Graph API
 *    con asunto formateado, cuerpo formal y adjunto según canal
 * 6. Registra en historial_eventos
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── CREDENCIALES (inyectadas por deploy.yml) ─────────────────────────
$SB_URL      = '__SB_URL__';
$SB_KEY      = '__SB_KEY__';
$OPENAI_KEY  = '__OPENAI_KEY__';
$TENANT_ID   = '__AZURE_TENANT_ID__';
$CLIENT_ID   = '__AZURE_CLIENT_ID__';
$CLIENT_SECRET = '__AZURE_CLIENT_SECRET__';
$BUZÓN_PQRS  = 'pqrsfd@tododrogas.com.co';
$GRAPH_USER_ID = '__GRAPH_USER_ID__';
$LOGO_URL      = 'https://lyosqaqhiwhgvjigvqtc.supabase.co/storage/v1/object/public/logos-config/LOGO_Tododrogas_Color%201%20(3).png';

// ── HELPERS ──────────────────────────────────────────────────────────
function sbPost($url, $key, $endpoint, $data, $prefer = 'return=representation') {
    $ch = curl_init("$url/rest/v1/$endpoint");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "apikey: $key", "Authorization: Bearer $key",
            'Content-Type: application/json', "Prefer: $prefer"
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $resp];
}

function sbPatch($url, $key, $endpoint, $filter, $data) {
    $ch = curl_init("$url/rest/v1/$endpoint?$filter");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "apikey: $key", "Authorization: Bearer $key",
            'Content-Type: application/json', 'Prefer: return=minimal'
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function getGraphToken($tenant, $client_id, $client_secret) {
    $ch = curl_init("https://login.microsoftonline.com/$tenant/oauth2/v2.0/token");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'scope'         => 'https://graph.microsoft.com/.default',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return $data['access_token'] ?? null;
}

// ── Descarga autenticada de Supabase Storage ─────────────────────────────
function fetchUrlBytes(string $url, string $sbKey = ''): string {
    $ch = curl_init($url);
    $headers = ['Accept: */*'];
    if ($sbKey) {
        $headers[] = "apikey: $sbKey";
        $headers[] = "Authorization: Bearer $sbKey";
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $data = curl_exec($ch);
    curl_close($ch);
    return ($data && strlen($data) > 0) ? $data : '';
}

// ── LOGO desde Supabase configuracion_sistema ───────────────────────
// ✅ FIX #C1 — Caché de logo en APCu (memoria del proceso PHP)
// En vez de hacer una query a Supabase en cada radicación, el logo se cachea
// por 1 hora. Una sola query alimenta todos los requests del período.
$logo_url             = '';
$logo_img_html        = '';
$logo_img_html_usuario = '';
try {
    // Intentar leer desde caché APCu (si está disponible)
    $cfg_data = [];
    $cache_key = 'nova_td_config_main';
    $cache_hit = false;
    if (function_exists('apcu_fetch')) {
        $cached = apcu_fetch($cache_key, $cache_hit);
        if ($cache_hit) {
            $cfg_data = $cached;
        }
    }
    if (!$cache_hit) {
        // Sin caché o expirado: consultar Supabase
        $ch_cfg = curl_init("$SB_URL/rest/v1/configuration_sistema?id=eq.main&select=data");
        curl_setopt_array($ch_cfg, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>5,
            CURLOPT_HTTPHEADER=>["apikey: $SB_KEY","Authorization: Bearer $SB_KEY",'Accept: application/json']]);
        $cfg_resp = curl_exec($ch_cfg); curl_close($ch_cfg);
        $cfg_rows = json_decode($cfg_resp, true);
        $cfg_data = $cfg_rows[0]['data'] ?? [];
        // Guardar en caché por 3600 segundos (1 hora)
        if (function_exists('apcu_store') && !empty($cfg_data)) {
            apcu_store($cache_key, $cfg_data, 3600);
        }
    }
    if (!empty($cfg_data['logo'])) {
        $logo_url = $cfg_data['logo'];

        if (strpos($logo_url, 'data:image') === 0) {
            // El logo ya está almacenado como base64 en la BD (no es una URL externa)
            // Outlook acepta base64 inline → usarlo para el correo interno
            $logo_img_html = "<div style='background:#fff;border-radius:10px;padding:8px 16px;display:inline-block;margin-bottom:10px'><img src=\"{$logo_url}\" alt=\"Tododrogas\" style=\"height:36px;max-width:180px;object-fit:contain;display:block\"></div>";
            // Gmail bloquea base64 inline y no hay URL pública → adjuntar logo como CID (Content-ID)
            // Por ahora se omite en el acuse al usuario para evitar imagen rota
            $logo_img_html_usuario = "";
        } else {
            // El logo es una URL externa pública
            // Correo interno (Outlook): embeber en base64 para evitar bloqueos de imágenes externas
            $logo_data = fetchUrlBytes($logo_url, $SB_KEY);
            if ($logo_data && strlen($logo_data) < 200*1024) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $logo_mime = $finfo->buffer($logo_data) ?: 'image/png';
                $logo_b64  = base64_encode($logo_data);
                $logo_img_html = "<div style='background:#fff;border-radius:10px;padding:8px 16px;display:inline-block;margin-bottom:10px'><img src=\"data:{$logo_mime};base64,{$logo_b64}\" alt=\"Tododrogas\" style=\"height:36px;max-width:180px;object-fit:contain;display:block\"></div>";
            } else {
                $logo_img_html = "<div style='background:#fff;border-radius:10px;padding:8px 16px;display:inline-block;margin-bottom:10px'><img src=\"{$logo_url}\" alt=\"Tododrogas\" style=\"height:36px;max-width:180px;object-fit:contain;display:block\"></div>";
            }
            // Acuse al usuario (Gmail): usar URL directa — Gmail carga URLs públicas sin problema
            $logo_img_html_usuario = "<div style='background:#fff;border-radius:10px;padding:8px 16px;display:inline-block;margin:0 auto 12px'><img src=\"{$logo_url}\" alt=\"Tododrogas\" style=\"height:36px;max-width:180px;object-fit:contain;display:block\"></div>";
        }
    }
} catch (Exception $e) { /* sin logo */ }

// ── LEER INPUT ───────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); echo json_encode(['error' => 'Invalid JSON']); exit; }

$now         = date('c');
$fecha       = date('Ymd');
$rand        = str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
$ticket_id   = "TD-{$fecha}-{$rand}";

$nombre       = trim($body['nombre']       ?? '');
$correo       = trim($body['correo']       ?? '');
$telefono     = trim($body['telefono']     ?? '');
$documento    = trim($body['documento']    ?? '');
$descripcion  = trim($body['descripcion']  ?? '');
$tipo_pqr_raw = trim($body['tipo_pqr'] ?? $body['tipo'] ?? 'peticion');
$tipo_pqr     = strtolower($tipo_pqr_raw);
$transcripcion = trim($body['transcripcion'] ?? '');
$audio_url    = trim($body['audio_url']    ?? '');
$canvas_url   = trim($body['canvas_url']   ?? '');

// FIX: leer medio declarado por el formulario
$medio_form   = strtolower(trim($body['medio'] ?? ''));
$tiene_audio  = !empty($body['tiene_audio']);
$tiene_canvas = !empty($body['tiene_canvas']);

$canal = 'escrito';
if      ($medio_form === 'audio'  || $tiene_audio)  $canal = 'audio';
elseif  ($medio_form === 'lapiz'  || $tiene_canvas) $canal = 'canvas';
elseif  ($audio_url)                                $canal = 'audio';
elseif  ($canvas_url)                               $canal = 'canvas';
elseif  ($transcripcion)                            $canal = 'audio';

$canal_contacto = $body['contacto_preferido'] ?? $body['canal'] ?? 'formulario_web';

// ── PRE-PASO: TRANSCRIBIR AUDIO CON WHISPER ANTES DE CLASIFICAR ───────
$whisper_transcripcion = '';
$whisper_error_pre     = '';
$whisper_avg_logprob   = null;
$whisper_duracion      = null;
$audio_tono_contexto   = '';

if ($canal === 'audio' && $OPENAI_KEY) {
    $audio_b64_pre  = '';
    $mime_type_pre  = 'audio/webm';

    if (!empty($audio_url) && strpos($audio_url, 'data:audio') === 0) {
        // Caso 1: base64 inline
        $b64sep_pre    = strpos($audio_url, ';base64,');
        $mime_type_pre = $b64sep_pre ? substr($audio_url, 5, $b64sep_pre - 5) : 'audio/webm';
        $audio_b64_pre = $b64sep_pre ? substr($audio_url, $b64sep_pre + 8) : '';

    } elseif (!empty($audio_url) && strpos($audio_url, 'http') === 0) {
        // Caso 2: URL pública de Supabase Storage → descargar primero
        $audio_bytes = fetchUrlBytes($audio_url, $SB_KEY);
        if ($audio_bytes && strlen($audio_bytes) > 100) {
            $ext_from_url  = strtolower(pathinfo(parse_url($audio_url, PHP_URL_PATH), PATHINFO_EXTENSION));
            $mime_type_pre = in_array($ext_from_url, ['webm','mp4','ogg','wav','mp3','m4a'])
                           ? "audio/{$ext_from_url}" : 'audio/webm';
            $audio_b64_pre = base64_encode($audio_bytes);
        }

    } elseif (!empty($body['audio_base64'])) {
        // Caso 3: base64 en el body JSON
        $audio_b64_pre = $body['audio_base64'];
    }

    if ($audio_b64_pre) {
        $audio_data_pre = base64_decode($audio_b64_pre);
        if ($audio_data_pre && strlen($audio_data_pre) > 100) {
            $ext_pre = match(true) {
                str_contains($mime_type_pre, 'webm') => 'webm',
                str_contains($mime_type_pre, 'mp4')  => 'mp4',
                str_contains($mime_type_pre, 'ogg')  => 'ogg',
                str_contains($mime_type_pre, 'wav')  => 'wav',
                str_contains($mime_type_pre, 'mp3')  => 'mp3',
                default => 'webm',
            };
            $tmp_pre = tempnam(sys_get_temp_dir(), 'aud_') . '.' . $ext_pre;
            file_put_contents($tmp_pre, $audio_data_pre);

            $ch_w = curl_init('https://api.openai.com/v1/audio/transcriptions');
            curl_setopt_array($ch_w, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer $OPENAI_KEY"],
                CURLOPT_POSTFIELDS     => [
                    'file'            => new CURLFile($tmp_pre, $mime_type_pre, "audio.$ext_pre"),
                    'model'           => 'whisper-1',
                    'language'        => 'es',
                    'response_format' => 'verbose_json',
                    'prompt'          => 'Droguería colombiana, PQR, servicio al cliente, medicamentos, EPS, Tododrogas',
                ],
            ]);
            $w_resp = curl_exec($ch_w);
            $w_code = curl_getinfo($ch_w, CURLINFO_HTTP_CODE);
            curl_close($ch_w);
            @unlink($tmp_pre);

            $w_data = json_decode($w_resp, true);
            if ($w_code === 200 && !empty($w_data['text'])) {
                $whisper_transcripcion = trim($w_data['text']);
                $whisper_duracion      = $w_data['duration'] ?? null;
                $segmentos = $w_data['segments'] ?? [];
                if ($segmentos) {
                    $sum_lp = array_sum(array_column($segmentos, 'avg_logprob'));
                    $whisper_avg_logprob = $sum_lp / count($segmentos);
                }
                // Construir contexto de tono para el prompt de GPT
                $duracion_str  = $whisper_duracion ? round($whisper_duracion) . 's' : '?';
                $confianza_str = $whisper_avg_logprob !== null
                    ? ($whisper_avg_logprob > -0.3 ? 'alta (voz clara y segura)'
                      : ($whisper_avg_logprob > -0.6 ? 'media' : 'baja (voz emocionada o temblorosa)'))
                    : 'desconocida';
                $tl = mb_strtolower($whisper_transcripcion);
                $sig_pos = (int)(bool)preg_match('/\b(gracias|muchas gracias|excelente|felicit|maravillo|encanta|agradec|buen servicio|muy bien|genial|perfecto|satisfech|contento|alegr|feliz|los amo|me ayudaron)\b/u', $tl);
                $sig_urg = (int)(bool)preg_match('/\b(urgente|emergencia|reacci[oó]n|dolor|grave|peligro|hospital|auxilio)\b/u', $tl);
                $sig_neg = (int)(bool)preg_match('/\b(p[eé]simo|horrible|nunca|incumpl|abusivo|ladr[oó]n|indigna|el colmo|harto)\b/u', $tl);
                $audio_tono_contexto = "\nCONTEXTO AUDIO — duración:{$duracion_str}, voz:{$confianza_str}, señales_positivas:" . ($sig_pos?'SÍ':'No') . ", señales_urgentes:" . ($sig_urg?'SÍ':'No') . ", señales_negativas:" . ($sig_neg?'SÍ':'No') . "\nSi señales_positivas=SÍ → clasificar sentimiento=positivo+tono=agradecido+prioridad=baja.";
            } else {
                $whisper_error_pre = $w_data['error']['message'] ?? "HTTP $w_code";
            }
        }
    }
}
if ($whisper_transcripcion) {
    $transcripcion = $whisper_transcripcion;
}

// Texto final para clasificar
$texto_pqr = $transcripcion ?: $descripcion;

// ── PASO 1: CLASIFICACIÓN IA ─────────────────────────────────────────
$sentimiento  = 'neutro';
$prioridad    = 'media';
$categoria_ia = ucfirst($tipo_pqr);
$nivel_riesgo = 'bajo';
$resumen_corto = mb_substr($texto_pqr, 0, 120);
$ley_aplicable = 'Ley 1755/2015';
$horas_sla    = 15 * 24;
$tono_ia      = 'neutro';

// ── Inferencia por tipo cuando no hay texto (audio sin transcripción) ──
// Si Whisper no pudo transcribir pero el usuario declaró un tipo,
// usar el tipo para inferir sentimiento/prioridad por defecto
if (!$texto_pqr && $canal === 'audio') {
    $map_tipo = [
        'felicitacion' => ['sentimiento'=>'positivo','tono'=>'agradecido','prioridad'=>'baja','horas_sla'=>360,'nivel_riesgo'=>'bajo'],
        'sugerencia'   => ['sentimiento'=>'neutro',  'tono'=>'neutro',    'prioridad'=>'baja','horas_sla'=>360,'nivel_riesgo'=>'bajo'],
        'peticion'     => ['sentimiento'=>'neutro',  'tono'=>'neutro',    'prioridad'=>'media','horas_sla'=>120,'nivel_riesgo'=>'bajo'],
        'queja'        => ['sentimiento'=>'negativo','tono'=>'frustrado', 'prioridad'=>'alta','horas_sla'=>72, 'nivel_riesgo'=>'medio'],
        'reclamo'      => ['sentimiento'=>'negativo','tono'=>'enojado',   'prioridad'=>'alta','horas_sla'=>72, 'nivel_riesgo'=>'medio'],
        'denuncia'     => ['sentimiento'=>'negativo','tono'=>'enojado',   'prioridad'=>'alta','horas_sla'=>24, 'nivel_riesgo'=>'alto'],
        'urgente'      => ['sentimiento'=>'urgente', 'tono'=>'ansioso',   'prioridad'=>'critica','horas_sla'=>4,'nivel_riesgo'=>'critico'],
    ];
    if (isset($map_tipo[$tipo_pqr])) {
        $d = $map_tipo[$tipo_pqr];
        $sentimiento   = $d['sentimiento'];
        $tono_ia       = $d['tono'];
        $prioridad     = $d['prioridad'];
        $horas_sla     = $d['horas_sla'];
        $nivel_riesgo  = $d['nivel_riesgo'];
        $resumen_corto = "[Audio sin transcripción] Tipo declarado: {$tipo_pqr}";
    }
}

if ($OPENAI_KEY && $texto_pqr) {
    $prompt = <<<PROMPT
Analiza esta solicitud de una drogueria colombiana. Responde SOLO JSON valido sin markdown.

CANAL: {$canal}
TIPO DECLARADO: $tipo_pqr_raw
TEXTO: $texto_pqr
{$audio_tono_contexto}

JSON requerido:
{
  "sentimiento": "positivo|neutro|negativo|urgente",
  "tono": "enojado|frustrado|triste|ansioso|neutro|satisfecho|agradecido",
  "prioridad": "baja|media|alta|critica",
  "nivel_riesgo": "bajo|medio|alto|critico",
  "resumen": "maximo 100 caracteres",
  "ley": "ley colombiana aplicable",
  "horas_sla": numero entero
}

REGLAS:
1. El contenido del texto MANDA sobre el tipo declarado.
2. FELICITACIÓN/AGRADECIMIENTO → sentimiento=positivo, tono=agradecido, prioridad=baja, horas_sla=360. Palabras clave: "gracias", "muchas gracias", "excelente", "felicito", "maravilloso", "buen servicio", "muy bien atendido", "contento", "satisfecho", "perfecto", "genial", "los amo", "me ayudaron mucho".
3. Si CONTEXTO AUDIO indica señales_positivas=SÍ → sentimiento=positivo+tono=agradecido OBLIGATORIO.
4. URGENTE → riesgo de salud, error medicamento. prioridad=critica, horas_sla=4.
5. ENOJO → "horrible", "pésimo", "ladrones", "abusivos", "el colmo". tono=enojado.
6. FRUSTRACIÓN → queja sin ira, "llevo días", "no me atienden". tono=frustrado.
7. NEUTRO → petición informativa sin emoción.
8. horas_sla: felicitacion=360, sugerencia=360, peticion=120, queja=72, reclamo=72, denuncia=24, urgente=4.
PROMPT;

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'       => 'gpt-4o-mini',
            'max_tokens'  => 300,
            'temperature' => 0.1,
            'messages'    => [
                ['role' => 'system', 'content' => 'Eres clasificador de solicitudes colombianas de drogueria. Responde SOLO JSON valido con los campos exactos solicitados. CRITICO: el contenido del texto SIEMPRE prevalece sobre el tipo que el usuario declaró en el formulario. Si el texto es positivo/agradecido (felicitaciones, gracias, buen servicio) clasificar como sentimiento=positivo+tono=agradecido+prioridad=baja+horas_sla=360 SIN IMPORTAR el tipo declarado. Si el texto muestra ira/frustración real, clasificar como negativo aunque el tipo diga petición.'],
                ['role' => 'user',   'content' => $prompt],
            ],
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $OPENAI_KEY", 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $ai_resp = curl_exec($ch);
    curl_close($ch);

    $ai_data = json_decode($ai_resp, true);
    $ai_text = $ai_data['choices'][0]['message']['content'] ?? '';
    $ai_text = preg_replace('/```json|```/', '', $ai_text);
    $ia = json_decode(trim($ai_text), true);

    if ($ia) {
        $sentimiento   = $ia['sentimiento']  ?? $sentimiento;
        $tono_ia       = $ia['tono']          ?? 'neutro';
        $prioridad     = $ia['prioridad']    ?? $prioridad;
        $categoria_ia  = $ia['categoria']    ?? $categoria_ia;
        $nivel_riesgo  = $ia['nivel_riesgo'] ?? $nivel_riesgo;
        $resumen_corto = mb_substr($ia['resumen'] ?? $resumen_corto, 0, 150);
        $ley_aplicable = $ia['ley']          ?? $ley_aplicable;
        $horas_sla     = intval($ia['horas_sla'] ?? $horas_sla);
    }
}

// Fecha límite SLA
$fecha_limite_sla = date('c', strtotime("+{$horas_sla} hours"));

// Emojis para el asunto
$emoji_sent = ['positivo'=>'😊','neutro'=>'😐','negativo'=>'😤','urgente'=>'🚨'][$sentimiento] ?? '📋';
$emoji_prio = ['baja'=>'🟢','media'=>'🟡','alta'=>'🟠','critica'=>'🔴'][$prioridad] ?? '🟡';
$emoji_canal = ['audio'=>'🎤','canvas'=>'✏️','escrito'=>'📝'][$canal] ?? '📝';
$tipo_label  = mb_strtoupper($tipo_pqr, 'UTF-8');
$canal_label = mb_strtoupper($canal, 'UTF-8');

// Asunto formateado completo
$subject = "[{$ticket_id}] {$emoji_canal} {$canal_label} | {$tipo_label} | {$emoji_sent} ".strtoupper($sentimiento)." | {$emoji_prio} ".strtoupper($prioridad);

// ── PASO 2: INSERTAR EN SUPABASE ────────────────────────────────────
$payload_correo = [
    'ticket_id'         => $ticket_id,
    'from_email'        => $correo ?: ($telefono . '@whatsapp'),
    'from_name'         => $nombre,
    'nombre'            => $nombre,
    'correo'            => $correo ?: null,
    'telefono_contacto' => $telefono,
    'subject'           => $subject,
    'descripcion'       => $descripcion,
    'body_preview'      => mb_substr($texto_pqr ?: ($descripcion ?: ''), 0, 200),
    'body_content'      => $texto_pqr ?: ($descripcion ?: ''),
    'body_type'         => 'text',
    'transcripcion'     => $transcripcion ?: null,
    'audio_url'         => (strpos($audio_url,'data:')===0 ? null : ($audio_url?:null)),
    'canvas_url'        => $canvas_url    ?: null,
    'tipo_pqr'          => $tipo_pqr,
    'categoria_ia'      => $categoria_ia,
    'sentimiento'       => $sentimiento,
    'datos_legales'     => json_encode(['tono' => $tono_ia ?? 'neutro']),
    'nivel_riesgo'      => $nivel_riesgo,
    'resumen_corto'     => $resumen_corto,
    'ley_aplicable'     => $ley_aplicable,
    'canal_contacto'    => $canal_contacto,
    'origen'            => 'formulario_web',
    'estado'            => 'pendiente',
    'prioridad'         => $prioridad,
    'es_urgente'        => in_array($sentimiento, ['urgente']) || $prioridad === 'critica',
    'horas_sla'         => $horas_sla,
    'fecha_limite_sla'  => $fecha_limite_sla,
    'has_attachments'   => !empty($audio_url) || !empty($canvas_url),
    'is_read'           => false,
    'acuse_enviado'     => null,
    'received_at'       => $now,
    'created_at'        => $now,
    'updated_at'        => $now,
];

$sb_result = sbPost($SB_URL, $SB_KEY, 'correos', $payload_correo);
$correo_id = null;
if ($sb_result['code'] < 400) {
    $inserted = json_decode($sb_result['body'], true);
    $correo_id = $inserted[0]['id'] ?? null;
} else {
    // Error crítico — no se pudo guardar en BD
    http_response_code(502);
    echo json_encode(['error' => 'supabase_error', 'code' => $sb_result['code'], 'detalle' => $sb_result['body']]);
    exit;
}

// ── PASO 3: ENVIAR CORREO A pqrsfd via Graph API ─────────────────────
date_default_timezone_set('America/Bogota'); // Hora Colombia UTC-5
$token = getGraphToken($TENANT_ID, $CLIENT_ID, $CLIENT_SECRET);

if ($token) {
    // Cuerpo del correo HTML
    $fecha_fmt  = date('d/m/Y H:i', strtotime($now));
    $canal_txt  = ['audio' => 'mensaje de voz', 'canvas' => 'escritura con lápiz inteligente', 'escrito' => 'texto escrito'][$canal] ?? 'formulario web';

    $badge_sent  = "<span style='background:" . (['positivo'=>'#dcfce7','neutro'=>'#f3f4f6','negativo'=>'#fee2e2','urgente'=>'#fef3c7'][$sentimiento]??'#f3f4f6') . ";color:" . (['positivo'=>'#166534','neutro'=>'#374151','negativo'=>'#991b1b','urgente'=>'#92400e'][$sentimiento]??'#374151') . ";padding:3px 10px;border-radius:12px;font-weight:700;font-size:12px'>{$emoji_sent} " . mb_strtoupper($sentimiento, "UTF-8") . "</span>";
    $badge_prio  = "<span style='background:" . (['baja'=>'#dcfce7','media'=>'#fef9c3','alta'=>'#fed7aa','critica'=>'#fee2e2'][$prioridad]??'#fef9c3') . ";color:" . (['baja'=>'#166534','media'=>'#854d0e','alta'=>'#9a3412','critica'=>'#991b1b'][$prioridad]??'#854d0e') . ";padding:3px 10px;border-radius:12px;font-weight:700;font-size:12px'>{$emoji_prio} " . mb_strtoupper($prioridad, "UTF-8") . "</span>";
    $badge_canal = "<span style='background:#dbeafe;color:#1e40af;padding:3px 10px;border-radius:12px;font-weight:700;font-size:12px'>{$emoji_canal} " . mb_strtoupper($canal, "UTF-8") . "</span>";

    $cuerpo_html = "
<!DOCTYPE html><html><head><meta charset='UTF-8'>
<style>@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap');body,table,td,p,span,div,a{font-family:'Poppins',Arial,sans-serif!important}</style>
</head><body style='margin:0;padding:0;background:#f1f5f9;font-family:Poppins,Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f1f5f9;padding:32px 16px'>
<tr><td align='center'>
<table width='680' cellpadding='0' cellspacing='0' style='max-width:680px;width:100%'>

  <!-- HEADER -->
  <tr><td style='background:#1e40af;border-radius:12px 12px 0 0;padding:24px 32px'>
    <table width='100%'><tr>
      <td><div style='background:#fff;border-radius:10px;padding:8px 16px;display:inline-block'>
        <img src='{$logo_url}' alt='Tododrogas' style='height:34px;max-width:180px;object-fit:contain;display:block'>
      </div></td>
      <td align='right'><span style='color:#93c5fd;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase'>NUEVA PQRSFD RECIBIDA</span></td>
    </tr></table>
  </td></tr>

  <!-- RADICADO BAND -->
  <tr><td style='background:#1e3a8a;padding:18px 32px;text-align:center'>
    <p style='color:#93c5fd;margin:0;font-size:10px;letter-spacing:1.5px;text-transform:uppercase'>Número de radicado</p>
    <p style='color:#fff;margin:6px 0 2px;font-size:28px;font-weight:800;letter-spacing:3px;font-family:monospace'>{$ticket_id}</p>
    <p style='color:#bfdbfe;margin:0;font-size:11px'>{$fecha_fmt} &nbsp;·&nbsp; {$badge_canal}</p>
  </td></tr>

  <!-- BODY -->
  <tr><td style='background:#fff;padding:24px 32px;border:1px solid #e2e8f0;border-top:none'>
    <p style='margin:0 0 16px;color:#374151;font-size:14px'>Estimado equipo PQRSFD,</p>
    <p style='margin:0 0 20px;color:#374151;font-size:14px;line-height:1.6'>Mediante la <strong>Plataforma Inteligente Nova TD</strong> se ha recibido el siguiente caso radicado:</p>

    <!-- Tabla de datos del ticket -->
    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-bottom:20px;font-size:13px'>
      <tr style='background:#eff6ff'><td style='padding:9px 14px;font-weight:700;color:#1e40af;width:160px;border-bottom:1px solid #e5e7eb'>Radicado</td>
          <td style='padding:9px 14px;font-weight:700;color:#1e40af;font-size:15px;border-bottom:1px solid #e5e7eb'>{$ticket_id}</td></tr>
      <tr><td style='padding:9px 14px;font-weight:600;color:#6b7280;border-bottom:1px solid #f3f4f6'>Fecha</td>
          <td style='padding:9px 14px;color:#111827;border-bottom:1px solid #f3f4f6'>{$fecha_fmt}</td></tr>
      <tr style='background:#fafafa'><td style='padding:9px 14px;font-weight:600;color:#6b7280;border-bottom:1px solid #f3f4f6'>Canal</td>
          <td style='padding:9px 14px;border-bottom:1px solid #f3f4f6'>{$badge_canal}</td></tr>
      <tr><td style='padding:9px 14px;font-weight:600;color:#6b7280;border-bottom:1px solid #f3f4f6'>Tipo</td>
          <td style='padding:9px 14px;color:#111827;font-weight:700;border-bottom:1px solid #f3f4f6'>" . mb_strtoupper($tipo_pqr, "UTF-8") . "" . (strtolower($categoria_ia) !== strtolower($tipo_pqr) ? " — {$categoria_ia}" : '') . "</td></tr>
      <tr style='background:#fafafa'><td style='padding:9px 14px;font-weight:600;color:#6b7280;border-bottom:1px solid #f3f4f6'>Sentimiento</td>
          <td style='padding:9px 14px;border-bottom:1px solid #f3f4f6'>{$badge_sent}</td></tr>
      <tr><td style='padding:9px 14px;font-weight:600;color:#6b7280;border-bottom:1px solid #f3f4f6'>Prioridad</td>
          <td style='padding:9px 14px;border-bottom:1px solid #f3f4f6'>{$badge_prio}</td></tr>
      <tr style='background:#fafafa'><td style='padding:9px 14px;font-weight:600;color:#6b7280;border-bottom:1px solid #f3f4f6'>SLA</td>
          <td style='padding:9px 14px;border-bottom:1px solid #f3f4f6'>{$horas_sla}h · Límite: " . date('d/m/Y H:i', strtotime($fecha_limite_sla)) . "</td></tr>
      <tr><td style='padding:9px 14px;font-weight:600;color:#6b7280'>Ley aplicable</td>
          <td style='padding:9px 14px;color:#111827'>{$ley_aplicable}</td></tr>
    </table>

    <!-- Datos del ciudadano -->
    <div style='background:#f0f7ff;border:1px solid #bfdbfe;border-left:4px solid #1e40af;border-radius:6px;padding:16px 20px;margin-bottom:16px'>
      <p style='margin:0 0 10px;font-weight:700;color:#1e40af;font-size:13px'>👤 Datos del ciudadano</p>
      <p style='margin:3px 0;font-size:13px;color:#374151'><strong>Nombre:</strong> {$nombre}</p>" .
      ($documento ? "<p style='margin:3px 0;font-size:13px;color:#374151'><strong>Documento:</strong> {$documento}</p>" : "") .
      ($correo ? "<p style='margin:3px 0;font-size:13px;color:#374151'><strong>Correo:</strong> <a href='mailto:{$correo}' style='color:#2563eb'>{$correo}</a></p>" : "") .
      ($telefono ? "<p style='margin:3px 0;font-size:13px;color:#374151'><strong>Celular:</strong> {$telefono}</p>" : "") .
      "<p style='margin:3px 0;font-size:13px;color:#374151'><strong>Canal preferido:</strong> {$canal_contacto}</p>
    </div>

    <!-- Mensaje recibido -->
    <div style='background:#faf5ff;border:1px solid #e9d5ff;border-left:4px solid #7c3aed;border-radius:6px;padding:16px 20px;margin-bottom:16px'>
      <p style='margin:0 0 8px;font-weight:700;color:#7c3aed;font-size:13px'>{$emoji_canal} Mensaje recibido via {$canal_txt}</p>" .
      ($canal === 'audio' && $transcripcion
        ? "<p style='margin:0 0 6px;font-size:10px;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:.5px'>🎙️ Transcripción Whisper AI:</p>
           <p style='margin:0;font-size:13px;line-height:1.6;color:#374151;background:#fff;border:1px solid #e9d5ff;border-radius:4px;padding:10px 14px'>" . nl2br(htmlspecialchars($transcripcion)) . "</p>"
        : "<p style='margin:0;font-size:13px;line-height:1.6;color:#374151'>" . nl2br(htmlspecialchars($texto_pqr ?: '[Audio adjunto — escuchar archivo adjunto]')) . "</p>") .
      ($resumen_corto && !str_starts_with($resumen_corto, '[Audio') ? "<p style='margin:10px 0 0;font-size:12px;color:#6b7280;font-style:italic'>📌 Resumen IA: " . htmlspecialchars($resumen_corto) . "</p>" : "") .
      "
    </div>" .

    ($audio_url || $canvas_url ? "
    <div style='background:#fefce8;border:1px solid #fde68a;border-radius:6px;padding:12px 16px;margin-bottom:16px;font-size:12px;color:#92400e'>
      <strong>📎 Adjunto incluido:</strong> " . ($audio_url ? "Archivo de audio (🎤 .webm)" : "Imagen del lápiz inteligente (✏️)") . " — ver adjunto en este correo.
    </div>" : "") . "

    <!-- Estado sistema -->
    <div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:12px 16px;margin-bottom:0;font-size:12px;color:#166534'>
      ✅ Este caso ha sido <strong>guardado automáticamente en el sistema</strong>. Si hay problemas de conectividad, el registro persiste en la base de datos y estará disponible cuando se recupere la conexión.
    </div>
  </td></tr>

  <!-- FOOTER -->
  <tr><td style='background:#f8fafc;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;padding:16px 32px;text-align:center'>
    <p style='font-size:11px;color:#9ca3af;margin:0'>Experiencia de Servicio al Cliente · Tododrogas CIA SAS · Nova TD v4 · {$fecha_fmt}<br>
    Radicado: <strong>{$ticket_id}</strong> · ID interno: " . ($correo_id ?? 'N/A') . "</p>
  </td></tr>

</table>
</td></tr></table>
</body></html>";


    // Construir mensaje Graph API
    $mail_payload = [
        'subject' => $subject,
        'importance' => in_array($prioridad, ['alta', 'critica']) ? 'high' : 'normal',
        'body' => ['contentType' => 'HTML', 'content' => $cuerpo_html],
        'toRecipients' => [['emailAddress' => ['address' => $BUZÓN_PQRS]]],
        'attachments' => [],
    ];

    // Adjuntar audio si existe (máx 4MB inline)
    if ($audio_url) {
        // Soporte para data URL base64 (enviado directo desde el formulario)
        if (strpos($audio_url, 'data:audio') === 0) {
            // Usar strpos/substr en lugar de preg_match para evitar backtracking en base64 grande
            $b64sep = strpos($audio_url, ';base64,');
            $mime_audio = $b64sep ? substr($audio_url, 5, $b64sep - 5) : 'audio/webm';
            $ext_audio  = (substr($mime_audio, -4) === 'webm') ? 'webm' : substr($mime_audio, strrpos($mime_audio,'/')+1);
            $audio_b64  = $b64sep ? substr($audio_url, $b64sep + 8) : '';
            if ($audio_b64) {
                $mail_payload['attachments'][] = [
                    '@odata.type'  => '#microsoft.graph.fileAttachment',
                    'name'         => "audio_{$ticket_id}.{$ext_audio}",
                    'contentType'  => $mime_audio,
                    'contentBytes' => $audio_b64,
                ];
            }
        } else {
            $audio_data = fetchUrlBytes($audio_url, $SB_KEY);
            if ($audio_data && strlen($audio_data) < 4 * 1024 * 1024) {
                $mail_payload['attachments'][] = [
                    '@odata.type'  => '#microsoft.graph.fileAttachment',
                    'name'         => "audio_{$ticket_id}.webm",
                    'contentType'  => 'audio/webm',
                    'contentBytes' => base64_encode($audio_data),
                ];
            }
        }
    }

    // Adjuntar imagen canvas si existe
    if ($canvas_url) {
        // Si es base64 data URL
        if (strpos($canvas_url, 'data:image') === 0) {
            preg_match('/data:image\/(\w+);base64,(.+)/', $canvas_url, $m);
            if ($m) {
                $mail_payload['attachments'][] = [
                    '@odata.type'  => '#microsoft.graph.fileAttachment',
                    'name'         => "canvas_{$ticket_id}.{$m[1]}",
                    'contentType'  => "image/{$m[1]}",
                    'contentBytes' => $m[2],
                ];
            }
        } else {
            // URL externa — descargar
            $img_data = fetchUrlBytes($canvas_url, $SB_KEY);
            if ($img_data && strlen($img_data) < 4 * 1024 * 1024) {
                $mail_payload['attachments'][] = [
                    '@odata.type'  => '#microsoft.graph.fileAttachment',
                    'name'         => "canvas_{$ticket_id}.png",
                    'contentType'  => 'image/png',
                    'contentBytes' => base64_encode($img_data),
                ];
            }
        }
    }

    // Enviar correo
    $ch = curl_init("https://graph.microsoft.com/v1.0/users/{$GRAPH_USER_ID}/sendMail");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['message' => $mail_payload, 'saveToSentItems' => true]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $mail_resp = curl_exec($ch);
    $mail_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $correo_enviado = ($mail_code === 202);

    // Actualizar BD: guardar HTML completo como body_content para que admin lo muestre igual que el correo a pqrsfd
    if ($correo_id) {
        // Reemplazar footer con ID real (el insert inicial tenía correo_id null en el footer)
        $cuerpo_html_final = str_replace(
            'ID interno: N/A',
            'ID interno: ' . $correo_id,
            $cuerpo_html
        );
        $patch_data = [
            'body_content' => $cuerpo_html_final, // HTML completo con logo, datos ciudadano, clasificación IA
            'body_type'    => 'html',
            'updated_at'   => date('c'),
        ];
        // Actualizar transcripción y preview si Whisper la obtuvo
        if ($transcripcion) {
            $patch_data['transcripcion'] = $transcripcion;
            $patch_data['body_preview']  = mb_substr($transcripcion, 0, 200);
        }
        sbPatch($SB_URL, $SB_KEY, 'correos', "id=eq.$correo_id", $patch_data);
    }
} else {
    $correo_enviado = false;
    $mail_code = 0;

    // Sin token Graph: construir HTML básico para que admin no vea "[Audio adjunto]"
    if ($correo_id && $texto_pqr) {
        $emoji_sent_f = ['positivo'=>'😊','neutro'=>'😐','negativo'=>'😤','urgente'=>'🚨'][$sentimiento] ?? '📋';
        $emoji_prio_f = ['baja'=>'🟢','media'=>'🟡','alta'=>'🟠','critica'=>'🔴'][$prioridad] ?? '🟡';
        $fecha_fmt_f  = date('d/m/Y H:i', strtotime($now));
        $body_fallback = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body style='margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif'>
<div style='max-width:680px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)'>
  <div style='background:#1e3a8a;padding:20px 28px;text-align:center'>
    <p style='color:#93c5fd;margin:0;font-size:10px;letter-spacing:1.5px;text-transform:uppercase'>Número de radicado</p>
    <p style='color:#fff;margin:6px 0;font-size:26px;font-weight:800;letter-spacing:3px;font-family:monospace'>{$ticket_id}</p>
    <p style='color:#bfdbfe;margin:0;font-size:11px'>{$fecha_fmt_f}</p>
  </div>
  <div style='padding:20px 28px'>
    <table width='100%' style='border-collapse:collapse;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-bottom:16px;font-size:13px'>
      <tr style='background:#eff6ff'><td style='padding:8px 14px;font-weight:700;color:#1e40af;width:140px'>Radicado</td><td style='padding:8px 14px;font-weight:700;color:#1e40af'>{$ticket_id}</td></tr>
      <tr><td style='padding:8px 14px;color:#6b7280'>Tipo</td><td style='padding:8px 14px;font-weight:700'>" . mb_strtoupper($tipo_pqr,'UTF-8') . "</td></tr>
      <tr style='background:#fafafa'><td style='padding:8px 14px;color:#6b7280'>Sentimiento</td><td style='padding:8px 14px'>{$emoji_sent_f} " . mb_strtoupper($sentimiento,'UTF-8') . "</td></tr>
      <tr><td style='padding:8px 14px;color:#6b7280'>Prioridad</td><td style='padding:8px 14px'>{$emoji_prio_f} " . mb_strtoupper($prioridad,'UTF-8') . "</td></tr>
      <tr style='background:#fafafa'><td style='padding:8px 14px;color:#6b7280'>Canal</td><td style='padding:8px 14px'>" . mb_strtoupper($canal,'UTF-8') . "</td></tr>
    </table>
    <div style='background:#f0f7ff;border-left:4px solid #1e40af;border-radius:6px;padding:14px 18px;margin-bottom:14px'>
      <p style='margin:0 0 8px;font-weight:700;color:#1e40af;font-size:13px'>👤 Datos del ciudadano</p>
      <p style='margin:3px 0;font-size:13px'><strong>Nombre:</strong> {$nombre}</p>" .
      ($documento ? "<p style='margin:3px 0;font-size:13px'><strong>Documento:</strong> {$documento}</p>" : "") .
      ($correo ? "<p style='margin:3px 0;font-size:13px'><strong>Correo:</strong> {$correo}</p>" : "") .
      ($telefono ? "<p style='margin:3px 0;font-size:13px'><strong>Celular:</strong> {$telefono}</p>" : "") . "
    </div>
    <div style='background:#faf5ff;border-left:4px solid #7c3aed;border-radius:6px;padding:14px 18px'>
      <p style='margin:0 0 8px;font-weight:700;color:#7c3aed;font-size:13px'>📝 Mensaje</p>
      <p style='margin:0;font-size:13px;line-height:1.7'>" . nl2br(htmlspecialchars($texto_pqr)) . "</p>
    </div>
  </div>
</div>
</body></html>";
        sbPatch($SB_URL, $SB_KEY, 'correos', "id=eq.$correo_id", [
            'body_content' => $body_fallback,
            'body_type'    => 'html',
            'updated_at'   => date('c'),
        ]);
    }
}

// ── PASO 3B: ACUSE AL USUARIO ───────────────────────────────────────
if ($token && $correo && filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    $fecha_fmt_u = date('d/m/Y H:i', strtotime($now));
    $dias_resp   = round($horas_sla / 24);
    $fecha_lim_u = date('d/m/Y H:i', strtotime($fecha_limite_sla));

    $tipo_label_u = mb_strtoupper($tipo_pqr_raw, "UTF-8");
    $emoji_tipo_u = ['PETICIÓN'=>'💡','QUEJA'=>'😤','RECLAMO'=>'⚠️','SUGERENCIA'=>'💬','FELICITACIÓN'=>'⭐','DENUNCIA'=>'🚨'][mb_strtoupper($tipo_pqr_raw, "UTF-8")] ?? '📋';

    $cuerpo_acuse = "
<!DOCTYPE html><html><head><style>@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap');body,table,td,p,span,div{font-family:'Poppins',Arial,sans-serif!important}</style></head><body style='margin:0;padding:0;background:#f1f5f9;font-family:Poppins,Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f1f5f9;padding:32px 16px'>
<tr><td align='center'>
<table width='640' cellpadding='0' cellspacing='0' style='max-width:640px;width:100%'>

  <tr><td style='background:#1e40af;border-radius:12px 12px 0 0;padding:28px 32px;text-align:center'>
    ".($logo_img_html_usuario ?: "")."
    <p style='color:#bfdbfe;margin:0;font-size:11px;letter-spacing:1px;text-transform:uppercase'>Tododrogas CIA SAS · Experiencia de Servicio al Cliente</p>
    <h2 style='color:#fff;margin:6px 0 0;font-size:20px;font-weight:700'>Su solicitud fue recibida</h2>
  </td></tr>

  <tr><td style='background:#1e3a8a;padding:20px 32px;text-align:center'>
    <p style='color:#93c5fd;margin:0;font-size:11px;letter-spacing:1.5px;text-transform:uppercase'>Su número de radicado</p>
    <p style='color:#fff;margin:6px 0;font-size:30px;font-weight:700;letter-spacing:3px;font-family:monospace'>{$ticket_id}</p>
    <p style='color:#93c5fd;margin:0;font-size:11px'>Guárdelo para hacer seguimiento</p>
  </td></tr>

  <tr><td style='background:#fff;padding:24px 32px;border:1px solid #e2e8f0;border-top:none'>
    <p style='margin:0 0 16px;color:#374151;font-size:14px'>Estimado/a <strong>{$nombre}</strong>,</p>
    <p style='margin:0 0 16px;color:#374151;font-size:14px;line-height:1.6'>Hemos recibido su solicitud. Queremos que sepa que para nosotros su bienestar es lo más importante y estamos comprometidos a darle una respuesta oportuna y de calidad.</p>

    <table width='100%' cellpadding='8' cellspacing='0' style='font-size:13px;border-collapse:collapse;margin-bottom:20px'>
      <tr><td style='color:#6b7280;width:160px;border-bottom:1px solid #f3f4f6'>Fecha de radicado</td>
          <td style='color:#111827;font-weight:600;border-bottom:1px solid #f3f4f6'>{$fecha_fmt_u} (hora Colombia)</td></tr>
      <tr><td style='color:#6b7280;border-bottom:1px solid #f3f4f6'>Tipo de solicitud</td>
          <td style='color:#111827;font-weight:600;border-bottom:1px solid #f3f4f6'>{$emoji_tipo_u} {$tipo_label_u}" . (strtolower($categoria_ia) !== strtolower($tipo_pqr) ? " — {$categoria_ia}" : '') . "</td></tr>

      <tr><td style='color:#6b7280'>Canal de contacto</td>
          <td style='color:#111827;font-weight:600'>{$canal_contacto}</td></tr>
    </table>

    <div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:16px 20px;margin-bottom:20px'>
      <p style='margin:0 0 8px;font-size:13px;font-weight:700;color:#166534'>¿Qué sigue?</p>
      <p style='margin:2px 0;font-size:12px;color:#166534'>→ Su caso será revisado por uno de nuestros asesores especializados.</p>
      <p style='margin:2px 0;font-size:12px;color:#166534'>→ Recibirá respuesta a este correo en el plazo indicado.</p>
      <p style='margin:2px 0;font-size:12px;color:#166534'>→ Si necesita información urgente, responda este correo con su número de radicado.</p>
    </div>

    ".( ($audio_url || $canvas_url || $texto_pqr) ? "
    <div style='background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #7c3aed;border-radius:4px;padding:14px 16px;margin-bottom:20px'>
      <p style='margin:0 0 6px;font-size:12px;font-weight:700;color:#7c3aed'>📋 Copia de su mensaje (para su referencia)</p>
      <p style='margin:0;font-size:12px;color:#374151;line-height:1.6'>".htmlspecialchars(mb_substr($texto_pqr, 0, 500)).(strlen($texto_pqr)>500?'…':'')."</p>
      ".($audio_url || $canvas_url ? "<p style='margin:8px 0 0;font-size:11px;color:#6b7280'>📎 Su ".($audio_url?'audio':'imagen')." fue adjuntado a este correo como evidencia.</p>" : "")."
    </div>" : "")."

  </td></tr>

  <!-- CANALES PQRSFD -->
  <tr><td style='padding:0'>
    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse'>
      <tr><td style='background:linear-gradient(135deg,#1e3a5f 0%,#1e40af 100%);padding:22px 32px'>
        <p style='margin:0 0 14px;font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:#93c5fd'>📍 Nuestros canales de atención · Tododrogas CIA SAS</p>
        <table width='100%' cellpadding='0' cellspacing='0'>
          <tr>
            <td width='50%' style='vertical-align:top;padding-right:8px'>
              <p style='margin:0 0 2px;font-size:10px;color:#93c5fd;text-transform:uppercase;letter-spacing:.7px;font-weight:700'>💬 WhatsApp</p>
              <a href='https://wa.me/573043412431' style='color:#fff;font-size:14px;font-weight:700;text-decoration:none'>304 341 2431</a>
              <p style='margin:10px 0 2px;font-size:10px;color:#93c5fd;text-transform:uppercase;letter-spacing:.7px;font-weight:700'>📞 PBX Atención</p>
              <a href='tel:6043222432' style='color:#fff;font-size:14px;font-weight:700;text-decoration:none'>604 322 2432 Op. 2</a>
            </td>
            <td width='50%' style='vertical-align:top;padding-left:8px'>
              <p style='margin:0 0 2px;font-size:10px;color:#93c5fd;text-transform:uppercase;letter-spacing:.7px;font-weight:700'>📧 Correo PQRSFD</p>
              <a href='mailto:pqrsfd@tododrogas.com.co' style='color:#fff;font-size:13px;font-weight:700;text-decoration:none'>pqrsfd@tododrogas.com.co</a>
              <p style='margin:10px 0 2px;font-size:10px;color:#93c5fd;text-transform:uppercase;letter-spacing:.7px;font-weight:700'>🌐 Formulario PQR</p>
              <a href='https://tododrogas.online/pqr_form.html' style='color:#fff;font-size:13px;font-weight:700;text-decoration:none'>tododrogas.online/pqr</a>
            </td>
          </tr>
        </table>
        <p style='margin:12px 0 0;font-size:10px;color:rgba(255,255,255,.55);border-top:1px solid rgba(255,255,255,.1);padding-top:10px'>
        </p>
      </td></tr>
    </table>
  </td></tr>
  <!-- FIN CANALES PQRSFD -->

  <tr><td style='background:#f8fafc;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;padding:16px 32px;text-align:center'>
    <p style='font-size:11px;color:#9ca3af;margin:0'>Tododrogas CIA SAS · Experiencia de Servicio al Cliente<br>
    Este es un mensaje automático, no responda directamente a este correo.</p>
  </td></tr>

</table></td></tr></table></body></html>";

    $acuse_payload = [
        'subject'      => "✅ Su solicitud fue recibida · Radicado {$ticket_id} · Tododrogas CIA SAS",
        'importance'   => 'normal',
        'body'         => ['contentType' => 'HTML', 'content' => $cuerpo_acuse],
        'toRecipients' => [['emailAddress' => ['address' => $correo, 'name' => $nombre]]],
        'attachments'  => [],
    ];

    // Adjuntar su propio audio/canvas como evidencia
    // Audio: adjuntar solo si es < 3MB (límite Graph API inline attachment)
    if ($audio_url) {
        if (strpos($audio_url, 'data:audio') === 0) {
            $b64sep2 = strpos($audio_url, ';base64,');
            $mime_au2 = $b64sep2 ? substr($audio_url, 5, $b64sep2 - 5) : 'audio/webm';
            $ext_au2  = (substr($mime_au2, -4) === 'webm') ? 'webm' : substr($mime_au2, strrpos($mime_au2,'/')+1);
            $audio_b642 = $b64sep2 ? substr($audio_url, $b64sep2 + 8) : '';
            if ($audio_b642 && strlen($audio_b642) < 3*1024*1024) {
                $acuse_payload['attachments'][] = [
                    '@odata.type'  => '#microsoft.graph.fileAttachment',
                    'name'         => "su_audio_{$ticket_id}.{$ext_au2}",
                    'contentType'  => $mime_au2,
                    'contentBytes' => $audio_b642,
                ];
            }
        } else {
            $audio_ev = fetchUrlBytes($audio_url, $SB_KEY);
            if ($audio_ev && strlen($audio_ev) < 2*1024*1024) { // <2MB
                $acuse_payload['attachments'][] = [
                    '@odata.type'  => '#microsoft.graph.fileAttachment',
                    'name'         => "su_audio_{$ticket_id}.webm",
                    'contentType'  => 'audio/webm',
                    'contentBytes' => base64_encode($audio_ev),
                ];
            }
        }
    }
    if ($canvas_url && strpos($canvas_url, 'data:image') === 0) {
        preg_match('/data:image\/(\w+);base64,(.+)/', $canvas_url, $mc);
        if ($mc) {
            $acuse_payload['attachments'][] = [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => "su_escrito_{$ticket_id}.{$mc[1]}",
                'contentType'  => "image/{$mc[1]}",
                'contentBytes' => $mc[2],
            ];
        }
    } elseif ($canvas_url) {
        $canvas_ev = fetchUrlBytes($canvas_url, $SB_KEY);
        if ($canvas_ev && strlen($canvas_ev) < 4*1024*1024) {
            $acuse_payload['attachments'][] = [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => "su_escrito_{$ticket_id}.png",
                'contentType'  => 'image/png',
                'contentBytes' => base64_encode($canvas_ev),
            ];
        }
    }

    $ch_acuse = curl_init("https://graph.microsoft.com/v1.0/users/{$GRAPH_USER_ID}/sendMail");
    curl_setopt_array($ch_acuse, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['message' => $acuse_payload, 'saveToSentItems' => true]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $acuse_resp = curl_exec($ch_acuse);
    $acuse_code = curl_getinfo($ch_acuse, CURLINFO_HTTP_CODE);
    curl_close($ch_acuse);

    $acuse_enviado = ($acuse_code === 202);
    if ($correo_id && $acuse_enviado) {
        sbPatch($SB_URL, $SB_KEY, 'correos', "id=eq.$correo_id", [
            'acuse_enviado' => true,
            'updated_at'    => date('c'),
        ]);
    }
}

// ── PASO 4: HISTORIAL EVENTOS ────────────────────────────────────────
if ($correo_id) {
    sbPost($SB_URL, $SB_KEY, 'historial_eventos', [
        'correo_id'   => $correo_id,
        'evento'      => 'pqr_recibida',
        'descripcion' => "PQR recibida via {$canal} ({$canal_contacto}). Clasificada: {$sentimiento} / {$prioridad}. Correo PQRSFD: " . ($correo_enviado ? 'OK' : 'error') . ". Acuse usuario: " . (isset($acuse_enviado) && $acuse_enviado ? 'OK' : ($correo ? 'error' : 'sin correo')),
        'from_email'  => $correo ?: $telefono,
        'subject'     => $subject,
        'datos_extra' => json_encode([
            'ticket_id'      => $ticket_id,
            'canal'          => $canal,
            'sentimiento'    => $sentimiento,
            'prioridad'      => $prioridad,
            'categoria_ia'   => $categoria_ia,
            'correo_enviado' => $correo_enviado,
            'horas_sla'      => $horas_sla,
        ]),
        'created_at' => $now,
    ], 'return=minimal');
}

// ── RESPUESTA FINAL ──────────────────────────────────────────────────
http_response_code(200);
echo json_encode([
    'ok'              => true,
    'radicado'        => $ticket_id,
    'ticket_id'       => $ticket_id,
    'canal'           => $canal,
    'sentimiento'     => $sentimiento,
    'prioridad'       => $prioridad,
    'categoria'       => $categoria_ia,
    'correo_enviado'  => $correo_enviado,
    'acuse_enviado'   => isset($acuse_enviado) ? $acuse_enviado : null,
    'acuse_code'      => isset($acuse_code) ? $acuse_code : null,
    'mensaje'         => "Tu solicitud fue recibida exitosamente. Radicado: {$ticket_id}",
]);
