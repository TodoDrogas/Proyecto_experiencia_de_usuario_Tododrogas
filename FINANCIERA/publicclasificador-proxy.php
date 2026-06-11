<?php
/**
 * clasificador-proxy.php
 * Proxy seguro OpenAI GPT-4o Vision — clasificador documentos radicación
 * __OPENAI_KEY__ se inyecta automáticamente por deploy.yml
 */

$allowed = ['https://tododrogas.online','https://www.tododrogas.online','http://localhost','http://127.0.0.1'];
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $allowed)) header('Access-Control-Allow-Origin: '.$origin);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['error'=>'Método no permitido']); exit; }

$body = json_decode(file_get_contents('php://input'), true);
if (!$body || empty($body['pages_b64']) || !is_array($body['pages_b64'])) {
    http_response_code(400); echo json_encode(['error'=>'Falta pages_b64 (array)']); exit;
}
if (empty($body['ocr_text'])) {
    http_response_code(400); echo json_encode(['error'=>'Falta ocr_text']); exit;
}

$api_key = '__OPENAI_KEY__';
if (!$api_key || str_contains($api_key, '__')) {
    http_response_code(500); echo json_encode(['error'=>'OPENAI_KEY no configurada']); exit;
}

// ── CONSTRUIR CONTENT: texto OCR + todas las páginas ─────────────────────────
$content = [];

// 1. Contexto OCR primero (texto extraído del PDF)
$content[] = [
    'type' => 'text',
    'text' => "=== TEXTO EXTRAÍDO DEL PDF (OCR) ===\n" . mb_substr($body['ocr_text'], 0, 3000),
];

// 2. Imágenes de todas las páginas
foreach ($body['pages_b64'] as $idx => $b64) {
    $content[] = [
        'type' => 'text',
        'text' => '=== IMAGEN PÁGINA ' . ($idx + 1) . ' ===',
    ];
    $content[] = [
        'type'      => 'image_url',
        'image_url' => [
            'url'    => 'data:image/jpeg;base64,' . $b64,
            'detail' => 'high',  // high para máxima precisión
        ],
    ];
}

// 3. El prompt maestro
$content[] = [
    'type' => 'text',
    'text' => $body['prompt'],
];

$payload = json_encode([
    'model'      => 'gpt-4o',
    'max_tokens' => 400,
    'temperature'=> 0,   // 0 = máximo determinismo, sin creatividad
    'messages'   => [['role'=>'user','content'=>$content]],
]);

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer '.$api_key,
    ],
]);
$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);
curl_close($ch);

if ($curl_err)        { http_response_code(502); echo json_encode(['error'=>'Red: '.$curl_err]); exit; }
if ($http_code!==200) { http_response_code($http_code); echo $response; exit; }

$data = json_decode($response, true);
$text = $data['choices'][0]['message']['content'] ?? '';
echo json_encode(['text'=>$text]);
