<?php
/**
 * nova-proxy.php — Proxy seguro para OpenAI GPT-4o
 * La API key nunca se expone al navegador
 */

// ── PROTECCIÓN: Solo dominios autorizados ────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://tododrogas.online', 'https://www.tododrogas.online'];
if (!in_array($origin, $allowed)) {
    http_response_code(403);
    echo json_encode(['error' => 'Origen no permitido']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Solo POST']); exit; }

// ── API KEY (inyectada por deploy.yml desde GitHub Secrets) ──
$OPENAI_KEY = '__OPENAI_KEY__';

if (!$OPENAI_KEY || strlen($OPENAI_KEY) < 20) {
    http_response_code(500);
    echo json_encode(['error' => 'OpenAI key no configurada en el servidor']);
    exit;
}

// ── INPUT ──────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON inválido']);
    exit;
}

$action = $body['action'] ?? 'chat';

// ── MODO WHISPER: Transcripción de audio ──────────────────
if ($action === 'whisper') {
    $audio_b64 = $body['audio_base64'] ?? '';
    $mime_type = $body['mime_type']    ?? 'audio/webm';
    if (!$audio_b64) {
        http_response_code(400);
        echo json_encode(['error' => 'audio_base64 requerido']);
        exit;
    }
    $audio_data = base64_decode($audio_b64);
    if (!$audio_data || strlen($audio_data) < 100) {
        http_response_code(400);
        echo json_encode(['error' => 'audio inválido']);
        exit;
    }
    $ext = match(true) {
        str_contains($mime_type, 'webm') => 'webm',
        str_contains($mime_type, 'mp4')  => 'mp4',
        str_contains($mime_type, 'ogg')  => 'ogg',
        str_contains($mime_type, 'wav')  => 'wav',
        default => 'webm',
    };
    $tmp = tempnam(sys_get_temp_dir(), 'ntd_') . '.' . $ext;
    file_put_contents($tmp, $audio_data);
    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $OPENAI_KEY],
        CURLOPT_POSTFIELDS     => [
            'file'     => new CURLFile($tmp, $mime_type, 'audio.' . $ext),
            'model'    => 'whisper-1',
            'language' => 'es',
            'prompt'   => 'Tododrogas CIA SAS, Nova TD, PQRSFD, Colombia, Antioquia, Medellin, Bogota, Cali, Barranquilla. EPS: COOSALUD, SAVIA SALUD, Salud Total, Nueva EPS, Preventiva, CEM, Angiosur, Comfama, Comfenalco, Medimas, Sanitas, Compensar, Famisanar, Mutual Ser, Asmet Salud, Emssanar, Capresoca, Cajacopi. Numeros y codigos: TD-2024, TD-2025, TD-2026, cero, uno, dos, tres, cuatro, cinco, seis, siete, ocho, nueve, cien, mil, cedula, numero de documento, identificacion, codigo de radicado, numero de radicado. Municipios Antioquia: Medellin, Turbo, Apartado, Caucasia, Rionegro, Yarumal, Segovia, El Bagre, Necocli, Carepa, Chigorodo, Mutata, Frontino, Dabeiba, Valdivia, Taraza, Caceres, Anori, Amalfi, Jerico, Andes, Ciudad Bolivar, Santa Barbara, Santa Fe de Antioquia, Amaga, Puerto Berrio, Zaragoza, Remedios, Yolombo, San Carlos, Guatape, Abejorral, Angostura, Armenia, Briceno, Caicedo, Copacabana, Girardota, Barbosa, La Ceja, La Union, Sabaneta, Marinilla, Concordia, Liborina, Olaya, Llanadas, San Jeronimo, Sopetran, Anza, Betulia, Hispania, Jardin, Pueblorrico, Sabanalarga, Uramita, Yali, Nechi, Peque, Puerto Nare, Samana. Departamentos: Antioquia, Cundinamarca, Valle del Cauca, Bolivar, Atlantico, Cordoba, Sucre, Santander, Huila, Tolima, Nariño, Cauca, Choco, Risaralda, Caldas, Quindio, Meta, Boyaca, Cesar, Magdalena. Medicamentos y farmacos: insulina, metformina, losartan, enalapril, atorvastatina, omeprazol, amoxicilina, azitromicina, ibuprofeno, acetaminofen, salbutamol, budesonida, fluticasona, levotiroxina, clonazepam, lorazepam, alprazolam, risperidona, quetiapina, haloperidol, warfarina, clopidogrel, aspirina, carvedilol, metoprolol, amlodipino, hidroclorotiazida, furosemida, espironolactona, atorvastatina, rosuvastatina, simvastatina, ranitidina, pantoprazol, lansoprazol, metoclopramida, ondansetron, ciprofloxacino, trimetoprim, sulfametoxazol, doxiciclina, clindamicina, cefalexina, ampicilina, eritromicina, fluconazol, itraconazol, aciclovir, oseltamivir, prednisona, dexametasona, betametasona, hidrocortisona, calcio, vitamina D, acido folico, hierro, vitamina B12, zinc, magnesio. Gestiones farmaceuticas: formula medica, dispensacion, tecnologia de salud, medicamento pendiente, entrega parcial, entrega total, historial de entregas, autorizacion de medicamento, gestion de autorizacion, tramite de autorizacion, medicamento no POS, medicamento POS, complementario, recobro, tutela, derecho de peticion, queja por medicamento, reclamo de medicamento, medicamento vencido, medicamento en mal estado, medicamento deteriorado, concentracion incorrecta, sustitucion de medicamento, cambio de medicamento, equivalente terapeutico, alternativa farmacologica, dispensacion domiciliaria, entrega a domicilio, punto de dispensacion, servicio farmaceutico, regencia de farmacia, quimica farmaceutica, tecnologo en regencia, auxiliar de farmacia. Tramites PQRSFD: radicar, radicado, solicitud, queja, reclamo, peticion, felicitacion, sugerencia, denuncia, consultar estado, numero de caso, numero de ticket, estado de solicitud, tiempo de respuesta, SLA, vencimiento, fecha limite, asesor asignado. Documentos: cedula de ciudadania, tarjeta de identidad, cedula de extranjeria, pasaporte, documento de identidad, historia clinica, autorizacion medica, orden medica, epicrisis, resumen de historia clinica, formula medica, receta. Frases frecuentes: donde puedo reclamar, puntos de dispensacion, sede mas cercana, requisitos para reclamar, encuesta de satisfaccion, horarios de atencion, cuales son los documentos, que necesito llevar, cuando llega mi medicamento, por que no me entregaron, me faltaron medicamentos, entregaron incompleto, medicamento pendiente, cuando esta disponible, como radicar una queja, como consultar mi radicado, hablar con un asesor, numero de telefono, whatsapp, correo electronico, BIC piso seis, primer piso, sede Medellin, cobertura EPS.',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmp);
    $data = json_decode($resp, true);
    echo json_encode(['text' => $data['text'] ?? '', 'code' => $code]);
    exit;
}

