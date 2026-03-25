<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$OPENAI_KEY = '__OPENAI_KEY__';
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); echo json_encode(['error'=>'Invalid JSON']); exit; }

$canvas_url = $body['canvas_url'] ?? $body['image_url'] ?? '';
if (!$canvas_url) { echo json_encode(['ok'=>true,'transcripcion'=>'']); exit; }

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['model'=>'gpt-4o','max_tokens'=>500,'messages'=>[['role'=>'user','content'=>[['type'=>'text','text'=>'Transcribe el texto escrito en esta imagen de forma literal y completa.'],['type'=>'image_url','image_url'=>['url'=>$canvas_url]]]]]]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$OPENAI_KEY}", 'Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 30,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) { http_response_code(502); echo json_encode(['error'=>'vision_error','code'=>$code]); exit; }
$data = json_decode($resp, true);
echo json_encode(['ok'=>true,'transcripcion'=>$data['choices'][0]['message']['content']??'']);
