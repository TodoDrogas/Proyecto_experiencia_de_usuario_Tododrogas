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

// ── API KEY (inyectada por deploy.yml desde GitHub Secrets) ──
$OPENAI_KEY = '__OPENAI_KEY__';

if (!$OPENAI_KEY || strlen($OPENAI_KEY) < 20) {
    http_response_code(500);
    echo json_encode(['error' => 'OpenAI key no configurada en el servidor']);
    exit;
}

// ── INPUT ──────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON inválido']);
    exit;
}

$action = $body['action'] ?? 'chat';

// ── MODO WHISPER: Transcripción de audio ──────────────────
if ($action === 'whisper') {
    $audio_b64 = $body['audio_base64'] ?? '';
    $mime_type = $body['mime_type']    ?? 'audio/webm';
    if (!$audio_b64) {
        http_response_code(400);
        echo json_encode(['error' => 'audio_base64 requerido']);
        exit;
    }
    $audio_data = base64_decode($audio_b64);
    if (!$audio_data || strlen($audio_data) < 100) {
        http_response_code(400);
        echo json_encode(['error' => 'audio inválido']);
        exit;
    }
    $ext = match(true) {
        str_contains($mime_type, 'webm') => 'webm',
        str_contains($mime_type, 'mp4')  => 'mp4',
        str_contains($mime_type, 'ogg')  => 'ogg',
        str_contains($mime_type, 'wav')  => 'wav',
        default => 'webm',
    };
    $tmp = tempnam(sys_get_temp_dir(), 'ntd_') . '.' . $ext;
    file_put_contents($tmp, $audio_data);
    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $OPENAI_KEY],
        CURLOPT_POSTFIELDS     => [
            'file'     => new CURLFile($tmp, $mime_type, 'audio.' . $ext),
            'model'    => 'whisper-1',
            'language' => 'es',
            'prompt'   => 'Tododrogas, COOSALUD, SAVIA, SAVIA SALUD, SALUD TOTAL, NUEVA EPS, EPS, medicamentos, fórmula médica, dispensación, PQRSFD, reclamar, municipio, Antioquia, Colombia, cédula, afiliado, sede, punto de dispensación, autorización, historia clínica, Medellín, Turbo, Apartadó, Caucasia, Rionegro, Yarumal, Segovia, radicado, asesor, pendiente, entrega, solicitud.',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmp);
    $data = json_decode($resp, true);
    echo json_encode(['text' => $data['text'] ?? '', 'code' => $code]);
    exit;
}

// ── MODO CHAT: GPT ─────────────────────────────────────────
if (!isset($body['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'messages requerido']);
    exit;
}

$messages  = $body['messages'];
$system    = $body['system']    ?? '';
$max_tok   = min((int)($body['max_tokens'] ?? 1500), 4000);
$model     = 'gpt-4o';

$payload = [
    'model'       => $model,
    'max_tokens'  => $max_tok,
    'temperature' => 0.3,
    'messages'    => array_merge(
        $system ? [['role' => 'system', 'content' => $system]] : [],
        $messages
    ),
];

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

http_response_code($code);
echo $resp;
