<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$OPENAI_KEY = '__OPENAI_KEY__';

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); echo json_encode(['error'=>'Invalid JSON']); exit; }

$ticket_id   = $body['correo_id']    ?? '';
$asunto      = $body['asunto']       ?? '';
$contenido   = $body['body_content'] ?? '';
$tipo_pqr    = $body['tipo_pqr']     ?? '';
$categoria   = $body['categoria_ia'] ?? '';
$prioridad   = $body['prioridad']    ?? 'media';
$ley         = $body['ley_aplicable'] ?? 'Ley 1755/2015';
$nivel_riesgo= $body['nivel_riesgo'] ?? '';
$horas_sla   = $body['horas_sla']    ?? 120;

$prompt = "Eres Nova TD, asistente de atención al ciudadano de Tododrogas CIA SAS.
Redacta una respuesta profesional y empática para la siguiente PQR.

DATOS: Tipo: {$tipo_pqr} | Categoría: {$categoria} | Prioridad: {$prioridad} | Riesgo: {$nivel_riesgo} | Ley: {$ley} | SLA: {$horas_sla}h
ASUNTO: {$asunto}
CONTENIDO: {$contenido}

Respuesta formal en español, máximo 3 párrafos. Saludo, respuesta y cierre con compromiso.";

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['model'=>'gpt-4o-mini','max_tokens'=>500,'messages'=>[['role'=>'system','content'=>'Eres Nova TD de Tododrogas.'],['role'=>'user','content'=>$prompt]]]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$OPENAI_KEY}", 'Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 30,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) { http_response_code(502); echo json_encode(['error'=>'openai_error','code'=>$code]); exit; }
$data = json_decode($resp, true);
echo json_encode(['ok'=>true,'respuesta'=>$data['choices'][0]['message']['content']??'','correo_id'=>$ticket_id]);
