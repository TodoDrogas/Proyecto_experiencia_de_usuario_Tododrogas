<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$path    = $_GET['path'] ?? '';
$allowed = ['radicar-pqr', 'transcribir-audio', 'procesar-canvas'];

if (!in_array($path, $allowed)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ruta no permitida', 'path' => $path]);
    exit;
}

$n8n_url = 'https://n8n.srv1490847.hstgr.cloud/webhook/' . $path;
$body    = file_get_contents('php://input');

$ch = curl_init($n8n_url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(502);
    echo json_encode(['error' => 'curl_error', 'detalle' => $curlError]);
    exit;
}

if (empty($response)) {
    http_response_code(502);
    echo json_encode(['error' => 'respuesta_vacia', 'httpCode' => $httpCode]);
    exit;
}

http_response_code($httpCode);
echo $response;
