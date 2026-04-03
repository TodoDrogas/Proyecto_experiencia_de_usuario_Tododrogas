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
// Primero buscar solo por cédula (más preciso)
$encontrado = null;

if ($cedula) {
    $url_ced = "$SB_URL/rest/v1/tabla_usuarios"
             . '?select=' . urlencode('"Cedula Pacientes","Nombre Paciente","Telefono","Telefono 2"')
             . '&"Cedula Pacientes"=eq.' . rawurlencode($cedula)
             . '&limit=5';
    $ch = curl_init($url_ced);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "apikey: $SB_KEY",
            "Authorization: Bearer $SB_KEY",
            'Accept: application/json',
        ],
    ]);
    $resp_ced = curl_exec($ch);
    $code_ced = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp_ced && $code_ced === 200) {
        $rows_ced = json_decode($resp_ced, true);
        if ($rows_ced && count($rows_ced) > 0) $encontrado = $rows_ced;
    }
}

// Si no encontró por cédula, buscar por teléfono
if (!$encontrado && $telefono) {
    $url_tel = "$SB_URL/rest/v1/tabla_usuarios"
             . '?select=' . urlencode('"Cedula Pacientes","Nombre Paciente","Telefono","Telefono 2"')
             . '&or=(' . urlencode('"Telefono".eq.' . $telefono . ',"Telefono 2".eq.' . $telefono) . ')'
             . '&limit=5';
    $ch2 = curl_init($url_tel);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "apikey: $SB_KEY",
            "Authorization: Bearer $SB_KEY",
            'Accept: application/json',
        ],
    ]);
    $resp_tel = curl_exec($ch2);
    $code_tel = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    if ($resp_tel && $code_tel === 200) {
        $rows_tel = json_decode($resp_tel, true);
        if ($rows_tel && count($rows_tel) > 0) $encontrado = $rows_tel;
    }
}

// Sin resultados en ninguna búsqueda
if (!$encontrado) {
    echo json_encode([
        'ok'    => false,
        'razon' => 'no_encontrado',
        'msg'   => 'No encontramos su registro en nuestra base de datos. Verifique sus datos, comuníquese al 604 322 2432 o escríbanos al WhatsApp 304 341 2431.',
    ]);
    exit;
}

$rows = $encontrado;


// ── EVALUAR COINCIDENCIAS (2 de 3) ────────────────────────
$nombre_tok = $nombre ? explode(' ', $nombre)[0] : ''; // primer token del nombre

foreach ($rows as $row) {
    $puntos   = 0;
    $dbCedula = trim($row['Cedula Pacientes'] ?? '');
    $dbNombre = strtoupper(trim($row['Nombre Paciente'] ?? ''));
    $dbTel1   = preg_replace('/\D/', '', $row['Telefono']   ?? '');
    $dbTel2   = preg_replace('/\D/', '', $row['Telefono 2'] ?? '');

    // Punto 1: cédula exacta
    if ($cedula && $dbCedula === $cedula) $puntos++;

    // Punto 2: nombre — al menos el primer token (mín 3 chars) contenido en el nombre BD
    if ($nombre_tok && strlen($nombre_tok) >= 3 && str_contains($dbNombre, $nombre_tok)) $puntos++;

    // Punto 3: teléfono — solo si el valor en BD no es EMPTY ni vacío
    if ($telefono && $dbTel1 && $dbTel1 !== 'EMPTY' && $dbTel1 === $telefono) $puntos++;
    if ($telefono && $dbTel2 && $dbTel2 !== 'EMPTY' && $dbTel2 === $telefono) $puntos++;

    // Si encontró por cédula Y hay nombre → 2 puntos → pasa
    // Si encontró por teléfono Y hay cédula o nombre → 2 puntos → pasa
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
