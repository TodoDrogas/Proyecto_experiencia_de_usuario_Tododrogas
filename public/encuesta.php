<?php
/**
 * encuesta.php — Recibe calificación de encuesta embebida en correo
 * Parámetros GET: id=CORREO_ID, cal=1|2|3, ag=AGENTE_NOMBRE
 */

$SB_URL = '__SB_URL__';
$SB_KEY = '__SB_KEY__';

$id  = trim($_GET['id']  ?? '');
$cal = intval($_GET['cal'] ?? 0);
$ag  = htmlspecialchars(trim($_GET['ag'] ?? ''));

$textos  = [1 => 'Mala',   2 => 'Regular', 3 => 'Buena'];
$emojis  = [1 => '😞',    2 => '😐',      3 => '😊'];
$accents = [1 => '#ef4444', 2 => '#f59e0b', 3 => '#22c55e'];

if (!$id || !in_array($cal, [1, 2, 3])) {
    mostrarError('Enlace inválido o expirado.');
    exit;
}

$texto  = $textos[$cal];
$emoji  = $emojis[$cal];
$accent = $accents[$cal];

// Verificar si ya calificó
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

if (!empty($row['calificacion'])) {
    $cal_prev    = $row['calificacion'];
    $texto_prev  = $textos[$cal_prev]  ?? $row['calificacion_texto'];
    $emoji_prev  = $emojis[$cal_prev]  ?? '✅';
    $accent_prev = $accents[$cal_prev] ?? '#22c55e';
    mostrarYaRespondido($texto_prev, $emoji_prev, $accent_prev, $ag);
    exit;
}

// Guardar calificación
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

mostrarGracias($emoji, $texto, $accent, $ag);

// ── Funciones HTML ────────────────────────────────────────────────────

