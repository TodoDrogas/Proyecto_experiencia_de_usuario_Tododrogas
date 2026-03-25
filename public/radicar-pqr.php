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
$tipo_pqr     = strtolower(trim($body['tipo_pqr'] ?? 'peticion'));
$transcripcion = trim($body['transcripcion'] ?? '');
$audio_url    = trim($body['audio_url']    ?? '');
$canvas_url   = trim($body['canvas_url']   ?? '');

// Detectar canal
$canal = 'escrito';
if ($audio_url)           $canal = 'audio';
elseif ($canvas_url)      $canal = 'canvas';
elseif ($transcripcion)   $canal = 'audio'; // transcripción sin URL

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
    $prompt = "Analiza esta PQR de un ciudadano colombiano a una droguería y responde SOLO en JSON válido sin markdown.

TIPO DECLARADO: $tipo_pqr
TEXTO: $texto_pqr

Responde exactamente con este JSON:
{
  \"sentimiento\": \"positivo|neutro|negativo|urgente\",
  \"prioridad\": \"baja|media|alta|critica\",
  \"categoria\": \"string corto en español (ej: Servicio al cliente, Precios, Disponibilidad medicamentos)\",
  \"nivel_riesgo\": \"bajo|medio|alto|critico\",
  \"resumen\": \"máximo 100 caracteres resumiendo el caso\",
  \"ley\": \"ley colombiana aplicable (ej: Ley 1755/2015, Ley 100/1993)\",
  \"horas_sla\": número de horas límite de respuesta según urgencia y ley
}";

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'       => 'gpt-4o-mini',
            'max_tokens'  => 200,
            'temperature' => 0.1,
            'messages'    => [
                ['role' => 'system', 'content' => 'Eres un clasificador de PQRs. Responde SOLO JSON válido.'],
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
    <h2 style='color:#fff;margin:0;font-size:18px'>🏥 Tododrogas CIA SAS — Nueva PQR Recibida</h2>
    <p style='color:#bfdbfe;margin:6px 0 0;font-size:13px'>Sistema PQR Inteligente · Plataforma Nova TD</p>
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

// ── PASO 4: HISTORIAL EVENTOS ────────────────────────────────────────
if ($correo_id) {
    sbPost($SB_URL, $SB_KEY, 'historial_eventos', [
        'correo_id'   => $correo_id,
        'evento'      => 'pqr_recibida',
        'descripcion' => "PQR recibida vía {$canal} ({$canal_contacto}). Clasificada: {$sentimiento} / {$prioridad}. Correo a pqrsfd: " . ($correo_enviado ? 'enviado' : 'pendiente'),
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
