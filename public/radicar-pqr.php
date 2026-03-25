<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$SB_URL = 'https://lyosqaqhiwhgvjigvqtc.supabase.co';
$SB_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Imx5b3NxYXFoaXdoZ3ZqaWd2cXRjIiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImlhdCI6MTc3Mzg0MDEyNSwiZXhwIjoyMDg5NDE2MTI1fQ.IoFTm2ffiwcH9ENjB4GKZV9HWN48fIviUIb6tgGrOnA';

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); echo json_encode(['error'=>'Invalid JSON']); exit; }

$fecha     = date('Ymd');
$rand      = str_pad(rand(1000,9999), 4, '0', STR_PAD_LEFT);
$ticket_id = "TD-{$fecha}-{$rand}";
$now       = date('c');
$nombre    = $body['nombre']      ?? '';
$correo    = $body['correo']      ?? '';

$payload = [
    'ticket_id'         => $ticket_id,
    'from_email'        => $correo,
    'from_name'         => $nombre,
    'nombre'            => $nombre,
    'correo'            => $correo,
    'telefono_contacto' => $body['telefono']    ?? '',
    'subject'           => "[{$ticket_id}] PQR - {$nombre}",
    'descripcion'       => $body['descripcion'] ?? '',
    'body_preview'      => $body['descripcion'] ?? '',
    'body_content'      => $body['descripcion'] ?? '',
    'body_type'         => 'text',
    'tipo_pqr'          => $body['tipo_pqr']    ?? 'peticion',
    'canal_contacto'    => $body['canal']       ?? 'formulario_web',
    'origen'            => 'formulario_web',
    'transcripcion'     => $body['transcripcion'] ?? null,
    'audio_url'         => $body['audio_url']   ?? null,
    'canvas_url'        => $body['canvas_url']  ?? null,
    'estado'            => 'pendiente',
    'prioridad'         => 'media',
    'has_attachments'   => false,
    'is_read'           => false,
    'received_at'       => $now,
    'created_at'        => $now,
    'updated_at'        => $now,
];

$ch = curl_init("{$SB_URL}/rest/v1/correos");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["apikey: {$SB_KEY}", "Authorization: Bearer {$SB_KEY}", 'Content-Type: application/json', 'Prefer: resolution=merge-duplicates,return=representation'],
    CURLOPT_TIMEOUT        => 15,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code >= 400) { http_response_code(502); echo json_encode(['error'=>'supabase_error','code'=>$code,'detalle'=>$resp]); exit; }
echo json_encode(['ok'=>true,'radicado'=>$ticket_id,'ticket_id'=>$ticket_id,'mensaje'=>"Tu solicitud fue recibida. Radicado: {$ticket_id}"]);
