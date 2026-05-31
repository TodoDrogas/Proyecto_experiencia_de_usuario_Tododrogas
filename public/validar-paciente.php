<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$SB_URL = rtrim('__SB_URL__', '/');
$SB_KEY = '__SB_KEY__';

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); echo json_encode(['ok'=>false,'razon'=>'invalid_json']); exit; }

$cedula   = preg_replace('/\D/', '', trim($body['cedula']   ?? ''));
$nombre   = strtoupper(trim($body['nombre']   ?? ''));
$telefono = preg_replace('/\D/', '', trim($body['telefono'] ?? ''));
$debug    = !empty($body['debug']);

// ── CONSULTA DIRECTA A tabla_usuarios ─────────────────────
// Evita depender de la RPC buscar_paciente y sus alias de campos
function sbGet($sb_url, $sb_key, $path) {
    $ch = curl_init($sb_url . '/rest/v1/' . $path);
    curl_setopt_array($ch, [
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
    return ['code'=>$code, 'rows'=>json_decode($resp, true)];
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
    return ['code'=>$code, 'rows'=>json_decode($resp, true)];
}

// Buscar en tabla_usuarios directamente por cédula
$row = null;
if ($cedula) {
    $enc = urlencode($cedula);
    $r = sbGet($SB_URL, $SB_KEY,
        'tabla_usuarios?select=Tipo+De+Documento,Cedula+Pacientes,Nombre+Paciente,Direccion,Telefono,Departamento,Ciudad,EPS,Correo'
        . '&Cedula+Pacientes=eq.' . $enc . '&limit=1'
    );
    if ($r['code'] === 200 && is_array($r['rows']) && count($r['rows']) > 0) {
        $row = $r['rows'][0];
    }
}

// Fallback: buscar por teléfono si no encontró por cédula
if (!$row && $telefono) {
    $enc = urlencode($telefono);
    $r = sbGet($SB_URL, $SB_KEY,
        'tabla_usuarios?select=Tipo+De+Documento,Cedula+Pacientes,Nombre+Paciente,Direccion,Telefono,Departamento,Ciudad,EPS,Correo'
        . '&Telefono=eq.' . $enc . '&limit=1'
    );
    if ($r['code'] === 200 && is_array($r['rows']) && count($r['rows']) > 0) {
        $row = $r['rows'][0];
    }
}

if ($debug) {
    echo json_encode(['row' => $row, 'cedula' => $cedula, 'telefono' => $telefono], JSON_PRETTY_PRINT);
    exit;
}

if (!$row) {
    echo json_encode(['ok'=>false,'razon'=>'no_encontrado',
        'msg'=>'No encontramos su registro en nuestra base de datos. Verifique sus datos, comuníquese al 604 322 2432 o escríbanos al WhatsApp 304 341 2431.']);
    exit;
}

// ── VERIFICAR VIP ─────────────────────────────────────────
$vip_data = null;
if ($cedula) {
    $r_vip = sbRpc($SB_URL, $SB_KEY, 'verificar_vip', ['p_cedula' => $cedula]);
    if ($r_vip['code']===200 && is_array($r_vip['rows']) && count($r_vip['rows'])>0) {
        $vip_data = $r_vip['rows'][0];
    }
}

// Mapeo exacto desde los campos reales de tabla_usuarios
$resp = [
    'ok'             => true,
    'razon'          => 'validado',
    'nombre'         => $row['Nombre Paciente']   ?? '',
    'tipo_documento' => $row['Tipo De Documento'] ?? '',
    'cedula'         => $row['Cedula Pacientes']  ?? '',
    'direccion'      => $row['Direccion']         ?? '',
    'telefono'       => $row['Telefono']          ?? '',
    'email'          => $row['Correo']            ?? '',
    'ciudad'         => $row['Ciudad']            ?? '',
    'departamento'   => $row['Departamento']      ?? '',
    'eps'            => $row['EPS']               ?? '',
];

if ($vip_data) {
    $resp['vip']    = true;
    $resp['saludo'] = $vip_data['saludo'];
}

echo json_encode($resp);
