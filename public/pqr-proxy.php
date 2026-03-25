<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$path    = $_GET['path'] ?? 'radicar-pqr';
$allowed = ['radicar-pqr', 'pqr-recepcion', 'transcribir-audio', 'procesar-canvas'];

if (!in_array($path, $allowed)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ruta no permitida']);
    exit;
}

$map = ['radicar-pqr' => 'pqr-recepcion'];
$n8n_path = $map[$path] ?? $path;
$n8n_url  = 'https://n8n.srv1490847.hstgr.cloud/webhook/' . $n8n_path;

$body = file_get_contents('php://input');

$ch = curl_init($n8n_url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 30,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error    = curl_error($ch);
curl_close($ch);

if (empty($response)) {
    echo json_encode([
        'error'    => 'n8n devolvio respuesta vacia',
        'httpCode' => $httpCode,
        'curlError'=> $error,
        'n8n_url'  => $n8n_url
    ]);
    exit;
}

http_response_code($httpCode);
echo $response;
