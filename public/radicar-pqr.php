<?php
/**
 * radicar-pqr.php v4 — Sistema PQR Tododrogas CIA SAS
 * ─────────────────────────────────────────────────────
 * 1. Recibe PQR del formulario (escrito / audio / canvas)
 * 2. Genera ticket TD-YYYYMMDD-XXXX con hora Colombia
 * 3. Clasifica con GPT-4o-mini + SLA REAL según normativa colombiana
 * 4. Inserta en Supabase con documento, sede, datos completos
 * 5. Envía notificación a pqrsfd@tododrogas.com.co con adjuntos
 * 6. Envía confirmación automática al ciudadano desde pqrsfd
 * 7. Registra en historial_eventos
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── CREDENCIALES ────────────────────────────────────────────────────
$SB_URL         = '__SB_URL__';
$SB_KEY         = '__SB_KEY__';
$OPENAI_KEY     = '__OPENAI_KEY__';
$TENANT_ID      = '__AZURE_TENANT_ID__';
$CLIENT_ID      = '__AZURE_CLIENT_ID__';
$CLIENT_SECRET  = '__AZURE_CLIENT_SECRET__';
$BUZON_PQRS     = 'pqrsfd@tododrogas.com.co';
$GRAPH_USER_ID  = '__GRAPH_USER_ID__';

// ── TIMEZONE COLOMBIA ────────────────────────────────────────────────
date_default_timezone_set('America/Bogota');

// ── SLA REALES SEGÚN NORMATIVA COLOMBIANA ────────────────────────────
// Fuente: TIEMPOS_PQRSFD.xlsx — Ley 1755/2015
function getSLA($tipo, $prioridad, $nivel_riesgo) {
    $sla = [
        'peticion'        => ['default' => [360,  '15 días hábiles',              'Ley 1755/2015']],
        'queja'           => [
            'critica'     => [24,   'Riesgo vital — 24 horas',           'Ley 1755/2015'],
            'alta'        => [48,   'Riesgo priorizado — 48 horas',      'Ley 1755/2015'],
            'media'       => [72,   'Riesgo simple — 72 horas',          'Ley 1755/2015'],
            'default'     => [360,  '15 días hábiles',                   'Ley 1755/2015'],
        ],
        'reclamo'         => ['default' => [360,  '15 días hábiles',              'Ley 1755/2015 / Ley 1751/2015']],
        'sugerencia'      => ['default' => [360,  '15 días hábiles',              'Ley 1755/2015']],
        'solicitud'       => ['default' => [192,  '8 días hábiles',               'Ley 1755/2015']],
        'solicitud_copias'=> ['default' => [240,  '10 días hábiles',              'Ley 1755/2015']],
        'consulta'        => ['default' => [720,  '30 días hábiles',              'Ley 1755/2015']],
        'felicitacion'    => ['default' => [360,  '15 días hábiles',              'Ley 1755/2015']],
        'denuncia'        => ['default' => [360,  '15 días hábiles',              'Ley 1755/2015 / Vigilancia Sanitaria']],
    ];

    $tipo_key = strtolower(trim($tipo));
    // Mapeo de variantes
    $map = ['petition'=>'peticion','queja'=>'queja','reclamo'=>'reclamo',
            'sugerencia'=>'sugerencia','felicitacion'=>'felicitacion',
            'denuncia'=>'denuncia','solicitud'=>'solicitud','consulta'=>'consulta'];
    $tipo_key = $map[$tipo_key] ?? 'peticion';

    $reglas = $sla[$tipo_key] ?? $sla['peticion'];

    // Para quejas usar prioridad/riesgo para determinar SLA
    if ($tipo_key === 'queja') {
        if ($prioridad === 'critica' || $nivel_riesgo === 'critico' || $nivel_riesgo === 'vital') {
            return $reglas['critica'];
        } elseif ($prioridad === 'alta' || $nivel_riesgo === 'alto') {
            return $reglas['alta'];
        } elseif ($prioridad === 'media' || $nivel_riesgo === 'medio') {
            return $reglas['media'];
        }
    }
    return $reglas['default'] ?? [360, '15 días hábiles', 'Ley 1755/2015'];
}

// ── HELPERS ──────────────────────────────────────────────────────────
function sbPost($SB_URL, $SB_KEY, $endpoint, $data, $prefer = 'return=representation') {
    $ch = curl_init("$SB_URL/rest/v1/$endpoint");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["apikey: $SB_KEY","Authorization: Bearer $SB_KEY",
                                   'Content-Type: application/json',"Prefer: $prefer"],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code'=>$code,'body'=>$resp];
}

function getToken($tenant, $client_id, $secret) {
    $ch = curl_init("https://login.microsoftonline.com/$tenant/oauth2/v2.0/token");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['grant_type'=>'client_credentials',
            'client_id'=>$client_id,'client_secret'=>$secret,
            'scope'=>'https://graph.microsoft.com/.default']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode($r,true)['access_token'] ?? null;
}

function sendMail($token, $userId, $payload) {
    $ch = curl_init("https://graph.microsoft.com/v1.0/users/$userId/sendMail");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['message'=>$payload,'saveToSentItems'=>true]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token",'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $r = curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $c === 202;
}

function getAttachments($audio_url, $canvas_url, $ticket_id) {
    $attachments = [];
    if ($audio_url) {
        $data = @file_get_contents($audio_url);
        if ($data && strlen($data) < 5*1024*1024) {
            $attachments[] = ['@odata.type'=>'#microsoft.graph.fileAttachment',
                'name'=>"audio_{$ticket_id}.webm",'contentType'=>'audio/webm',
                'contentBytes'=>base64_encode($data)];
        }
    }
    if ($canvas_url) {
        if (strpos($canvas_url,'data:image')===0) {
            preg_match('/data:image\/(\w+);base64,(.+)/',$canvas_url,$m);
            if ($m) $attachments[] = ['@odata.type'=>'#microsoft.graph.fileAttachment',
                'name'=>"canvas_{$ticket_id}.{$m[1]}",'contentType'=>"image/{$m[1]}",
                'contentBytes'=>$m[2]];
        } else {
            $data = @file_get_contents($canvas_url);
            if ($data && strlen($data)<5*1024*1024)
                $attachments[] = ['@odata.type'=>'#microsoft.graph.fileAttachment',
                    'name'=>"canvas_{$ticket_id}.png",'contentType'=>'image/png',
                    'contentBytes'=>base64_encode($data)];
        }
    }
    return $attachments;
}

// ── LEER INPUT ───────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); echo json_encode(['error'=>'Invalid JSON']); exit; }

$now         = new DateTime('now', new DateTimeZone('America/Bogota'));
$fecha_iso   = $now->format('c');
$fecha_fmt   = $now->format('d/m/Y H:i');
$hora_fmt    = $now->format('H:i');
$fecha_ticket= $now->format('Ymd');
$rand        = str_pad(rand(1000,9999),4,'0',STR_PAD_LEFT);
$ticket_id   = "TD-{$fecha_ticket}-{$rand}";

$nombre       = trim($body['nombre']       ?? '');
$documento    = trim($body['documento']    ?? '');
$correo       = trim($body['correo']       ?? '');
$telefono     = trim($body['telefono']     ?? '');
$descripcion  = trim($body['descripcion']  ?? '');
$tipo_pqr     = strtolower(trim($body['tipo_pqr'] ?? $body['tipo'] ?? 'peticion'));
$transcripcion = trim($body['transcripcion'] ?? '');
$audio_url    = trim($body['audio_url']    ?? '');
$canvas_url   = trim($body['canvas_url']   ?? '');
$sede_nombre  = trim($body['sede_nombre']  ?? '');
$sede_ciudad  = trim($body['sede_ciudad']  ?? '');
$canal_pref   = trim($body['contacto_preferido'] ?? $body['canal'] ?? 'formulario_web');
$sin_correo   = (bool)($body['sin_correo'] ?? empty($correo));

$canal = 'escrito';
if ($audio_url || ($transcripcion && !$canvas_url)) $canal = 'audio';
elseif ($canvas_url) $canal = 'canvas';

$texto_pqr = $transcripcion ?: $descripcion;

// ── PASO 1: CLASIFICACIÓN IA ─────────────────────────────────────────
$sentimiento  = 'neutro';
$prioridad    = 'media';
$categoria_ia = ucfirst($tipo_pqr);
$nivel_riesgo = 'bajo';
$resumen_corto = mb_substr($texto_pqr, 0, 120);
$riesgo_vital = false;

if ($OPENAI_KEY && $texto_pqr) {
    $prompt = "Clasifica esta PQR de una droguería colombiana. Responde SOLO JSON sin markdown.

TIPO: $tipo_pqr | SEDE: $sede_nombre | CIUDAD: $sede_ciudad
TEXTO: $texto_pqr

JSON requerido (sin texto adicional):
{
  \"sentimiento\": \"positivo|neutro|negativo|urgente\",
  \"prioridad\": \"baja|media|alta|critica\",
  \"categoria\": \"categoría breve en español\",
  \"nivel_riesgo\": \"bajo|medio|alto|critico\",
  \"riesgo_vital\": true/false,
  \"resumen\": \"máximo 100 caracteres\",
  \"ley\": \"ley colombiana aplicable\",
  \"tipo_confirmado\": \"peticion|queja|reclamo|sugerencia|solicitud|felicitacion|denuncia\"
}";

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode(['model'=>'gpt-4o-mini','max_tokens'=>250,'temperature'=>0.1,
            'messages'=>[['role'=>'system','content'=>'Clasificador de PQRs farmacéuticas colombianas. Solo JSON.'],
                         ['role'=>'user','content'=>$prompt]]]),
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>["Authorization: Bearer $OPENAI_KEY",'Content-Type: application/json'],
        CURLOPT_TIMEOUT=>20]);
    $ai_resp = curl_exec($ch); curl_close($ch);
    $ai_data = json_decode($ai_resp,true);
    $ai_text = preg_replace('/```json|```/','',
        $ai_data['choices'][0]['message']['content'] ?? '');
    $ia = json_decode(trim($ai_text),true);
    if ($ia) {
        $sentimiento   = $ia['sentimiento']      ?? $sentimiento;
        $prioridad     = $ia['prioridad']        ?? $prioridad;
        $categoria_ia  = $ia['categoria']        ?? $categoria_ia;
        $nivel_riesgo  = $ia['nivel_riesgo']     ?? $nivel_riesgo;
        $riesgo_vital  = !empty($ia['riesgo_vital']);
        $resumen_corto = mb_substr($ia['resumen'] ?? $resumen_corto, 0,150);
        $ley_aplicable = $ia['ley']              ?? 'Ley 1755/2015';
        if (!empty($ia['tipo_confirmado'])) $tipo_pqr = $ia['tipo_confirmado'];
    }
}

// ── SLA REAL ─────────────────────────────────────────────────────────
[$horas_sla, $sla_desc, $ley_aplicable_sla] = getSLA($tipo_pqr, $prioridad, $nivel_riesgo);
if (!isset($ley_aplicable)) $ley_aplicable = $ley_aplicable_sla;

$limite_sla = clone $now;
$limite_sla->modify("+{$horas_sla} hours");
$fecha_limite_sla = $limite_sla->format('c');
$fecha_limite_fmt = $limite_sla->format('d/m/Y H:i');
$dias_habiles = round($horas_sla / 24);

// Emojis
$emoji_sent  = ['positivo'=>'😊','neutro'=>'😐','negativo'=>'😤','urgente'=>'🚨'][$sentimiento] ?? '📋';
$emoji_prio  = ['baja'=>'🟢','media'=>'🟡','alta'=>'🟠','critica'=>'🔴'][$prioridad] ?? '🟡';
$emoji_canal = ['audio'=>'🎤','canvas'=>'✏️','escrito'=>'📝'][$canal] ?? '📝';
$canal_label = strtoupper($canal);
$tipo_label  = strtoupper($tipo_pqr);

// Asunto completo
$subject = "[{$ticket_id}] {$emoji_canal} {$canal_label} | {$tipo_label} | {$emoji_sent} ".strtoupper($sentimiento)." | {$emoji_prio} ".strtoupper($prioridad);

// ── PASO 2: INSERTAR EN SUPABASE ─────────────────────────────────────
$payload_correo = [
    'ticket_id'         => $ticket_id,
    'from_email'        => $correo ?: ($telefono.'@whatsapp'),
    'from_name'         => $nombre,
    'nombre'            => $nombre,
    'correo'            => $correo ?: null,
    'telefono_contacto' => $telefono,
    'subject'           => $subject,
    'descripcion'       => $descripcion,
    'body_preview'      => mb_substr($texto_pqr,0,200),
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
    'canal_contacto'    => $canal_pref,
    'origen'            => 'formulario_web',
    'estado'            => 'pendiente',
    'prioridad'         => $prioridad,
    'es_urgente'        => $riesgo_vital || $prioridad === 'critica',
    'horas_sla'         => $horas_sla,
    'fecha_limite_sla'  => $fecha_limite_sla,
    'has_attachments'   => !empty($audio_url) || !empty($canvas_url),
    'is_read'           => false,
    'acuse_enviado'     => null,
    'datos_legales'     => ['documento'=>$documento,'sede'=>$sede_nombre,'sede_ciudad'=>$sede_ciudad,
                            'riesgo_vital'=>$riesgo_vital,'sla_desc'=>$sla_desc],
    'received_at'       => $fecha_iso,
    'created_at'        => $fecha_iso,
    'updated_at'        => $fecha_iso,
];

$sb_result  = sbPost($SB_URL, $SB_KEY, 'correos', $payload_correo);
$correo_id  = null;
if ($sb_result['code'] < 400) {
    $ins = json_decode($sb_result['body'],true);
    $correo_id = $ins[0]['id'] ?? null;
} else {
    http_response_code(502);
    echo json_encode(['error'=>'supabase_error','code'=>$sb_result['code'],'detalle'=>$sb_result['body']]);
    exit;
}

// ── PASO 3: OBTENER TOKEN GRAPH ──────────────────────────────────────
$token = getToken($TENANT_ID, $CLIENT_ID, $CLIENT_SECRET);
$attachments = $token ? getAttachments($audio_url, $canvas_url, $ticket_id) : [];

// ── PASO 4: CORREO A pqrsfd (NOTIFICACIÓN INTERNA) ──────────────────
$correo_interno_ok = false;
if ($token) {
    $badge_s = "<span style='background:".['positivo'=>'#dcfce7','neutro'=>'#f3f4f6','negativo'=>'#fee2e2','urgente'=>'#fef3c7'][$sentimiento]."20;color:".['positivo'=>'#166534','neutro'=>'#374151','negativo'=>'#991b1b','urgente'=>'#92400e'][$sentimiento].";padding:3px 10px;border-radius:12px;font-weight:700;font-size:11px'>{$emoji_sent} ".strtoupper($sentimiento)."</span>";
    $badge_p = "<span style='background:".['baja'=>'#dcfce7','media'=>'#fef9c3','alta'=>'#fed7aa','critica'=>'#fee2e2'][$prioridad]."20;color:".['baja'=>'#166534','media'=>'#854d0e','alta'=>'#9a3412','critica'=>'#991b1b'][$prioridad].";padding:3px 10px;border-radius:12px;font-weight:700;font-size:11px'>{$emoji_prio} ".strtoupper($prioridad)."</span>";
    $badge_c = "<span style='background:#dbeafe20;color:#1e40af;padding:3px 10px;border-radius:12px;font-weight:700;font-size:11px'>{$emoji_canal} {$canal_label}</span>";
    $riesgo_alert = $riesgo_vital ? "<div style='background:#fee2e2;border:2px solid #dc2626;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-weight:700;color:#991b1b;font-size:14px'>🚨 RIESGO VITAL DETECTADO — Respuesta requerida en 24 horas</div>" : "";

    $html_interno = "
<div style='font-family:Arial,sans-serif;max-width:680px;margin:0 auto;color:#1f2937'>
  <div style='background:#1e40af;padding:20px 28px;border-radius:8px 8px 0 0'>
    <h2 style='color:#fff;margin:0;font-size:18px'>🏥 Tododrogas CIA SAS — Nueva PQR Recibida</h2>
    <p style='color:#bfdbfe;margin:6px 0 0;font-size:13px'>Plataforma Inteligente Nova TD · {$fecha_fmt}</p>
  </div>
  <div style='background:#f8fafc;padding:24px 28px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px'>
    {$riesgo_alert}
    <table style='width:100%;border-collapse:collapse;margin-bottom:20px'>
      <tr><td style='padding:8px 12px;background:#eff6ff;font-weight:700;width:160px;border:1px solid #dbeafe'>Radicado</td>
          <td style='padding:8px 12px;border:1px solid #dbeafe;font-weight:700;color:#1e40af;font-size:15px'>{$ticket_id}</td></tr>
      <tr><td style='padding:8px 12px;background:#f8fafc;font-weight:700;border:1px solid #e2e8f0'>Fecha y hora</td>
          <td style='padding:8px 12px;border:1px solid #e2e8f0'>{$fecha_fmt} (hora Colombia)</td></tr>
      <tr><td style='padding:8px 12px;background:#f8fafc;font-weight:700;border:1px solid #e2e8f0'>Canal</td>
          <td style='padding:8px 12px;border:1px solid #e2e8f0'>{$badge_c}</td></tr>
      <tr><td style='padding:8px 12px;background:#f8fafc;font-weight:700;border:1px solid #e2e8f0'>Tipo</td>
          <td style='padding:8px 12px;border:1px solid #e2e8f0'><strong>{$tipo_label}</strong> — {$categoria_ia}</td></tr>
      <tr><td style='padding:8px 12px;background:#f8fafc;font-weight:700;border:1px solid #e2e8f0'>Sentimiento</td>
          <td style='padding:8px 12px;border:1px solid #e2e8f0'>{$badge_s}</td></tr>
      <tr><td style='padding:8px 12px;background:#f8fafc;font-weight:700;border:1px solid #e2e8f0'>Prioridad</td>
          <td style='padding:8px 12px;border:1px solid #e2e8f0'>{$badge_p}</td></tr>
      <tr><td style='padding:8px 12px;background:#f8fafc;font-weight:700;border:1px solid #e2e8f0'>SLA</td>
          <td style='padding:8px 12px;border:1px solid #e2e8f0'>{$sla_desc} · Límite: <strong>{$fecha_limite_fmt}</strong></td></tr>
      <tr><td style='padding:8px 12px;background:#f8fafc;font-weight:700;border:1px solid #e2e8f0'>Ley aplicable</td>
          <td style='padding:8px 12px;border:1px solid #e2e8f0'>{$ley_aplicable}</td></tr>
      ".($sede_nombre ? "<tr><td style='padding:8px 12px;background:#f8fafc;font-weight:700;border:1px solid #e2e8f0'>Sede</td>
          <td style='padding:8px 12px;border:1px solid #e2e8f0'><strong>{$sede_nombre}</strong> — {$sede_ciudad}</td></tr>" : "")."
    </table>
    <div style='background:#fff;border:1px solid #e2e8f0;border-left:4px solid #1e40af;border-radius:4px;padding:14px 18px;margin-bottom:16px'>
      <p style='margin:0 0 6px;font-weight:700;color:#1e40af'>👤 Datos del paciente/ciudadano</p>
      <p style='margin:2px 0;font-size:13px'><strong>Nombre:</strong> {$nombre}</p>
      ".($documento ? "<p style='margin:2px 0;font-size:13px'><strong>Documento:</strong> {$documento}</p>" : "")."
      ".($correo ? "<p style='margin:2px 0;font-size:13px'><strong>Correo:</strong> {$correo}</p>" : "")."
      ".($telefono ? "<p style='margin:2px 0;font-size:13px'><strong>Celular:</strong> {$telefono}</p>" : "")."
      <p style='margin:2px 0;font-size:13px'><strong>Canal preferido:</strong> {$canal_pref}</p>
    </div>
    <div style='background:#fff;border:1px solid #e2e8f0;border-left:4px solid #7c3aed;border-radius:4px;padding:14px 18px;margin-bottom:16px'>
      <p style='margin:0 0 8px;font-weight:700;color:#7c3aed'>{$emoji_canal} Mensaje — {$canal_label}</p>
      <p style='margin:0;font-size:14px;line-height:1.6'>".nl2br(htmlspecialchars($texto_pqr))."</p>
      ".($resumen_corto ? "<p style='margin:10px 0 0;font-size:12px;color:#6b7280;font-style:italic'>📌 Resumen IA: {$resumen_corto}</p>" : "")."
    </div>
    ".(!empty($attachments) ? "<div style='background:#fefce8;border:1px solid #fde68a;border-radius:4px;padding:10px 14px;margin-bottom:16px;font-size:13px'>📎 <strong>Adjunto incluido:</strong> ".($audio_url ? "Archivo de audio 🎤" : "Imagen del lápiz inteligente ✏️")."</div>" : "")."
    <div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:4px;padding:10px 14px;font-size:12px;color:#166534'>
      ✅ Caso guardado en el sistema. Si hay problemas de conectividad, el registro persiste y estará disponible al recuperarse la conexión.
    </div>
    <p style='font-size:11px;color:#9ca3af;margin:12px 0 0;border-top:1px solid #e5e7eb;padding-top:10px'>
      Nova TD v4 · Tododrogas CIA SAS · {$fecha_fmt} · Radicado: <strong>{$ticket_id}</strong>
    </p>
  </div>
</div>";

    $correo_interno_ok = sendMail($token, $GRAPH_USER_ID, [
        'subject'      => $subject,
        'importance'   => in_array($prioridad,['alta','critica']) ? 'high' : 'normal',
        'body'         => ['contentType'=>'HTML','content'=>$html_interno],
        'toRecipients' => [['emailAddress'=>['address'=>$BUZON_PQRS]]],
        'attachments'  => $attachments,
    ]);
}

// ── PASO 5: CONFIRMACIÓN AL CIUDADANO ────────────────────────────────
$acuse_ok = false;
if ($token && $correo && !$sin_correo) {
    $html_ciudadano = "
<div style='font-family:Arial,sans-serif;max-width:620px;margin:0 auto;color:#1f2937'>
  <div style='background:linear-gradient(135deg,#1e40af 0%,#3b82f6 100%);padding:28px 32px;border-radius:12px 12px 0 0;text-align:center'>
    <div style='font-size:32px;margin-bottom:8px'>🏥</div>
    <h1 style='color:#fff;margin:0;font-size:22px;font-weight:700'>Tododrogas CIA SAS</h1>
    <p style='color:#bfdbfe;margin:6px 0 0;font-size:14px'>Servicio Farmacéutico de Excelencia</p>
  </div>
  <div style='background:#ffffff;padding:28px 32px;border:1px solid #e2e8f0;border-top:none'>
    <p style='font-size:15px;margin:0 0 12px'>Estimado/a <strong>{$nombre}</strong>,</p>
    <p style='font-size:14px;line-height:1.7;margin:0 0 20px;color:#374151'>
      Reciba un cordial saludo de parte del equipo de <strong>Tododrogas CIA SAS</strong>. 
      Nos complace informarle que su solicitud ha sido recibida exitosamente a través de nuestra 
      <em>Plataforma Inteligente Nova TD</em> y ha sido registrada en nuestro sistema de atención.
    </p>
    <div style='background:#eff6ff;border:2px solid #bfdbfe;border-radius:12px;padding:20px 24px;margin-bottom:24px;text-align:center'>
      <p style='margin:0 0 4px;font-size:13px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:1px'>Número de radicado</p>
      <p style='margin:0;font-size:28px;font-weight:800;color:#1e40af;letter-spacing:2px'>{$ticket_id}</p>
      <p style='margin:6px 0 0;font-size:12px;color:#6b7280'>Guarde este número para hacer seguimiento a su solicitud</p>
    </div>
    <table style='width:100%;border-collapse:collapse;margin-bottom:24px;font-size:13px'>
      <tr><td style='padding:8px 14px;background:#f8fafc;font-weight:700;width:140px;border:1px solid #e5e7eb;border-radius:4px 0 0 0'>Fecha de radicado</td>
          <td style='padding:8px 14px;border:1px solid #e5e7eb'>{$fecha_fmt} (hora Colombia)</td></tr>
      <tr><td style='padding:8px 14px;background:#f8fafc;font-weight:700;border:1px solid #e5e7eb'>Tipo de solicitud</td>
          <td style='padding:8px 14px;border:1px solid #e5e7eb'>{$tipo_label} — {$categoria_ia}</td></tr>
      ".($sede_nombre ? "<tr><td style='padding:8px 14px;background:#f8fafc;font-weight:700;border:1px solid #e5e7eb'>Sede relacionada</td>
          <td style='padding:8px 14px;border:1px solid #e5e7eb'>{$sede_nombre} · {$sede_ciudad}</td></tr>" : "")."
      <tr><td style='padding:8px 14px;background:#f8fafc;font-weight:700;border:1px solid #e5e7eb'>Tiempo de respuesta</td>
          <td style='padding:8px 14px;border:1px solid #e5e7eb'>{$sla_desc} · Fecha límite: <strong>{$fecha_limite_fmt}</strong></td></tr>
      <tr><td style='padding:8px 14px;background:#f8fafc;font-weight:700;border:1px solid #e5e7eb'>Marco normativo</td>
          <td style='padding:8px 14px;border:1px solid #e5e7eb'>{$ley_aplicable}</td></tr>
    </table>
    <div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:16px 20px;margin-bottom:24px'>
      <p style='margin:0 0 6px;font-weight:700;color:#166534;font-size:14px'>✅ ¿Qué sucede ahora?</p>
      <ul style='margin:0;padding-left:18px;font-size:13px;color:#374151;line-height:1.8'>
        <li>Su caso ha sido asignado a uno de nuestros agentes especializados.</li>
        <li>Recibirá respuesta a este correo electrónico dentro del plazo indicado.</li>
        <li>Puede hacer seguimiento mencionando su número de radicado: <strong>{$ticket_id}</strong>.</li>
      </ul>
    </div>
    <p style='font-size:14px;line-height:1.7;color:#374151;margin:0 0 20px'>
      Si tiene alguna consulta adicional o requiere información urgente, no dude en contactarnos 
      respondiendo este correo con su número de radicado.
    </p>
    <p style='font-size:14px;margin:0'>Un cordial saludo,</p>
    <div style='margin-top:16px;padding-top:16px;border-top:1px solid #e5e7eb'>
      <p style='margin:0;font-weight:700;font-size:15px;color:#1e40af'>Equipo PQRSFD</p>
      <p style='margin:2px 0;font-size:13px;color:#6b7280'>Tododrogas CIA SAS — Servicio Farmacéutico</p>
      <p style='margin:2px 0;font-size:13px;color:#6b7280'>📧 pqrsfd@tododrogas.com.co</p>
      <p style='margin:8px 0 0;font-size:11px;color:#9ca3af'>
        Este correo es generado automáticamente por la Plataforma Inteligente Nova TD.<br>
        © 2026 Tododrogas CIA SAS — Todos los derechos reservados.
      </p>
    </div>
  </div>
</div>";

    $acuse_ok = sendMail($token, $GRAPH_USER_ID, [
        'subject'      => "✅ Su solicitud fue radicada — {$ticket_id} | Tododrogas CIA SAS",
        'importance'   => 'normal',
        'body'         => ['contentType'=>'HTML','content'=>$html_ciudadano],
        'toRecipients' => [['emailAddress'=>['address'=>$correo,'name'=>$nombre]]],
        'attachments'  => [],
    ]);
}

// ── PASO 6: HISTORIAL ────────────────────────────────────────────────
if ($correo_id) {
    sbPost($SB_URL,$SB_KEY,'historial_eventos',[
        'correo_id'   => $correo_id,
        'evento'      => 'pqr_recibida',
        'descripcion' => "Radicado {$ticket_id} vía {$canal}. Clasificación: {$sentimiento}/{$prioridad}. ".
                         "Notif pqrsfd: ".($correo_interno_ok?'✅':'⏳')." | Acuse ciudadano: ".($acuse_ok?'✅':'—'),
        'from_email'  => $correo ?: $telefono,
        'subject'     => $subject,
        'datos_extra' => json_encode([
            'ticket_id'=>$ticket_id,'canal'=>$canal,'sentimiento'=>$sentimiento,
            'prioridad'=>$prioridad,'categoria_ia'=>$categoria_ia,'riesgo_vital'=>$riesgo_vital,
            'horas_sla'=>$horas_sla,'sla_desc'=>$sla_desc,'sede'=>$sede_nombre,
            'documento'=>$documento,'correo_interno'=>$correo_interno_ok,'acuse'=>$acuse_ok,
        ]),
        'created_at'  => $fecha_iso,
    ],'return=minimal');

    // Marcar acuse si se envió
    if ($acuse_ok) {
        $ch = curl_init("$SB_URL/rest/v1/correos?id=eq.$correo_id");
        curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>'PATCH',
            CURLOPT_POSTFIELDS=>json_encode(['acuse_enviado'=>$fecha_iso,'updated_at'=>$fecha_iso]),
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>["apikey: $SB_KEY","Authorization: Bearer $SB_KEY",
                                 'Content-Type: application/json','Prefer: return=minimal'],
            CURLOPT_TIMEOUT=>10]);
        curl_exec($ch); curl_close($ch);
    }
}

// ── RESPUESTA ────────────────────────────────────────────────────────
http_response_code(200);
echo json_encode([
    'ok'             => true,
    'radicado'       => $ticket_id,
    'ticket_id'      => $ticket_id,
    'canal'          => $canal,
    'sentimiento'    => $sentimiento,
    'prioridad'      => $prioridad,
    'categoria'      => $categoria_ia,
    'sla_horas'      => $horas_sla,
    'sla_desc'       => $sla_desc,
    'fecha_limite'   => $fecha_limite_fmt,
    'correo_enviado' => $correo_interno_ok,
    'acuse_enviado'  => $acuse_ok,
    'mensaje'        => "Tu solicitud fue recibida exitosamente. Radicado: {$ticket_id}. Tiempo de respuesta: {$sla_desc}.",
]);
