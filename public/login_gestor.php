<?php
define('USUARIO_GESTOR', '__USUARIO_GESTOR__');
define('PASS_GESTOR',    '__PASS_GESTOR__');

header('Content-Type: application/json; charset=utf-8');

$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://tododrogas.online', 'https://www.tododrogas.online'];
if ($origin && in_array($origin, $allowed)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$body   = json_decode(file_get_contents('php://input'), true);
$action = $body['action'] ?? '';

if ($action === 'login_admin') {
    $user = trim($body['user'] ?? '');
    $pass = $body['pass'] ?? '';

    if ($user === USUARIO_GESTOR && $pass === PASS_GESTOR) {
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(401);
        echo json_encode(['ok' => false]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Acción inválida']);
