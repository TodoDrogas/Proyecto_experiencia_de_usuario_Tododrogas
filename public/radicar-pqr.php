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

// ── LOGO desde Supabase configuracion_sistema ───────────────────────
$logo_url      = '';
$logo_img_html = '';
try {
    $ch_cfg = curl_init("$SB_URL/rest/v1/configuracion_sistema?id=eq.main&select=data");
    curl_setopt_array($ch_cfg, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>5,
        CURLOPT_HTTPHEADER=>["apikey: $SB_KEY","Authorization: Bearer $SB_KEY",'Accept: application/json']]);
    $cfg_resp = curl_exec($ch_cfg); curl_close($ch_cfg);
    $cfg_rows = json_decode($cfg_resp, true);
    $cfg_data = $cfg_rows[0]['data'] ?? [];
    if (!empty($cfg_data['logo'])) {
        $logo_url = $cfg_data['logo'];

        // Correo interno (Outlook/pqrsfd) → base64 embebido
        $logo_data = fetchUrlBytes($logo_url, $SB_KEY);
        if ($logo_data && strlen($logo_data) < 200*1024) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $logo_mime = $finfo->buffer($logo_data) ?: 'image/png';
            $logo_b64  = base64_encode($logo_data);
            $logo_img_html = "<img src=\"data:{$logo_mime};base64,{$logo_b64}\" alt=\"Tododrogas\" style=\"height:52px;max-width:220px;object-fit:contain;display:block;margin:0 auto 10px\">";
        } else {
            $logo_img_html = "<img src=\"{$logo_url}\" alt=\"Tododrogas\" style=\"height:52px;max-width:220px;object-fit:contain;display:block;margin:0 auto 10px\">";
        }

        // Acuse al usuario (Gmail) → siempre URL directa (Gmail bloquea base64 inline)
        $logo_img_html_usuario = "<img src=\"{$logo_url}\" alt=\"Tododrogas\" style=\"height:52px;max-width:220px;object-fit:contain;display:block;margin:0 auto 10px\">";
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

// Texto final para clasificar (transcripción si hay, sino descripción)
$texto_pqr = $transcripcion ?: $descripcion;


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

// ── PASO 1: CLASIFICACIÓN IA ─────────────────────────────────────────
$sentimiento  = 'neutro';
$prioridad    = 'media';
$categoria_ia = ucfirst($tipo_pqr);
$nivel_riesgo = 'bajo';
$resumen_corto = mb_substr($texto_pqr, 0, 120);
$ley_aplicable = 'Ley 1755/2015';
$horas_sla    = 15 * 24; // 15 días por defecto

