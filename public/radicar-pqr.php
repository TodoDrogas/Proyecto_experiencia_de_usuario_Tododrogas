<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$SB_URL = '__SB_URL__';
$SB_KEY = '__SB_KEY__';

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); echo json_encode(['error'=>'Invalid JSON']); exit; }

$fecha     = date('Ymd');
$rand      = str_pad(rand(1000,9999), 4, '0', STR_PAD_LEFT);
$ticket_id = "TD-{$fecha}-{$rand}";
$now       = date('c');

$nombre      = $body['nombre']      ?? '';
$correo      = $body['correo']      ?? '';
$telefono    = $body['telefono']    ?? '';
$descripcion = $body['descripcion'] ?? '';
$tipo_pqr    = $body['tipo_pqr']    ?? 'peticion';

// canal_contacto: usa contacto_preferido si viene del formulario nuevo
// 'whatsapp', 'email', 'formulario_web' son los valores posibles
$canal_contacto = $body['contacto_preferido'] ?? $body['canal'] ?? 'formulario_web';

// confirmacion_correo y sin_correo — para lógica de acuse en F04
$confirmacion_correo = $body['confirmacion_correo'] ?? ($correo ?: null);
$sin_correo          = $body['sin_correo'] ?? empty($correo);

$payload = [
    'ticket_id'         => $ticket_id,
    'from_email'        => $correo ?: ($telefono . '@whatsapp'),
    'from_name'         => $nombre,
    'nombre'            => $nombre,
    'correo'            => $correo ?: null,
    'telefono_contacto' => $telefono,
    'subject'           => "[{$ticket_id}] PQR - {$nombre}",
    'descripcion'       => $descripcion,
    'body_preview'      => mb_substr($descripcion, 0, 200),
    'body_content'      => $descripcion,
    'body_type'         => 'text',
    'tipo_pqr'          => $tipo_pqr,
    'canal_contacto'    => $canal_contacto,
    'origen'            => 'formulario_web',
    'transcripcion'     => $body['transcripcion'] ?? null,
    'audio_url'         => $body['audio_url']     ?? null,
    'canvas_url'        => $body['canvas_url']    ?? null,
    'estado'            => 'pendiente',
    'prioridad'         => 'media',
    'es_urgente'        => false,
    'has_attachments'   => false,
    'is_read'           => false,
    'acuse_enviado'     => null,
    'received_at'       => $now,
    'created_at'        => $now,
    'updated_at'        => $now,
];

$ch = curl_init("{$SB_URL}/rest/v1/correos");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        "apikey: {$SB_KEY}",
        "Authorization: Bearer {$SB_KEY}",
        'Content-Type: application/json',
        'Prefer: resolution=merge-duplicates,return=representation',
    ],
    CURLOPT_TIMEOUT => 15,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($err) {
    http_response_code(502);
    echo json_encode(['error'=>'curl_error','detalle'=>$err]);
    exit;
}

if ($code >= 400) {
    http_response_code(502);
    echo json_encode(['error'=>'supabase_error','code'=>$code,'detalle'=>$resp]);
    exit;
}

// Registrar en historial_eventos
$evento_payload = [
    'correo_id'  => null, // se actualiza abajo con el id real
    'evento'     => 'pqr_recibida',
    'descripcion'=> "PQR recibida vía {$canal_contacto}. Radicado: {$ticket_id}",
    'from_email' => $correo ?: $telefono,
    'subject'    => "[{$ticket_id}] PQR - {$nombre}",
    'datos_extra'=> json_encode([
        'canal_contacto'    => $canal_contacto,
        'sin_correo'        => $sin_correo,
        'confirmacion_correo'=> $confirmacion_correo,
        'ticket_id'         => $ticket_id,
    ]),
    'created_at' => $now,
];

// Obtener el id del correo recién insertado
$inserted = json_decode($resp, true);
if (is_array($inserted) && isset($inserted[0]['id'])) {
    $evento_payload['correo_id'] = $inserted[0]['id'];
    
    $ch2 = curl_init("{$SB_URL}/rest/v1/historial_eventos");
    curl_setopt_array($ch2, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($evento_payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "apikey: {$SB_KEY}",
            "Authorization: Bearer {$SB_KEY}",
            'Content-Type: application/json',
            'Prefer: return=minimal',
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch2);
    curl_close($ch2);
}

http_response_code(200);
echo json_encode([
    'ok'       => true,
    'radicado' => $ticket_id,
    'ticket_id'=> $ticket_id,
    'canal'    => $canal_contacto,
    'mensaje'  => "Tu solicitud fue recibida exitosamente. Radicado: {$ticket_id}",
]);
