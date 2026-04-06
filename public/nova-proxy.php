<?php
/**
 * nova-proxy.php — Proxy seguro para OpenAI GPT-4o
 * La API key nunca se expone al navegador
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Solo POST']); exit; }

// ── API KEY ────────────────────────────────────────────────
// Se inyecta en deploy via GitHub Secret → __OPENAI_KEY__
$OPENAI_KEY = '__OPENAI_KEY__';

if (!$OPENAI_KEY || $OPENAI_KEY === '__OPENAI_KEY__') {
    http_response_code(500);
    echo json_encode(['error' => 'OpenAI key no configurada']);
    exit;
}

// ── INPUT ──────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!$body || !isset($body['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'messages requerido']);
    exit;
}

$messages  = $body['messages'];
$system    = $body['system']    ?? '';
$max_tok   = min((int)($body['max_tokens'] ?? 1500), 4000);
$model     = 'gpt-4o'; // Siempre el más potente

// Construir payload para OpenAI
$payload = [
    'model'       => $model,
    'max_tokens'  => $max_tok,
    'temperature' => 0.3,
    'messages'    => array_merge(
        $system ? [['role' => 'system', 'content' => $system]] : [],
        $messages
    ),
];

// ── LLAMAR A OPENAI ────────────────────────────────────────
$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 45,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $OPENAI_KEY,
        'Content-Type: application/json',
    ],
]);

$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($err) {
    http_response_code(502);
    echo json_encode(['error' => 'curl: ' . $err]);
    exit;
}

// Pasar la respuesta de OpenAI tal cual al cliente
http_response_code($code);
echo $resp;
