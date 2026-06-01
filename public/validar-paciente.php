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

// ── HTTP helper ───────────────────────────────────────────
function sbHttp($sb_url, $sb_key, $path, $method='GET', $post=null) {
    $ch = curl_init($sb_url . '/rest/v1/' . $path);
    $headers = [
        'apikey: '               . $sb_key,
        'Authorization: Bearer ' . $sb_key,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($post);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'rows' => json_decode($resp, true)];
}

// ── BUSCAR EN tabla_usuarios ──────────────────────────────
// Los nombres de columnas con espacios van como %22Nombre%20Col%22 en PostgREST
$SELECT = 'select=' . implode(',', array_map('rawurlencode', [
    'Tipo De Documento',
    'Cedula Pacientes',
    'Nombre Paciente',
    'Direccion',
    'Telefono',
    'Departamento',
    'Ciudad',
    'EPS',
    'Correo',
]));

$row = null;

// Buscar por cédula
if ($cedula) {
    $path = 'tabla_usuarios?' . $SELECT
          . '&' . rawurlencode('Cedula Pacientes') . '=eq.' . rawurlencode($cedula)
          . '&limit=1';
    $r = sbHttp($SB_URL, $SB_KEY, $path);
    if ($r['code'] === 200 && !empty($r['rows'])) {
        $row = $r['rows'][0];
    }
}

// Fallback: buscar por teléfono
if (!$row && $telefono) {
    $path = 'tabla_usuarios?' . $SELECT
          . '&' . rawurlencode('Telefono') . '=eq.' . rawurlencode($telefono)
          . '&limit=1';
    $r = sbHttp($SB_URL, $SB_KEY, $path);
    if ($r['code'] === 200 && !empty($r['rows'])) {
        $row = $r['rows'][0];
    }
}

if ($debug) {
    echo json_encode(['row' => $row, 'cedula' => $cedula, 'telefono' => $telefono], JSON_PRETTY_PRINT);
    exit;
}

if (!$row) {
    echo json_encode(['ok' => false, 'razon' => 'no_encontrado',
        'msg' => 'No encontramos su registro en nuestra base de datos. Verifique sus datos, comuníquese al 604 322 2432 o escríbanos al WhatsApp 304 341 2431.']);
    exit;
}

// ── VERIFICAR VIP — consulta directa a usuarios_vip ──────
$vip_data = null;
if ($cedula) {
    $r_vip = sbHttp($SB_URL, $SB_KEY,
        'usuarios_vip?cedula=eq.' . rawurlencode($cedula) . '&select=cedula,nombre,saludo,eps,ciudad&limit=1'
    );
    if ($r_vip['code'] === 200 && !empty($r_vip['rows'])) {
        $vip_data = $r_vip['rows'][0];
    }
}

// ── RESPUESTA con todos los campos de tabla_usuarios ──────
$resp = [
    'ok'             => true,
    'razon'          => 'validado',
    'nombre'         => $row['Nombre Paciente']   ?? '',
    'tipo_documento' => $row['Tipo De Documento'] ?? '',
    'cedula'         => $row['Cedula Pacientes']  ?? '',
    'direccion'      => $row['Direccion']         ?? '',
    'telefono'       => $row['Telefono']          ?? '',
    'email'          => !empty($row['Correo']) ? $row['Correo'] : ($cedula . '@tododrogas.online'),
    'ciudad'         => $row['Ciudad']            ?? '',
    'departamento'   => $row['Departamento']      ?? '',
    'eps'            => $row['EPS']               ?? '',
];

if ($vip_data) {
    $resp['vip']    = true;
    $resp['saludo'] = $vip_data['saludo'] ?? '';
}

echo json_encode($resp);
