<?php
/**
 * nova-proxy.php — Proxy seguro para Nova TD
 * Maneja: Whisper (transcripción) + GPT (chat) + TTS (voz)
 */

// ── CORS ──────────────────────────────────────────────────────────────
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://tododrogas.online','https://www.tododrogas.online',
            'http://localhost','http://127.0.0.1'];
$originOk = empty($origin) || in_array($origin, $allowed);
if (!$originOk) { http_response_code(403); echo json_encode(['error'=>'Origen no permitido']); exit; }
header('Content-Type: application/json; charset=utf-8');
if (!empty($origin)) header('Access-Control-Allow-Origin: '.$origin);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Nova-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── TOKEN ─────────────────────────────────────────────────────────────
$NOVA_TOKEN  = '__NOVA_TOKEN__';    // reemplaza con tu token
$OPENAI_KEY  = '__OPENAI_KEY__';    // reemplaza con tu key

$token = $_SERVER['HTTP_X_NOVA_TOKEN'] ?? '';
if ($NOVA_TOKEN !== '__NOVA_TOKEN__' && $token !== $NOVA_TOKEN) {
    http_response_code(401); echo json_encode(['error'=>'Token inválido']); exit;
}

// ── INPUT ─────────────────────────────────────────────────────────────
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? 'chat';

// ══════════════════════════════════════════════════════════════════════
// ACCIÓN: WHISPER — transcripción de audio
// ══════════════════════════════════════════════════════════════════════
if ($action === 'whisper') {

    $audio_b64 = $body['audio_base64'] ?? '';
    $mime_type = $body['mime_type']    ?? 'audio/webm';
    $prompt    = $body['prompt']       ?? '';

    if (!$audio_b64) {
        http_response_code(400); echo json_encode(['error'=>'audio_base64 requerido']); exit;
    }

    // Decodificar audio de base64
    $audio_data = base64_decode($audio_b64);
    if (!$audio_data) {
        http_response_code(400); echo json_encode(['error'=>'Audio base64 inválido']); exit;
    }

    // Determinar extensión según mime type
    $ext_map = [
        'audio/webm'       => 'webm',
        'audio/webm;codecs=opus' => 'webm',
        'audio/ogg'        => 'ogg',
        'audio/mp4'        => 'mp4',
        'audio/mpeg'       => 'mp3',
        'audio/wav'        => 'wav',
        'audio/x-wav'      => 'wav',
    ];
    $ext = $ext_map[strtolower($mime_type)] ?? 'webm';

    // Guardar audio en archivo temporal
    $tmpFile = sys_get_temp_dir() . '/nova_audio_' . uniqid() . '.' . $ext;
    file_put_contents($tmpFile, $audio_data);

    // ── Llamar a Whisper API con multipart/form-data ──────────────────
    // CRÍTICO: Whisper requiere multipart, no JSON
    $boundary = '----NovaBoundary' . uniqid();

    // Construir multipart body manualmente para cURL
    $postData = '';

    // Campo: model
    $postData .= "--{$boundary}\r\n";
    $postData .= "Content-Disposition: form-data; name=\"model\"\r\n\r\n";
    $postData .= "whisper-1\r\n";

    // Campo: language = "es" — FORZAR español colombiano
    $postData .= "--{$boundary}\r\n";
    $postData .= "Content-Disposition: form-data; name=\"language\"\r\n\r\n";
    $postData .= "es\r\n";

    // Campo: temperature = 0 — ELIMINA alucinaciones
    $postData .= "--{$boundary}\r\n";
    $postData .= "Content-Disposition: form-data; name=\"temperature\"\r\n\r\n";
    $postData .= "0\r\n";

    // Campo: response_format
    $postData .= "--{$boundary}\r\n";
    $postData .= "Content-Disposition: form-data; name=\"response_format\"\r\n\r\n";
    $postData .= "json\r\n";

    // Campo: prompt — vocabulario de dominio (máx 224 tokens)
    if ($prompt) {
        $postData .= "--{$boundary}\r\n";
        $postData .= "Content-Disposition: form-data; name=\"prompt\"\r\n\r\n";
        $postData .= $prompt . "\r\n";
    }

    // Campo: file — el audio
    $postData .= "--{$boundary}\r\n";
    $postData .= "Content-Disposition: form-data; name=\"file\"; filename=\"audio.{$ext}\"\r\n";
    $postData .= "Content-Type: {$mime_type}\r\n\r\n";
    $postData .= $audio_data . "\r\n";
    $postData .= "--{$boundary}--\r\n";

    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$OPENAI_KEY}",
            "Content-Type: multipart/form-data; boundary={$boundary}",
        ],
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    // Limpiar archivo temporal
    @unlink($tmpFile);

    if ($err) {
        http_response_code(500);
        echo json_encode(['error' => 'cURL error: '.$err]);
        exit;
    }

    if ($code !== 200) {
        http_response_code($code);
        echo json_encode(['error' => 'Whisper error '.$code, 'detail' => $resp]);
        exit;
    }

    $data = json_decode($resp, true);
    $text = trim($data['text'] ?? '');

    // Post-procesamiento: corregir términos conocidos mal transcritos
    $correcciones = [
        // EPS
        '/\bkuza(lud)?\b/i'           => 'COOSALUD',
        '/\bsabi[ao]\b/i'             => 'SAVIA',
        '/\bnueva e\.?p\.?s\.?\b/i'   => 'NUEVA EPS',
        '/\bpreventi[vb]a\b/i'        => 'PREVENTIVA',
        '/\bsalud total\b/i'          => 'SALUD TOTAL',
        // Términos médicos frecuentes
        '/\bformula\b/i'              => 'fórmula',
        '/\bradicado\b/i'             => 'radicado',
        '/\bdispensacion\b/i'         => 'dispensación',
        '/\btutela\b/i'               => 'tutela',
        '/\bglp\s*1\b/i'              => 'GLP1',
        '/\bpqrs\w*/i'                => 'PQRSFD',
    ];

    foreach ($correcciones as $pattern => $replacement) {
        $text = preg_replace($pattern, $replacement, $text);
    }

    echo json_encode(['text' => $text, 'raw' => $data['text'] ?? ''], JSON_UNESCAPED_UNICODE);
    exit;
}

