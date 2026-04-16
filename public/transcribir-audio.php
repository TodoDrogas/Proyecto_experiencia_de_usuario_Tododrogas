<?php
/**
 * transcribir-audio.php v3 — Tododrogas CIA SAS
 * FIXES v3:
 *  - Valida HTTP code del upload ANTES de construir URL pública
 *  - Log detallado del error de storage para diagnóstico
 *  - No guarda URL rota en BD si el upload falló
 *  - Registra adjunto en tabla adjuntos SOLO si upload fue exitoso
 */

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://tododrogas.online', 'https://www.tododrogas.online'];
if (!in_array($origin, $allowed)) { http_response_code(403); echo json_encode(['error'=>'Origen no permitido']); exit; }
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

date_default_timezone_set('America/Bogota');

$SB_URL      = '__SB_URL__';
$SB_KEY      = '__SB_KEY__';
$OPENAI_KEY  = '__OPENAI_KEY__';

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); echo json_encode(['error'=>'Invalid JSON']); exit; }

$pqr_id      = $body['pqr_id']       ?? '';
$audio_b64   = $body['audio_base64'] ?? '';
$mime_type   = $body['mime_type']    ?? 'audio/webm';
$nombre_pac  = $body['nombre_paciente'] ?? 'Paciente';

if (!$pqr_id || !$audio_b64) {
    http_response_code(400);
    echo json_encode(['error' => 'pqr_id y audio_base64 requeridos']);
    exit;
}

$audio_data = base64_decode($audio_b64);
if (!$audio_data || strlen($audio_data) < 100) {
    http_response_code(400);
    echo json_encode(['error' => 'audio_base64 inválido o vacío']);
    exit;
}

$ext = 'webm';
if (strpos($mime_type, 'mp4')  !== false) $ext = 'mp4';
elseif (strpos($mime_type, 'mpeg') !== false) $ext = 'mp3';
elseif (strpos($mime_type, 'ogg')  !== false) $ext = 'ogg';

$ts        = round(microtime(true) * 1000);
$filename  = "audio_{$pqr_id}.{$ext}";
$now       = date('c');

// ── MODO PRE-TRANSCRIPCIÓN: pqr_id temporal (pre_TIMESTAMP) ────────────
// El frontend llama este endpoint ANTES de radicar para obtener la
// transcripción e incluirla en el correo. En este caso no hay ticket en BD.
$modo_pre = str_starts_with($pqr_id, 'pre_');

// ── 1. Obtener correo_id (solo si no es modo pre) ────────────────────
$correo_id      = null;
$subject_actual = '';

if (!$modo_pre) {
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
    $correo_id      = $correos[0]['id'];
    $subject_actual = $correos[0]['subject'] ?? '';
}

// ── 2. Subir audio a bucket 'audios' (solo si hay ticket real) ──────
$storage_path     = $correo_id ? "$correo_id/{$ts}_{$filename}" : null;
$audio_public_url = null;
$storage_ok       = false;
$storage_error    = null;
$upload_code      = null;

if (!$modo_pre && $correo_id && $storage_path) {
    $storage_url = "$SB_URL/storage/v1/object/audios/$storage_path";
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

    if ($upload_code >= 200 && $upload_code < 300) {
        $audio_public_url = "$SB_URL/storage/v1/object/public/audios/$storage_path";
        $storage_ok       = true;
    } else {
        $storage_error = "HTTP $upload_code: " . substr($upload_resp, 0, 300);
    }
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

// ── MODO PRE: solo devolver transcripción, sin tocar BD ─────────────
if ($modo_pre) {
    echo json_encode([
        'ok'            => true,
        'transcripcion' => $transcripcion,
        'whisper_usado' => !empty($transcripcion),
        'whisper_error' => $whisper_error ?: null,
        'tamano_kb'     => round(strlen($audio_data)/1024),
        'modo'          => 'pre_transcripcion',
    ]);
    exit;
}

// ── 4. Actualizar correo en BD ───────────────────────────────────────
$update_data = [
    'canal_contacto'  => 'formulario_web',
    'has_attachments' => true,
    'updated_at'      => $now,
];
// FIX: Solo guardar audio_url si el upload fue exitoso
if ($audio_public_url) {
    $update_data['audio_url'] = $audio_public_url;
}
if ($transcripcion) {
    $update_data['transcripcion']  = $transcripcion;
    $update_data['body_content']   = $transcripcion;
    $update_data['body_preview']   = mb_substr($transcripcion, 0, 200);
    $update_data['descripcion']    = $transcripcion;
}
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

// ── 5. Registrar adjunto SOLO si upload exitoso ──────────────────────
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

// ── 6. Historial ────────────────────────────────────────────────────
$ch = curl_init("$SB_URL/rest/v1/historial_eventos");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'correo_id'   => $correo_id,
        'evento'      => 'audio_procesado',
        'descripcion' => "Audio procesado. Storage: ".($storage_ok?'OK':"ERROR: $storage_error").". ".
                         ($transcripcion ? "Whisper OK: ".mb_substr($transcripcion,0,100) : "Whisper error: $whisper_error"),
        'datos_extra' => json_encode([
            'audio_url'      => $audio_public_url,
            'storage_path'   => $storage_path,
            'storage_ok'     => $storage_ok,
            'storage_error'  => $storage_error,
            'transcripcion'  => $transcripcion,
            'whisper_error'  => $whisper_error,
            'tamano_kb'      => round(strlen($audio_data)/1024),
            'mime_type'      => $mime_type,
            'upload_code'    => $upload_code,
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

// ── Respuesta ────────────────────────────────────────────────────────
echo json_encode([
    'ok'             => true,
    'correo_id'      => $correo_id,
    'audio_url'      => $audio_public_url,
    'transcripcion'  => $transcripcion,
    'whisper_usado'  => !empty($transcripcion),
    'storage_ok'     => $storage_ok,
    'storage_error'  => $storage_error,
    'tamano_kb'      => round(strlen($audio_data)/1024),
    'whisper_error'  => $whisper_error ?: null,
    'upload_code'    => $upload_code,
]);