function css(): string {
    return <<<CSS
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap');
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Poppins',sans-serif;background:#f0f2f5;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
    .card{background:#fff;border-radius:20px;padding:40px 48px;max-width:520px;width:100%;box-shadow:0 4px 0 rgba(0,0,0,0.06),0 12px 40px rgba(0,0,0,0.08);text-align:center}
    .enc-title{font-size:14px;font-weight:700;color:#0f172a;margin-bottom:4px}
    .enc-sub{font-size:10px;font-weight:600;color:#94a3b8;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:24px}
    .divider{height:1px;background:#f1f5f9;margin-bottom:20px;margin-top:0}
    .logo-img{height:40px;object-fit:contain;margin-bottom:20px}
    .agent-chip{display:inline-flex;align-items:center;gap:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:99px;padding:8px 18px;margin-bottom:22px}
    .av{width:28px;height:28px;border-radius:50%;background:#1e3a8a;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:#fff;flex-shrink:0}
    .ag-label{font-size:9px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;text-align:left}
    .ag-name{font-size:12px;font-weight:700;color:#1e293b;text-align:left}
    .q{font-size:17px;font-weight:700;color:#0f172a;margin-bottom:6px;line-height:1.4}
    .q-sub{font-size:12px;color:#94a3b8;margin-bottom:28px}
    .btns{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-bottom:28px}
    .btn{display:inline-flex;align-items:center;gap:8px;padding:12px 28px;border-radius:99px;text-decoration:none;border:none;border-bottom:3px solid;background:#f1f5f9;white-space:nowrap;font-family:'Poppins',sans-serif}
    .btn .em{font-size:18px}
    .btn .lb{font-size:13px;font-weight:700;color:#334155}
    .pill{display:inline-flex;align-items:center;gap:8px;padding:12px 28px;border-radius:99px;border:none;border-bottom:3px solid;background:#f1f5f9;margin-bottom:24px}
    .pill .em{font-size:18px}
    .pill .lb{font-size:13px;font-weight:700;color:#334155;font-family:'Poppins',sans-serif}
    .thanks-title{font-size:19px;font-weight:700;color:#0f172a;margin-bottom:8px}
    .thanks-sub{font-size:13px;color:#64748b;line-height:1.6;margin-bottom:24px}
    .footer-enc{font-size:10px;color:#cbd5e1;letter-spacing:.3px}
CSS;
}

function mostrarGracias(string $emoji, string $texto, string $accent, string $agente): void {
    $ag_str = $agente ? "Atendido por <strong>$agente</strong>" : '';
    $css = css();
    echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gracias — Tododrogas</title>
  <style>{$css}</style>
</head>
<body>
  <div class="card">
    <img class="logo-img" src="https://lyosqaqhiwhgvjigvqtc.supabase.co/storage/v1/object/public/logos-config/LOGO_Tododrogas_Color%201%20(3).png" alt="Tododrogas">
    <div class="thanks-title">¡Gracias por su calificación!</div>
    <div class="thanks-sub">Su opinión nos ayuda a mejorar la experiencia del servicio.<br>{$ag_str}</div>
    <div class="pill" style="border-bottom-color:{$accent}">
      <span class="em">{$emoji}</span><span class="lb">{$texto}</span>
    </div>
    <div class="divider"></div>
    <div class="footer-enc">Tododrogas · Experiencia del Servicio</div>
  </div>
</body>
</html>
HTML;
}

function mostrarYaRespondido(string $texto, string $emoji, string $accent, string $agente): void {
    $ag_str = $agente ? "Atendido por <strong>$agente</strong>" : '';
    $css = css();
    echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ya calificado — Tododrogas</title>
  <style>{$css}</style>
</head>
<body>
  <div class="card">
    <img class="logo-img" src="https://lyosqaqhiwhgvjigvqtc.supabase.co/storage/v1/object/public/logos-config/LOGO_Tododrogas_Color%201%20(3).png" alt="Tododrogas">
    <div class="thanks-title">Ya registramos su calificación</div>
    <div class="thanks-sub">Usted ya calificó este caso. ¡Gracias por su opinión!<br>{$ag_str}</div>
    <div class="pill" style="border-bottom-color:{$accent}">
      <span class="em">{$emoji}</span><span class="lb">{$texto}</span>
    </div>
    <div class="divider"></div>
    <div class="footer-enc">Tododrogas · Experiencia del Servicio</div>
  </div>
</body>
</html>
HTML;
}

function mostrarError(string $msg): void {
    $css = css();
    echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Error — Tododrogas</title>
  <style>{$css}</style>
</head>
<body>
  <div class="card">
    <div class="thanks-title" style="color:#ef4444">⚠️ Enlace inválido</div>
    <div class="thanks-sub" style="margin-top:8px">{$msg}</div>
    <div class="divider" style="margin-top:24px"></div>
    <div class="footer-enc">Tododrogas · Experiencia del Servicio</div>
  </div>
</body>
</html>
HTML;
}

// Nota: mostrarEncuesta no se usa en el flujo actual — el usuario llega con cal= ya definido
// desde el correo. Esta función es para referencia futura si se quiere mostrar la encuesta
// antes de que el usuario elija (ej: landing sin cal en el URL).
function mostrarEncuesta(string $id, string $agente): void {
    $css = css();
    $base = 'https://tododrogas.online/encuesta.php';
    $ag_enc = urlencode($agente);
    echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Encuesta de Satisfacción — Tododrogas</title>
  <style>{$css}</style>
</head>
<body>
  <div class="card">
    <div class="enc-title">Encuesta de Satisfacción</div>
    <div class="enc-sub">Experiencia del Servicio</div>
    <div class="divider"></div>
    <div class="agent-chip">
      <div class="av">{$agente[0]}</div>
      <div>
        <div class="ag-label">Atendido por</div>
        <div class="ag-name">{$agente}</div>
      </div>
    </div>
    <div class="q">¿Cómo califica la atención recibida?</div>
    <div class="q-sub">Su opinión nos toma menos de 5 segundos</div>
    <div class="btns">
      <a href="{$base}?id={$id}&cal=1&ag={$ag_enc}" class="btn" style="border-bottom-color:#ef4444">
        <span class="em">😞</span><span class="lb">Mala</span>
      </a>
      <a href="{$base}?id={$id}&cal=2&ag={$ag_enc}" class="btn" style="border-bottom-color:#f59e0b">
        <span class="em">😐</span><span class="lb">Regular</span>
      </a>
      <a href="{$base}?id={$id}&cal=3&ag={$ag_enc}" class="btn" style="border-bottom-color:#22c55e">
        <span class="em">😊</span><span class="lb">Buena</span>
      </a>
    </div>
    <div class="footer-enc">Tododrogas · Experiencia del Servicio</div>
  </div>
</body>
</html>
HTML;
}
