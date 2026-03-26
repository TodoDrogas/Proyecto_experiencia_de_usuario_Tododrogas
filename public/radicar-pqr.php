<?php
/**
 * radicar-pqr.php v3 — Sistema PQR Tododrogas CIA SAS
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
        $logo_url      = $cfg_data['logo'];
        $logo_img_html = "<img src=\"{$logo_url}\" alt=\"Tododrogas\" style=\"height:52px;max-width:220px;object-fit:contain;display:block;margin:0 auto 10px\">";
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

// ── PASO 1: CLASIFICACIÓN IA ─────────────────────────────────────────
$sentimiento  = 'neutro';
$prioridad    = 'media';
$categoria_ia = ucfirst($tipo_pqr);
$nivel_riesgo = 'bajo';
$resumen_corto = mb_substr($texto_pqr, 0, 120);
$ley_aplicable = 'Ley 1755/2015';
$horas_sla    = 15 * 24; // 15 días por defecto

if ($OPENAI_KEY && $texto_pqr) {
    $prompt = "Analiza esta PQR de una drogueria colombiana. Responde SOLO JSON valido sin markdown.

TIPO DECLARADO POR EL USUARIO: $tipo_pqr_raw
TEXTO EXACTO: $texto_pqr

JSON requerido:
{
  \"sentimiento\": \"positivo|neutro|negativo|urgente\",
  \"tono\": \"enojado|frustrado|triste|ansioso|neutro|satisfecho|agradecido\",
  \"prioridad\": \"baja|media|alta|critica\",
  \"categoria\": \"frase corta en espanol\",
  \"nivel_riesgo\": \"bajo|medio|alto|critico\",
  \"resumen\": \"maximo 100 caracteres\",
  \"ley\": \"ley colombiana aplicable\",
  \"horas_sla\": numero entero
}

REGLAS CRITICAS DE SENTIMIENTO Y TONO:
1. Lee el texto completo antes de clasificar. El tono emocional predomina sobre las palabras sueltas.
2. sentimiento=negativo + tono=enojado: si hay exclamaciones, palabras como horrible/pesimo/increible/incumplieron/nunca/siempre (en negativo)/no pueden/es el colmo/que verguenza/abusivos/ladrones/estafadores o frases que expresan ira o indignacion.
3. sentimiento=negativo + tono=frustrado: quejas sin ira explicita, insatisfaccion repetida, 'no me atienden', 'llevo dias esperando'.
4. sentimiento=urgente: riesgo de salud, error en medicamento, reaccion adversa, urgencia medica. Prioridad siempre critica.
5. sentimiento=positivo + tono=agradecido: felicitaciones, gracias, elogios. Prioridad siempre baja. horas_sla=360.
6. sentimiento=neutro: SOLO peticiones informativas sin carga emocional (pedir un documento, consultar horario).
7. prioridad segun tipo: felicitacion/sugerencia=baja, peticion=media, queja/reclamo=alta, denuncia/urgente=critica.
8. horas_sla: felicitacion=360, sugerencia=360, peticion=120, queja=72, reclamo=72, denuncia=24, urgente=4."

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'       => 'gpt-4o-mini',
            'max_tokens'  => 300,
            'temperature' => 0.1,
            'messages'    => [
                ['role' => 'system', 'content' => 'Eres clasificador de PQRs colombianas. Responde SOLO JSON valido con los campos exactos solicitados incluyendo tono. CRITICO: detecta ira y frustracion en el texto aunque no usen palabras explicitas de queja. Si el tipo es felicitacion, sentimiento=positivo+tono=agradecido+prioridad=baja siempre.'],
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
    'audio_url'         => $audio_url     ?: null,
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
<div style='font-family:Arial,sans-serif;max-width:680px;margin:0 auto;color:#1f2937'>
  <div style='background:#1e40af;padding:20px 28px;border-radius:8px 8px 0 0'>
    {$logo_img_html}
    <h2 style='color:#fff;margin:4px 0 0;font-size:20px;font-weight:700'>Nueva PQR Recibida</h2>
    <p style='color:#bfdbfe;margin:4px 0 0;font-size:12px;letter-spacing:.5px'>Sistema PQR Inteligente · Plataforma Nova TD</p>
  </div>
  <div style='background:#f8fafc;padding:24px 28px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px'>
    <p style='margin:0 0 16px'>Estimado equipo PQRSFD,</p>
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
      Sistema PQR Inteligente · Tododrogas CIA SAS · Nova TD v4 · {$fecha_fmt}<br>
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
        $audio_data = @file_get_contents($audio_url);
        if ($audio_data && strlen($audio_data) < 4 * 1024 * 1024) {
            $mail_payload['attachments'][] = [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => "audio_{$ticket_id}.webm",
                'contentType'  => 'audio/webm',
                'contentBytes' => base64_encode($audio_data),
            ];
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
            $img_data = @file_get_contents($canvas_url);
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

    $evidencia_tipo = $audio_url ? '🎤 audio de voz' : ($canvas_url ? '✏️ escrito con lápiz inteligente' : '');
    $cuerpo_acuse = "
<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#eef2f7;font-family:Arial,Helvetica,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#eef2f7;padding:32px 16px'>
<tr><td align='center'>
<table width='600' cellpadding='0' cellspacing='0' style='max-width:600px;width:100%;border-radius:14px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.10)'>
  <tr><td style='background:linear-gradient(135deg,#1e3a8a 0%,#1e40af 60%,#2563eb 100%);padding:30px 36px 24px;text-align:center'>
    ".($logo_img_html ? "<div style='margin-bottom:14px'>{$logo_img_html}</div>" : "<div style='margin-bottom:14px'><span style='color:#fff;font-size:22px;font-weight:800;letter-spacing:1px'>TODODROGAS</span></div>")."
    <p style='color:#93c5fd;margin:0;font-size:11px;letter-spacing:2px;text-transform:uppercase;font-weight:600'>Sistema PQR · Servicio al Cliente</p>
    <h1 style='color:#fff;margin:10px 0 0;font-size:22px;font-weight:700'>✅ Solicitud Recibida Exitosamente</h1>
  </td></tr>
  <tr><td style='background:#1e3a8a;padding:22px 36px;text-align:center;border-top:1px solid rgba(255,255,255,0.1)'>
    <p style='color:#93c5fd;margin:0 0 6px;font-size:11px;letter-spacing:2px;text-transform:uppercase;font-weight:600'>Su número de radicado</p>
    <p style='color:#fff;margin:0;font-size:32px;font-weight:800;letter-spacing:4px;font-family:Courier New,monospace'>{$ticket_id}</p>
    <p style='color:#bfdbfe;margin:8px 0 0;font-size:12px'>📌 Guárdelo — lo necesitará para consultar el estado de su caso</p>
  </td></tr>
  <tr><td style='background:#fff;padding:28px 36px'>
    <p style='margin:0 0 18px;color:#111827;font-size:15px'>Estimado/a <strong style='color:#1e40af'>{$nombre}</strong>,</p>
    <p style='margin:0 0 20px;color:#374151;font-size:14px;line-height:1.7'>Hemos recibido su solicitud a través de nuestra <strong>Plataforma Inteligente Nova TD</strong>. Estamos comprometidos con brindarle una respuesta oportuna y de calidad. A continuación el resumen de su caso:</p>
    <table width='100%' cellpadding='0' cellspacing='0' style='font-size:13px;border-collapse:collapse;margin-bottom:24px;border-radius:8px;overflow:hidden;border:1px solid #e5e7eb'>
      <tr style='background:#eff6ff'><td style='padding:10px 14px;font-weight:700;color:#1e40af;width:170px;border-bottom:1px solid #dbeafe'>📅 Fecha de radicado</td>
          <td style='padding:10px 14px;color:#111827;font-weight:600;border-bottom:1px solid #dbeafe'>{$fecha_fmt_u} (hora Colombia)</td></tr>
      <tr style='background:#f9fafb'><td style='padding:10px 14px;font-weight:700;color:#374151;border-bottom:1px solid #f3f4f6'>{$emoji_tipo_u} Tipo</td>
          <td style='padding:10px 14px;color:#111827;font-weight:600;border-bottom:1px solid #f3f4f6'>{$tipo_label_u} — {$categoria_ia}</td></tr>
      <tr style='background:#fff'><td style='padding:10px 14px;font-weight:700;color:#374151;border-bottom:1px solid #f3f4f6'>⏱ Tiempo de respuesta</td>
          <td style='padding:10px 14px;color:#111827;font-weight:600;border-bottom:1px solid #f3f4f6'>{$dias_resp} días hábiles<br><span style='font-size:11px;color:#6b7280;font-weight:400'>Fecha límite: {$fecha_lim_u}</span></td></tr>
      <tr style='background:#f9fafb'><td style='padding:10px 14px;font-weight:700;color:#374151'>📲 Contacto preferido</td>
          <td style='padding:10px 14px;color:#111827;font-weight:600'>{$canal_contacto}</td></tr>
    </table>
    <div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:18px 20px;margin-bottom:22px'>
      <p style='margin:0 0 10px;font-size:13px;font-weight:700;color:#166534'>¿Qué pasa ahora con su solicitud?</p>
      <p style='margin:4px 0;font-size:13px;color:#166534'>→ Un asesor especializado revisará su caso.</p>
      <p style='margin:4px 0;font-size:13px;color:#166534'>→ Recibirá respuesta a <strong>{$correo}</strong> en el plazo indicado.</p>
      <p style='margin:4px 0;font-size:13px;color:#166534'>→ Para urgencias, responda este correo indicando su radicado <strong>{$ticket_id}</strong>.</p>
    </div>
    ".($texto_pqr ? "
    <div style='background:#fafafa;border:1px solid #e5e7eb;border-left:4px solid #7c3aed;border-radius:6px;padding:16px 18px;margin-bottom:22px'>
      <p style='margin:0 0 8px;font-size:12px;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:.5px'>📋 Copia de su mensaje</p>
      <p style='margin:0;font-size:13px;color:#374151;line-height:1.7'>".htmlspecialchars(mb_substr($texto_pqr,0,600)).(strlen($texto_pqr)>600?'…':'')."</p>
    </div>" : "")."
    ".($audio_url || $canvas_url ? "
    <div style='background:#fff7ed;border:1px solid #fed7aa;border-left:4px solid #f97316;border-radius:6px;padding:14px 18px;margin-bottom:22px'>
      <p style='margin:0 0 6px;font-size:12px;font-weight:700;color:#c2410c;text-transform:uppercase;letter-spacing:.5px'>🔒 Evidencia adjunta de su solicitud</p>
      <p style='margin:0;font-size:13px;color:#7c2d12;line-height:1.6'>Su <strong>{$evidencia_tipo}</strong> ha sido adjuntado a este correo como constancia de lo que manifestó. Esta evidencia queda registrada en nuestro sistema y garantiza la fidelidad de su solicitud.</p>
    </div>" : "")."
  </td></tr>
  <tr><td style='background:#f8fafc;border-top:1px solid #e5e7eb;padding:18px 36px;text-align:center'>
    <p style='font-size:11px;color:#9ca3af;margin:0 0 4px'><strong style='color:#6b7280'>Tododrogas CIA SAS</strong> · Experiencia de Servicio al Cliente · Nova TD v4</p>
    <p style='font-size:10px;color:#d1d5db;margin:0'>Este es un correo automático — use su número de radicado <strong>{$ticket_id}</strong> para comunicarse con nosotros.</p>
  </td></tr>
</table></td></tr></table></body></html>";

    $acuse_payload = [
        'subject'      => "Su solicitud fue recibida · Radicado {$ticket_id} · Tododrogas CIA SAS",
        'importance'   => 'normal',
        'body'         => ['contentType' => 'HTML', 'content' => $cuerpo_acuse],
        'toRecipients' => [['emailAddress' => ['address' => $correo, 'name' => $nombre]]],
        'attachments'  => [],
    ];

    // Adjuntar su propio audio/canvas como evidencia (usa curl para URLs de Supabase Storage)
    function fetchUrlBytes($url, $maxBytes = 4194304) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20,
            CURLOPT_FOLLOWLOCATION=>true, CURLOPT_SSL_VERIFYPEER=>true]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300 && $data && strlen($data) < $maxBytes) return $data;
        return null;
    }

    if ($audio_url) {
        // Detectar extensión/mime desde URL
        $audio_ext  = 'webm';
        $audio_mime = 'audio/webm';
        if (strpos($audio_url, '.mp4') !== false) { $audio_ext='mp4'; $audio_mime='audio/mp4'; }
        elseif (strpos($audio_url, '.ogg') !== false) { $audio_ext='ogg'; $audio_mime='audio/ogg'; }
        elseif (strpos($audio_url, '.mp3') !== false) { $audio_ext='mp3'; $audio_mime='audio/mpeg'; }

        $audio_ev = fetchUrlBytes($audio_url);
        if ($audio_ev) {
            $acuse_payload['attachments'][] = [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => "su_audio_{$ticket_id}.{$audio_ext}",
                'contentType'  => $audio_mime,
                'contentBytes' => base64_encode($audio_ev),
            ];
        }
    }
    if ($canvas_url && strpos($canvas_url, 'data:image') === 0) {
        preg_match('/data:image\/(\w+);base64,(.+)/s', $canvas_url, $mc);
        if ($mc) {
            $acuse_payload['attachments'][] = [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => "su_escrito_{$ticket_id}.{$mc[1]}",
                'contentType'  => "image/{$mc[1]}",
                'contentBytes' => $mc[2],
            ];
        }
    } elseif ($canvas_url) {
        $canvas_ev = fetchUrlBytes($canvas_url);
        if ($canvas_ev) {
            $ext_canvas = (strpos($canvas_url,'.jpg')!==false||strpos($canvas_url,'.jpeg')!==false) ? 'jpg' : 'png';
            $mime_canvas = $ext_canvas === 'jpg' ? 'image/jpeg' : 'image/png';
            $acuse_payload['attachments'][] = [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => "su_escrito_{$ticket_id}.{$ext_canvas}",
                'contentType'  => $mime_canvas,
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
    'mensaje'         => "Tu solicitud fue recibida exitosamente. Radicado: {$ticket_id}",
]);
