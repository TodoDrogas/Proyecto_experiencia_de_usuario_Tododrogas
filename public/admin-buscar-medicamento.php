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

$codigo = trim($body['codigo'] ?? '');
if (!$codigo) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'razon'=>'codigo_requerido']);
    exit;
}

// Buscar en tabla medicamentos — columnas: "Codigo", "Nombre", "Articulo_PBS"
// Usar REST directo con service_role key para evitar restricciones RLS
$ch = curl_init();
$url = $SB_URL . '/rest/v1/medicamentos?select=*&limit=1';

// Agregar filtro con columna en mayúscula (Supabase acepta comillas dobles en header)
$filterHeader = '"Codigo"=eq.' . urlencode($codigo);

curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER     => [
        'apikey: '               . $SB_KEY,
        'Authorization: Bearer ' . $SB_KEY,
        'Content-Type: application/json',
        'Accept: application/json',
        'Prefer: count=none',
    ],
    // Pasar el filtro como query param adicional
    CURLOPT_URL => $SB_URL . '/rest/v1/medicamentos?select=*&limit=1&' . $filterHeader,
]);

$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$rows = json_decode($resp, true);

if ($code !== 200 || !is_array($rows) || count($rows) === 0) {
    // Intentar búsqueda parcial por nombre si el código no existe exacto
    $ch2 = curl_init();
    curl_setopt_array($ch2, [
        CURLOPT_URL            => $SB_URL . '/rest/v1/medicamentos?select=*&limit=10&"Codigo"=ilike.' . urlencode('%' . $codigo . '%'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'apikey: '               . $SB_KEY,
            'Authorization: Bearer ' . $SB_KEY,
            'Content-Type: application/json',
        ],
    ]);
    $resp2 = curl_exec($ch2);
    $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    $rows = json_decode($resp2, true);

    if ($code2 !== 200 || !is_array($rows) || count($rows) === 0) {
        echo json_encode(['ok' => false, 'razon' => 'no_encontrado']);
        exit;
    }
}

$med = $rows[0];

// Normalizar PBS: "Sí"/"Si"/"SI" → "SI", cualquier otra → "NO"
$pbsRaw = strtoupper(trim($med['Articulo_PBS'] ?? ''));
$pbs    = (str_starts_with($pbsRaw, 'S')) ? 'SI' : 'NO';

echo json_encode([
    'ok'          => true,
    'codigo'      => $med['Codigo']      ?? '',
    'nombre'      => $med['Nombre']      ?? '',
    'articulo_pbs'=> $med['Articulo_PBS']?? '',
    'pbs'         => $pbs,
    // Si hay múltiples resultados (búsqueda parcial) devolver todos para el buscador
    'resultados'  => array_map(fn($r) => [
        'codigo' => $r['Codigo'] ?? '',
        'nombre' => $r['Nombre'] ?? '',
        'pbs'    => strtoupper(str_starts_with(strtoupper(trim($r['Articulo_PBS']??'')), 'S') ? 'SI' : 'NO'),
    ], $rows),
]);
