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

$cedula   = preg_replace('/\D/', '', trim($body['cedula']   ?? '')); // solo dígitos
$nombre   = strtoupper(trim($body['nombre']   ?? ''));
$telefono = preg_replace('/\D/', '', trim($body['telefono'] ?? ''));
$debug    = !empty($body['debug']);

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
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code'=>$code, 'rows'=>json_decode($resp,true), 'raw'=>$resp, 'err'=>$err];
}

// ── BUSCAR ────────────────────────────────────────────────
$r_ced = $cedula   ? sbRpc($SB_URL, $SB_KEY, 'buscar_paciente',     ['p_cedula'   => $cedula])   : null;
$r_tel = $telefono ? sbRpc($SB_URL, $SB_KEY, 'buscar_paciente_tel', ['p_telefono' => $telefono]) : null;

if ($debug) {
    echo json_encode(['sb_url'=>$SB_URL,'cedula'=>$cedula,'nombre'=>$nombre,'telefono'=>$telefono,'rpc_ced'=>$r_ced,'rpc_tel'=>$r_tel], JSON_PRETTY_PRINT);
    exit;
}

$rows = null;
if ($r_ced && $r_ced['code']===200 && is_array($r_ced['rows']) && count($r_ced['rows'])>0)
    $rows = $r_ced['rows'];
if (!$rows && $r_tel && $r_tel['code']===200 && is_array($r_tel['rows']) && count($r_tel['rows'])>0)
    $rows = $r_tel['rows'];

if (!$rows) {
    echo json_encode(['ok'=>false,'razon'=>'no_encontrado','msg'=>'No encontramos su registro en nuestra base de datos. Verifique sus datos, comuníquese al 604 322 2432 o escríbanos al WhatsApp 304 341 2431.']);
    exit;
}

// ── EVALUAR 2 DE 3 con tolerancia ────────────────────────
// Nombre: comparar solo primeras 4 letras del primer token (tolerante a errores)
$partes   = array_values(array_filter(explode(' ', $nombre)));
$tok1     = $partes[0] ?? '';                        // primer nombre
$tok1_4   = substr($tok1, 0, 4);                     // primeras 4 letras
$tok2     = $partes[1] ?? '';                        // segundo nombre (si existe)

foreach ($rows as $row) {
    $puntos = 0;
    $dbCed  = preg_replace('/\D/', '', $row['Cedula Pacientes'] ?? '');
    $dbNom  = strtoupper(trim($row['Nombre Paciente'] ?? ''));
    $dbTel1 = preg_replace('/\D/', '', $row['Telefono']   ?? '');
    $dbTel2 = preg_replace('/\D/', '', $row['Telefono 2'] ?? '');

    // CÉDULA: exacta (solo dígitos, sin espacios ni guiones)
    if ($cedula && $dbCed === $cedula) $puntos++;

    // NOMBRE: al menos las primeras 4 letras del primer nombre coinciden
    // Ej: "LUNA" encuentra "LUNA NAZARETH URDANETA GONZALEZ" ✅
    // Ej: "LUN" (3 letras) también funciona si están en la BD
    if ($tok1_4 && strlen($tok1_4) >= 3 && str_contains($dbNom, $tok1_4)) $puntos++;

    // TELÉFONO: exacto (solo dígitos)
    // Si el campo en BD está vacío o es EMPTY, no suma ni resta
    if ($telefono && $dbTel1 && strlen($dbTel1) >= 7 && $dbTel1 === $telefono) $puntos++;
    if ($telefono && $dbTel2 && strlen($dbTel2) >= 7 && $dbTel2 === $telefono) $puntos++;

    if ($puntos >= 2) {
        echo json_encode(['ok'=>true,'razon'=>'validado','nombre'=>$row['Nombre Paciente']]);
        exit;
    }
}

echo json_encode(['ok'=>false,'razon'=>'datos_no_coinciden','msg'=>'Sus datos no coinciden con ningún registro. Verifique cédula, nombre y teléfono, comuníquese al 604 322 2432 o escríbanos al WhatsApp 304 341 2431.']);
