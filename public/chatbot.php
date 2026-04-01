<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$OPENAI_KEY = '__OPENAI_KEY__';
$SB_URL     = '__SB_URL__';
$SB_KEY     = '__SB_KEY__';

// ══════════════════════════════════════════════════════════════
// MODO WHISPER — detectar por presencia de $_FILES['audio']
// ══════════════════════════════════════════════════════════════
if (!empty($_FILES['audio']['tmp_name'])) {

    $tmpFile  = $_FILES['audio']['tmp_name'];
    $origName = $_FILES['audio']['name'] ?? 'audio.webm';
    $fileSize = $_FILES['audio']['size'] ?? 0;
    $language = $_POST['language'] ?? 'es';

    // Validar que llegó audio real
    if ($fileSize < 100) {
        echo json_encode(['text' => '', 'error' => 'Audio demasiado corto']);
        exit;
    }

    // Determinar extensión correcta para Whisper
    // Whisper acepta: mp3, mp4, mpeg, mpga, m4a, wav, webm, ogg
    $ext = 'webm';
    $mime = 'audio/webm';

    // Leer magic bytes para detectar formato real
    $fh = fopen($tmpFile, 'rb');
    $header = bin2hex(fread($fh, 12));
    fclose($fh);

    if (substr($header, 0, 8) === '4f676753') {
        // OggS header
        $ext = 'ogg'; $mime = 'audio/ogg';
    } elseif (substr($header, 8, 8) === '4d344120' || substr($header, 8, 8) === '66747970') {
        // ftyp → mp4/m4a
        $ext = 'mp4'; $mime = 'audio/mp4';
    } elseif (substr($header, 0, 6) === '494433' || substr($header, 0, 4) === 'fffb') {
        // ID3 tag o frame mp3
        $ext = 'mp3'; $mime = 'audio/mpeg';
    } elseif (substr($header, 0, 8) === '52494646') {
        // RIFF → WAV
        $ext = 'wav'; $mime = 'audio/wav';
    }
    // Default: webm (lo que envía Chrome/Edge)

    // Renombrar tmp con extensión correcta para que Whisper lo acepte
    $namedFile = sys_get_temp_dir() . '/nova_audio_' . time() . '.' . $ext;
    copy($tmpFile, $namedFile);

    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$OPENAI_KEY}"],
        CURLOPT_POSTFIELDS     => [
            'file'            => new CURLFile($namedFile, $mime, 'audio.'.$ext),
            'model'           => 'whisper-1',
            'language'        => $language,
            'response_format' => 'json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    @unlink($namedFile);

    if ($code === 200) {
        http_response_code(200);
        echo $resp; // { "text": "..." }
    } else {
        // Devolver error legible
        http_response_code(200); // siempre 200 al JS
        $errData = json_decode($resp, true);
        echo json_encode([
            'text'  => '',
            'error' => $errData['error']['message'] ?? "HTTP $code",
            'debug' => ['size'=>$fileSize,'ext'=>$ext,'curl_err'=>$err]
        ]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════
// MODO CHAT — llamada a GPT para Nova TD
// ══════════════════════════════════════════════════════════════
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); echo json_encode(['error'=>'Invalid JSON']); exit; }

$messages          = $body['messages']          ?? [];
$model             = $body['model']             ?? 'gpt-4o-mini';
$max_tokens        = $body['max_tokens']        ?? 500;
$temperature       = $body['temperature']       ?? 0.7;
$session_id        = $body['session_id']        ?? null;
$eps_usuario       = $body['eps_usuario']       ?? null;
$municipio_usuario = $body['municipio_usuario'] ?? null;
$estado_flujo      = $body['estado_flujo']      ?? 'libre';
$escalado          = $body['escalado']          ?? false;
$contexto_asesor   = $body['contexto_asesor']   ?? null;

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'model'       => $model,
        'max_tokens'  => $max_tokens,
        'messages'    => $messages,
        'temperature' => $temperature,
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer {$OPENAI_KEY}",
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT => 30,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Guardar sesión en Supabase
if ($session_id && $SB_URL && $SB_KEY && $code === 200) {
    $respData = json_decode($resp, true);
    $reply    = $respData['choices'][0]['message']['content'] ?? '';
    $escalado_final = $escalado || str_starts_with(trim($reply), 'ESCALAR');

    $msgs_guardados = array_values(array_filter($messages, fn($m) => $m['role'] !== 'system'));
    $msgs_guardados[] = ['role'=>'assistant','content'=>$reply];

    $sb = curl_init("{$SB_URL}/rest/v1/chatbot_sesiones");
    curl_setopt_array($sb, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'session_id'        => $session_id,
            'eps_usuario'       => $eps_usuario,
            'municipio_usuario' => $municipio_usuario,
            'mensajes'          => $msgs_guardados,
            'estado'            => 'activa',
            'estado_flujo'      => $estado_flujo,
            'escalado'          => $escalado_final,
            'contexto_asesor'   => $escalado_final
                ? ($contexto_asesor ?? "EPS:{$eps_usuario} Municipio:{$municipio_usuario}")
                : null,
            'updated_at'        => date('c'),
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "apikey: {$SB_KEY}",
            "Authorization: Bearer {$SB_KEY}",
            'Content-Type: application/json',
            'Prefer: resolution=merge-duplicates',
        ],
        CURLOPT_TIMEOUT => 5,
    ]);
    curl_exec($sb);
    curl_close($sb);
}

http_response_code($code);
echo $resp;