// ══════════════════════════════════════════════════════════════════════
// ACCIÓN: CHAT — GPT para respuestas de Nova TD
// ══════════════════════════════════════════════════════════════════════
if ($action === 'chat' || !isset($body['action'])) {

    $system   = $body['system']     ?? '';
    $messages = $body['messages']   ?? [];
    $maxTok   = intval($body['max_tokens'] ?? 1500);

    if (empty($messages)) {
        http_response_code(400); echo json_encode(['error'=>'messages requerido']); exit;
    }

    $payload = [
        'model'       => 'gpt-4o-mini',
        'max_tokens'  => min($maxTok, 2000),
        'temperature' => 0.3,
        'messages'    => array_merge(
            $system ? [['role'=>'system','content'=>$system]] : [],
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
            "Authorization: Bearer {$OPENAI_KEY}",
            'Content-Type: application/json',
        ],
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        http_response_code($code);
        echo json_encode(['error'=>'GPT error '.$code, 'detail'=>$resp]);
        exit;
    }

    echo $resp; // reenviar respuesta de OpenAI directo
    exit;
}

// ══════════════════════════════════════════════════════════════════════
// ACCIÓN: TTS — texto a voz (OpenAI shimmer)
// ══════════════════════════════════════════════════════════════════════
if ($action === 'tts') {

    $texto = trim($body['texto'] ?? '');
    if (!$texto) {
        http_response_code(400); echo json_encode(['error'=>'texto requerido']); exit;
    }

    // Limpiar texto para TTS (quitar markdown, emojis)
    $texto = preg_replace('/\*\*(.+?)\*\*/s', '$1', $texto);
    $texto = preg_replace('/[^\p{L}\p{N}\p{P}\s]/u', '', $texto);
    $texto = mb_substr(trim($texto), 0, 4096);

    $ch = curl_init('https://api.openai.com/v1/audio/speech');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model' => 'tts-1',
            'input' => $texto,
            'voice' => 'shimmer',
            'speed' => 1.0,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$OPENAI_KEY}",
            'Content-Type: application/json',
        ],
    ]);

    $audio = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$audio) {
        http_response_code(500);
        echo json_encode(['error'=>'TTS error '.$code]);
        exit;
    }

    echo json_encode([
        'audio_b64' => base64_encode($audio),
        'mime'      => 'audio/mpeg',
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Acción no reconocida: '.$action]);
