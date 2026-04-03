<?php
/**
 * validar-paciente.php
 * Valida si un usuario existe en tabla_usuarios (2 de 3 campos)
 * Llamado desde pqr_form.html y pqr_encuesta.html
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── CREDENCIALES (inyectadas por deploy.yml) ─────────────
$SB_URL = '__SB_URL__';
$SB_KEY = '__SB_KEY__';

// Verificar que las credenciales fueron inyectadas
if ($SB_URL === '__SB_URL__' || $SB_KEY === '__SB_KEY__' || empty($SB_URL) || empty($SB_KEY)) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'razon'=>'servicio_no_disponible','msg'=>'Servicio de validación no configurado. Intente más tarde o comuníquese al 604 322 2432.']);
    exit;
}

// ── LEER INPUT ───────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); echo json_encode(['ok'=>false,'razon'=>'invalid_json']); exit; }

$cedula   = trim($body['cedula']   ?? '');
$nombre   = strtoupper(trim($body['nombre']   ?? ''));
$telefono = preg_replace('/\D/', '', $body['telefono'] ?? '');

if (!$cedula && !$nombre && !$telefono) {
    echo json_encode(['ok'=>false,'razon'=>'sin_datos']);
    exit;
}

// ── CONSULTA SUPABASE ────────────────────────────────────
// Buscar por cédula O teléfono (OR en Supabase REST API)
$filtros = [];
if ($cedula)   $filtros[] = '"Cedula Pacientes".eq.' . urlencode($cedula);
if ($telefono) $filtros[] = '"Telefono".eq.'         . urlencode($telefono);
if ($telefono) $filtros[] = '"Telefono 2".eq.'       . urlencode($telefono);

$or_query = 'or=(' . implode(',', $filtros) . ')';
$url = "$SB_URL/rest/v1/tabla_usuarios?{$or_query}&select=" .
       urlencode('"Cedula Pacientes","Nombre Paciente","Telefono","Telefono 2"') .
       '&limit=5';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_HTTPHEADER     => [
        "apikey: $SB_KEY",
        "Authorization: Bearer $SB_KEY",
        'Accept: application/json',
    ],
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Error de red o Supabase caído → BLOQUEAR por seguridad
if (!$resp || $code >= 500 || $code === 0) {
    http_response_code(503);
    echo json_encode([
        'ok'    => false,
        'razon' => 'servicio_no_disponible',
        'msg'   => 'No fue posible verificar su registro en este momento. Intente nuevamente o comuníquese al 604 322 2432.'
    ]);
    exit;
}

// Respuesta inesperada de Supabase (401, 403, etc.) → BLOQUEAR
if ($code >= 400) {
    http_response_code(503);
    echo json_encode([
        'ok'    => false,
        'razon' => 'error_autorizacion',
        'msg'   => 'No fue posible verificar su registro. Comuníquese al 604 322 2432.'
    ]);
    exit;
}

$rows = json_decode($resp, true);
if (!$rows || count($rows) === 0) {
    echo json_encode(['ok'=>false,'razon'=>'no_encontrado']);
    exit;
}

// ── EVALUAR COINCIDENCIAS ────────────────────────────────
$nombre_primera = explode(' ', $nombre)[0]; // solo primer nombre para comparar

foreach ($rows as $row) {
    $puntos    = 0;
    $dbCedula  = trim($row['Cedula Pacientes'] ?? '');
    $dbNombre  = strtoupper(trim($row['Nombre Paciente']  ?? ''));
    $dbTel1    = preg_replace('/\D/', '', $row['Telefono']   ?? '');
    $dbTel2    = preg_replace('/\D/', '', $row['Telefono 2'] ?? '');

    if ($cedula   && $dbCedula === $cedula)                                  $puntos++;
    if ($nombre   && $nombre_primera && str_contains($dbNombre, $nombre_primera)) $puntos++;
    if ($telefono && ($dbTel1 === $telefono || $dbTel2 === $telefono))       $puntos++;

    if ($puntos >= 2) {
        echo json_encode([
            'ok'     => true,
            'razon'  => 'validado',
            'nombre' => $row['Nombre Paciente'],
            'puntos' => $puntos,
        ]);
        exit;
    }
}

echo json_encode(['ok'=>false,'razon'=>'datos_no_coinciden']);
