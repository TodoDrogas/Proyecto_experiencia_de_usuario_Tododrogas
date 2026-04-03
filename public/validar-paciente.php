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

// ── HELPER: GET a Supabase ────────────────────────────────
function sbGet($sb_url, $sb_key, $query) {
    $url = "$sb_url/rest/v1/tabla_usuarios?$query";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "apikey: $sb_key",
            "Authorization: Bearer $sb_key",
            'Accept: application/json',
            'Content-Type: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $resp];
}

// ── BUSCAR POR CÉDULA ─────────────────────────────────────
// Supabase REST: columnas con espacios usan comillas dobles en el nombre del filtro
// Formato: "Cedula Pacientes"=eq.VALOR
$rows = null;

if ($cedula) {
    // select sin comillas en columnas simples, con comillas en las que tienen espacios
    $q   = 'select=id,"Cedula Pacientes","Nombre Paciente","Telefono","Telefono 2"'
         . '&"Cedula Pacientes"=eq.' . rawurlencode($cedula)
         . '&limit=5';
    $res = sbGet($SB_URL, $SB_KEY, $q);

    if ($res['code'] === 401 || $res['code'] === 403) {
        echo json_encode(['ok'=>false,'razon'=>'error_auth','msg'=>'No fue posible verificar su registro. Comuníquese al 604 322 2432.']);
        exit;
    }
    if ($res['code'] >= 200 && $res['code'] < 300 && $res['body']) {
        $decoded = json_decode($res['body'], true);
        if (is_array($decoded) && count($decoded) > 0) $rows = $decoded;
    }
}

// ── SI NO HAY POR CÉDULA, BUSCAR POR TELÉFONO ────────────
if (!$rows && $telefono) {
    // Buscar en Telefono
    $q1  = 'select=id,"Cedula Pacientes","Nombre Paciente","Telefono","Telefono 2"'
         . '&"Telefono"=eq.' . rawurlencode($telefono)
         . '&limit=5';
    $res1 = sbGet($SB_URL, $SB_KEY, $q1);
    if ($res1['code'] >= 200 && $res1['code'] < 300 && $res1['body']) {
        $d1 = json_decode($res1['body'], true);
        if (is_array($d1) && count($d1) > 0) $rows = $d1;
    }

    // Si no, buscar en Telefono 2
    if (!$rows) {
        $q2  = 'select=id,"Cedula Pacientes","Nombre Paciente","Telefono","Telefono 2"'
             . '&"Telefono 2"=eq.' . rawurlencode($telefono)
             . '&limit=5';
        $res2 = sbGet($SB_URL, $SB_KEY, $q2);
        if ($res2['code'] >= 200 && $res2['code'] < 300 && $res2['body']) {
            $d2 = json_decode($res2['body'], true);
            if (is_array($d2) && count($d2) > 0) $rows = $d2;
        }
    }
}

// ── SIN RESULTADOS ────────────────────────────────────────
if (!$rows) {
    // Verificar si fue error de conexión o simplemente no encontró
    if (!isset($res) && !isset($res1)) {
        echo json_encode(['ok'=>false,'razon'=>'error_conexion','msg'=>'No fue posible verificar su registro en este momento. Intente nuevamente o comuníquese al 604 322 2432.']);
    } else {
        echo json_encode(['ok'=>false,'razon'=>'no_encontrado','msg'=>'No encontramos su registro en nuestra base de datos. Verifique sus datos, comuníquese al 604 322 2432 o escríbanos al WhatsApp 304 341 2431.']);
    }
    exit;
}

// ── EVALUAR COINCIDENCIAS (2 de 3) ────────────────────────
$nombre_tok = '';
if ($nombre) {
    $partes = array_filter(explode(' ', $nombre));
    // Usar los dos primeros tokens para mayor precisión
    $nombre_tok = implode(' ', array_slice(array_values($partes), 0, 2));
}

foreach ($rows as $row) {
    $puntos   = 0;
    $dbCedula = trim($row['Cedula Pacientes'] ?? '');
    $dbNombre = strtoupper(trim($row['Nombre Paciente'] ?? ''));
    $dbTel1   = preg_replace('/\D/', '', $row['Telefono']   ?? '');
    $dbTel2   = preg_replace('/\D/', '', $row['Telefono 2'] ?? '');

    // Punto 1: cédula exacta
    if ($cedula && $dbCedula === $cedula) $puntos++;

    // Punto 2: nombre — primer token (mín 3 chars) contenido en nombre BD
    $tok1 = $nombre ? explode(' ', $nombre)[0] : '';
    if ($tok1 && strlen($tok1) >= 3 && str_contains($dbNombre, $tok1)) $puntos++;

    // Punto 3: teléfono — ignorar valores vacíos o "EMPTY"
    $tel1_ok = $dbTel1 && strtoupper($dbTel1) !== 'EMPTY' && strlen($dbTel1) >= 7;
    $tel2_ok = $dbTel2 && strtoupper($dbTel2) !== 'EMPTY' && strlen($dbTel2) >= 7;
    if ($telefono && $tel1_ok && $dbTel1 === $telefono) $puntos++;
    if ($telefono && $tel2_ok && $dbTel2 === $telefono) $puntos++;

    if ($puntos >= 2) {
        echo json_encode(['ok'=>true,'razon'=>'validado','nombre'=>$row['Nombre Paciente']]);
        exit;
    }
}

// Encontró filas pero ninguna pasó 2/3
echo json_encode([
    'ok'    => false,
    'razon' => 'datos_no_coinciden',
    'msg'   => 'Sus datos no coinciden con ningún registro. Verifique cédula, nombre y teléfono, comuníquese al 604 322 2432 o escríbanos al WhatsApp 304 341 2431.',
]);
