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
// sede_id debe ser UUID real — el array del formulario usa strings como 'medellin'
// Si no es UUID válido, lo descartamos (los datos de sede van en historial_eventos.datos_extra)
$sede_id_raw   = trim($body['sede_id'] ?? '');
$sede_id       = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $sede_id_raw)
                 ? $sede_id_raw
                 : null;
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
    'sede_id'         => $sede_id,  // null si no es UUID válido
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
    error_log('[radicar-encuesta] Supabase error ' . $sb_result['code'] . ': ' . $sb_result['body']);
    // Retornar error detallado para debugging (solo en no-producción lo verías en consola)
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

        $labels = ['Instalaciones', 'Atención', 'Tiempos', 'Medicamentos', 'Recomienda'];
        $valores = [$instalaciones, $atencion, $tiempos, $medicamentos, $recomendacion];
        $emojis_cats = [
            ['😞','😐','🙂','😊','🤩'],
            ['😞','😐','🙂','😊','🤩'],
            ['⏳','😐','🙂','⚡','🚀'],
            ['❌','⚠️','✅','💊','🌟'],
            ['👎','🤔','👍','😊','🙌'],
        ];

        $filas_calificaciones = '';
        foreach ($labels as $i => $label) {
            $v   = $valores[$i];
            $emo = $v > 0 ? ($emojis_cats[$i][$v - 1] ?? '⭐') : '—';
            $barras = '';
            for ($b = 1; $b <= 5; $b++) {
                $col = $b <= $v ? '#2563eb' : '#e2e8f0';
                $barras .= "<span style='display:inline-block;width:18px;height:6px;background:{$col};border-radius:3px;margin-right:2px'></span>";
            }
            $filas_calificaciones .= "
            <tr>
              <td style='padding:8px 12px;color:#6b7280;font-size:13px;border-bottom:1px solid #f3f4f6'>{$label}</td>
              <td style='padding:8px 12px;border-bottom:1px solid #f3f4f6;font-size:13px'>{$emo} {$barras}</td>
              <td style='padding:8px 12px;border-bottom:1px solid #f3f4f6;font-weight:700;font-size:13px;color:{$color_cal}'>{$v}/5</td>
            </tr>";
        }

        $bloque_comentario = '';
        if ($comentario) {
            $bloque_comentario = "
            <div style='background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #7c3aed;border-radius:4px;padding:14px 16px;margin-bottom:20px'>
              <p style='margin:0 0 6px;font-size:12px;font-weight:700;color:#7c3aed'>💬 Tu comentario</p>
              <p style='margin:0;font-size:13px;color:#374151;line-height:1.6'>" . htmlspecialchars($comentario) . "</p>
            </div>";
        }

        $cuerpo = "
<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f1f5f9;padding:32px 16px'>
<tr><td align='center'>
<table width='560' cellpadding='0' cellspacing='0' style='max-width:560px;width:100%'>

  <tr><td style='background:#1e40af;border-radius:12px 12px 0 0;padding:28px 32px;text-align:center'>
    <img src='{$LOGO_URL}' alt='Tododrogas' style='height:52px;max-width:220px;object-fit:contain;display:block;margin:0 auto 12px'>
    <p style='color:#bfdbfe;margin:0;font-size:11px;letter-spacing:1px;text-transform:uppercase'>Tododrogas CIA SAS · Experiencia de Servicio al Cliente</p>
    <h2 style='color:#fff;margin:8px 0 0;font-size:22px;font-weight:700'>¡Gracias por tu opinión!</h2>
  </td></tr>

  <tr><td style='background:#1e3a8a;padding:20px 32px;text-align:center'>
    <p style='color:#93c5fd;margin:0;font-size:11px;letter-spacing:1.5px;text-transform:uppercase'>Tu calificación global</p>
    <p style='color:#fff;margin:8px 0 4px;font-size:42px;font-weight:700;line-height:1'>{$emoji_final} {$promedio}</p>
    <p style='color:#93c5fd;margin:0;font-size:18px;letter-spacing:3px'>{$estrellas}</p>
  </td></tr>

  <tr><td style='background:#fff;padding:24px 32px;border:1px solid #e2e8f0;border-top:none'>
    <p style='margin:0 0 16px;color:#374151;font-size:14px'>Hola <strong>{$nombre_first}</strong>,</p>
    <p style='margin:0 0 16px;color:#374151;font-size:14px;line-height:1.6'>
      Recibimos tu encuesta de satisfacción en <strong>{$sede_nombre}</strong>
      " . ($sede_ciudad ? "({$sede_ciudad})" : '') . ".
      Tu opinión es muy valiosa para seguir mejorando nuestro servicio.
    </p>

    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;margin-bottom:20px;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden'>
      <tr style='background:#f8fafc'>
        <th style='padding:10px 12px;text-align:left;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #e2e8f0'>Categoría</th>
        <th style='padding:10px 12px;text-align:left;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #e2e8f0'>Valoración</th>
        <th style='padding:10px 12px;text-align:left;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #e2e8f0'>Puntaje</th>
      </tr>
      {$filas_calificaciones}
    </table>

    {$bloque_comentario}

    <div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:16px 20px;margin-bottom:20px'>
      <p style='margin:0 0 6px;font-size:13px;font-weight:700;color:#166534'>¿Qué pasa con tu opinión?</p>
      <p style='margin:2px 0;font-size:12px;color:#166534'>→ Tu calificación será revisada por el equipo de calidad.</p>
      <p style='margin:2px 0;font-size:12px;color:#166534'>→ Usamos tus respuestas para mejorar nuestros servicios.</p>
      <p style='margin:2px 0;font-size:12px;color:#166534'>→ ¡Gracias por tomarte el tiempo de ayudarnos a crecer!</p>
    </div>

  </td></tr>

  <tr><td style='background:#f8fafc;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;padding:16px 32px;text-align:center'>
    <p style='font-size:11px;color:#9ca3af;margin:0'>Tododrogas CIA SAS · Experiencia de Servicio al Cliente<br>
    Este es un mensaje automático. Si tienes una solicitud o queja, usa nuestro formulario PQRSFD.</p>
  </td></tr>

</table></td></tr></table>
</body></html>";

        $mail_payload = [
            'subject'      => "✅ Gracias por tu opinión · Tododrogas {$sede_nombre}",
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
    }
}

// ── PASO 3: HISTORIAL_EVENTOS ─────────────────────────────────────────
if ($saved) {
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

