<?php
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://tododrogas.online', 'https://www.tododrogas.online'];
if (!in_array($origin, $allowed)) { http_response_code(403); echo json_encode(['ok'=>false,'razon'=>'origen_no_permitido']); exit; }
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$SB_URL = rtrim('__SB_URL__', '/');
$SB_KEY = '__SB_KEY__';

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); echo json_encode(['ok'=>false,'razon'=>'invalid_json']); exit; }

$cedula = preg_replace('/\D/', '', trim($body['cedula'] ?? ''));
if (!$cedula) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'razon'=>'cedula_requerida']);
    exit;
}

function sbRpc($sb_url, $sb_key, $fn, $params) {
    $ch = curl_init($sb_url . '/rest/v1/rpc/' . $fn);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'apikey: '               . $sb_key,
            'Authorization: Bearer ' . $sb_key,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'rows' => json_decode($resp, true)];
}

// Usar RPC nueva que devuelve TODOS los campos incluyendo Direccion
$r = sbRpc($SB_URL, $SB_KEY, 'buscar_paciente_admin', ['p_cedula' => $cedula]);

// Fallback a RPC original si la nueva no existe aún
if ($r['code'] !== 200 || !is_array($r['rows']) || count($r['rows']) === 0) {
    $r = sbRpc($SB_URL, $SB_KEY, 'buscar_paciente', ['p_cedula' => $cedula]);
}

if ($r['code'] !== 200 || !is_array($r['rows']) || count($r['rows']) === 0) {
    echo json_encode(['ok' => false, 'razon' => 'no_encontrado']);
    exit;
}

$row = $r['rows'][0];

// Soportar tanto la RPC nueva (aliases en minúscula) como la original (nombres reales)
echo json_encode([
    'ok'            => true,
    'tipo_documento'=> $row['tipo_documento'] ?? $row['Tipo De Documento'] ?? '',
    'cedula'        => $row['cedula']         ?? $row['Cedula Pacientes']  ?? '',
    'nombre'        => $row['nombre']         ?? $row['Nombre Paciente']   ?? '',
    'direccion'     => $row['direccion']      ?? $row['Direccion']         ?? '',
    'telefono'      => $row['telefono']       ?? $row['Telefono']          ?? '',
    'departamento'  => $row['departamento']   ?? $row['Departamento']      ?? '',
    'ciudad'        => $row['ciudad']         ?? $row['Ciudad']            ?? '',
    'eps'           => $row['eps']            ?? $row['EPS']               ?? '',
]);
