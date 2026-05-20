<?php
/**
 * encuesta.php — Recibe calificación de encuesta embebida en correo
 * Parámetros GET: id=CORREO_ID, cal=1|2|3, ag=AGENTE_NOMBRE
 */

$SB_URL = '__SB_URL__';
$SB_KEY = '__SB_KEY__';

$id  = trim($_GET['id']  ?? '');
$cal = intval($_GET['cal'] ?? 0);
$ag  = trim($_GET['ag']  ?? '');

$textos = [1 => 'Mala', 2 => 'Regular', 3 => 'Buena'];
$emojis = [1 => '😞', 2 => '😐', 3 => '😊'];
$colores = [1 => '#dc2626', 2 => '#ca8a04', 3 => '#16a34a'];
$fondos  = [1 => '#fee2e2', 2 => '#fef9c3', 3 => '#dcfce7'];

// Validar parámetros
if (!$id || !in_array($cal, [1, 2, 3])) {
    mostrarError('Enlace inválido o expirado.');
    exit;
}

$texto = $textos[$cal];
$emoji = $emojis[$cal];
$color = $colores[$cal];
$fondo = $fondos[$cal];

// Verificar que el correo existe y no tiene calificación ya
$ch = curl_init("$SB_URL/rest/v1/correos?id=eq.$id&select=id,calificacion,calificacion_texto&limit=1");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "apikey: $SB_KEY",
        "Authorization: Bearer $SB_KEY",
        "Content-Type: application/json",
    ]
]);
$resp = curl_exec($ch);
curl_close($ch);
$rows = json_decode($resp, true);

if (empty($rows)) {
    mostrarError('No se encontró el caso asociado a este enlace.');
    exit;
}

$row = $rows[0];

// Si ya calificó, mostrar mensaje de ya respondido
if (!empty($row['calificacion'])) {
    mostrarYaRespondido($textos[$row['calificacion']] ?? $row['calificacion_texto'], $emojis[$row['calificacion']] ?? '');
    exit;
}

// Guardar calificación en correos
$patch = json_encode([
    'calificacion'        => $cal,
    'calificacion_texto'  => $texto,
    'encuesta_enviada_at' => date('c'),
]);
$ch = curl_init("$SB_URL/rest/v1/correos?id=eq.$id");
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST  => 'PATCH',
    CURLOPT_POSTFIELDS     => $patch,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        "apikey: $SB_KEY",
        "Authorization: Bearer $SB_KEY",
        "Content-Type: application/json",
        "Prefer: return=minimal",
    ]
]);
curl_exec($ch);
curl_close($ch);

// Registrar en historial_eventos
$evento = json_encode([
    'correo_id'   => $id,
    'evento'      => 'encuesta_respondida',
    'descripcion' => "Calificación: $texto ($cal/3)",
    'datos_extra' => json_encode([
        'calificacion'       => $cal,
        'calificacion_texto' => $texto,
        'canal'              => 'correo',
        'agente'             => $ag,
    ]),
    'created_at' => date('c'),
]);
$ch = curl_init("$SB_URL/rest/v1/historial_eventos");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $evento,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        "apikey: $SB_KEY",
        "Authorization: Bearer $SB_KEY",
        "Content-Type: application/json",
        "Prefer: return=minimal",
    ]
]);
curl_exec($ch);
curl_close($ch);

// Mostrar página de agradecimiento
mostrarGracias($emoji, $texto, $color, $fondo, $ag);

// ── Funciones de renderizado ──────────────────────────────────────────

function mostrarGracias(string $emoji, string $texto, string $color, string $fondo, string $agente): void {
    $ag_str = $agente ? "<p style='font-size:14px;color:#666;margin-top:8px'>Atendido por <strong>" . htmlspecialchars($agente) . "</strong></p>" : '';
    echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gracias por su calificación — Tododrogas</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Arial, sans-serif; background: #f5f5f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
    .card { background: white; border-radius: 16px; padding: 40px 32px; max-width: 420px; width: 100%; text-align: center; box-shadow: 0 4px 24px rgba(0,0,0,.08); }
    .emoji { font-size: 64px; margin-bottom: 16px; }
    .badge { display: inline-block; padding: 6px 18px; border-radius: 99px; font-size: 15px; font-weight: bold; background: {$fondo}; color: {$color}; margin-bottom: 16px; }
    h1 { font-size: 22px; color: #1a1a1a; margin-bottom: 8px; }
    p { font-size: 15px; color: #555; line-height: 1.5; }
    .footer { margin-top: 32px; font-size: 12px; color: #aaa; }
  </style>
</head>
<body>
  <div class="card">
    <div class="emoji">{$emoji}</div>
    <div class="badge">{$texto}</div>
    <h1>¡Gracias por su calificación!</h1>
    <p>Su opinión nos ayuda a mejorar la atención en Tododrogas.</p>
    {$ag_str}
    <div class="footer">Tododrogas · Experiencia del Servicio</div>
  </div>
</body>
</html>
HTML;
}

function mostrarYaRespondido(string $texto, string $emoji): void {
    echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Ya calificado — Tododrogas</title>
  <style>
    body { font-family: Arial, sans-serif; background: #f5f5f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
    .card { background: white; border-radius: 16px; padding: 40px 32px; max-width: 400px; text-align: center; box-shadow: 0 4px 24px rgba(0,0,0,.08); }
    h1 { font-size: 20px; color: #1a1a1a; margin: 16px 0 8px; }
    p { font-size: 14px; color: #666; }
  </style>
</head>
<body>
  <div class="card">
    <div style="font-size:48px">✅</div>
    <h1>Ya registramos su calificación</h1>
    <p>Usted calificó este caso como <strong>{$emoji} {$texto}</strong>.<br>¡Gracias por su opinión!</p>
    <p style="margin-top:24px;font-size:12px;color:#aaa">Tododrogas · Experiencia del Servicio</p>
  </div>
</body>
</html>
HTML;
}

function mostrarError(string $msg): void {
    echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Error — Tododrogas</title>
  <style>
    body { font-family: Arial, sans-serif; background: #f5f5f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
    .card { background: white; border-radius: 16px; padding: 40px 32px; max-width: 400px; text-align: center; box-shadow: 0 4px 24px rgba(0,0,0,.08); }
  </style>
</head>
<body>
  <div class="card">
    <div style="font-size:48px">⚠️</div>
    <h1 style="font-size:20px;margin:16px 0 8px;color:#1a1a1a">Enlace inválido</h1>
    <p style="color:#666;font-size:14px">{$msg}</p>
    <p style="margin-top:24px;font-size:12px;color:#aaa">Tododrogas · Experiencia del Servicio</p>
  </div>
</body>
</html>
HTML;
}