if ($OPENAI_KEY && $texto_pqr) {
    $prompt = <<<PROMPT
Analiza esta solicitud de una drogueria colombiana. Responde SOLO JSON valido sin markdown.

TIPO DECLARADO POR EL USUARIO: $tipo_pqr_raw
TEXTO EXACTO: $texto_pqr

JSON requerido:
{
  "sentimiento": "positivo|neutro|negativo|urgente",
  "tono": "enojado|frustrado|triste|ansioso|neutro|satisfecho|agradecido",
  "prioridad": "baja|media|alta|critica",
  "categoria": "frase corta en espanol",
  "nivel_riesgo": "bajo|medio|alto|critico",
  "resumen": "maximo 100 caracteres",
  "ley": "ley colombiana aplicable",
  "horas_sla": numero entero
}

REGLAS CRITICAS — LEER TODAS ANTES DE CLASIFICAR:
1. EL CONTENIDO DEL TEXTO MANDA sobre el tipo declarado. Si el usuario seleccionó "queja" pero el texto es claramente una felicitación, clasificar por el contenido real del texto.
2. sentimiento=positivo + tono=agradecido: cuando el texto contiene felicitaciones, agradecimientos, elogios, "muchas gracias", "buen servicio", "los felicito", "excelente atención". Prioridad siempre=baja. horas_sla=360. SIN IMPORTAR el tipo declarado.
3. sentimiento=negativo + tono=enojado: exclamaciones, palabras como horrible/pesimo/increible/incumplieron/nunca/es el colmo/abusivos/ladrones o frases de ira/indignación explícita.
4. sentimiento=negativo + tono=frustrado: quejas sin ira explícita, insatisfacción repetida, "no me atienden", "llevo días esperando", "siempre pasa lo mismo".
5. sentimiento=urgente: riesgo de salud, error en medicamento, reacción adversa, urgencia médica. Prioridad siempre=critica.
6. sentimiento=neutro: peticiones informativas sin carga emocional (pedir documento, consultar horario, solicitar información).
7. prioridad según contenido real: si el texto es positivo → baja; peticion/sugerencia → media; queja/reclamo con inconformidad → alta; urgente/denuncia → critica.
8. horas_sla: texto_positivo/felicitacion=360, sugerencia=360, peticion=120, queja_real=72, reclamo=72, denuncia=24, urgente=4.
9. nivel_riesgo: si sentimiento=positivo → bajo. Si urgente → critico. Si negativo+alta → medio. Si reclamo económico → medio.
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
$tipo_label  = strtoupper($tipo_pqr);
$canal_label = strtoupper($canal);

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
    'body_preview'      => mb_substr($texto_pqr, 0, 200),
    'body_content'      => $texto_pqr,
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
$token = getGraphToken($TENANT_ID, $CLIENT_ID, $CLIENT_SECRET);

if ($token) {
    // Cuerpo del correo HTML
    $fecha_fmt  = date('d/m/Y H:i', strtotime($now));
    $canal_txt  = ['audio' => 'mensaje de voz', 'canvas' => 'escritura con lápiz inteligente', 'escrito' => 'texto escrito'][$canal] ?? 'formulario web';

    $badge_sent  = "<span style='background:" . (['positivo'=>'#dcfce7','neutro'=>'#f3f4f6','negativo'=>'#fee2e2','urgente'=>'#fef3c7'][$sentimiento]??'#f3f4f6') . ";color:" . (['positivo'=>'#166534','neutro'=>'#374151','negativo'=>'#991b1b','urgente'=>'#92400e'][$sentimiento]??'#374151') . ";padding:3px 10px;border-radius:12px;font-weight:700;font-size:12px'>{$emoji_sent} " . strtoupper($sentimiento) . "</span>";
    $badge_prio  = "<span style='background:" . (['baja'=>'#dcfce7','media'=>'#fef9c3','alta'=>'#fed7aa','critica'=>'#fee2e2'][$prioridad]??'#fef9c3') . ";color:" . (['baja'=>'#166534','media'=>'#854d0e','alta'=>'#9a3412','critica'=>'#991b1b'][$prioridad]??'#854d0e') . ";padding:3px 10px;border-radius:12px;font-weight:700;font-size:12px'>{$emoji_prio} " . strtoupper($prioridad) . "</span>";
    $badge_canal = "<span style='background:#dbeafe;color:#1e40af;padding:3px 10px;border-radius:12px;font-weight:700;font-size:12px'>{$emoji_canal} " . strtoupper($canal) . "</span>";

    $cuerpo_html = "
<div style='font-family:Poppins,Arial,sans-serif;max-width:680px;margin:0 auto;color:#1f2937'>
  <div style='background:#1e40af;padding:20px 28px;border-radius:8px 8px 0 0'>
    {$logo_img_html}
    <h2 style='color:#fff;margin:4px 0 0;font-size:20px;font-weight:700'>Nueva PQRSFD Recibida</h2>
    <p style='color:#bfdbfe;margin:4px 0 0;font-size:12px;letter-spacing:.5px'>Experiencia de Servicio al Cliente · Nova TD</p>
  </div>
  <div style='background:#f8fafc;padding:24px 28px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px'>
    <p style='margin:0 0 16px'>Estimado equipo PQRSFDSFD,</p>
    <p style='margin:0 0 16px'>Mediante la <strong>Plataforma Inteligente Nova TD</strong> se ha recibido el siguiente caso radicado:</p>

    <table style='width:100%;border-collapse:collapse;margin-bottom:20px'>
      <tr><td style='padding:8px 12px;background:#eff6ff;font-weight:700;width:160px;border:1px solid #dbeafe'>Radicado</td>
          <td style='padding:8px 12px;border:1px solid #dbeafe;font-weight:700;color:#1e40af;font-size:16px'>{$ticket_id}</td></tr>
      <tr><td style='padding:8px 12px;background:#f8fafc;font-weight:700;border:1px solid #e2e8f0'>Fecha</td>
          <td style='padding:8px 12px;border:1px solid #e2e8f0'>{$fecha_fmt}</td></tr>
      <tr><td style='padding:8px 12px;background:#f8fafc;font-weight:700;border:1px solid #e2e8f0'>Canal</td>
          <td style='padding:8px 12px;border:1px solid #e2e8f0'>{$badge_canal}</td></tr>
      <tr><td style='padding:8px 12px;background:#f8fafc;font-weight:700;border:1px solid #e2e8f0'>Tipo</td>
          <td style='padding:8px 12px;border:1px solid #e2e8f0'><strong>" . strtoupper($tipo_pqr) . "</strong> — {$categoria_ia}</td></tr>
      <tr><td style='padding:8px 12px;background:#f8fafc;font-weight:700;border:1px solid #e2e8f0'>Sentimiento</td>
          <td style='padding:8px 12px;border:1px solid #e2e8f0'>{$badge_sent}</td></tr>
      <tr><td style='padding:8px 12px;background:#f8fafc;font-weight:700;border:1px solid #e2e8f0'>Prioridad</td>
          <td style='padding:8px 12px;border:1px solid #e2e8f0'>{$badge_prio}</td></tr>
      <tr><td style='padding:8px 12px;background:#f8fafc;font-weight:700;border:1px solid #e2e8f0'>SLA</td>
          <td style='padding:8px 12px;border:1px solid #e2e8f0'>{$horas_sla}h · Límite: " . date('d/m/Y H:i', strtotime($fecha_limite_sla)) . "</td></tr>
      <tr><td style='padding:8px 12px;background:#f8fafc;font-weight:700;border:1px solid #e2e8f0'>Ley aplicable</td>
          <td style='padding:8px 12px;border:1px solid #e2e8f0'>{$ley_aplicable}</td></tr>
    </table>

    <div style='background:#fff;border:1px solid #e2e8f0;border-left:4px solid #1e40af;border-radius:4px;padding:16px 20px;margin-bottom:20px'>
      <p style='margin:0 0 8px;font-weight:700;color:#1e40af'>👤 Datos del ciudadano</p>
      <p style='margin:2px 0;font-size:13px'><strong>Nombre:</strong> {$nombre}</p>" .
      ($correo ? "<p style='margin:2px 0;font-size:13px'><strong>Correo:</strong> {$correo}</p>" : "") .
      ($telefono ? "<p style='margin:2px 0;font-size:13px'><strong>Celular:</strong> {$telefono}</p>" : "") .
      "<p style='margin:2px 0;font-size:13px'><strong>Canal de contacto preferido:</strong> {$canal_contacto}</p>
    </div>

    <div style='background:#fff;border:1px solid #e2e8f0;border-left:4px solid #7c3aed;border-radius:4px;padding:16px 20px;margin-bottom:20px'>
      <p style='margin:0 0 8px;font-weight:700;color:#7c3aed'>{$emoji_canal} Mensaje recibido via {$canal_txt}</p>
      <p style='margin:0;font-size:14px;line-height:1.6;color:#374151'>" . nl2br(htmlspecialchars($texto_pqr)) . "</p>" .
      ($resumen_corto ? "<p style='margin:10px 0 0;font-size:12px;color:#6b7280;font-style:italic'>📌 Resumen IA: {$resumen_corto}</p>" : "") .
      "
    </div>

    " . ($audio_url || $canvas_url ? "<div style='background:#fefce8;border:1px solid #fde68a;border-radius:4px;padding:12px 16px;margin-bottom:20px;font-size:13px'>
      <strong>📎 Adjunto incluido:</strong> " . ($audio_url ? "Archivo de audio (🎤 .webm)" : "Imagen del lápiz inteligente (✏️)") . " — ver adjunto en este correo.
    </div>" : "") . "

    <div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:4px;padding:12px 16px;margin-bottom:20px;font-size:12px;color:#166534'>
      ✅ Este caso ha sido <strong>guardado automáticamente en el sistema</strong>. 
      Si hay problemas de conectividad, el registro persiste en la base de datos y estará disponible cuando se recupere la conexión.
    </div>

    <p style='font-size:12px;color:#9ca3af;margin:0;border-top:1px solid #e5e7eb;padding-top:12px'>
      Experiencia de Servicio al Cliente · Tododrogas CIA SAS · Nova TD v4 · {$fecha_fmt}<br>
      Radicado: <strong>{$ticket_id}</strong> · ID interno: " . ($correo_id ?? 'N/A') . "
    </p>
  </div>
</div>";

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

    // Actualizar BD con datos de clasificación y correo_enviado
    if ($correo_id) {
        sbPatch($SB_URL, $SB_KEY, 'correos', "id=eq.$correo_id", [
            'updated_at' => date('c'),
        ]);
    }
} else {
    $correo_enviado = false;
    $mail_code = 0;
}

// ── PASO 3B: ACUSE AL USUARIO ───────────────────────────────────────
if ($token && $correo && filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    $fecha_fmt_u = date('d/m/Y H:i', strtotime($now));
    $dias_resp   = round($horas_sla / 24);
    $fecha_lim_u = date('d/m/Y H:i', strtotime($fecha_limite_sla));

    $tipo_label_u = strtoupper($tipo_pqr_raw);
    $emoji_tipo_u = ['PETICIÓN'=>'💡','QUEJA'=>'😤','RECLAMO'=>'⚠️','SUGERENCIA'=>'💬','FELICITACIÓN'=>'⭐','DENUNCIA'=>'🚨'][strtoupper($tipo_pqr_raw)] ?? '📋';

    $cuerpo_acuse = "
<!DOCTYPE html><html><head><style>@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap');body,table,td,p,span,div{font-family:'Poppins',Arial,sans-serif!important}</style></head><body style='margin:0;padding:0;background:#f1f5f9;font-family:Poppins,Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f1f5f9;padding:32px 16px'>
<tr><td align='center'>
<table width='560' cellpadding='0' cellspacing='0' style='max-width:560px;width:100%'>

  <tr><td style='background:#1e40af;border-radius:12px 12px 0 0;padding:28px 32px;text-align:center'>
    ".($logo_img_html ?: "")."
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
          <td style='color:#111827;font-weight:600;border-bottom:1px solid #f3f4f6'>{$emoji_tipo_u} {$tipo_label_u} — {$categoria_ia}</td></tr>

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
  <tr><td style='background:#f8fafc;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;padding:16px 32px;text-align:center'>
    <p style='font-size:11px;color:#9ca3af;margin:0'>Tododrogas CIA SAS · Experiencia de Servicio al Cliente<br>
    Este es un mensaje automático, no responda directamente a este correo.</p>
  </td></tr>

</table></td></tr></table></body></html>";

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
