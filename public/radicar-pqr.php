<?php
/**
 * radicar-pqr.php v5 — Correos + Supabase
 * ─────────────────────────────────────────────────────
 * 1. Recibe PQR del formulario (escrito / audio / canvas)
 * 2. Genera ticket TD-YYYYMMDD-XXXX
 * 3. Clasifica con GPT-4o-mini (sentimiento, prioridad, categoría, ley)
 * 4. Envía correo a pqrsfd@tododrogas.com.co vía Graph API
 *    — Si origen=nova_td: asunto incluye 🤖 NOVA TD
 * 5. Envía acuse al usuario (si tiene correo)
 * NO inserta en Supabase ni historial_eventos
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

// ── HELPERS ──────────────────────────────────────────────────────────
// sbPost / sbPatch eliminados — versión sin BD

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
// $logo_img_html      → correo interno a pqrsfd (Outlook — acepta base64)
// $logo_img_html_usuario → acuse al usuario (Gmail — NO acepta base64 inline)
$logo_url             = '';
$logo_img_html        = '';
$logo_img_html_usuario = '';
try {
    $ch_cfg = curl_init("$SB_URL/rest/v1/configuracion_sistema?id=eq.main&select=data");
    curl_setopt_array($ch_cfg, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>5,
        CURLOPT_HTTPHEADER=>["apikey: $SB_KEY","Authorization: Bearer $SB_KEY",'Accept: application/json']]);
    $cfg_resp = curl_exec($ch_cfg); curl_close($ch_cfg);
    $cfg_rows = json_decode($cfg_resp, true);
    $cfg_data = $cfg_rows[0]['data'] ?? [];
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
$origen         = $body['origen'] ?? 'formulario_web'; // nova_td | formulario_web

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
        // Tipos del botón Varios
        'solicitud'    => ['sentimiento'=>'neutro',  'tono'=>'neutro',    'prioridad'=>'media','horas_sla'=>120,'nivel_riesgo'=>'bajo'],
        'información'  => ['sentimiento'=>'neutro',  'tono'=>'neutro',    'prioridad'=>'baja', 'horas_sla'=>360,'nivel_riesgo'=>'bajo'],
        'informacion'  => ['sentimiento'=>'neutro',  'tono'=>'neutro',    'prioridad'=>'baja', 'horas_sla'=>360,'nivel_riesgo'=>'bajo'],
        'otros'        => ['sentimiento'=>'neutro',  'tono'=>'neutro',    'prioridad'=>'media','horas_sla'=>120,'nivel_riesgo'=>'bajo'],
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

// Asunto formateado — con indicador NOVA TD si aplica
// Asunto según canal de origen
if (in_array($origen, ['nova_web', 'nova_directo', 'nova_td'])) {
    $origen_label = $origen === 'nova_directo' ? '🤖 NOVA TD DIRECTO' : '🤖 NOVA TD';
    $subject = "[{$ticket_id}] {$origen_label} | {$tipo_label} | {$emoji_sent} " . strtoupper($sentimiento) . " | {$emoji_prio} " . strtoupper($prioridad);
} elseif ($origen === 'qr') {
    $subject = "[{$ticket_id}] 📷 QR | {$emoji_canal} {$canal_label} | {$tipo_label} | {$emoji_sent} " . strtoupper($sentimiento) . " | {$emoji_prio} " . strtoupper($prioridad);
} else {
    if (in_array($origen, ['web', 'formulario_web'])) {
        $subject = "[{$ticket_id}] ð¥ï¸ WEB | {$emoji_canal} {$canal_label} | {$tipo_label} | {$emoji_sent} " . strtoupper($sentimiento) . " | {$emoji_prio} " . strtoupper($prioridad);
    } else {
        $subject = "[{$ticket_id}] {$emoji_canal} {$canal_label} | {$tipo_label} | {$emoji_sent} " . strtoupper($sentimiento) . " | {$emoji_prio} " . strtoupper($prioridad);
    }
}

// ── PASO 2: INSERT EN SUPABASE tabla correos ────────────────────────
$correo_id = null;
if (!empty($SB_URL) && $SB_URL !== '__SB_URL__' && !empty($SB_KEY)) {
    $sb_data = [
        'ticket_id'        => $ticket_id,
        'subject'          => $subject,
        'from_email'       => $correo   ?: null,
        'nombre'           => $nombre   ?: null,
        'telefono'         => $telefono ?: null,
        'documento'        => $documento?: null,
        'body_text'        => $texto_pqr?: null,
        'tipo_pqr'         => $tipo_pqr,
        'canal'            => $canal,
        'origen'           => $origen,
        'sentimiento'      => $sentimiento,
        'prioridad'        => $prioridad,
        'estado'           => 'pendiente',
        'horas_sla'        => $horas_sla,
        'fecha_limite_sla' => $fecha_limite_sla,
        'received_at'      => $now,
        'is_read'          => false,
    ];
    if (!empty($audio_url) && strpos($audio_url,'data:')!==0)  $sb_data['audio_url']  = $audio_url;
    if (!empty($canvas_url)&& strpos($canvas_url,'data:')!==0) $sb_data['canvas_url'] = $canvas_url;
    if (!empty($resumen_corto)) $sb_data['resumen_ia'] = $resumen_corto;

    $ch_sb = curl_init("$SB_URL/rest/v1/correos");
    curl_setopt_array($ch_sb, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($sb_data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "apikey: $SB_KEY",
            "Authorization: Bearer $SB_KEY",
            'Content-Type: application/json',
            'Prefer: return=representation',
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    $sb_resp = curl_exec($ch_sb);
    $sb_code = curl_getinfo($ch_sb, CURLINFO_HTTP_CODE);
    curl_close($ch_sb);
    if ($sb_code === 201) {
        $inserted  = json_decode($sb_resp, true);
        $correo_id = $inserted[0]['id'] ?? null;
    }
}

// ── PASO 3: ENVIAR CORREO A pqrsfd via Graph API ─────────────────────
date_default_timezone_set('America/Bogota'); // Hora Colombia UTC-5
$token = getGraphToken($TENANT_ID, $CLIENT_ID, $CLIENT_SECRET);

if ($token) {
    // Cuerpo del correo HTML
    $fecha_fmt  = date('d/m/Y H:i', strtotime($now));
    if (in_array($origen, ['nova_web', 'nova_directo', 'nova_td'])) {
        $canal_txt = $origen === 'nova_directo' ? 'Asistente Virtual Nova TD (acceso directo)' : 'Asistente Virtual Nova TD (módulo web)';
    } elseif ($origen === 'qr') {
        $canal_txt = 'Código QR';
    } else {
        $canal_txt = ['audio' => 'mensaje de voz', 'canvas' => 'escritura con lápiz inteligente', 'escrito' => 'texto escrito'][$canal] ?? 'formulario web';
    }

    $badge_sent  = "<span style='background:" . (['positivo'=>'#dcfce7','neutro'=>'#f3f4f6','negativo'=>'#fee2e2','urgente'=>'#fef3c7'][$sentimiento]??'#f3f4f6') . ";color:" . (['positivo'=>'#166534','neutro'=>'#374151','negativo'=>'#991b1b','urgente'=>'#92400e'][$sentimiento]??'#374151') . ";padding:3px 10px;border-radius:12px;font-weight:700;font-size:12px'>{$emoji_sent} " . mb_strtoupper($sentimiento, "UTF-8") . "</span>";
    $badge_prio  = "<span style='background:" . (['baja'=>'#dcfce7','media'=>'#fef9c3','alta'=>'#fed7aa','critica'=>'#fee2e2'][$prioridad]??'#fef9c3') . ";color:" . (['baja'=>'#166534','media'=>'#854d0e','alta'=>'#9a3412','critica'=>'#991b1b'][$prioridad]??'#854d0e') . ";padding:3px 10px;border-radius:12px;font-weight:700;font-size:12px'>{$emoji_prio} " . mb_strtoupper($prioridad, "UTF-8") . "</span>";
    if (in_array($origen, ['nova_web', 'nova_directo', 'nova_td'])) {
    $lbl_nova    = $origen === 'nova_directo' ? '🤖 NOVA TD DIRECTO' : '🤖 NOVA TD';
    $badge_canal = "<span style='background:#ede9fe;color:#5b21b6;padding:3px 10px;border-radius:12px;font-weight:700;font-size:12px'>{$lbl_nova}</span>";
} elseif ($origen === 'qr') {
    $badge_canal = "<span style='background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:12px;font-weight:700;font-size:12px'>📷 QR</span>";
} else {
    $badge_canal = "<span style='background:#dbeafe;color:#1e40af;padding:3px 10px;border-radius:12px;font-weight:700;font-size:12px'>{$emoji_canal} " . mb_strtoupper($canal, "UTF-8") . "</span>";
}

    $cuerpo_html = "
<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#d8dfe9;font-family:Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#d8dfe9;padding:32px 16px'>
<tr><td align='center'>
<table width='580' cellpadding='0' cellspacing='0' style='max-width:580px;width:100%;background:#ffffff'>

  <!-- HEADER -->
  <tr><td style='background:#0c2d5e;padding:32px 44px;text-align:center'>
    <img src='{$logo_url}' alt='Tododrogas' style='height:32px;max-width:180px;object-fit:contain;display:block;margin:0 auto 12px;filter:brightness(0) invert(1);opacity:.92'>
    <p style='color:#6a90b8;margin:0;font-size:10px;letter-spacing:2.5px;text-transform:uppercase;font-weight:400'>Nueva PQRSFD recibida &middot; Plataforma Nova TD</p>
  </td></tr>

  <!-- RADICADO BAND -->
  <tr><td style='background:#0a2448;padding:20px 44px;text-align:center'>
    <p style='color:#6a90b8;margin:0;font-size:9px;letter-spacing:2.5px;text-transform:uppercase;font-weight:400'>Número de radicado</p>
    <p style='color:#ffffff;margin:8px 0 4px;font-size:26px;font-weight:700;letter-spacing:4px;font-family:monospace'>{$ticket_id}</p>
    <p style='color:#4a6a90;margin:0;font-size:10px'>{$fecha_fmt}</p>
  </td></tr>

  <!-- BODY -->
  <tr><td style='background:#ffffff;padding:36px 44px'>

    <p style='margin:0 0 10px;font-size:14px;color:#1a2535;line-height:1.8;font-weight:300'>Estimado equipo PQRSFD,</p>
    <p style='margin:0 0 24px;font-size:14px;color:#1a2535;line-height:1.8;font-weight:300'>Se ha recibido el siguiente caso mediante la <strong style='font-weight:500;color:#0c2d5e'>Plataforma Inteligente Nova TD</strong>:</p>

    <!-- DIVIDER DATOS TICKET -->
    <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:12px'>
      <tr>
        <td style='font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:#7a90a8;font-weight:500;white-space:nowrap;padding-right:12px'>Datos del radicado</td>
        <td style='border-top:1px solid #d4dce8'></td>
      </tr>
    </table>

    <!-- TABLA RADICADO -->
    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;border:1px solid #d4dce8;margin-bottom:24px'>
      <tr style='background:#f6f9fd'>
        <td style='padding:9px 14px;font-size:11px;font-weight:500;color:#0c2d5e;width:150px;border-bottom:1px solid #d4dce8'>Radicado</td>
        <td style='padding:9px 14px;font-size:13px;font-weight:700;color:#0c2d5e;letter-spacing:1px;border-bottom:1px solid #d4dce8'>{$ticket_id}</td>
      </tr>
      <tr>
        <td style='padding:9px 14px;font-size:11px;color:#7a90a8;border-bottom:1px solid #e8eef6'>Fecha</td>
        <td style='padding:9px 14px;font-size:12px;color:#2a3a4a;border-bottom:1px solid #e8eef6'>{$fecha_fmt}</td>
      </tr>
      <tr style='background:#f6f9fd'>
        <td style='padding:9px 14px;font-size:11px;color:#7a90a8;border-bottom:1px solid #d4dce8'>Canal</td>
        <td style='padding:9px 14px;font-size:12px;color:#2a3a4a;border-bottom:1px solid #d4dce8'>{$emoji_canal} " . mb_strtoupper($canal, "UTF-8") . "</td>
      </tr>
      <tr>
        <td style='padding:9px 14px;font-size:11px;color:#7a90a8;border-bottom:1px solid #e8eef6'>Tipo</td>
        <td style='padding:9px 14px;font-size:12px;font-weight:500;color:#2a3a4a;border-bottom:1px solid #e8eef6'>" . mb_strtoupper($tipo_pqr, "UTF-8") . "" . (strtolower($categoria_ia) !== strtolower($tipo_pqr) ? " &mdash; {$categoria_ia}" : '') . "</td>
      </tr>
      <tr style='background:#f6f9fd'>
        <td style='padding:9px 14px;font-size:11px;color:#7a90a8;border-bottom:1px solid #d4dce8'>Sentimiento</td>
        <td style='padding:9px 14px;font-size:12px;color:#2a3a4a;border-bottom:1px solid #d4dce8'>{$emoji_sent} " . mb_strtoupper($sentimiento, "UTF-8") . "</td>
      </tr>
      <tr>
        <td style='padding:9px 14px;font-size:11px;color:#7a90a8;border-bottom:1px solid #e8eef6'>Prioridad</td>
        <td style='padding:9px 14px;font-size:12px;color:#2a3a4a;border-bottom:1px solid #e8eef6'>{$emoji_prio} " . mb_strtoupper($prioridad, "UTF-8") . "</td>
      </tr>
      <tr style='background:#f6f9fd'>
        <td style='padding:9px 14px;font-size:11px;color:#7a90a8;border-bottom:1px solid #d4dce8'>SLA</td>
        <td style='padding:9px 14px;font-size:12px;color:#2a3a4a;border-bottom:1px solid #d4dce8'>{$horas_sla}h &middot; Límite: " . date('d/m/Y H:i', strtotime($fecha_limite_sla)) . "</td>
      </tr>
      <tr>
        <td style='padding:9px 14px;font-size:11px;color:#7a90a8'>Ley aplicable</td>
        <td style='padding:9px 14px;font-size:12px;color:#2a3a4a'>{$ley_aplicable}</td>
      </tr>
    </table>

    <!-- DIVIDER CIUDADANO -->
    <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:12px'>
      <tr>
        <td style='font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:#7a90a8;font-weight:500;white-space:nowrap;padding-right:12px'>Datos del ciudadano</td>
        <td style='border-top:1px solid #d4dce8'></td>
      </tr>
    </table>

    <!-- TABLA CIUDADANO -->
    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;border:1px solid #d4dce8;margin-bottom:24px'>
      <tr>
        <td style='padding:9px 14px;font-size:11px;color:#7a90a8;width:150px;border-bottom:1px solid #e8eef6'>Nombre</td>
        <td style='padding:9px 14px;font-size:12px;font-weight:500;color:#2a3a4a;border-bottom:1px solid #e8eef6'>{$nombre}</td>
      </tr>" .
      ($documento ? "<tr style='background:#f6f9fd'><td style='padding:9px 14px;font-size:11px;color:#7a90a8;border-bottom:1px solid #d4dce8'>Documento</td><td style='padding:9px 14px;font-size:12px;color:#2a3a4a;border-bottom:1px solid #d4dce8'>{$documento}</td></tr>" : "") .
      ($correo ? "<tr><td style='padding:9px 14px;font-size:11px;color:#7a90a8;border-bottom:1px solid #e8eef6'>Correo</td><td style='padding:9px 14px;font-size:12px;border-bottom:1px solid #e8eef6'><a href='mailto:{$correo}' style='color:#0c2d5e;font-weight:500;text-decoration:none'>{$correo}</a></td></tr>" : "") .
      ($telefono ? "<tr style='background:#f6f9fd'><td style='padding:9px 14px;font-size:11px;color:#7a90a8;border-bottom:1px solid #d4dce8'>Celular</td><td style='padding:9px 14px;font-size:12px;color:#2a3a4a;border-bottom:1px solid #d4dce8'>{$telefono}</td></tr>" : "") .
      "<tr><td style='padding:9px 14px;font-size:11px;color:#7a90a8'>Canal preferido</td><td style='padding:9px 14px;font-size:12px;color:#2a3a4a'>{$canal_contacto}</td></tr>
    </table>

    <!-- DIVIDER MENSAJE -->
    <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:12px'>
      <tr>
        <td style='font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:#7a90a8;font-weight:500;white-space:nowrap;padding-right:12px'>Mensaje recibido &mdash; {$canal_txt}</td>
        <td style='border-top:1px solid #d4dce8'></td>
      </tr>
    </table>

    <!-- BLOQUE MENSAJE -->
    <div style='background:#f6f9fd;border:1px solid #d4dce8;border-top:2px solid #0c2d5e;padding:18px 20px;margin-bottom:24px'>" .
      ($canal === 'audio' && $transcripcion
        ? "<p style='margin:0 0 8px;font-size:9px;font-weight:500;color:#7a90a8;text-transform:uppercase;letter-spacing:2px'>Transcripción Whisper AI</p>
           <p style='margin:0;font-size:12px;color:#3a4a5a;line-height:1.7;font-style:italic'>" . nl2br(htmlspecialchars($transcripcion)) . "</p>"
        : "<p style='margin:0;font-size:12px;color:#3a4a5a;line-height:1.7'>" . nl2br(htmlspecialchars($texto_pqr ?: '[Audio adjunto — escuchar archivo adjunto]')) . "</p>") .
      ($resumen_corto && !str_starts_with($resumen_corto, '[Audio') ? "<p style='margin:12px 0 0;font-size:10px;color:#7a90a8;font-style:normal;letter-spacing:.3px'><span style='font-weight:500;color:#0c2d5e'>Resumen IA:</span> " . htmlspecialchars($resumen_corto) . "</p>" : "") .
      "
    </div>" .

    ($audio_url || $canvas_url ? "
    <div style='background:#f6f9fd;border:1px solid #d4dce8;padding:12px 18px;margin-bottom:24px;font-size:11px;color:#2a4870'>
      <strong>Adjunto incluido:</strong> " . ($audio_url ? "Archivo de audio (🎤 .webm)" : "Imagen del lápiz inteligente (✏️)") . " &mdash; ver adjunto en este correo.
    </div>" : "") . "

    <!-- BLOQUE ESTADO SISTEMA -->
    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;border:1px solid #d4dce8;margin-bottom:0'>
      <tr><td style='padding:16px 20px'>
        <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:10px'>
          <tr>
            <td style='width:20px;border-top:2px solid #0c2d5e;vertical-align:middle'></td>
            <td style='padding-left:10px;font-size:9px;letter-spacing:2px;text-transform:uppercase;color:#0c2d5e;font-weight:500'>Estado del sistema</td>
          </tr>
        </table>
        <p style='margin:0;font-size:12px;color:#4a5a6a;line-height:1.6;font-weight:300'>Este caso ha sido <strong style='font-weight:500'>guardado automáticamente</strong>. El registro persiste en la base de datos y estará disponible cuando se recupere la conexión.</p>
      </td></tr>
    </table>

  </td></tr>

  <!-- FOOTER -->
  <tr><td style='background:#0c2d5e;padding:18px 44px'>
    <table width='100%' cellpadding='0' cellspacing='0'>
      <tr>
        <td style='font-size:10px;color:#4a6a90;line-height:1.6'>Tododrogas CIA SAS<br>Experiencia de Servicio al Cliente &middot; Nova TD v4</td>
        <td align='right' style='font-size:9px;letter-spacing:2px;text-transform:uppercase;color:#2a4870;font-weight:500'>Radicado: {$ticket_id}<br>ID: " . ($correo_id ?? 'N/A') . "</td>
      </tr>
    </table>
  </td></tr>

</table></td></tr></table>
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

    // (sin actualización BD en esta versión)
} else {
    $correo_enviado = false;
    $mail_code = 0;

    // Sin token Graph: construir HTML básico para que admin no vea "[Audio adjunto]"
    if ($correo_id && $texto_pqr) {
        $emoji_sent_f = ['positivo'=>'😊','neutro'=>'😐','negativo'=>'😤','urgente'=>'🚨'][$sentimiento] ?? '📋';
        $emoji_prio_f = ['baja'=>'🟢','media'=>'🟡','alta'=>'🟠','critica'=>'🔴'][$prioridad] ?? '🟡';
        $fecha_fmt_f  = date('d/m/Y H:i', strtotime($now));
        $body_fallback = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#d8dfe9;font-family:Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#d8dfe9;padding:32px 16px'>
<tr><td align='center'>
<table width='580' cellpadding='0' cellspacing='0' style='max-width:580px;width:100%;background:#ffffff'>
  <tr><td style='background:#0c2d5e;padding:32px 44px;text-align:center'>
    <p style='color:#6a90b8;margin:0;font-size:10px;letter-spacing:2.5px;text-transform:uppercase;font-weight:400'>Nueva PQRSFD &middot; Plataforma Nova TD</p>
    <p style='color:#ffffff;margin:12px 0 4px;font-size:24px;font-weight:700;letter-spacing:4px;font-family:monospace'>{$ticket_id}</p>
    <p style='color:#4a6a90;margin:0;font-size:10px'>{$fecha_fmt_f}</p>
  </td></tr>
  <tr><td style='background:#ffffff;padding:36px 44px'>

    <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:12px'>
      <tr>
        <td style='font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:#7a90a8;font-weight:500;white-space:nowrap;padding-right:12px'>Datos del radicado</td>
        <td style='border-top:1px solid #d4dce8'></td>
      </tr>
    </table>

    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;border:1px solid #d4dce8;margin-bottom:24px'>
      <tr style='background:#f6f9fd'><td style='padding:9px 14px;font-size:11px;color:#7a90a8;width:150px;border-bottom:1px solid #d4dce8'>Radicado</td><td style='padding:9px 14px;font-size:12px;font-weight:700;color:#0c2d5e;border-bottom:1px solid #d4dce8'>{$ticket_id}</td></tr>
      <tr><td style='padding:9px 14px;font-size:11px;color:#7a90a8;border-bottom:1px solid #e8eef6'>Tipo</td><td style='padding:9px 14px;font-size:12px;font-weight:500;color:#2a3a4a;border-bottom:1px solid #e8eef6'>" . mb_strtoupper($tipo_pqr,'UTF-8') . "</td></tr>
      <tr style='background:#f6f9fd'><td style='padding:9px 14px;font-size:11px;color:#7a90a8;border-bottom:1px solid #d4dce8'>Sentimiento</td><td style='padding:9px 14px;font-size:12px;color:#2a3a4a;border-bottom:1px solid #d4dce8'>{$emoji_sent_f} " . mb_strtoupper($sentimiento,'UTF-8') . "</td></tr>
      <tr><td style='padding:9px 14px;font-size:11px;color:#7a90a8;border-bottom:1px solid #e8eef6'>Prioridad</td><td style='padding:9px 14px;font-size:12px;color:#2a3a4a;border-bottom:1px solid #e8eef6'>{$emoji_prio_f} " . mb_strtoupper($prioridad,'UTF-8') . "</td></tr>
      <tr style='background:#f6f9fd'><td style='padding:9px 14px;font-size:11px;color:#7a90a8'>Canal</td><td style='padding:9px 14px;font-size:12px;color:#2a3a4a'>" . mb_strtoupper($canal,'UTF-8') . "</td></tr>
    </table>

    <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:12px'>
      <tr>
        <td style='font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:#7a90a8;font-weight:500;white-space:nowrap;padding-right:12px'>Datos del ciudadano</td>
        <td style='border-top:1px solid #d4dce8'></td>
      </tr>
    </table>

    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;border:1px solid #d4dce8;margin-bottom:24px'>
      <tr><td style='padding:9px 14px;font-size:11px;color:#7a90a8;width:150px;border-bottom:1px solid #e8eef6'>Nombre</td><td style='padding:9px 14px;font-size:12px;font-weight:500;color:#2a3a4a;border-bottom:1px solid #e8eef6'>{$nombre}</td></tr>" .
      ($documento ? "<tr style='background:#f6f9fd'><td style='padding:9px 14px;font-size:11px;color:#7a90a8;border-bottom:1px solid #d4dce8'>Documento</td><td style='padding:9px 14px;font-size:12px;color:#2a3a4a;border-bottom:1px solid #d4dce8'>{$documento}</td></tr>" : "") .
      ($correo ? "<tr><td style='padding:9px 14px;font-size:11px;color:#7a90a8;border-bottom:1px solid #e8eef6'>Correo</td><td style='padding:9px 14px;font-size:12px;border-bottom:1px solid #e8eef6'><a href='mailto:{$correo}' style='color:#0c2d5e;font-weight:500;text-decoration:none'>{$correo}</a></td></tr>" : "") .
      ($telefono ? "<tr style='background:#f6f9fd'><td style='padding:9px 14px;font-size:11px;color:#7a90a8'>Celular</td><td style='padding:9px 14px;font-size:12px;color:#2a3a4a'>{$telefono}</td></tr>" : "") . "
    </table>

    <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:12px'>
      <tr>
        <td style='font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:#7a90a8;font-weight:500;white-space:nowrap;padding-right:12px'>Mensaje</td>
        <td style='border-top:1px solid #d4dce8'></td>
      </tr>
    </table>

    <div style='background:#f6f9fd;border:1px solid #d4dce8;border-top:2px solid #0c2d5e;padding:18px 20px;margin-bottom:0'>
      <p style='margin:0;font-size:12px;color:#3a4a5a;line-height:1.7'>" . nl2br(htmlspecialchars($texto_pqr)) . "</p>
    </div>

  </td></tr>
  <tr><td style='background:#0c2d5e;padding:18px 44px'>
    <table width='100%' cellpadding='0' cellspacing='0'>
      <tr>
        <td style='font-size:10px;color:#4a6a90;line-height:1.6'>Tododrogas CIA SAS<br>Experiencia de Servicio al Cliente &middot; Nova TD</td>
        <td align='right' style='font-size:9px;letter-spacing:2px;text-transform:uppercase;color:#2a4870;font-weight:500'>Sistema PQRSFD</td>
      </tr>
    </table>
  </td></tr>
</table></td></tr></table>
</body></html>";
        // (sin actualización BD en esta versión)
    }
}

// ── PASO 3B: ACUSE AL USUARIO ───────────────────────────────────────
if ($token && $correo && filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    $fecha_fmt_u = date('d/m/Y H:i', strtotime($now));
    $dias_resp   = round($horas_sla / 24);
    $fecha_lim_u = date('d/m/Y H:i', strtotime($fecha_limite_sla));

    $tipo_label_u = mb_strtoupper($tipo_pqr_raw, "UTF-8");
    $emoji_tipo_u = [
        'PETICIÓN'    => '💡',
        'QUEJA'       => '😤',
        'RECLAMO'     => '⚠️',
        'SUGERENCIA'  => '💬',
        'FELICITACIÓN'=> '⭐',
        'DENUNCIA'    => '🚨',
        'SOLICITUD'   => '📋',
        'INFORMACIÓN' => 'ℹ️',
        'OTROS'       => '📝',
    ][mb_strtoupper($tipo_pqr_raw, 'UTF-8')] ?? '📋';

    $cuerpo_acuse = "
<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#d8dfe9;font-family:Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#d8dfe9;padding:32px 16px'>
<tr><td align='center'>
<table width='580' cellpadding='0' cellspacing='0' style='max-width:580px;width:100%;background:#ffffff'>

  <!-- HEADER -->
  <tr><td style='background:#0c2d5e;padding:32px 44px;text-align:center'>
    ".($logo_img_html_usuario ?: "")."
    <p style='color:#6a90b8;margin:0;font-size:10px;letter-spacing:2.5px;text-transform:uppercase;font-weight:400'>Acuse de recibo &middot; Tododrogas CIA SAS &middot; PQRSFD</p>
  </td></tr>

  <!-- RADICADO -->
  <tr><td style='background:#0a2448;padding:24px 44px;text-align:center'>
    <p style='color:#6a90b8;margin:0;font-size:9px;letter-spacing:2.5px;text-transform:uppercase;font-weight:400'>Su número de radicado</p>
    <p style='color:#ffffff;margin:10px 0 6px;font-size:28px;font-weight:700;letter-spacing:4px;font-family:monospace'>{$ticket_id}</p>
    <p style='color:#4a6a90;margin:0;font-size:10px;letter-spacing:.5px'>Guárdelo para hacer seguimiento</p>
  </td></tr>

  <!-- BODY -->
  <tr><td style='background:#ffffff;padding:36px 44px'>

    <p style='margin:0 0 10px;font-size:14px;color:#1a2535;line-height:1.8;font-weight:300'>Estimado/a <strong style='font-weight:500;color:#0c2d5e'>{$nombre}</strong>,</p>
    <p style='margin:0 0 24px;font-size:14px;color:#1a2535;line-height:1.8;font-weight:300'>Hemos recibido su solicitud. Su bienestar es lo más importante para nosotros y estamos comprometidos a darle una respuesta oportuna y de calidad.</p>

    <!-- DIVIDER DETALLES -->
    <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:12px'>
      <tr>
        <td style='font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:#7a90a8;font-weight:500;white-space:nowrap;padding-right:12px'>Detalles de su solicitud</td>
        <td style='border-top:1px solid #d4dce8'></td>
      </tr>
    </table>

    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;border:1px solid #d4dce8;margin-bottom:24px'>
      <tr style='background:#f6f9fd'>
        <td style='padding:11px 14px;font-size:11px;color:#7a90a8;width:160px;border-bottom:1px solid #d4dce8'>Fecha de radicado</td>
        <td style='padding:11px 14px;font-size:12px;font-weight:500;color:#2a3a4a;border-bottom:1px solid #d4dce8'>{$fecha_fmt_u} (hora Colombia)</td>
      </tr>
      <tr>
        <td style='padding:11px 14px;font-size:11px;color:#7a90a8;border-bottom:1px solid #e8eef6'>Tipo de solicitud</td>
        <td style='padding:11px 14px;font-size:12px;font-weight:500;color:#2a3a4a;border-bottom:1px solid #e8eef6'>{$emoji_tipo_u} {$tipo_label_u}" . (strtolower($categoria_ia) !== strtolower($tipo_pqr) ? " &mdash; {$categoria_ia}" : '') . "</td>
      </tr>
      <tr style='background:#f6f9fd'>
        <td style='padding:11px 14px;font-size:11px;color:#7a90a8'>Canal de contacto</td>
        <td style='padding:11px 14px;font-size:12px;font-weight:500;color:#2a3a4a'>{$canal_contacto}</td>
      </tr>
    </table>

    <!-- BLOQUE QUÉ SIGUE -->
    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;border:1px solid #d4dce8;margin-bottom:24px'>
      <tr><td style='padding:22px 24px'>
        <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:16px'>
          <tr>
            <td style='width:20px;border-top:2px solid #0c2d5e;vertical-align:middle'></td>
            <td style='padding-left:10px;font-size:9px;letter-spacing:2px;text-transform:uppercase;color:#0c2d5e;font-weight:500'>¿Qué sigue?</td>
          </tr>
        </table>
        <table width='100%' cellpadding='0' cellspacing='0'>
          <tr><td style='width:24px;font-size:10px;font-weight:500;color:#0c2d5e;vertical-align:top;padding:0 0 10px'>01</td><td style='font-size:12px;color:#4a5a6a;line-height:1.6;font-weight:300;padding:0 0 10px'>Su caso será revisado por uno de nuestros asesores especializados.</td></tr>
          <tr><td style='width:24px;font-size:10px;font-weight:500;color:#0c2d5e;vertical-align:top;padding:0 0 10px'>02</td><td style='font-size:12px;color:#4a5a6a;line-height:1.6;font-weight:300;padding:0 0 10px'>Recibirá respuesta a este correo en el plazo indicado.</td></tr>
          <tr><td style='width:24px;font-size:10px;font-weight:500;color:#0c2d5e;vertical-align:top'>03</td><td style='font-size:12px;color:#4a5a6a;line-height:1.6;font-weight:300'>Tododrogas CIA SAS se compromete a gestionar su solicitud con transparencia, diligencia y respeto, conforme a los estándares del servicio farmacéutico colombiano.</td></tr>
        </table>
      </td></tr>
    </table>

    ".( ($audio_url || $canvas_url || $texto_pqr) ? "
    <!-- DIVIDER COPIA -->
    <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:12px'>
      <tr>
        <td style='font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:#7a90a8;font-weight:500;white-space:nowrap;padding-right:12px'>Copia de su mensaje</td>
        <td style='border-top:1px solid #d4dce8'></td>
      </tr>
    </table>

    <div style='background:#f6f9fd;border:1px solid #d4dce8;border-top:2px solid #0c2d5e;padding:18px 20px;margin-bottom:24px'>
      <p style='margin:0;font-size:12px;color:#3a4a5a;line-height:1.7;font-style:italic'>".htmlspecialchars(mb_substr($texto_pqr, 0, 500)).(strlen($texto_pqr)>500?'…':'')."</p>
      ".($audio_url || $canvas_url ? "<p style='margin:10px 0 0;font-size:10px;color:#7a90a8'>Su ".($audio_url?'audio':'imagen')." fue adjuntado a este correo como evidencia.</p>" : "")."
    </div>" : "")."

    <!-- DIVIDER CANALES -->
    <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:12px'>
      <tr>
        <td style='font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:#7a90a8;font-weight:500;white-space:nowrap;padding-right:12px'>Canales de atención</td>
        <td style='border-top:1px solid #d4dce8'></td>
      </tr>
    </table>

    <!-- CANALES GRILLA 2x2 -->
    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;border:1px solid #d4dce8'>
      <tr>
        <td width='50%' style='padding:16px 18px;border-bottom:1px solid #d4dce8;border-right:1px solid #d4dce8;vertical-align:top'>
          <p style='margin:0 0 4px;font-size:9px;letter-spacing:1.8px;text-transform:uppercase;color:#8a9ab8'>WhatsApp</p>
          <a href='https://wa.me/573043412431' style='font-size:12px;color:#0c2d5e;font-weight:500;text-decoration:none'>304 341 2431</a>
        </td>
        <td width='50%' style='padding:16px 18px;border-bottom:1px solid #d4dce8;vertical-align:top'>
          <p style='margin:0 0 4px;font-size:9px;letter-spacing:1.8px;text-transform:uppercase;color:#8a9ab8'>PBX Atención</p>
          <a href='tel:6043222432' style='font-size:12px;color:#0c2d5e;font-weight:500;text-decoration:none'>604 322 2432 Op. 2</a>
        </td>
      </tr>
      <tr>
        <td width='50%' style='padding:16px 18px;border-right:1px solid #d4dce8;vertical-align:top'>
          <p style='margin:0 0 4px;font-size:9px;letter-spacing:1.8px;text-transform:uppercase;color:#8a9ab8'>Correo PQRSFD</p>
          <a href='mailto:pqrsfd@tododrogas.com.co' style='font-size:12px;color:#0c2d5e;font-weight:500;text-decoration:none'>pqrsfd@tododrogas.com.co</a>
        </td>
        <td width='50%' style='padding:16px 18px;vertical-align:top'>
          <p style='margin:0 0 4px;font-size:9px;letter-spacing:1.8px;text-transform:uppercase;color:#8a9ab8'>Portal web</p>
          <a href='https://tododrogas.online/pqr_form.html' style='font-size:12px;color:#0c2d5e;font-weight:500;text-decoration:none'>tododrogas.online/pqr</a>
        </td>
      </tr>
    </table>

  </td></tr>

  <!-- FOOTER -->
  <tr><td style='background:#0c2d5e;padding:18px 44px'>
    <table width='100%' cellpadding='0' cellspacing='0'>
      <tr>
        <td style='font-size:10px;color:#4a6a90;line-height:1.6'>Tododrogas CIA SAS<br>Experiencia de Servicio al Cliente</td>
        <td align='right' style='font-size:9px;letter-spacing:2px;text-transform:uppercase;color:#2a4870;font-weight:500'>Sistema PQRSFD</td>
      </tr>
    </table>
  </td></tr>

</table></td></tr></table>
</body></html>";

    $acuse_payload = [
        'subject'      => "Su solicitud fue recibida · Radicado {$ticket_id} · Tododrogas CIA SAS",
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
}

// ── PASO 4: HISTORIAL_EVENTOS pqr_recibida ──────────────────────────
if (!empty($SB_URL) && $SB_URL !== '__SB_URL__' && $correo_id) {
    $ev = [
        'correo_id'   => $correo_id,
        'evento'      => 'pqr_recibida',
        'descripcion' => "PQR recibida via {$canal} ({$canal_contacto}). Clasificada: {$sentimiento} / {$prioridad}. Correo PQRSFD: ".($correo_enviado?'OK':'ERROR').". Acuse: ".(isset($acuse_enviado)&&$acuse_enviado?'OK':($correo?'ERROR':'sin correo')),
        'datos_extra' => json_encode([
            'ticket_id'    => $ticket_id,
            'canal'        => $canal,
            'origen'       => $origen,
            'sentimiento'  => $sentimiento,
            'prioridad'    => $prioridad,
            'categoria_ia' => $categoria_ia,
            'horas_sla'    => $horas_sla,
        ]),
    ];
    $ch_ev = curl_init("$SB_URL/rest/v1/historial_eventos");
    curl_setopt_array($ch_ev, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($ev),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["apikey: $SB_KEY","Authorization: Bearer $SB_KEY",'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 8,
    ]);
    curl_exec($ch_ev); curl_close($ch_ev);
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
