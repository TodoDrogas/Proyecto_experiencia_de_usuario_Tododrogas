<?php
/**
 * transcribir-audio.php v2
 * Recibe audio_base64 del formulario DESPUÉS del radicado
 * 1. Sube audio al bucket 'audios' de Supabase Storage
 * 2. Transcribe con OpenAI Whisper-1
 * 3. Actualiza correo en BD: audio_url, transcripcion, canal_contacto, has_attachments
 * 4. Registra adjunto en tabla adjuntos
 * 5. Actualiza asunto del ticket con canal correcto
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

date_default_timezone_set('America/Bogota');

$SB_URL      = '__SB_URL__';
$SB_KEY      = '__SB_KEY__';
$OPENAI_KEY  = '__OPENAI_KEY__';

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); echo json_encode(['error'=>'Invalid JSON']); exit; }

$pqr_id      = $body['pqr_id']       ?? '';  // ticket_id como TD-YYYYMMDD-XXXX
$audio_b64   = $body['audio_base64'] ?? '';
$mime_type   = $body['mime_type']    ?? 'audio/webm';
$nombre_pac  = $body['nombre_paciente'] ?? 'Paciente';

if (!$pqr_id || !$audio_b64) {
    http_response_code(400);
    echo json_encode(['error' => 'pqr_id y audio_base64 requeridos']);
    exit;
}

// Decodificar base64
$audio_data = base64_decode($audio_b64);
if (!$audio_data || strlen($audio_data) < 100) {
    http_response_code(400);
    echo json_encode(['error' => 'audio_base64 inválido o vacío']);
    exit;
}

$ext = 'webm';
if (strpos($mime_type, 'mp4') !== false) $ext = 'mp4';
elseif (strpos($mime_type, 'mpeg') !== false) $ext = 'mp3';
elseif (strpos($mime_type, 'ogg') !== false) $ext = 'ogg';

$ts        = round(microtime(true) * 1000);
$filename  = "audio_{$pqr_id}.{$ext}";
$now       = date('c');

// ── 1. Obtener correo_id desde ticket_id ────────────────────────────
$ch = curl_init("$SB_URL/rest/v1/correos?ticket_id=eq.$pqr_id&select=id,subject,canal_contacto");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10,
    CURLOPT_HTTPHEADER=>["apikey: $SB_KEY","Authorization: Bearer $SB_KEY",'Accept: application/json']]);
$res = curl_exec($ch); curl_close($ch);
$correos = json_decode($res, true);
if (empty($correos)) {
    http_response_code(404);
    echo json_encode(['error'=>'Ticket no encontrado', 'ticket_id'=>$pqr_id]);
    exit;
}
$correo_id = $correos[0]['id'];
$subject_actual = $correos[0]['subject'] ?? '';

// ── 2. Subir audio a Supabase Storage bucket 'audios' ───────────────
$storage_path = "$correo_id/{$ts}_{$filename}";
$storage_url  = "$SB_URL/storage/v1/object/audios/$storage_path";

$ch = curl_init($storage_url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $audio_data,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        "apikey: $SB_KEY",
        "Authorization: Bearer $SB_KEY",
        "Content-Type: $mime_type",
        "x-upsert: true"
    ],
]);
$upload_resp = curl_exec($ch);
$upload_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$audio_public_url = null;
if ($upload_code < 300) {
    $audio_public_url = "$SB_URL/storage/v1/object/public/audios/$storage_path";
}

// ── 3. Transcribir con OpenAI Whisper ───────────────────────────────
$transcripcion = '';
$whisper_error = '';
if ($OPENAI_KEY && strlen($audio_data) > 0) {
    $tmp_file = tempnam(sys_get_temp_dir(), 'audio_') . ".$ext";
    file_put_contents($tmp_file, $audio_data);

    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $OPENAI_KEY"],
        CURLOPT_POSTFIELDS     => [
            'file'     => new CURLFile($tmp_file, $mime_type, $filename),
            'model'    => 'whisper-1',
            'language' => 'es',
        ],
    ]);
    $whisper_resp = curl_exec($ch);
    $whisper_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmp_file);

    $whisper_data = json_decode($whisper_resp, true);
    if ($whisper_code === 200 && !empty($whisper_data['text'])) {
        $transcripcion = trim($whisper_data['text']);
    } else {
        $whisper_error = $whisper_data['error']['message'] ?? "HTTP $whisper_code";
    }
}

// ── 4. Actualizar correo en BD ───────────────────────────────────────
$update_data = [
    'canal_contacto'  => 'formulario_web',
    'has_attachments' => true,
    'updated_at'      => $now,
];
if ($audio_public_url) {
    $update_data['audio_url'] = $audio_public_url;
}
if ($transcripcion) {
    $update_data['transcripcion']  = $transcripcion;
    $update_data['body_content']   = $transcripcion;
    $update_data['body_preview']   = mb_substr($transcripcion, 0, 200);
    $update_data['descripcion']    = $transcripcion;
}

// Actualizar asunto para reflejar canal AUDIO
if ($subject_actual && strpos($subject_actual, 'ESCRITO') !== false) {
    $update_data['subject'] = str_replace(
        ['📝 ESCRITO', 'ESCRITO'],
        ['🎤 AUDIO',   'AUDIO'],
        $subject_actual
    );
}

$ch = curl_init("$SB_URL/rest/v1/correos?id=eq.$correo_id");
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST  => 'PATCH',
    CURLOPT_POSTFIELDS     => json_encode($update_data),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => [
        "apikey: $SB_KEY", "Authorization: Bearer $SB_KEY",
        'Content-Type: application/json', 'Prefer: return=minimal'
    ],
]);
curl_exec($ch);
curl_close($ch);

// ── 5. Registrar en tabla adjuntos ───────────────────────────────────
if ($audio_public_url) {
    $ch = curl_init("$SB_URL/rest/v1/adjuntos");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'correo_id'      => $correo_id,
            'attachment_id'  => "audio_{$pqr_id}_{$ts}",
            'nombre'         => $filename,
            'tipo_contenido' => $mime_type,
            'tamano_bytes'   => strlen($audio_data),
            'es_inline'      => false,
            'storage_url'    => $audio_public_url,
            'storage_path'   => $storage_path,
            'direccion'      => 'entrante',
            'enviado_por'    => 'formulario_web',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "apikey: $SB_KEY", "Authorization: Bearer $SB_KEY",
            'Content-Type: application/json', 'Prefer: return=minimal'
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ── 6. Registrar en historial_eventos ───────────────────────────────
$ch = curl_init("$SB_URL/rest/v1/historial_eventos");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'correo_id'   => $correo_id,
        'evento'      => 'audio_procesado',
        'descripcion' => "Audio subido y transcrito con Whisper. Tamaño: ".round(strlen($audio_data)/1024)."KB. ".
                         ($transcripcion ? "Transcripción: ".mb_substr($transcripcion,0,100) : "Sin transcripción: $whisper_error"),
        'datos_extra' => json_encode([
            'audio_url'      => $audio_public_url,
            'storage_path'   => $storage_path,
            'transcripcion'  => $transcripcion,
            'whisper_error'  => $whisper_error,
            'tamano_kb'      => round(strlen($audio_data)/1024),
            'mime_type'      => $mime_type,
        ]),
        'created_at'  => $now,
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => [
        "apikey: $SB_KEY", "Authorization: Bearer $SB_KEY",
        'Content-Type: application/json', 'Prefer: return=minimal'
    ],
]);
curl_exec($ch);
curl_close($ch);

// ── RESPUESTA ────────────────────────────────────────────────────────
echo json_encode([
    'ok'             => true,
    'correo_id'      => $correo_id,
    'audio_url'      => $audio_public_url,
    'transcripcion'  => $transcripcion,
    'whisper_usado'  => !empty($transcripcion),
    'storage_ok'     => !is_null($audio_public_url),
    'tamano_kb'      => round(strlen($audio_data)/1024),
    'whisper_error'  => $whisper_error ?: null,
]);
