<?php
/**
 * clasificador-proxy.php — fix validacion pages_b64
 * __OPENAI_KEY__ inyectado por deploy.yml
 */

$allowed = ['https://tododrogas.online','https://www.tododrogas.online','http://localhost','http://127.0.0.1'];
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $allowed)) header('Access-Control-Allow-Origin: '.$origin);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['error'=>'Metodo no permitido']); exit; }

// ── Leer body ────────────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body)) {
    http_response_code(400); echo json_encode(['error'=>'JSON invalido']); exit;
}

$prompt   = $body['prompt']   ?? '';
$ocr_text = $body['ocr_text'] ?? '';
$pages    = $body['pages_b64'] ?? [];

// pages_b64 puede ser array vacío si solo se manda texto — aceptar igual
if (!is_array($pages)) {
    http_response_code(400); echo json_encode(['error'=>'pages_b64 debe ser array']); exit;
}

if (empty($prompt)) {
    http_response_code(400); echo json_encode(['error'=>'Falta prompt']); exit;
}

// ── API KEY ──────────────────────────────────────────────────────────────────
$api_key = '__OPENAI_KEY__';
if (empty($api_key) || str_contains($api_key, '__')) {
    http_response_code(500); echo json_encode(['error'=>'OPENAI_KEY no configurada']); exit;
}

// ── Construir content ────────────────────────────────────────────────────────
$content = [];

// Texto OCR
if (!empty($ocr_text)) {
    $content[] = [
        'type' => 'text',
        'text' => "=== TEXTO OCR DE LA PAGINA ===\n" . mb_substr($ocr_text, 0, 2000),
    ];
}

// Imágenes de páginas
foreach ($pages as $idx => $b64) {
    if (empty($b64)) continue;
    $content[] = [
        'type' => 'text',
        'text' => '=== IMAGEN PAGINA ' . ($idx + 1) . ' ===',
    ];
    $content[] = [
        'type'      => 'image_url',
        'image_url' => [
            'url'    => 'data:image/jpeg;base64,' . $b64,
            'detail' => 'high',
        ],
    ];
}

// Prompt
$content[] = ['type' => 'text', 'text' => $prompt];

// ── Payload OpenAI ───────────────────────────────────────────────────────────
$payload = json_encode([
    'model'       => 'gpt-4o',
    'max_tokens'  => 400,
    'temperature' => 0,
    'messages'    => [['role' => 'user', 'content' => $content]],
], JSON_UNESCAPED_UNICODE);

// ── Llamada ──────────────────────────────────────────────────────────────────
$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key,
    ],
]);
$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);
curl_close($ch);

if ($curl_err)         { http_response_code(502); echo json_encode(['error' => 'Red: '.$curl_err]); exit; }
if ($http_code !== 200){ http_response_code($http_code); echo $response; exit; }

$data = json_decode($response, true);
$text = $data['choices'][0]['message']['content'] ?? '';
echo json_encode(['text' => $text]);
