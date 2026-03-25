<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$OPENAI_KEY = '__OPENAI_KEY__';
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); echo json_encode(['error'=>'Invalid JSON']); exit; }

$audio_url = $body['audio_url'] ?? '';
if (!$audio_url) { echo json_encode(['ok'=>true,'transcripcion'=>'']); exit; }

$tmpFile = tempnam(sys_get_temp_dir(), 'audio_') . '.webm';
file_put_contents($tmpFile, file_get_contents($audio_url));

$ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$OPENAI_KEY}"],
    CURLOPT_POSTFIELDS     => ['file'=>new CURLFile($tmpFile,'audio/webm','audio.webm'),'model'=>'whisper-1','language'=>'es'],
    CURLOPT_TIMEOUT        => 60,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
unlink($tmpFile);

if ($code !== 200) { http_response_code(502); echo json_encode(['error'=>'whisper_error','code'=>$code]); exit; }
$data = json_decode($resp, true);
echo json_encode(['ok'=>true,'transcripcion'=>$data['text']??'']);
