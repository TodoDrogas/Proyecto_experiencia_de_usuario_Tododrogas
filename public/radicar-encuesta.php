<?php
/**
 * radicar-encuesta.php — Encuesta de Satisfacción · Tododrogas CIA SAS
 * ─────────────────────────────────────────────────────────────────────
 * 1. Recibe datos de la encuesta desde pqr_encuesta.html
 * 2. Inserta en Supabase tabla encuestas_satisfaccion
 * 3. Envía correo de confirmación al usuario vía Graph API
 * 4. Registra en historial_eventos
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── CREDENCIALES (inyectadas por deploy.yml) ─────────────────────────
$SB_URL        = '__SB_URL__';
$SB_KEY        = '__SB_KEY__';
$TENANT_ID     = '__AZURE_TENANT_ID__';
$CLIENT_ID     = '__AZURE_CLIENT_ID__';
$CLIENT_SECRET = '__AZURE_CLIENT_SECRET__';
$GRAPH_USER_ID = '__GRAPH_USER_ID__';
$BUZÓN_PQRS    = 'pqrsfd@tododrogas.com.co';
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

$now    = date('c');
$nombre = trim($body['nombre']       ?? '');
$correo = trim($body['correo']       ?? '');
$telefono = trim($body['telefono']   ?? '');
$documento = trim($body['documento'] ?? '');

// Ratings individuales
$instalaciones = intval($body['instalaciones'] ?? 0);
$atencion      = intval($body['atencion']      ?? 0);
$tiempos       = intval($body['tiempos']       ?? 0);
$medicamentos  = intval($body['medicamentos']  ?? 0);
$recomendacion = intval($body['recomendacion'] ?? 0);
$promedio      = floatval($body['promedio']    ?? 0);

$comentario    = trim($body['comentario']      ?? '');
$sede_id       = trim($body['sede_id']         ?? '');
$sede_nombre   = trim($body['sede_nombre']     ?? '');
$sede_ciudad   = trim($body['sede_ciudad']     ?? '');
$sede_direccion = trim($body['sede_direccion'] ?? '');
$canal         = trim($body['canal']           ?? 'web');
$ip_origen     = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ($body['ip_origen'] ?? 'web');

// Calificacion final = promedio redondeado a entero para la tabla
$calificacion  = (int) round($promedio);

// ── PASO 1: INSERTAR EN SUPABASE ─────────────────────────────────────
$payload = [
    'calificacion'    => $calificacion,
    'comentario'      => $comentario ?: null,
    'sede_id'         => $sede_id    ?: null,
    'canal'           => $canal,
    'ip_origen'       => $ip_origen,
    'ticket_id'       => null,
    'correo_id'       => null,
    'created_at'      => $now,
];

$sb_result  = sbPost($SB_URL, $SB_KEY, 'encuestas_satisfaccion', $payload);
$encuesta_id = null;
$saved       = ($sb_result['code'] >= 200 && $sb_result['code'] < 300);

if ($saved) {
    $inserted    = json_decode($sb_result['body'], true);
    $encuesta_id = $inserted[0]['id'] ?? null;
} else {
    error_log('[radicar-encuesta] Supabase error: ' . $sb_result['code'] . ' — ' . $sb_result['body']);
}

// ── PASO 1B: INSERTAR EN TABLA CORREOS (igual que radicación) ─────────
// Así las encuestas aparecen en el buzón del admin automáticamente
$fecha_enc   = date('Ymd');
$rand_enc    = str_pad(rand(1000,9999),4,'0',STR_PAD_LEFT);
$ticket_enc  = "ENC-{$fecha_enc}-{$rand_enc}";

$emoji_cal   = [1=>'😞',2=>'😐',3=>'🙂',4=>'😊',5=>'🤩'][$calificacion] ?? '⭐';
$emoji_prio  = $calificacion >= 4 ? '🟢' : ($calificacion >= 3 ? '🟡' : '🔴');
$sent_enc    = $calificacion >= 4 ? 'positivo' : ($calificacion >= 3 ? 'neutro' : 'negativo');
$prio_enc    = $calificacion >= 4 ? 'baja'     : ($calificacion >= 3 ? 'media'  : 'alta');

$subject_enc = "[{$ticket_enc}] ⭐ ENCUESTA | {$emoji_cal} {$calificacion}/5 | {$emoji_prio} " . mb_strtoupper($prio_enc,'UTF-8') . " | 📍 {$sede_nombre}";

$resumen_enc = "Encuesta de satisfacción. Promedio: {$promedio}/5. " .
               "Instalaciones:{$instalaciones} Atención:{$atencion} " .
               "Tiempos:{$tiempos} Medicamentos:{$medicamentos} Recomienda:{$recomendacion}." .
               ($comentario ? " Comentario: {$comentario}" : '');

$horas_enc        = 360; // SLA encuestas = 15 días
$fecha_limite_enc = date('c', strtotime("+{$horas_enc} hours"));

$payload_correo_enc = [
    'ticket_id'         => $ticket_enc,
    'from_email'        => $correo ?: ($telefono ? $telefono.'@encuesta' : 'anonimo@encuesta'),
    'from_name'         => $nombre ?: 'Anónimo',
    'nombre'            => $nombre ?: 'Anónimo',
    'correo'            => $correo ?: null,
    'telefono_contacto' => $telefono ?: null,
    'subject'           => $subject_enc,
    'descripcion'       => $resumen_enc,
    'body_preview'      => mb_substr($resumen_enc, 0, 200),
    'body_content'      => $resumen_enc . ($comentario ? "

Comentario: {$comentario}" : ''),
    'body_type'         => 'text',
    'tipo_pqr'          => 'encuesta',
    'categoria_ia'      => "Encuesta · {$sede_nombre}",
    'sentimiento'       => $sent_enc,
    'nivel_riesgo'      => $calificacion <= 2 ? 'medio' : 'bajo',
    'resumen_corto'     => "Encuesta {$calificacion}/5 — {$sede_nombre}" . ($sede_ciudad ? ", {$sede_ciudad}" : ''),
    'ley_aplicable'     => 'N/A',
    'canal_contacto'    => $canal,
    'origen'            => 'formulario_encuesta',
    'estado'            => 'solucionado', // encuestas no requieren gestión
    'prioridad'         => $prio_enc,
    'es_urgente'        => false,
    'horas_sla'         => $horas_enc,
    'fecha_limite_sla'  => $fecha_limite_enc,
    'has_attachments'   => false,
    'is_read'           => false,
    'datos_legales'     => json_encode([
        'encuesta_id'   => $encuesta_id,
        'instalaciones' => $instalaciones,
        'atencion'      => $atencion,
        'tiempos'       => $tiempos,
        'medicamentos'  => $medicamentos,
        'recomendacion' => $recomendacion,
        'promedio'      => $promedio,
        'sede_nombre'   => $sede_nombre,
        'sede_ciudad'   => $sede_ciudad,
        'sede_direccion'=> $sede_direccion,
    ]),
    'received_at'       => $now,
    'created_at'        => $now,
    'updated_at'        => $now,
];

$enc_correo_result = sbPost($SB_URL, $SB_KEY, 'correos', $payload_correo_enc);
$enc_correo_id = null;
if ($enc_correo_result['code'] < 400) {
    $enc_ins = json_decode($enc_correo_result['body'], true);
    $enc_correo_id = $enc_ins[0]['id'] ?? null;
} else {
    error_log('[radicar-encuesta] correos insert error: ' . $enc_correo_result['code']);
}

// ── PASO 2: CORREO DE CONFIRMACIÓN AL USUARIO ────────────────────────
$correo_enviado = false;
if ($correo && filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    $token = getGraphToken($TENANT_ID, $CLIENT_ID, $CLIENT_SECRET);

    if ($token) {
        // Determinar emoji de calificación
        $emojis_rating = [1=>'😞', 2=>'😐', 3=>'🙂', 4=>'😊', 5=>'🤩'];
        $emoji_final   = $emojis_rating[$calificacion] ?? '⭐';
        $estrellas     = str_repeat('⭐', $calificacion) . str_repeat('☆', 5 - $calificacion);
        $color_cal     = $calificacion >= 4 ? '#10b981' : ($calificacion >= 3 ? '#f59e0b' : '#ef4444');
        $nombre_first  = explode(' ', $nombre)[0];

        $labels = ['Instalaciones', 'Atención al cliente', 'Tiempos de espera', 'Disponibilidad de medicamentos', 'Recomendaría el servicio'];
        $valores = [$instalaciones, $atencion, $tiempos, $medicamentos, $recomendacion];

        $filas_calificaciones = '';
        foreach ($labels as $i => $label) {
            $v = $valores[$i];
            $barras = '';
            for ($b = 1; $b <= 5; $b++) {
                $col = $b <= $v ? '#0c2d5e' : '#d8e4f0';
                $barras .= "<span style='display:inline-block;width:18px;height:3px;background:{$col};border-radius:2px;margin-right:2px'></span>";
            }
            if ($v >= 4) { $score_color = '#0f5c2e'; }
            elseif ($v >= 3) { $score_color = '#7a5200'; }
            else { $score_color = '#8a1a1a'; }
            $border_bottom = ($i < count($labels) - 1) ? "border-bottom:1px solid #e8eef6;" : "";
            $filas_calificaciones .= "
            <tr>
              <td style='padding:11px 0;font-size:12px;color:#2a3a4a;font-weight:400;width:40%;{$border_bottom}'>{$label}</td>
              <td style='padding:11px 14px 11px 0;width:40%;{$border_bottom}'>{$barras}</td>
              <td style='padding:11px 0;font-size:12px;font-weight:500;text-align:right;width:20%;color:{$score_color};{$border_bottom}'>{$v} / 5</td>
            </tr>";
        }

        $bloque_comentario = '';
        if ($comentario) {
            $bloque_comentario = "
            <div style='background:#f6f9fd;border:1px solid #d4dce8;border-top:2px solid #0c2d5e;padding:18px 20px;margin-bottom:24px'>
              <p style='margin:0 0 8px;font-size:9px;font-weight:500;color:#7a90a8;text-transform:uppercase;letter-spacing:2px'>Observación del usuario</p>
              <p style='margin:0;font-size:12px;color:#3a4a5a;line-height:1.7;font-style:italic'>" . htmlspecialchars($comentario) . "</p>
            </div>";
        }

        $cuerpo = "
<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#d8dfe9;font-family:Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#d8dfe9;padding:32px 16px'>
<tr><td align='center'>
<table width='580' cellpadding='0' cellspacing='0' style='max-width:580px;width:100%;background:#ffffff'>

  <!-- HEADER -->
  <tr><td style='background:#0c2d5e;padding:32px 44px;text-align:center'>
    <img src='{$LOGO_URL}' alt='Tododrogas' style='height:32px;max-width:180px;object-fit:contain;display:block;margin:0 auto 12px;filter:brightness(0) invert(1);opacity:.92'>
    <p style='color:#6a90b8;margin:0;font-size:10px;letter-spacing:2.5px;text-transform:uppercase;font-weight:400'>Evaluación de experiencia &middot; Servicio farmacéutico</p>
  </td></tr>

  <!-- CUERPO -->
  <tr><td style='background:#ffffff;padding:36px 44px'>

    <p style='margin:0 0 10px;font-size:14px;color:#1a2535;line-height:1.8;font-weight:300'>Hola, <strong style='font-weight:500;color:#0c2d5e'>{$nombre_first}</strong>,</p>
    <p style='margin:0 0 24px;font-size:14px;color:#1a2535;line-height:1.8;font-weight:300'>Hemos recibido su evaluación de experiencia. Su retroalimentación es parte fundamental de nuestro proceso de mejora continua &mdash; cada respuesta se revisa de manera directa por el equipo de calidad de servicio.</p>

    <p style='display:inline-block;background:#eef2f8;border:1px solid #a8bed8;border-radius:2px;padding:4px 10px;font-size:11px;letter-spacing:.8px;text-transform:uppercase;color:#2a4870;font-weight:500;margin:0 0 28px'>{$sede_nombre}</p>

    <!-- DIVIDER INDICADORES -->
    <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:12px'>
      <tr>
        <td style='font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:#7a90a8;font-weight:500;white-space:nowrap;padding-right:12px'>Resumen de indicadores</td>
        <td style='border-top:1px solid #d4dce8'></td>
      </tr>
    </table>

    <!-- TABLA INDICADORES -->
    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;margin-bottom:24px'>
      {$filas_calificaciones}
    </table>

    {$bloque_comentario}

    <!-- BLOQUE QUÉ SUCEDE -->
    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;border:1px solid #d4dce8;margin-bottom:28px'>
      <tr><td style='padding:22px 24px'>
        <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:16px'>
          <tr>
            <td style='width:20px;border-top:2px solid #0c2d5e;vertical-align:middle'></td>
            <td style='padding-left:10px;font-size:9px;letter-spacing:2px;text-transform:uppercase;color:#0c2d5e;font-weight:500'>Qué sucede con tu evaluación</td>
          </tr>
        </table>
        <table width='100%' cellpadding='0' cellspacing='0'>
          <tr><td style='width:24px;font-size:10px;font-weight:500;color:#0c2d5e;vertical-align:top;padding:0 0 10px'>01</td><td style='font-size:12px;color:#4a5a6a;line-height:1.6;font-weight:300;padding:0 0 10px'>Tu calificación es revisada por el equipo de calidad de la sede.</td></tr>
          <tr><td style='width:24px;font-size:10px;font-weight:500;color:#0c2d5e;vertical-align:top;padding:0 0 10px'>02</td><td style='font-size:12px;color:#4a5a6a;line-height:1.6;font-weight:300;padding:0 0 10px'>Los indicadores con oportunidad de mejora son escalados al área correspondiente.</td></tr>
          <tr><td style='width:24px;font-size:10px;font-weight:500;color:#0c2d5e;vertical-align:top'>03</td><td style='font-size:12px;color:#4a5a6a;line-height:1.6;font-weight:300'>Si tu caso requiere seguimiento, nos pondremos en contacto contigo.</td></tr>
        </table>
      </td></tr>
    </table>

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

        $mail_payload = [
            'subject'      => "Gracias por su opinión {$nombre} - Tododrogas CIA SAS",
            'importance'   => 'normal',
            'body'         => ['contentType' => 'HTML', 'content' => $cuerpo],
            'toRecipients' => [['emailAddress' => ['address' => $correo, 'name' => $nombre]]],
        ];

        $ch = curl_init("https://graph.microsoft.com/v1.0/users/{$GRAPH_USER_ID}/sendMail");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['message' => $mail_payload, 'saveToSentItems' => false]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $mail_resp = curl_exec($ch);
        $mail_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $correo_enviado = ($mail_code === 202);

        // ── NOTIFICACIÓN INTERNA A PQRSFD ──────────────────────────────────
        if ($token) {
            $estrellas_admin = str_repeat('★', $calificacion) . str_repeat('☆', 5 - $calificacion);
            $color_cal_admin = $calificacion >= 4 ? '#16a34a' : ($calificacion >= 3 ? '#d97706' : '#dc2626');
            $badge_cal       = $calificacion >= 4 ? '✅ Satisfecho' : ($calificacion >= 3 ? '⚠️ Neutro' : '🔴 Insatisfecho');

            $filas_admin = '';
            $labels_a = ['Instalaciones', 'Atención al cliente', 'Tiempos de espera', 'Disponibilidad medicamentos', 'Recomendaría'];
            $vals_a   = [$instalaciones, $atencion, $tiempos, $medicamentos, $recomendacion];
            foreach ($labels_a as $i => $lbl) {
                $v = $vals_a[$i];
                $bar = '';
                for ($b = 1; $b <= 5; $b++) {
                    $col = $b <= $v ? '#2563eb' : '#e2e8f0';
                    $bar .= "<span style='display:inline-block;width:16px;height:5px;background:{$col};border-radius:3px;margin-right:2px'></span>";
                }
                $filas_admin .= "<tr><td style='padding:6px 10px;font-size:12px;color:#374151;border-bottom:1px solid #f3f4f6'>{$lbl}</td>"
                              . "<td style='padding:6px 10px;font-size:12px;color:#374151;border-bottom:1px solid #f3f4f6'>{$bar} <span style='color:#6b7280;font-size:11px'>{$v}/5</span></td></tr>";
            }

            $bloque_comentario_admin = $comentario
                ? "<tr><td colspan='2' style='padding:8px 10px;font-size:12px;background:#fefce8;border-top:2px solid #facc15'><strong>💬 Comentario:</strong> " . htmlspecialchars($comentario, ENT_QUOTES) . "</td></tr>"
                : '';

            $cuerpo_admin = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body style='margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f1f5f9;padding:24px 0'>
<tr><td align='center'>
<table width='680' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);max-width:680px;width:100%'>

  <tr><td style='background:#1e3a5f;padding:20px 28px'>
    <table width='100%'><tr>
      <td><div style='background:#fff;border-radius:8px;padding:6px 12px;display:inline-block'><img src='{$LOGO_URL}' alt='Tododrogas' style='height:28px;object-fit:contain'></div></td>
      <td align='right'><span style='color:#93c5fd;font-size:11px;font-weight:700'>NUEVA ENCUESTA RECIBIDA</span></td>
    </tr></table>
  </td></tr>

  <tr><td style='padding:20px 28px 12px'>
    <table width='100%'><tr>
      <td><p style='margin:0 0 4px;font-size:16px;font-weight:700;color:#111827'>Calificación: <span style='color:{$color_cal_admin}'>{$estrellas_admin} ({$calificacion}/5)</span></p>
          <p style='margin:0;font-size:12px;color:#6b7280'>{$badge_cal}</p></td>
      <td align='right'><p style='margin:0;font-size:11px;color:#6b7280'>Sede: <strong style='color:#111827'>{$sede_nombre}</strong>" . (($sede_ciudad && stripos(iconv('UTF-8','ASCII//TRANSLIT',$sede_nombre), iconv('UTF-8','ASCII//TRANSLIT',$sede_ciudad)) === false) ? "<br>{$sede_ciudad}" : "") . "</p></td>
    </tr></table>
  </td></tr>

  <tr><td style='padding:4px 28px 16px'>
    <table width='100%' style='border-collapse:collapse;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden'>
      <tr style='background:#f9fafb'><th style='padding:8px 10px;font-size:11px;font-weight:700;color:#6b7280;text-align:left;border-bottom:1px solid #e5e7eb'>DATOS DEL ENCUESTADO</th><th style='padding:8px 10px;font-size:11px;font-weight:700;color:#6b7280;text-align:left;border-bottom:1px solid #e5e7eb'>DETALLES</th></tr>
      <tr><td style='padding:7px 10px;font-size:12px;color:#6b7280'>Nombre</td><td style='padding:7px 10px;font-size:12px;color:#111827;font-weight:600'>" . htmlspecialchars($nombre, ENT_QUOTES) . "</td></tr>
      " . ($documento ? "<tr><td style='padding:7px 10px;font-size:12px;color:#6b7280'>Documento</td><td style='padding:7px 10px;font-size:12px;color:#111827;font-weight:600'>" . htmlspecialchars($documento, ENT_QUOTES) . "</td></tr>" : "") . "
      " . ($correo ? "<tr><td style='padding:7px 10px;font-size:12px;color:#6b7280'>Correo</td><td style='padding:7px 10px;font-size:12px;color:#2563eb'>" . htmlspecialchars($correo, ENT_QUOTES) . "</td></tr>" : "") . "
      " . ($telefono ? "<tr><td style='padding:7px 10px;font-size:12px;color:#6b7280'>Teléfono</td><td style='padding:7px 10px;font-size:12px;color:#111827'>" . htmlspecialchars($telefono, ENT_QUOTES) . "</td></tr>" : "") . "
      <tr><td style='padding:7px 10px;font-size:12px;color:#6b7280'>Canal</td><td style='padding:7px 10px;font-size:12px;color:#111827;text-transform:capitalize'>{$canal}</td></tr>
      <tr><td style='padding:7px 10px;font-size:12px;color:#6b7280'>Fecha</td><td style='padding:7px 10px;font-size:12px;color:#111827'>" . date('d/m/Y H:i', strtotime($now)) . "</td></tr>
    </table>
  </td></tr>

  <tr><td style='padding:0 28px 16px'>
    <p style='margin:0 0 8px;font-size:12px;font-weight:700;color:#374151'>Calificaciones por categoría:</p>
    <table width='100%' style='border-collapse:collapse;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden'>
      {$filas_admin}
      {$bloque_comentario_admin}
    </table>
  </td></tr>

  <tr><td style='background:#f8fafc;border-top:1px solid #e5e7eb;padding:14px 28px;text-align:center'>
    <p style='font-size:11px;color:#9ca3af;margin:0'>Notificación automática · Sistema PQRSFD · Tododrogas CIA SAS</p>
  </td></tr>

</table></td></tr></table>
</body></html>";

            $admin_payload = [
                'subject'      => "⭐ Nueva encuesta [{$calificacion}/5] · {$sede_nombre} · " . htmlspecialchars($nombre, ENT_QUOTES),
                'importance'   => $calificacion <= 2 ? 'high' : 'normal',
                'body'         => ['contentType' => 'HTML', 'content' => $cuerpo_admin],
                'toRecipients' => [['emailAddress' => ['address' => $BUZÓN_PQRS, 'name' => 'PQRSFD Tododrogas']]],
            ];
            $ch2 = curl_init("https://graph.microsoft.com/v1.0/users/{$GRAPH_USER_ID}/sendMail");
            curl_setopt_array($ch2, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['message' => $admin_payload, 'saveToSentItems' => true]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", 'Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 30,
            ]);
            curl_exec($ch2);
            curl_close($ch2);
        }
        // ── FIN NOTIFICACIÓN INTERNA (dentro de bloque correo usuario) ─────
    }
}

// ── PASO 2B: NOTIFICACIÓN INTERNA A PQRSFD (SIEMPRE, sin importar si el usuario tiene correo) ──
// Esto garantiza que las encuestas lleguen al admin aunque el usuario no haya dado su correo
$token_admin = $token ?? getGraphToken($TENANT_ID, $CLIENT_ID, $CLIENT_SECRET);
if ($token_admin && !isset($token)) {
    // token_admin recién obtenido (usuario no tenía correo, no se generó antes)
    $token = $token_admin;
}
if ($token_admin && !$correo_enviado) {
    // Solo enviar si NO se envió ya dentro del bloque de correo usuario
    // (cuando hay correo, ya se envió adentro)
    $estrellas_admin2 = str_repeat('★', $calificacion) . str_repeat('☆', 5 - $calificacion);
    $color_cal_admin2 = $calificacion >= 4 ? '#16a34a' : ($calificacion >= 3 ? '#d97706' : '#dc2626');
    $badge_cal2       = $calificacion >= 4 ? '✅ Satisfecho' : ($calificacion >= 3 ? '⚠️ Neutro' : '🔴 Insatisfecho');
    $LOGO_URL_A       = 'https://lyosqaqhiwhgvjigvqtc.supabase.co/storage/v1/object/public/logos-config/LOGO_Tododrogas_Color%201%20(3).png';

    $labels_a2 = ['Instalaciones', 'Atención al cliente', 'Tiempos de espera', 'Disponibilidad medicamentos', 'Recomendaría'];
    $vals_a2   = [$instalaciones, $atencion, $tiempos, $medicamentos, $recomendacion];
    $filas_a2  = '';
    foreach ($labels_a2 as $i => $lbl) {
        $v = $vals_a2[$i];
        $bar = '';
        for ($b = 1; $b <= 5; $b++) {
            $col = $b <= $v ? '#2563eb' : '#e2e8f0';
            $bar .= "<span style='display:inline-block;width:16px;height:5px;background:{$col};border-radius:3px;margin-right:2px'></span>";
        }
        $filas_a2 .= "<tr><td style='padding:6px 10px;font-size:12px;color:#374151;border-bottom:1px solid #f3f4f6'>{$lbl}</td>"
                   . "<td style='padding:6px 10px;font-size:12px;color:#374151;border-bottom:1px solid #f3f4f6'>{$bar} <span style='color:#6b7280;font-size:11px'>{$v}/5</span></td></tr>";
    }
    $bloque_com_a2 = $comentario
        ? "<tr><td colspan='2' style='padding:8px 10px;font-size:12px;background:#fefce8;border-top:2px solid #facc15'><strong>💬 Comentario:</strong> " . htmlspecialchars($comentario, ENT_QUOTES) . "</td></tr>"
        : '';

    $cuerpo_a2 = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body style='margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f1f5f9;padding:24px 0'>
<tr><td align='center'>
<table width='680' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);max-width:680px;width:100%'>
  <tr><td style='background:#1e3a5f;padding:20px 28px'>
    <table width='100%'><tr>
      <td><div style='background:#fff;border-radius:8px;padding:6px 12px;display:inline-block'><img src='{$LOGO_URL_A}' alt='Tododrogas' style='height:28px;object-fit:contain'></div></td>
      <td align='right'><span style='color:#93c5fd;font-size:11px;font-weight:700'>NUEVA ENCUESTA RECIBIDA</span></td>
    </tr></table>
  </td></tr>
  <tr><td style='padding:20px 28px 12px'>
    <table width='100%'><tr>
      <td><p style='margin:0 0 4px;font-size:16px;font-weight:700;color:#111827'>Calificación: <span style='color:{$color_cal_admin2}'>{$estrellas_admin2} ({$calificacion}/5)</span></p>
          <p style='margin:0;font-size:12px;color:#6b7280'>{$badge_cal2}</p></td>
      <td align='right'><p style='margin:0;font-size:11px;color:#6b7280'>Sede: <strong style='color:#111827'>{$sede_nombre}</strong>" . ($sede_ciudad ? "<br>{$sede_ciudad}" : "") . "</p></td>
    </tr></table>
  </td></tr>
  <tr><td style='padding:4px 28px 16px'>
    <table width='100%' style='border-collapse:collapse;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden'>
      <tr style='background:#f9fafb'><th style='padding:8px 10px;font-size:11px;font-weight:700;color:#6b7280;text-align:left;border-bottom:1px solid #e5e7eb'>DATOS DEL ENCUESTADO</th><th style='padding:8px 10px;font-size:11px;font-weight:700;color:#6b7280;text-align:left;border-bottom:1px solid #e5e7eb'>DETALLES</th></tr>
      <tr><td style='padding:7px 10px;font-size:12px;color:#6b7280'>Nombre</td><td style='padding:7px 10px;font-size:12px;color:#111827;font-weight:600'>" . htmlspecialchars($nombre, ENT_QUOTES) . "</td></tr>
      " . ($documento ? "<tr><td style='padding:7px 10px;font-size:12px;color:#6b7280'>Documento</td><td style='padding:7px 10px;font-size:12px;color:#111827'>" . htmlspecialchars($documento, ENT_QUOTES) . "</td></tr>" : "") . "
      " . ($correo ? "<tr><td style='padding:7px 10px;font-size:12px;color:#6b7280'>Correo</td><td style='padding:7px 10px;font-size:12px;color:#2563eb'>" . htmlspecialchars($correo, ENT_QUOTES) . "</td></tr>" : "<tr><td style='padding:7px 10px;font-size:12px;color:#6b7280'>Correo</td><td style='padding:7px 10px;font-size:12px;color:#9ca3af'>No proporcionado</td></tr>") . "
      " . ($telefono ? "<tr><td style='padding:7px 10px;font-size:12px;color:#6b7280'>Teléfono</td><td style='padding:7px 10px;font-size:12px;color:#111827'>" . htmlspecialchars($telefono, ENT_QUOTES) . "</td></tr>" : "") . "
      <tr><td style='padding:7px 10px;font-size:12px;color:#6b7280'>Canal</td><td style='padding:7px 10px;font-size:12px;color:#111827;text-transform:capitalize'>{$canal}</td></tr>
      <tr><td style='padding:7px 10px;font-size:12px;color:#6b7280'>Fecha</td><td style='padding:7px 10px;font-size:12px;color:#111827'>" . date('d/m/Y H:i', strtotime($now)) . "</td></tr>
    </table>
  </td></tr>
  <tr><td style='padding:0 28px 16px'>
    <p style='margin:0 0 8px;font-size:12px;font-weight:700;color:#374151'>Calificaciones por categoría:</p>
    <table width='100%' style='border-collapse:collapse;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden'>
      {$filas_a2}
      {$bloque_com_a2}
    </table>
  </td></tr>
  <tr><td style='background:#f8fafc;border-top:1px solid #e5e7eb;padding:14px 28px;text-align:center'>
    <p style='font-size:11px;color:#9ca3af;margin:0'>Notificación automática · Sistema PQRSFD · Tododrogas CIA SAS</p>
  </td></tr>
</table></td></tr></table>
</body></html>";

    $admin_pay2 = [
        'subject'      => "⭐ Nueva encuesta [{$calificacion}/5] · {$sede_nombre} · " . htmlspecialchars($nombre, ENT_QUOTES),
        'importance'   => $calificacion <= 2 ? 'high' : 'normal',
        'body'         => ['contentType' => 'HTML', 'content' => $cuerpo_a2],
        'toRecipients' => [['emailAddress' => ['address' => $BUZÓN_PQRS, 'name' => 'PQRSFD Tododrogas']]],
    ];
    $ch_a2 = curl_init("https://graph.microsoft.com/v1.0/users/{$GRAPH_USER_ID}/sendMail");
    curl_setopt_array($ch_a2, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['message' => $admin_pay2, 'saveToSentItems' => true]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token_admin", 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    curl_exec($ch_a2);
    curl_close($ch_a2);
}

// ── PASO 2C: INSERTAR ENCUESTA EN TABLA CORREOS ───────────────────────
// Así aparece en admin con el mismo estilo HTML que llega a pqrsfd
$fecha_enc  = date('Ymd');
$rand_enc   = str_pad(rand(1000,9999),4,'0',STR_PAD_LEFT);
$ticket_enc = "ENC-{$fecha_enc}-{$rand_enc}";

$emoji_cal  = [1=>'😞',2=>'😐',3=>'🙂',4=>'😊',5=>'🤩'][$calificacion] ?? '⭐';
$prio_enc   = $calificacion >= 4 ? 'baja' : ($calificacion >= 3 ? 'media' : 'alta');
$sent_enc   = $calificacion >= 4 ? 'positivo' : ($calificacion >= 3 ? 'neutro' : 'negativo');
$subject_enc = "[{$ticket_enc}] ⭐ ENCUESTA | {$emoji_cal} {$calificacion}/5 | 📍 {$sede_nombre}";

// Usar el cuerpo_admin ya construido (HTML bonito) como body del correo en admin
$body_enc = isset($cuerpo_admin) ? $cuerpo_admin : (isset($cuerpo_a2) ? $cuerpo_a2 : '');

$payload_enc_correo = [
    'ticket_id'     => $ticket_enc,
    'from_email'    => $correo ?: ($telefono ? $telefono.'@encuesta' : 'anonimo@encuesta'),
    'from_name'     => $nombre ?: 'Anónimo',
    'nombre'        => $nombre ?: 'Anónimo',
    'correo'        => $correo ?: null,
    'subject'       => $subject_enc,
    'body_content'  => $body_enc,
    'body_preview'  => "Encuesta {$calificacion}/5 — {$sede_nombre}" . ($comentario ? ". Comentario: ".mb_substr($comentario,0,100) : ''),
    'body_type'     => $body_enc ? 'html' : 'text',
    'tipo_pqr'      => 'encuesta',
    'sentimiento'   => $sent_enc,
    'prioridad'     => $prio_enc,
    'categoria_ia'  => "Encuesta · {$sede_nombre}",
    'resumen_corto' => "Encuesta {$calificacion}/5 — {$sede_nombre}",
    'canal_contacto'=> $canal,
    'origen'        => 'formulario_encuesta',
    'estado'        => 'solucionado',
    'is_read'       => false,
    'has_attachments'=> false,
    'received_at'   => $now,
    'created_at'    => $now,
    'updated_at'    => $now,
];
sbPost($SB_URL, $SB_KEY, 'correos', $payload_enc_correo);

// ── PASO 3: HISTORIAL_EVENTOS ─────────────────────────────────────────
// Siempre guardar en historial (admin lee encuestas desde aquí)
if (true) {
    sbPost($SB_URL, $SB_KEY, 'historial_eventos', [
        'evento'      => 'encuesta_recibida',
        'descripcion' => "Encuesta de satisfacción recibida. Sede: {$sede_nombre}. Promedio: {$promedio}. Confirmación: " . ($correo_enviado ? 'OK' : ($correo ? 'error' : 'sin correo')),
        'from_email'  => $correo ?: $telefono,
        'subject'     => "Encuesta · {$sede_nombre} · {$nombre}",
        'datos_extra' => json_encode([
            'encuesta_id'  => $encuesta_id,
            'nombre'       => $nombre,
            'correo'       => $correo,
            'sede_nombre'  => $sede_nombre,
            'sede_ciudad'  => $sede_ciudad,
            'calificacion' => $calificacion,
            'promedio'     => $promedio,
            'instalaciones'=> $instalaciones,
            'atencion'     => $atencion,
            'tiempos'      => $tiempos,
            'medicamentos' => $medicamentos,
            'recomendacion'=> $recomendacion,
            'canal'        => $canal,
        ]),
        'created_at' => $now,
    ], 'return=minimal');
}

// ── RESPUESTA ─────────────────────────────────────────────────────────
http_response_code(200);
echo json_encode([
    'ok'              => $saved,
    'encuesta_id'     => $encuesta_id,
    'correo_enviado'  => $correo_enviado,
    'mensaje'         => $saved
        ? "Encuesta registrada correctamente. ¡Gracias, {$nombre}!"
        : "No se pudo guardar en BD — se guardó localmente.",
]);
