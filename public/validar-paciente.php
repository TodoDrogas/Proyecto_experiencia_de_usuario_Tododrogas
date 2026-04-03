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
$debug_mode = !empty($body['debug']);

function sbRaw($sb_url, $sb_key, $path) {
    $ch = curl_init("$sb_url/rest/v1/$path");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "apikey: $sb_key",
            "Authorization: Bearer $sb_key",
            'Accept: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code'=>$code, 'rows'=>json_decode($resp,true), 'raw'=>$resp];
}

if ($debug_mode) {
    // Probar 4 formatos distintos de query para columnas con espacios
    $tests = [
        // Formato A: comillas dobles literales en la URL (sin encode)
        'A_comillas_literales' => 'tabla_usuarios?select=*&"Cedula Pacientes"=eq.'.$cedula.'&limit=2',

        // Formato B: nombre de columna con espacio encodificado como %20
        'B_espacio_encodificado' => 'tabla_usuarios?select=*&Cedula%20Pacientes=eq.'.$cedula.'&limit=2',

        // Formato C: usando el operador eq. con comillas dentro del valor del parámetro
        'C_eq_operator' => 'tabla_usuarios?select=*&"Cedula+Pacientes"=eq.'.$cedula.'&limit=2',

        // Formato D: primer registro de la tabla (para verificar que la conexión funciona)
        'D_primer_registro' => 'tabla_usuarios?select=*&limit=1',
    ];

    $results = [];
    foreach ($tests as $key => $path) {
        $r = sbRaw($SB_URL, $SB_KEY, $path);
        $results[$key] = [
            'code'     => $r['code'],
            'count'    => is_array($r['rows']) ? count($r['rows']) : 0,
            'primera_cedula' => $r['rows'][0]['Cedula Pacientes'] ?? ($r['rows'][0] ? array_keys($r['rows'][0]) : 'sin_rows'),
            'raw_snippet' => substr($r['raw'] ?? '', 0, 200),
        ];
    }
    echo json_encode(['debug'=>true, 'cedula'=>$cedula, 'results'=>$results], JSON_PRETTY_PRINT);
    exit;
}

// ── LÓGICA REAL (se activa después del debug) ─────────────
echo json_encode(['ok'=>false,'razon'=>'modo_debug_requerido']);