// ── MODO TTS: OpenAI Text-to-Speech (shimmer) ─────────────
if ($action === 'tts') {
    $texto = trim($body['texto'] ?? '');
    if (!$texto) {
        http_response_code(400);
        echo json_encode(['error' => 'texto requerido']);
        exit;
    }
    $texto = strip_tags($texto);
    $texto = preg_replace('/[\x{1F000}-\x{1FFFF}]/u', '', $texto);
    $texto = trim($texto);
    if (!$texto) { echo json_encode(['audio_b64' => '']); exit; }

    $ch = curl_init('https://api.openai.com/v1/audio/speech');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model' => 'tts-1',
            'voice' => 'shimmer',
            'input' => mb_substr($texto, 0, 4096),
            'speed' => 0.95,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $OPENAI_KEY,
            'Content-Type: application/json',
        ],
    ]);
    $audio = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 && $audio) {
        echo json_encode(['audio_b64' => base64_encode($audio), 'mime' => 'audio/mpeg']);
    } else {
        echo json_encode(['audio_b64' => '', 'error' => 'tts_failed_'.$code]);
    }
    exit;
}

// ── MODO CHAT: GPT ─────────────────────────────────────────
if (!isset($body['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'messages requerido']);
    exit;
}

$messages  = $body['messages'];
$system    = $body['system']    ?? '';
$max_tok   = min((int)($body['max_tokens'] ?? 1500), 4000);
$model     = 'gpt-4o';

$payload = [
    'model'       => $model,
    'max_tokens'  => $max_tok,
    'temperature' => 0.3,
    'messages'    => array_merge(
        $system ? [['role' => 'system', 'content' => $system]] : [],
        $messages
    ),
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 45,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $OPENAI_KEY,
        'Content-Type: application/json',
    ],
]);

$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($err) {
    http_response_code(502);
    echo json_encode(['error' => 'curl: ' . $err]);
    exit;
}

http_response_code($code);
echo $resp;
