<?php
/**
 * radicar-encuesta.php — Encuesta de Satisfaccion · Tododrogas CIA SAS
 * ──────────────────────────────────────────────────────────────────────
 * 1. Recibe datos de la encuesta desde pqr_encuesta.html
 * 2. Inserta en Supabase tabla encuestas_satisfaccion
 * 3. Envia correo al usuario con formato formal (igual que radicar-pqr)
 * 4. Envia notificacion interna a pqrsfd con mismo formato
 * 5. Registra en historial_eventos
 * NO inserta en tabla correos (no aparece en admin como PQR)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── CREDENCIALES ──────────────────────────────────────────────────────
$SB_URL        = '__SB_URL__';
$SB_KEY        = '__SB_KEY__';
$TENANT_ID     = '__AZURE_TENANT_ID__';
$CLIENT_ID     = '__AZURE_CLIENT_ID__';
$CLIENT_SECRET = '__AZURE_CLIENT_SECRET__';
$GRAPH_USER_ID = '__GRAPH_USER_ID__';
$BUZON_PQRS    = 'pqrsfd@tododrogas.com.co';
$LOGO_URL      = 'https://lyosqaqhiwhgvjigvqtc.supabase.co/storage/v1/object/public/logos-config/LOGO_Tododrogas_Color%201%20(3).png';

// ── HELPERS ───────────────────────────────────────────────────────────
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

function sendMail($token, $graph_user_id, $payload) {
    $ch = curl_init("https://graph.microsoft.com/v1.0/users/{$graph_user_id}/sendMail");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['message' => $payload, 'saveToSentItems' => true]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 202;
}

// ── LEER INPUT ────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); echo json_encode(['error' => 'Invalid JSON']); exit; }

date_default_timezone_set('America/Bogota');
$now      = date('c');
$fecha_fmt = date('d/m/Y H:i');

$nombre        = trim($body['nombre']        ?? '');
$correo        = trim($body['correo']        ?? '');
$telefono      = trim($body['telefono']      ?? '');
$documento     = trim($body['documento']     ?? '');
$comentario    = trim($body['comentario']    ?? '');
$sede_id       = trim($body['sede_id']       ?? '');
$sede_nombre   = trim($body['sede_nombre']   ?? '');
$sede_ciudad   = trim($body['sede_ciudad']   ?? '');
$sede_direccion = trim($body['sede_direccion'] ?? '');
$canal         = trim($body['canal']         ?? 'web');

$instalaciones = intval($body['instalaciones'] ?? 0);
$atencion      = intval($body['atencion']      ?? 0);
$tiempos       = intval($body['tiempos']       ?? 0);
$medicamentos  = intval($body['medicamentos']  ?? 0);
$recomendacion = intval($body['recomendacion'] ?? 0);
$promedio      = floatval($body['promedio']    ?? 0);
$calificacion  = (int) round($promedio);

// Ticket encuesta
$fecha_enc  = date('Ymd');
$rand_enc   = str_pad(rand(1000,9999),4,'0',STR_PAD_LEFT);
$ticket_enc = "ENC-{$fecha_enc}-{$rand_enc}";

// Clasificacion sin emojis
$prio_enc  = $calificacion >= 4 ? 'baja'     : ($calificacion >= 3 ? 'media'   : 'alta');
$sent_enc  = $calificacion >= 4 ? 'positivo' : ($calificacion >= 3 ? 'neutro'  : 'negativo');
$color_cal = $calificacion >= 4 ? '#0f5c2e'  : ($calificacion >= 3 ? '#7a5200' : '#8a1a1a');
$bg_cal    = $calificacion >= 4 ? '#dcfce7'  : ($calificacion >= 3 ? '#fef9c3' : '#fee2e2');
$nivel_cal = $calificacion >= 4 ? 'SATISFACTORIO' : ($calificacion >= 3 ? 'NEUTRO' : 'INSATISFACTORIO');

// ── PASO 1: INSERTAR EN encuestas_satisfaccion ────────────────────────
$payload = [
    'calificacion'    => $calificacion,
    'comentario'      => $comentario ?: null,
    'sede_id'         => $sede_id    ?: null,
    'canal'           => $canal,
    'ip_origen'       => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'web',
    'ticket_id'       => $ticket_enc,
    'correo_id'       => null,
    'created_at'      => $now,
];

$sb_result   = sbPost($SB_URL, $SB_KEY, 'encuestas_satisfaccion', $payload);
$encuesta_id = null;
$saved       = ($sb_result['code'] >= 200 && $sb_result['code'] < 300);
if ($saved) {
    $inserted    = json_decode($sb_result['body'], true);
    $encuesta_id = $inserted[0]['id'] ?? null;
}

// ── HELPER: construir filas de calificaciones ─────────────────────────
$labels = [
    'Instalaciones y limpieza',
    'Atencion al cliente',
    'Tiempos de espera',
    'Disponibilidad de medicamentos',
    'Recomendaria el servicio',
];
$valores = [$instalaciones, $atencion, $tiempos, $medicamentos, $recomendacion];

// ── Filas para correo al USUARIO (fondo blanco, barras azul marino) ───
$filas_usuario = '';
foreach ($labels as $i => $label) {
    $v = $valores[$i];
    $barras = '';
    for ($b = 1; $b <= 5; $b++) {
        $col = $b <= $v ? '#0c2d5e' : '#d8e4f0';
        $barras .= "<span style='display:inline-block;width:18px;height:3px;background:{$col};border-radius:2px;margin-right:2px'></span>";
    }
    $vc = $v >= 4 ? '#0f5c2e' : ($v >= 3 ? '#7a5200' : '#8a1a1a');
    $bb = ($i < count($labels)-1) ? 'border-bottom:1px solid #e8eef6;' : '';
    $filas_usuario .= "
    <tr>
      <td style='padding:11px 0;font-size:12px;color:#2a3a4a;width:42%;{$bb}'>{$label}</td>
      <td style='padding:11px 14px 11px 0;width:38%;{$bb}'>{$barras}</td>
      <td style='padding:11px 0;font-size:12px;font-weight:500;text-align:right;color:{$vc};{$bb}'>{$v} / 5</td>
    </tr>";
}

// ── Filas para correo INTERNO a pqrsfd ────────────────────────────────
$filas_interno = '';
foreach ($labels as $i => $label) {
    $v = $valores[$i];
    $barras = '';
    for ($b = 1; $b <= 5; $b++) {
        $col = $b <= $v ? '#2563eb' : '#d8e4f0';
        $barras .= "<span style='display:inline-block;width:16px;height:5px;background:{$col};border-radius:3px;margin-right:2px'></span>";
    }
    $vc = $v >= 4 ? '#0f5c2e' : ($v >= 3 ? '#7a5200' : '#8a1a1a');
    $bb = ($i < count($labels)-1) ? 'border-bottom:1px solid #e8eef6;' : '';
    $filas_interno .= "
      <tr>
        <td style='padding:9px 14px;font-size:11px;color:#7a90a8;width:200px;{$bb}'>{$label}</td>
        <td style='padding:9px 14px;{$bb}'>{$barras}</td>
        <td style='padding:9px 14px;font-size:12px;font-weight:500;color:{$vc};text-align:right;{$bb}'>{$v} / 5</td>
      </tr>";
}

// ── PASO 2: CORREO AL USUARIO ─────────────────────────────────────────
$correo_enviado = false;
$token = null;

if ($correo && filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    $token = getGraphToken($TENANT_ID, $CLIENT_ID, $CLIENT_SECRET);

    if ($token) {
        $nombre_first = explode(' ', $nombre)[0];

        $bloque_comentario_u = '';
        if ($comentario) {
            $bloque_comentario_u = "
            <div style='background:#f6f9fd;border:1px solid #d4dce8;border-top:2px solid #0c2d5e;padding:18px 20px;margin-bottom:24px'>
              <p style='margin:0 0 8px;font-size:9px;font-weight:500;color:#7a90a8;text-transform:uppercase;letter-spacing:2px'>Observacion del usuario</p>
              <p style='margin:0;font-size:12px;color:#3a4a5a;line-height:1.7;font-style:italic'>" . htmlspecialchars($comentario, ENT_QUOTES) . "</p>
            </div>";
        }

        $cuerpo_usuario = "
<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#d8dfe9;font-family:Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#d8dfe9;padding:32px 16px'>
<tr><td align='center'>
<table width='580' cellpadding='0' cellspacing='0' style='max-width:580px;width:100%;background:#ffffff'>

  <tr><td style='background:#0c2d5e;padding:32px 44px;text-align:center'>
    <img src='{$LOGO_URL}' alt='Tododrogas' style='height:32px;max-width:180px;object-fit:contain;display:block;margin:0 auto 12px;filter:brightness(0) invert(1);opacity:.92'>
    <p style='color:#6a90b8;margin:0;font-size:10px;letter-spacing:2.5px;text-transform:uppercase;font-weight:400'>Evaluacion de experiencia de usuario &middot; Servicio Farmaceutico</p>
  </td></tr>

  <tr><td style='background:#0a2448;padding:20px 44px;text-align:center'>
    <p style='color:#6a90b8;margin:0;font-size:9px;letter-spacing:2.5px;text-transform:uppercase;font-weight:400'>Registro</p>
    <p style='color:#ffffff;margin:8px 0 4px;font-size:22px;font-weight:700;letter-spacing:3px;font-family:monospace'>{$ticket_enc}</p>
    <p style='color:#4a6a90;margin:0;font-size:10px'>{$fecha_fmt} &middot; {$sede_nombre}</p>
  </td></tr>

  <tr><td style='background:#ffffff;padding:36px 44px'>

    <p style='margin:0 0 10px;font-size:14px;color:#1a2535;line-height:1.8;font-weight:300'>Estimado/a, <strong style='font-weight:500;color:#0c2d5e'>{$nombre_first}</strong>,</p>
    <p style='margin:0 0 24px;font-size:14px;color:#1a2535;line-height:1.8;font-weight:300'>Hemos recibido su evaluacion de experiencia. Su retroalimentacion es parte fundamental de nuestro proceso de mejora continua. Cada respuesta es revisada de manera directa por el equipo de calidad de servicio.</p>

    <!-- DIVIDER CALIFICACION GLOBAL -->
    <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:12px'>
      <tr>
        <td style='font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:#7a90a8;font-weight:500;white-space:nowrap;padding-right:12px'>Calificacion global</td>
        <td style='border-top:1px solid #d4dce8'></td>
      </tr>
    </table>

    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;border:1px solid #d4dce8;margin-bottom:24px'>
      <tr>
        <td style='padding:9px 14px;font-size:11px;color:#7a90a8;width:150px;border-bottom:1px solid #e8eef6'>Sede</td>
        <td style='padding:9px 14px;font-size:12px;font-weight:500;color:#2a3a4a;border-bottom:1px solid #e8eef6'>{$sede_nombre}" . ($sede_ciudad ? " &middot; {$sede_ciudad}" : "") . "</td>
      </tr>
      <tr style='background:#f6f9fd'>
        <td style='padding:9px 14px;font-size:11px;color:#7a90a8;border-bottom:1px solid #d4dce8'>Promedio</td>
        <td style='padding:9px 14px;font-size:13px;font-weight:700;color:{$color_cal};border-bottom:1px solid #d4dce8'>{$promedio} / 5.0</td>
      </tr>
      <tr>
        <td style='padding:9px 14px;font-size:11px;color:#7a90a8'>Resultado</td>
        <td style='padding:9px 14px'>
          <span style='background:{$bg_cal};color:{$color_cal};padding:3px 10px;font-size:11px;font-weight:700;letter-spacing:.5px'>{$nivel_cal}</span>
        </td>
      </tr>
    </table>

    <!-- DIVIDER INDICADORES -->
    <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:12px'>
      <tr>
        <td style='font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:#7a90a8;font-weight:500;white-space:nowrap;padding-right:12px'>Resumen de indicadores</td>
        <td style='border-top:1px solid #d4dce8'></td>
      </tr>
    </table>

    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;margin-bottom:24px'>
      {$filas_usuario}
    </table>

    {$bloque_comentario_u}

    <!-- BLOQUE QUE SUCEDE -->
    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;border:1px solid #d4dce8;margin-bottom:28px'>
      <tr><td style='padding:22px 24px'>
        <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:16px'>
          <tr>
            <td style='width:20px;border-top:2px solid #0c2d5e;vertical-align:middle'></td>
            <td style='padding-left:10px;font-size:9px;letter-spacing:2px;text-transform:uppercase;color:#0c2d5e;font-weight:500'>Que sucede con su evaluacion</td>
          </tr>
        </table>
        <table width='100%' cellpadding='0' cellspacing='0'>
          <tr><td style='width:24px;font-size:10px;font-weight:500;color:#0c2d5e;vertical-align:top;padding:0 0 10px'>01</td><td style='font-size:12px;color:#4a5a6a;line-height:1.6;font-weight:300;padding:0 0 10px'>Su calificacion es revisada por el equipo de calidad de la sede.</td></tr>
          <tr><td style='width:24px;font-size:10px;font-weight:500;color:#0c2d5e;vertical-align:top;padding:0 0 10px'>02</td><td style='font-size:12px;color:#4a5a6a;line-height:1.6;font-weight:300;padding:0 0 10px'>Los indicadores con oportunidad de mejora son escalados al area correspondiente.</td></tr>
          <tr><td style='width:24px;font-size:10px;font-weight:500;color:#0c2d5e;vertical-align:top'>03</td><td style='font-size:12px;color:#4a5a6a;line-height:1.6;font-weight:300'>Si su caso requiere seguimiento, nos pondremos en contacto.</td></tr>
        </table>
      </td></tr>
    </table>

    <!-- DIVIDER CANALES -->
    <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:12px'>
      <tr>
        <td style='font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:#7a90a8;font-weight:500;white-space:nowrap;padding-right:12px'>Canales de atencion</td>
        <td style='border-top:1px solid #d4dce8'></td>
      </tr>
    </table>

    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;border:1px solid #d4dce8'>
      <tr>
        <td width='50%' style='padding:16px 18px;border-bottom:1px solid #d4dce8;border-right:1px solid #d4dce8;vertical-align:top'>
          <p style='margin:0 0 4px;font-size:9px;letter-spacing:1.8px;text-transform:uppercase;color:#8a9ab8'>WhatsApp</p>
          <a href='https://wa.me/573043412431' style='font-size:12px;color:#0c2d5e;font-weight:500;text-decoration:none'>304 341 2431</a>
        </td>
        <td width='50%' style='padding:16px 18px;border-bottom:1px solid #d4dce8;vertical-align:top'>
          <p style='margin:0 0 4px;font-size:9px;letter-spacing:1.8px;text-transform:uppercase;color:#8a9ab8'>PBX Atencion</p>
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

  <tr><td style='background:#0c2d5e;padding:18px 44px'>
    <table width='100%' cellpadding='0' cellspacing='0'>
      <tr>
        <td style='font-size:10px;color:#4a6a90;line-height:1.6'>Tododrogas CIA SAS<br>Experiencia de Servicio al Cliente</td>
        <td align='right' style='font-size:9px;letter-spacing:2px;text-transform:uppercase;color:#2a4870;font-weight:500'>Sistema PQRSFD<br>{$ticket_enc}</td>
      </tr>
    </table>
  </td></tr>

</table></td></tr></table>
</body></html>";

        $correo_enviado = sendMail($token, $GRAPH_USER_ID, [
            'subject'      => "Su evaluacion fue recibida · {$ticket_enc} · Tododrogas CIA SAS",
            'importance'   => 'normal',
            'body'         => ['contentType' => 'HTML', 'content' => $cuerpo_usuario],
            'toRecipients' => [['emailAddress' => ['address' => $correo, 'name' => $nombre]]],
        ]);
    }
}

// ── PASO 3: CORREO INTERNO A pqrsfd ──────────────────────────────────
if (!$token) {
    $token = getGraphToken($TENANT_ID, $CLIENT_ID, $CLIENT_SECRET);
}

if ($token) {
    $bloque_comentario_i = '';
    if ($comentario) {
        $bloque_comentario_i = "
      <tr>
        <td colspan='3' style='padding:12px 14px;background:#f6f9fd;border-top:2px solid #0c2d5e'>
          <p style='margin:0 0 6px;font-size:9px;font-weight:500;color:#7a90a8;text-transform:uppercase;letter-spacing:2px'>Observacion del usuario</p>
          <p style='margin:0;font-size:12px;color:#3a4a5a;line-height:1.7;font-style:italic'>" . htmlspecialchars($comentario, ENT_QUOTES) . "</p>
        </td>
      </tr>";
    }

    $cuerpo_interno = "
<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#d8dfe9;font-family:Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#d8dfe9;padding:32px 16px'>
<tr><td align='center'>
<table width='580' cellpadding='0' cellspacing='0' style='max-width:580px;width:100%;background:#ffffff'>

  <tr><td style='background:#0c2d5e;padding:32px 44px;text-align:center'>
    <img src='{$LOGO_URL}' alt='Tododrogas' style='height:32px;max-width:180px;object-fit:contain;display:block;margin:0 auto 12px;filter:brightness(0) invert(1);opacity:.92'>
    <p style='color:#6a90b8;margin:0;font-size:10px;letter-spacing:2.5px;text-transform:uppercase;font-weight:400'>Nueva encuesta de satisfaccion recibida &middot; Plataforma Nova TD</p>
  </td></tr>

  <tr><td style='background:#0a2448;padding:20px 44px;text-align:center'>
    <p style='color:#6a90b8;margin:0;font-size:9px;letter-spacing:2.5px;text-transform:uppercase;font-weight:400'>Numero de registro</p>
    <p style='color:#ffffff;margin:8px 0 4px;font-size:22px;font-weight:700;letter-spacing:3px;font-family:monospace'>{$ticket_enc}</p>
    <p style='color:#4a6a90;margin:0;font-size:10px'>{$fecha_fmt}</p>
  </td></tr>

  <tr><td style='background:#ffffff;padding:36px 44px'>

    <p style='margin:0 0 24px;font-size:14px;color:#1a2535;line-height:1.8;font-weight:300'>Se ha recibido una nueva encuesta de satisfaccion mediante la <strong style='font-weight:500;color:#0c2d5e'>Plataforma Inteligente Nova TD</strong>.</p>

    <!-- DIVIDER RESULTADO -->
    <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:12px'>
      <tr>
        <td style='font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:#7a90a8;font-weight:500;white-space:nowrap;padding-right:12px'>Resultado de la evaluacion</td>
        <td style='border-top:1px solid #d4dce8'></td>
      </tr>
    </table>

    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;border:1px solid #d4dce8;margin-bottom:24px'>
      <tr style='background:#f6f9fd'>
        <td style='padding:9px 14px;font-size:11px;color:#7a90a8;width:150px;border-bottom:1px solid #d4dce8'>Sede</td>
        <td colspan='2' style='padding:9px 14px;font-size:12px;font-weight:500;color:#2a3a4a;border-bottom:1px solid #d4dce8'>{$sede_nombre}" . ($sede_ciudad ? " &middot; {$sede_ciudad}" : "") . ($sede_direccion ? "<br><span style='font-size:11px;color:#7a90a8;font-weight:400'>{$sede_direccion}</span>" : "") . "</td>
      </tr>
      <tr>
        <td style='padding:9px 14px;font-size:11px;color:#7a90a8;border-bottom:1px solid #e8eef6'>Promedio</td>
        <td colspan='2' style='padding:9px 14px;font-size:14px;font-weight:700;color:{$color_cal};border-bottom:1px solid #e8eef6'>{$promedio} / 5.0</td>
      </tr>
      <tr style='background:#f6f9fd'>
        <td style='padding:9px 14px;font-size:11px;color:#7a90a8;border-bottom:1px solid #d4dce8'>Resultado</td>
        <td colspan='2' style='padding:9px 14px;border-bottom:1px solid #d4dce8'>
          <span style='background:{$bg_cal};color:{$color_cal};padding:3px 10px;font-size:11px;font-weight:700;letter-spacing:.5px'>{$nivel_cal}</span>
        </td>
      </tr>
      <tr>
        <td style='padding:9px 14px;font-size:11px;color:#7a90a8;border-bottom:1px solid #e8eef6'>Prioridad</td>
        <td colspan='2' style='padding:9px 14px;font-size:12px;font-weight:500;color:#2a3a4a;border-bottom:1px solid #e8eef6'>" . mb_strtoupper($prio_enc, 'UTF-8') . "</td>
      </tr>
      <tr style='background:#f6f9fd'>
        <td style='padding:9px 14px;font-size:11px;color:#7a90a8'>Fecha</td>
        <td colspan='2' style='padding:9px 14px;font-size:12px;color:#2a3a4a'>{$fecha_fmt}</td>
      </tr>
    </table>

    <!-- DIVIDER CIUDADANO -->
    <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:12px'>
      <tr>
        <td style='font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:#7a90a8;font-weight:500;white-space:nowrap;padding-right:12px'>Datos del encuestado</td>
        <td style='border-top:1px solid #d4dce8'></td>
      </tr>
    </table>

    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;border:1px solid #d4dce8;margin-bottom:24px'>
      <tr>
        <td style='padding:9px 14px;font-size:11px;color:#7a90a8;width:150px;border-bottom:1px solid #e8eef6'>Nombre</td>
        <td style='padding:9px 14px;font-size:12px;font-weight:500;color:#2a3a4a;border-bottom:1px solid #e8eef6'>" . htmlspecialchars($nombre, ENT_QUOTES) . "</td>
      </tr>" .
      ($documento ? "<tr style='background:#f6f9fd'><td style='padding:9px 14px;font-size:11px;color:#7a90a8;border-bottom:1px solid #d4dce8'>Documento</td><td style='padding:9px 14px;font-size:12px;color:#2a3a4a;border-bottom:1px solid #d4dce8'>" . htmlspecialchars($documento, ENT_QUOTES) . "</td></tr>" : "") .
      ($correo ? "<tr><td style='padding:9px 14px;font-size:11px;color:#7a90a8;border-bottom:1px solid #e8eef6'>Correo</td><td style='padding:9px 14px;font-size:12px;border-bottom:1px solid #e8eef6'><a href='mailto:{$correo}' style='color:#0c2d5e;font-weight:500;text-decoration:none'>" . htmlspecialchars($correo, ENT_QUOTES) . "</a></td></tr>" : "") .
      ($telefono ? "<tr style='background:#f6f9fd'><td style='padding:9px 14px;font-size:11px;color:#7a90a8;border-bottom:1px solid #d4dce8'>Telefono</td><td style='padding:9px 14px;font-size:12px;color:#2a3a4a;border-bottom:1px solid #d4dce8'>" . htmlspecialchars($telefono, ENT_QUOTES) . "</td></tr>" : "") .
      "<tr><td style='padding:9px 14px;font-size:11px;color:#7a90a8'>Canal</td><td style='padding:9px 14px;font-size:12px;color:#2a3a4a;text-transform:capitalize'>{$canal}</td></tr>
    </table>

    <!-- DIVIDER INDICADORES -->
    <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:12px'>
      <tr>
        <td style='font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:#7a90a8;font-weight:500;white-space:nowrap;padding-right:12px'>Calificaciones por indicador</td>
        <td style='border-top:1px solid #d4dce8'></td>
      </tr>
    </table>

    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;border:1px solid #d4dce8;margin-bottom:24px'>
      {$filas_interno}
      {$bloque_comentario_i}
    </table>

    <!-- BLOQUE ESTADO -->
    <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;border:1px solid #d4dce8'>
      <tr><td style='padding:16px 20px'>
        <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:10px'>
          <tr>
            <td style='width:20px;border-top:2px solid #0c2d5e;vertical-align:middle'></td>
            <td style='padding-left:10px;font-size:9px;letter-spacing:2px;text-transform:uppercase;color:#0c2d5e;font-weight:500'>Estado del sistema</td>
          </tr>
        </table>
        <p style='margin:0;font-size:12px;color:#4a5a6a;line-height:1.6;font-weight:300'>Esta encuesta ha sido <strong style='font-weight:500'>guardada automaticamente</strong> en la tabla de encuestas_satisfaccion. No requiere gestion en el buzon PQRSFD.</p>
      </td></tr>
    </table>

  </td></tr>

  <tr><td style='background:#0c2d5e;padding:18px 44px'>
    <table width='100%' cellpadding='0' cellspacing='0'>
      <tr>
        <td style='font-size:10px;color:#4a6a90;line-height:1.6'>Tododrogas CIA SAS<br>Experiencia de Servicio al Cliente &middot; Nova TD</td>
        <td align='right' style='font-size:9px;letter-spacing:2px;text-transform:uppercase;color:#2a4870;font-weight:500'>Registro: {$ticket_enc}<br>ID: " . ($encuesta_id ?? 'N/A') . "</td>
      </tr>
    </table>
  </td></tr>

</table></td></tr></table>
</body></html>";

    $subject_interno = "[{$ticket_enc}] ENCUESTA | {$calificacion}/5 | {$nivel_cal} | {$sede_nombre}";

    sendMail($token, $GRAPH_USER_ID, [
        'subject'      => $subject_interno,
        'importance'   => $calificacion <= 2 ? 'high' : 'normal',
        'body'         => ['contentType' => 'HTML', 'content' => $cuerpo_interno],
        'toRecipients' => [['emailAddress' => ['address' => $BUZON_PQRS, 'name' => 'PQRSFD Tododrogas']]],
    ]);
}

// ── PASO 4: HISTORIAL_EVENTOS ─────────────────────────────────────────
sbPost($SB_URL, $SB_KEY, 'historial_eventos', [
    'evento'      => 'encuesta_recibida',
    'descripcion' => "Encuesta registrada. Sede: {$sede_nombre}. Promedio: {$promedio}. Confirmacion: " . ($correo_enviado ? 'OK' : ($correo ? 'error' : 'sin correo')),
    'from_email'  => $correo ?: $telefono,
    'subject'     => "Encuesta · {$sede_nombre} · {$nombre}",
    'datos_extra' => json_encode([
        'encuesta_id'   => $encuesta_id,
        'ticket'        => $ticket_enc,
        'nombre'        => $nombre,
        'correo'        => $correo,
        'sede_nombre'   => $sede_nombre,
        'sede_ciudad'   => $sede_ciudad,
        'calificacion'  => $calificacion,
        'promedio'      => $promedio,
        'instalaciones' => $instalaciones,
        'atencion'      => $atencion,
        'tiempos'       => $tiempos,
        'medicamentos'  => $medicamentos,
        'recomendacion' => $recomendacion,
    ]),
    'created_at' => $now,
], 'return=minimal');

// ── RESPUESTA ─────────────────────────────────────────────────────────
http_response_code(200);
echo json_encode([
    'ok'             => $saved,
    'encuesta_id'    => $encuesta_id,
    'ticket'         => $ticket_enc,
    'correo_enviado' => $correo_enviado,
    'mensaje'        => $saved
        ? "Encuesta registrada correctamente."
        : "No se pudo guardar en BD.",
]);
