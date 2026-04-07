<?php
/**
 * procesar-canvas.php v3 — Tododrogas CIA SAS
 * FIXES v3:
 *  - Valida HTTP code del upload ANTES de construir URL pública
 *  - Log detallado del error de storage para diagnóstico
 *  - No guarda URL rota en BD si el upload falló
 *  - Registra adjunto en tabla adjuntos SOLO si upload fue exitoso
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

$is_preview = str_starts_with($pqr_id, 'preview_');

// ── MODO PREVIEW: solo transcribir, sin tocar Supabase ───────────────
// Debe ir ANTES del paso 1 (que busca en BD y hace exit si no encuentra)
if ($is_preview) {
    // Extraer mime y base64
    // FIX: strpos/substr — preg_match falla con base64 grande (PCRE backtracking limit)
    $img_data_url = $imagen_b64;
    $mime = 'image/png';
    if (strpos($imagen_b64, 'data:image/') === 0) {
        $b64sep     = strpos($imagen_b64, ';base64,');
        $mime       = $b64sep ? substr($imagen_b64, 5, $b64sep - 5) : 'image/png';
        $imagen_b64 = $b64sep ? substr($imagen_b64, $b64sep + 8)    : $imagen_b64;
    }
    $img_binary = base64_decode($imagen_b64);
    if (!$img_binary || strlen($img_binary) < 100) {
        http_response_code(400);
        echo json_encode(['error' => 'imagen_base64 inválida']);
        exit;
    }

    // GPT-4o Vision — solo transcribir
    $transcripcion = '';
    if ($OPENAI_KEY) {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $OPENAI_KEY", 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'model'      => 'gpt-4o',
                'max_tokens' => 800,
                'messages'   => [[
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'text',
                         'text' => 'Transcribe EXACTAMENTE el texto escrito en esta imagen. Si hay texto escrito a mano, transcríbelo tal como está. Si no hay texto, describe brevemente la imagen. Solo devuelve el texto transcrito, sin explicaciones adicionales.'],
                        ['type' => 'image_url',
                         'image_url' => ['url' => "data:{$mime};base64,{$imagen_b64}"]],
                    ],
                ]],
            ]),
        ]);
        $vis_resp = curl_exec($ch);
        $vis_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $vis_data = json_decode($vis_resp, true);
        if ($vis_code === 200 && !empty($vis_data['choices'][0]['message']['content'])) {
            $transcripcion = trim($vis_data['choices'][0]['message']['content']);
        }
    }

    http_response_code(200);
    echo json_encode([
        'ok'           => true,
        'transcripcion' => $transcripcion,
        'texto'        => $transcripcion,
        'storage_ok'   => false,
        'vision_usado' => !empty($transcripcion),
    ]);
    exit; // ← Termina aquí para preview, nunca toca Supabase
}

$img_data_url = $imagen_b64;
$mime = 'image/png';
if (strpos($imagen_b64, 'data:image/') === 0) {
    // FIX: strpos/substr — preg_match falla con base64 grande (PCRE backtracking limit)
    $b64sep     = strpos($imagen_b64, ';base64,');
    $mime       = $b64sep ? substr($imagen_b64, 5, $b64sep - 5) : 'image/png';
    $imagen_b64 = $b64sep ? substr($imagen_b64, $b64sep + 8)    : $imagen_b64;
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

// ── 2. Subir imagen a bucket 'canvas-images' ─────────────────────────
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

// FIX: Solo construir URL pública si el upload realmente fue exitoso (2xx)
$canvas_public_url = null;
$storage_ok        = false;
$storage_error     = null;

if ($up_code >= 200 && $up_code < 300) {
    $canvas_public_url = "$SB_URL/storage/v1/object/public/canvas-images/$storage_path";
    $storage_ok        = true;
} else {
    $storage_error = "HTTP $up_code: " . substr($up_resp, 0, 300);
}

// ── 3. GPT-4o Vision → transcribir texto ────────────────────────────
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

// ── 4. Modo preview — solo transcribir, sin guardar ──────────────────
if ($is_preview) {
    echo json_encode(['ok'=>true,'transcripcion'=>$transcripcion,
        'texto'=>$transcripcion,'storage_ok'=>false,'vision_usado'=>!empty($transcripcion)]);
    exit;
}

// ── 5. Actualizar correo ─────────────────────────────────────────────
$update = ['has_attachments'=>true, 'updated_at'=>$now];
// FIX: Solo guardar canvas_url si el upload fue exitoso
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

// ── 6. Registrar adjunto SOLO si upload exitoso ──────────────────────
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

// ── 7. Historial ─────────────────────────────────────────────────────
$ch = curl_init("$SB_URL/rest/v1/historial_eventos");
curl_setopt_array($ch,[CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>json_encode(['correo_id'=>$correo_id,'evento'=>'canvas_procesado',
        'descripcion'=>"Canvas procesado. Storage: ".($storage_ok?'OK':"ERROR: $storage_error").". ".
            ($transcripcion?"GPT-4o Vision OK: ".mb_substr($transcripcion,0,100):"Sin texto: $vision_error"),
        'datos_extra'=>json_encode(['canvas_url'=>$canvas_public_url,
            'storage_ok'=>$storage_ok,'storage_error'=>$storage_error,
            'transcripcion'=>$transcripcion,'vision_error'=>$vision_error,
            'tamano_kb'=>round(strlen($img_binary)/1024),
            'upload_code'=>$up_code]),'created_at'=>$now]),
    CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,
    CURLOPT_HTTPHEADER=>["apikey: $SB_KEY","Authorization: Bearer $SB_KEY",
        'Content-Type: application/json','Prefer: return=minimal']]);
curl_exec($ch); curl_close($ch);

echo json_encode(['ok'=>true,'correo_id'=>$correo_id,'canvas_url'=>$canvas_public_url,
    'transcripcion'=>$transcripcion,'vision_usado'=>!empty($transcripcion),
    'storage_ok'=>$storage_ok,'storage_error'=>$storage_error,
    'vision_error'=>$vision_error?:null,'upload_code'=>$up_code]);
