<?php
/**
 * procesar-canvas.php v2
 * Recibe imagen_base64 del canvas (lápiz virtual)
 * 1. Sube imagen a bucket 'canvas-images' de Supabase Storage
 * 2. Transcribe texto con GPT-4o Vision
 * 3. Actualiza correo en BD: canvas_url, transcripcion, canal_contacto
 * 4. Registra en tabla adjuntos
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

date_default_timezone_set('America/Bogota');

$SB_URL     = '__SB_URL__';
$SB_KEY     = '__SB_KEY__';
$OPENAI_KEY = '__OPENAI_KEY__';

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); echo json_encode(['error'=>'Invalid JSON']); exit; }

$pqr_id      = $body['pqr_id']        ?? '';
$imagen_b64  = $body['imagen_base64'] ?? '';

if (!$pqr_id || !$imagen_b64) {
    http_response_code(400);
    echo json_encode(['error' => 'pqr_id e imagen_base64 requeridos']);
    exit;
}

// Limpiar data URL si viene con prefijo
$img_data_url = $imagen_b64;
$mime = 'image/png';
if (strpos($imagen_b64, 'data:image/') === 0) {
    preg_match('/data:(image\/\w+);base64,(.+)/s', $imagen_b64, $m);
    $mime      = $m[1] ?? 'image/png';
    $imagen_b64 = $m[2] ?? $imagen_b64;
}
$img_binary = base64_decode($imagen_b64);
if (!$img_binary || strlen($img_binary) < 100) {
    http_response_code(400);
    echo json_encode(['error' => 'imagen_base64 inválida']);
    exit;
}

$ext = str_replace('image/', '', $mime);
$ts  = round(microtime(true) * 1000);
$filename = "canvas_{$pqr_id}.{$ext}";
$now = date('c');

// ── 1. Obtener correo_id ─────────────────────────────────────────────
$ch = curl_init("$SB_URL/rest/v1/correos?ticket_id=eq.$pqr_id&select=id,subject");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10,
    CURLOPT_HTTPHEADER=>["apikey: $SB_KEY","Authorization: Bearer $SB_KEY",'Accept: application/json']]);
$res = curl_exec($ch); curl_close($ch);
$correos = json_decode($res, true);
if (empty($correos)) {
    http_response_code(404);
    echo json_encode(['error'=>'Ticket no encontrado']);
    exit;
}
$correo_id = $correos[0]['id'];
$subject_actual = $correos[0]['subject'] ?? '';

// ── 2. Subir a bucket 'canvas-images' ───────────────────────────────
$storage_path = "$correo_id/{$ts}_{$filename}";
$ch = curl_init("$SB_URL/storage/v1/object/canvas-images/$storage_path");
curl_setopt_array($ch, [
    CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$img_binary,
    CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30,
    CURLOPT_HTTPHEADER=>["apikey: $SB_KEY","Authorization: Bearer $SB_KEY",
        "Content-Type: $mime","x-upsert: true"],
]);
$up_resp = curl_exec($ch);
$up_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$canvas_public_url = null;
if ($up_code < 300) {
    $canvas_public_url = "$SB_URL/storage/v1/object/public/canvas-images/$storage_path";
}

// ── 3. GPT-4o Vision → transcribir texto escrito ───────────────────
$transcripcion = '';
$vision_error  = '';
if ($OPENAI_KEY) {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30,
        CURLOPT_POSTFIELDS=>json_encode([
            'model'      => 'gpt-4o',
            'max_tokens' => 800,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    ['type'=>'text', 'text'=>'Transcribe EXACTAMENTE el texto escrito en esta imagen. Si hay texto escrito a mano, transcríbelo tal como está. Si no hay texto, describe brevemente la imagen. Solo devuelve el texto transcrito, sin explicaciones adicionales.'],
                    ['type'=>'image_url', 'image_url'=>['url'=>"data:{$mime};base64,{$imagen_b64}"]],
                ],
            ]],
        ]),
        CURLOPT_HTTPHEADER=>["Authorization: Bearer $OPENAI_KEY",'Content-Type: application/json'],
    ]);
    $vis_resp = curl_exec($ch);
    $vis_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $vis_data = json_decode($vis_resp, true);
    if ($vis_code===200 && !empty($vis_data['choices'][0]['message']['content'])) {
        $transcripcion = trim($vis_data['choices'][0]['message']['content']);
    } else {
        $vision_error = $vis_data['error']['message'] ?? "HTTP $vis_code";
    }
}

// ── 4. Actualizar correo ─────────────────────────────────────────────
$update = ['has_attachments'=>true, 'updated_at'=>$now];
if ($canvas_public_url) $update['canvas_url'] = $canvas_public_url;
if ($transcripcion) {
    $update['transcripcion'] = $transcripcion;
    $update['body_content']  = $transcripcion;
    $update['body_preview']  = mb_substr($transcripcion, 0, 200);
    $update['descripcion']   = $transcripcion;
}
if ($subject_actual && strpos($subject_actual,'ESCRITO')!==false) {
    $update['subject'] = str_replace(['📝 ESCRITO','ESCRITO'],['✏️ CANVAS','CANVAS'],$subject_actual);
}

$ch = curl_init("$SB_URL/rest/v1/correos?id=eq.$correo_id");
curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>'PATCH',CURLOPT_POSTFIELDS=>json_encode($update),
    CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,
    CURLOPT_HTTPHEADER=>["apikey: $SB_KEY","Authorization: Bearer $SB_KEY",
        'Content-Type: application/json','Prefer: return=minimal']]);
curl_exec($ch); curl_close($ch);

// ── 5. Registrar adjunto ─────────────────────────────────────────────
if ($canvas_public_url) {
    $ch = curl_init("$SB_URL/rest/v1/adjuntos");
    curl_setopt_array($ch,[CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode(['correo_id'=>$correo_id,
            'attachment_id'=>"canvas_{$pqr_id}_{$ts}",'nombre'=>$filename,
            'tipo_contenido'=>$mime,'tamano_bytes'=>strlen($img_binary),
            'es_inline'=>false,'storage_url'=>$canvas_public_url,
            'storage_path'=>$storage_path,'direccion'=>'entrante','enviado_por'=>'formulario_web']),
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,
        CURLOPT_HTTPHEADER=>["apikey: $SB_KEY","Authorization: Bearer $SB_KEY",
            'Content-Type: application/json','Prefer: return=minimal']]);
    curl_exec($ch); curl_close($ch);
}

// ── 6. Historial ────────────────────────────────────────────────────
$ch = curl_init("$SB_URL/rest/v1/historial_eventos");
curl_setopt_array($ch,[CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>json_encode(['correo_id'=>$correo_id,'evento'=>'canvas_procesado',
        'descripcion'=>"Canvas subido y procesado con GPT-4o Vision. ".
            ($transcripcion?"Texto: ".mb_substr($transcripcion,0,100):"Sin texto: $vision_error"),
        'datos_extra'=>json_encode(['canvas_url'=>$canvas_public_url,
            'transcripcion'=>$transcripcion,'vision_error'=>$vision_error,
            'tamano_kb'=>round(strlen($img_binary)/1024)]),'created_at'=>$now]),
    CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,
    CURLOPT_HTTPHEADER=>["apikey: $SB_KEY","Authorization: Bearer $SB_KEY",
        'Content-Type: application/json','Prefer: return=minimal']]);
curl_exec($ch); curl_close($ch);

echo json_encode(['ok'=>true,'correo_id'=>$correo_id,'canvas_url'=>$canvas_public_url,
    'transcripcion'=>$transcripcion,'vision_usado'=>!empty($transcripcion),
    'storage_ok'=>!is_null($canvas_public_url),'vision_error'=>$vision_error?:null]);
