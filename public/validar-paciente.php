<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$SB_URL = '__SB_URL__';
$SB_KEY = '__SB_KEY__';

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); echo json_encode(['ok'=>false,'razon'=>'invalid_json']); exit; }

$cedula   = trim($body['cedula']   ?? '');
$nombre   = strtoupper(trim($body['nombre']   ?? ''));
$telefono = preg_replace('/\D/', '', $body['telefono'] ?? '');

if (!$cedula && !$telefono) {
    echo json_encode(['ok'=>false,'razon'=>'sin_datos','msg'=>'Ingrese cédula o teléfono.']);
    exit;
}

// ── HELPER RPC (llama función SQL en Supabase) ────────────
function sbRpc($sb_url, $sb_key, $fn, $params) {
    $ch = curl_init("$sb_url/rest/v1/rpc/$fn");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "apikey: $sb_key",
            "Authorization: Bearer $sb_key",
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'rows' => json_decode($resp, true)];
}

// ── BUSCAR POR CÉDULA (RPC) ───────────────────────────────
$rows = null;

if ($cedula) {
    $r = sbRpc($SB_URL, $SB_KEY, 'buscar_paciente', ['p_cedula' => $cedula]);
    if ($r['code'] === 200 && is_array($r['rows']) && count($r['rows']) > 0) {
        $rows = $r['rows'];
    }
}

// ── SI NO HAY POR CÉDULA, BUSCAR POR TELÉFONO (RPC) ──────
if (!$rows && $telefono) {
    $r2 = sbRpc($SB_URL, $SB_KEY, 'buscar_paciente_tel', ['p_telefono' => $telefono]);
    if ($r2['code'] === 200 && is_array($r2['rows']) && count($r2['rows']) > 0) {
        $rows = $r2['rows'];
    }
}

// ── SIN RESULTADOS ────────────────────────────────────────
if (!$rows) {
    echo json_encode([
        'ok'    => false,
        'razon' => 'no_encontrado',
        'msg'   => 'No encontramos su registro en nuestra base de datos. Verifique sus datos, comuníquese al 604 322 2432 o escríbanos al WhatsApp 304 341 2431.',
    ]);
    exit;
}

// ── EVALUAR COINCIDENCIAS (2 de 3) ────────────────────────
$tok1 = '';
if ($nombre) {
    $partes = array_values(array_filter(explode(' ', $nombre)));
    $tok1   = $partes[0] ?? '';
}

foreach ($rows as $row) {
    $puntos = 0;
    $dbCed  = trim($row['Cedula Pacientes'] ?? '');
    $dbNom  = strtoupper(trim($row['Nombre Paciente'] ?? ''));
    $dbTel1 = preg_replace('/\D/', '', $row['Telefono']   ?? '');
    $dbTel2 = preg_replace('/\D/', '', $row['Telefono 2'] ?? '');

    if ($cedula   && $dbCed === $cedula)                                   $puntos++;
    if ($tok1     && strlen($tok1) >= 3 && str_contains($dbNom, $tok1))   $puntos++;
    if ($telefono && $dbTel1 && $dbTel1 === $telefono)                    $puntos++;
    if ($telefono && $dbTel2 && $dbTel2 === $telefono)                    $puntos++;

    if ($puntos >= 2) {
        echo json_encode([
            'ok'     => true,
            'razon'  => 'validado',
            'nombre' => $row['Nombre Paciente'],
        ]);
        exit;
    }
}

echo json_encode([
    'ok'    => false,
    'razon' => 'datos_no_coinciden',
    'msg'   => 'Sus datos no coinciden con ningún registro. Verifique cédula, nombre y teléfono, comuníquese al 604 322 2432 o escríbanos al WhatsApp 304 341 2431.',
]);
