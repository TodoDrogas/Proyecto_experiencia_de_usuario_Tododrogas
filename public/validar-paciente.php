<?php
/**
 * validar-paciente.php
 * Valida si un usuario existe en tabla_usuarios (2 de 3: cédula, nombre, teléfono)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── CREDENCIALES (inyectadas por deploy.yml) ──────────────
$SB_URL = '__SB_URL__';
$SB_KEY = '__SB_KEY__';

// ── LEER INPUT ────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); echo json_encode(['ok'=>false,'razon'=>'invalid_json']); exit; }

$cedula   = trim($body['cedula']   ?? '');
$nombre   = strtoupper(trim($body['nombre']   ?? ''));
$telefono = preg_replace('/\D/', '', $body['telefono'] ?? '');

if (!$cedula && !$telefono) {
    echo json_encode(['ok'=>false,'razon'=>'sin_datos','msg'=>'Ingrese cédula o teléfono para continuar.']);
    exit;
}

// ── CONSULTA SUPABASE (mismo patrón que radicar-pqr.php) ──
// Buscar por cédula O teléfono usando el operador OR de Supabase
$params = [];
if ($cedula)   $params[] = '"Cedula Pacientes".eq.' . rawurlencode($cedula);
if ($telefono) $params[] = '"Telefono".eq.'         . rawurlencode($telefono);
if ($telefono) $params[] = '"Telefono 2".eq.'       . rawurlencode($telefono);

$or    = 'or=(' . implode(',', $params) . ')';
$cols  = rawurlencode('"Cedula Pacientes","Nombre Paciente","Telefono","Telefono 2"');
$url   = "$SB_URL/rest/v1/tabla_usuarios?$or&select=$cols&limit=10";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => [
        "apikey: $SB_KEY",
        "Authorization: Bearer $SB_KEY",
        'Accept: application/json',
    ],
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Error de conexión o Supabase caído → bloquear
if (!$resp || $code === 0 || $code >= 500) {
    echo json_encode([
        'ok'    => false,
        'razon' => 'error_conexion',
        'msg'   => 'No fue posible verificar su registro en este momento. Intente nuevamente o comuníquese al 604 322 2432.',
    ]);
    exit;
}

// Error de autenticación → bloquear
if ($code === 401 || $code === 403) {
    echo json_encode([
        'ok'    => false,
        'razon' => 'error_auth',
        'msg'   => 'No fue posible verificar su registro. Comuníquese al 604 322 2432.',
    ]);
    exit;
}

$rows = json_decode($resp, true);

// Sin resultados → no está en la base
if (!$rows || !is_array($rows) || count($rows) === 0) {
    echo json_encode([
        'ok'    => false,
        'razon' => 'no_encontrado',
        'msg'   => 'No encontramos su registro en nuestra base de datos. Verifique sus datos, comuníquese al 604 322 2432 o escríbanos al WhatsApp 304 341 2431.',
    ]);
    exit;
}

// ── EVALUAR COINCIDENCIAS (2 de 3) ────────────────────────
$nombre_tok = $nombre ? explode(' ', $nombre)[0] : ''; // primer token del nombre

foreach ($rows as $row) {
    $puntos   = 0;
    $dbCedula = trim($row['Cedula Pacientes'] ?? '');
    $dbNombre = strtoupper(trim($row['Nombre Paciente'] ?? ''));
    $dbTel1   = preg_replace('/\D/', '', $row['Telefono']   ?? '');
    $dbTel2   = preg_replace('/\D/', '', $row['Telefono 2'] ?? '');

    if ($cedula   && $dbCedula === $cedula)                                        $puntos++;
    if ($nombre_tok && strlen($nombre_tok) >= 3 && str_contains($dbNombre, $nombre_tok)) $puntos++;
    if ($telefono && ($dbTel1 === $telefono || $dbTel2 === $telefono))             $puntos++;

    if ($puntos >= 2) {
        echo json_encode([
            'ok'     => true,
            'razon'  => 'validado',
            'nombre' => $row['Nombre Paciente'],
        ]);
        exit;
    }
}

// Encontró filas pero ninguna pasó el umbral de 2/3
echo json_encode([
    'ok'    => false,
    'razon' => 'datos_no_coinciden',
    'msg'   => 'Sus datos no coinciden con ningún registro. Verifique cédula, nombre y teléfono, comuníquese al 604 322 2432 o escríbanos al WhatsApp 304 341 2431.',
]);
