<?php
/**
 * login.php — Tododrogas
 * Valida credenciales admin y carga agentes desde el servidor.
 * Las credenciales NUNCA salen al browser.
 */

$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://tododrogas.online', 'https://www.tododrogas.online'];
if ($origin && !in_array($origin, $allowed)) {
    http_response_code(403);
    echo json_encode(['error' => 'Origen no permitido']);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
if ($origin) header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$SB_URL    = '__SB_URL__';
$SB_KEY    = '__SB_KEY__';
$ADMIN_USER = '__ADMIN_USER__';
$ADMIN_PASS = '__ADMIN_PASS__';

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// ── LOGIN ADMIN ───────────────────────────────────────────────────────
if ($action === 'login_admin') {
    $user = trim($body['user'] ?? '');
    $pass = $body['pass'] ?? '';
    if ($user === $ADMIN_USER && $pass === $ADMIN_PASS) {
        echo json_encode(['ok' => true, 'role' => 'admin', 'name' => 'Administrador']);
    } else {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Usuario o contraseña incorrectos']);
    }
    exit;
}

// ── CARGAR AGENTES ────────────────────────────────────────────────────
if ($action === 'load_agents') {
    $ch = curl_init("$SB_URL/rest/v1/agentes?activo=eq.true&order=nombre.asc");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "apikey: $SB_KEY",
            "Authorization: Bearer $SB_KEY",
            'Accept: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) {
        $agents = json_decode($resp, true) ?? [];
        // Devolver solo id y nombre — el PIN nunca sale al browser
        $safe = array_map(fn($a) => ['id' => $a['id'], 'nombre' => $a['nombre']], $agents);
        echo json_encode(['ok' => true, 'agents' => $safe]);
    } else {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'Error cargando agentes']);
    }
    exit;
}

// ── LOGIN AGENTE ──────────────────────────────────────────────────────
if ($action === 'login_agent') {
    $agent_id = $body['agent_id'] ?? '';
    $pin      = $body['pin']      ?? '';

    if (!$agent_id || strlen($pin) !== 4) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Datos incompletos']);
        exit;
    }

    $ch = curl_init("$SB_URL/rest/v1/agentes?id=eq.$agent_id&activo=eq.true&select=id,nombre,pin");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "apikey: $SB_KEY",
            "Authorization: Bearer $SB_KEY",
            'Accept: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $agents = json_decode($resp, true) ?? [];
    if ($code === 200 && !empty($agents) && $agents[0]['pin'] === $pin) {
        echo json_encode(['ok' => true, 'role' => 'agente', 'id' => $agents[0]['id'], 'name' => $agents[0]['nombre']]);
    } else {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'PIN incorrecto']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Acción no reconocida']);
