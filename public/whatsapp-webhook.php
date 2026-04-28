<?php
// ══════════════════════════════════════════════════════════════
//  Nova TD — WhatsApp Webhook
//  Tododrogas CIA SAS
//  Phone Number ID : 1004615859412103   (+57 300 9732247)
//  WABA ID         : 925400393219673
// ══════════════════════════════════════════════════════════════

// ── Secrets inyectados por GitHub Actions ──────────────────────
define('WA_TOKEN',        '__TOKEN_WHATSAPP__');
define('WA_VERIFY_TOKEN', '__WA_VERIFY_TOKEN__');
define('WA_PHONE_ID',     '1004615859412103');
define('OPENAI_KEY',      '__OPENAI_KEY__');   // Whisper (audio) + GPT (chat)
define('SB_URL',          '__SB_URL__');
define('SB_KEY',          '__SB_KEY__');

// ── Seguridad ──────────────────────────────────────────────────
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    http_response_code(405); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody   = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    $appSecret = '__APP_SECRET__';
    $expected  = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);
    if (!hash_equals($expected, $signature)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
    $GLOBALS['_RAW_BODY'] = $rawBody;
}

header('Content-Type: application/json; charset=utf-8');

// ══════════════════════════════════════════════════════════════
//  VERIFICACIÓN DEL WEBHOOK (GET)
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode']         ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge']    ?? '';
    if ($mode === 'subscribe' && $token === WA_VERIFY_TOKEN) {
        http_response_code(200);
        echo $challenge;
    } else {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════
//  RECEPCIÓN DE MENSAJES (POST)
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body = $GLOBALS['_RAW_BODY'] ?? file_get_contents('php://input');
$data = json_decode($body, true);

// Meta espera siempre 200 inmediato
http_response_code(200);
echo json_encode(['status' => 'ok']);

$entry   = $data['entry'][0]    ?? null;
$changes = $entry['changes'][0] ?? null;
$value   = $changes['value']    ?? null;
$msgs    = $value['messages']   ?? [];

if (empty($msgs)) exit;

$msg   = $msgs[0];
$from  = $msg['from'] ?? '';
$msgId = $msg['id']   ?? '';
$type  = $msg['type'] ?? '';

// Evitar reprocesar el mismo mensaje
$cacheFile = sys_get_temp_dir() . '/wa_msg_' . md5($msgId) . '.lock';
if (file_exists($cacheFile)) exit;
file_put_contents($cacheFile, '1');

// ── Extraer texto o audio ──────────────────────────────────────
$userText = '';

if ($type === 'text') {
    $userText = trim($msg['text']['body'] ?? '');
} elseif ($type === 'audio') {
    $audioId  = $msg['audio']['id'] ?? '';
    $userText = transcribeAudio($audioId);
} else {
    sendWA($from, 'Lo siento, por ahora solo proceso mensajes de texto y audio. ¿En qué puedo ayudarle?');
    exit;
}

if (!$userText) exit;

// ── Sesión del usuario en Supabase ────────────────────────────
$session  = getOrCreateSession($from);
$history  = $session['history']  ?? [];
$userData = $session['usuario']  ?? [];
$eps      = $session['eps']      ?? '';
$sedes    = $session['sedes']    ?? [];

// ── Construir system prompt ────────────────────────────────────
$systemPrompt = buildSystemPrompt($userData, $eps, $sedes);

// ── Agregar mensaje del usuario al historial ──────────────────
$history[] = ['role' => 'user', 'content' => strtoupper($userText)];
if (count($history) > 20) $history = array_slice($history, -20);

// ── Llamar a GPT (OpenAI) ─────────────────────────────────────
$raw = callGPT($systemPrompt, $history);

if (!$raw) {
    sendWA($from, 'No pude conectarme. Por favor llame al PBX 604 322 2432 o WhatsApp 304 341 2431.');
    exit;
}

// ── Agregar respuesta al historial ────────────────────────────
$history[] = ['role' => 'assistant', 'content' => $raw];
saveSession($from, $history, $userData, $eps);

// ── Parsear tags de acción ────────────────────────────────────
$parsed     = parseAction($raw);
$text       = $parsed['text'];
$action     = $parsed['action'];
$actionData = $parsed['data'];

// ── Ejecutar acción y responder ───────────────────────────────
switch ($action) {

    case 'FORMULARIO':
        $url = 'https://tododrogas.online/pqr_form.html';
        if ($userData['cedula'] ?? '') {
            $url .= '?cedula=' . urlencode($userData['cedula']);
        }
        sendWA($from, $text . "\n\n📋 *Formulario PQRSFD:*\n" . $url);
        break;

    case 'ESCALAR':
        sendWA($from, $text . "\n\nPuede comunicarse con nosotros:\n📞 PBX: 604 322 2432\n💬 WhatsApp: 304 341 2431\n✉️ pqrsfd@tododrogas.com.co");
        break;

    case 'ENCUESTA':
        sendWA($from, $text . "\n\n⭐ Encuesta de satisfacción:\nhttps://tododrogas.online/pqr_encuesta.html");
        break;

    case 'MENU':
        $menu  = $text . "\n\n";
        $menu .= "1️⃣ Información sobre mi medicamento\n";
        $menu .= "2️⃣ Puntos de dispensación / Sedes\n";
        $menu .= "3️⃣ Requisitos para reclamar\n";
        $menu .= "4️⃣ Radicar PQRSFD\n";
        $menu .= "5️⃣ Estado de mi radicado PQRSFD\n";
        $menu .= "6️⃣ Hablar con un asesor";
        sendWA($from, $menu);
        break;

    case 'CONSULTAR':
        $radicado = consultarRadicado($actionData);
        sendWA($from, $text . "\n\n" . $radicado);
        break;

    case 'SEDES':
        $partes      = explode(':', $actionData ?? '');
        $municipio   = trim($partes[0] ?? '');
        $epsOverride = isset($partes[1]) ? strtoupper(trim($partes[1])) : null;
        $sedesText   = buscarSedes($municipio, $epsOverride ?: $eps, $sedes);
        sendWA($from, $text . "\n\n" . $sedesText);
        break;

    case 'REQUISITOS':
        $req  = "📋 *Requisitos para reclamar medicamentos:*\n\n";
        $req .= "• Fórmula médica vigente\n";
        $req .= "• Documento de identidad\n";
        $req .= "• Carné de afiliación a la EPS\n";
        $req .= "• En caso de representante: autorización escrita + copia del documento del paciente\n\n";
        $req .= "¿Necesita más información? 📞 604 322 2432";
        sendWA($from, $text . "\n\n" . $req);
        break;

    case 'MEDICAMENTOS':
        sendWA($from, $text . "\n\nPara consultar el estado de su medicamento comuníquese al:\n📞 PBX: 604 322 2432\n💬 WhatsApp: 304 341 2431");
        break;

    case 'CAMBIAR_EPS':
        sendWA($from, $text . "\n\nPor favor indíqueme el nombre de su EPS para actualizar sus datos.");
        saveSession($from, $history, $userData, '', $sedes);
        break;

    default:
        sendWA($from, $text);
        break;
}

exit;

// ══════════════════════════════════════════════════════════════
//  FUNCIONES
// ══════════════════════════════════════════════════════════════

function buildSystemPrompt(array $usuario, string $eps, array $sedes): string {
    $hoy    = (new DateTime('now', new DateTimeZone('America/Bogota')))->format('l, d \d\e F \d\e Y');
    $nombre = $usuario['nombre'] ?? '?';
    $epsU   = strtoupper($eps);

    $catalogo = "CATÁLOGO DE SEDES (datos reales):\n";
    foreach ($sedes as $s) {
        $epsArr    = is_array($s['eps']) ? $s['eps'] : json_decode($s['eps'] ?? '[]', true);
        $catalogo .= '- ' . ($s['nombre'] ?? '') . ' | Municipio: ' . ($s['municipio'] ?? '') . ' | EPS: ' . implode(', ', $epsArr) . "\n";
    }

    $s  = "Eres Nova TD, asistente virtual de Tododrogas CIA SAS.\n";
    $s .= "FECHA DE HOY: $hoy\n";
    $s .= "USUARIO: $nombre | EPS: " . ($epsU ?: 'Sin EPS') . " (SAVIA SALUD = SAVIA, PREVENTIVA SALUD = PREVENTIVA)\n";
    $s .= "CANAL: WhatsApp — responde en texto plano sin HTML. Usa emojis con moderación.\n";
    $s .= "TRATO: Siempre de USTED.\n";
    $s .= "HORARIOS SEDES:\n";
    $s .= "- SEDES PROPIAS (Tododrogas): Lun-Vie 7:00am-5:30pm | Sáb 8:00am-12:00m.\n";
    $s .= "- SEDES IN HOUSE (dentro de hospitales/IPS): Lun-Vie 7:00am-3:30pm | Sáb 8:00am-11:00am.\n";
    $s .= "TODAS las sedes abren sábados.\n";
    $s .= $catalogo;
    $s .= "REGLA SEDES ESPECIALES MEDELLÍN: Hay DOS sedes en Medellín en la misma dirección (BIC Piso 6 y Primer Piso). Consulta SIEMPRE el catálogo — NUNCA inventes datos.\n";
    $s .= "REGLA MEDICAMENTOS Y MEDICINA: Para CUALQUIER pregunta sobre medicamentos, dosis, enfermedades, tratamientos — responde con tu conocimiento médico-farmacéutico. Si el usuario menciona medicamento malo, vencido, dañado → di SIEMPRE: \"No lo consuma. Por su seguridad NO utilice ese medicamento.\" → luego ofrece: PBX 604 322 2432 / WA 304 341 2431.\n";
    $s .= "PBX 604 322 2432 | WA 304 341 2431 | pqrsfd@tododrogas.com.co\n";
    $s .= "Max 100 palabras. 1 emoji. NUNCA el menú, usa [MENU]. Un tag al final.\n";
    $s .= "REGLA SEDES — OBLIGATORIA:\n";
    $s .= "  PASO 1 — Si el usuario menciona una EPS → usar ESA EPS.\n";
    $s .= "  PASO 2 — Si EPS mencionada ≠ EPS usuario → usar [SEDES:municipio:EPSMENCIONADA]\n";
    $s .= "  PASO 3 — NUNCA omitir el tag.\n";
    $s .= "REGLA CONSULTAR: Si el usuario escribe ÚNICAMENTE un número TD-xxxxx o un correo → usa [CONSULTAR:valor].\n";
    $s .= "REGLA RADICAR: Si el usuario quiere radicar una PQRSFD → usa [FORMULARIO].\n";
    $s .= "REGLA MEDICAMENTOS VS REQUISITOS:\n";
    $s .= "  - QUÉ LLEVAR / REQUISITOS → [REQUISITOS]\n";
    $s .= "  - ESTADO medicamento / DEMORA → [MEDICAMENTOS]\n";
    $s .= "TAGS: [MENU][FORMULARIO][ESCALAR][ENCUESTA][MEDICAMENTOS][REQUISITOS][CAMBIAR_EPS][CONSULTAR:v][SEDES:m]";
    return $s;
}

// ── Llamar a GPT-4o (OpenAI Chat) ────────────────────────────
function callGPT(string $system, array $messages): string {
    $payload = json_encode([
        'model'       => 'gpt-4o',
        'messages'    => array_merge(
            [['role' => 'system', 'content' => $system]],
            $messages
        ),
        'max_tokens'  => 500,
        'temperature' => 0.4,
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_KEY,
        ],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    return $data['choices'][0]['message']['content'] ?? '';
}

// ── Enviar mensaje por WhatsApp ────────────────────────────────
function sendWA(string $to, string $text): void {
    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'text',
        'text'              => ['body' => $text],
    ]);

    $ch = curl_init('https://graph.facebook.com/v19.0/' . WA_PHONE_ID . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . WA_TOKEN,
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ── Transcribir audio con Whisper (OpenAI) ────────────────────
function transcribeAudio(string $mediaId): string {
    if (!$mediaId) return '';

    $ch = curl_init('https://graph.facebook.com/v19.0/' . $mediaId);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . WA_TOKEN],
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    $url = $res['url'] ?? '';
    if (!$url) return '';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . WA_TOKEN],
    ]);
    $audioData = curl_exec($ch);
    curl_close($ch);

    $tmpFile = tempnam(sys_get_temp_dir(), 'wa_audio_') . '.ogg';
    file_put_contents($tmpFile, $audioData);

    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . OPENAI_KEY],
        CURLOPT_POSTFIELDS     => [
            'file'     => new CURLFile($tmpFile, 'audio/ogg', 'audio.ogg'),
            'model'    => 'whisper-1',
            'language' => 'es',
        ],
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    unlink($tmpFile);

    return $res['text'] ?? '';
}

// ── Sesión en Supabase ─────────────────────────────────────────
function getOrCreateSession(string $phone): array {
    $url = SB_URL . '/rest/v1/wa_sesiones?telefono=eq.' . urlencode($phone) . '&select=*&limit=1';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . SB_KEY,
            'Authorization: Bearer ' . SB_KEY,
        ],
    ]);
    $res     = json_decode(curl_exec($ch), true);
    curl_close($ch);

    $session = $res[0] ?? [];
    $history = json_decode($session['history'] ?? '[]', true) ?: [];
    $usuario = json_decode($session['usuario'] ?? '{}', true) ?: [];
    $eps     = $session['eps'] ?? '';
    $sedes   = loadSedes();

    return compact('history', 'usuario', 'eps', 'sedes');
}

function saveSession(string $phone, array $history, array $usuario, string $eps, array $sedes = []): void {
    $payload = json_encode([
        'telefono'   => $phone,
        'history'    => json_encode(array_slice($history, -20)),
        'usuario'    => json_encode($usuario),
        'eps'        => $eps,
        'updated_at' => (new DateTime('now', new DateTimeZone('America/Bogota')))->format('c'),
    ]);

    $ch = curl_init(SB_URL . '/rest/v1/wa_sesiones');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . SB_KEY,
            'Authorization: Bearer ' . SB_KEY,
            'Content-Type: application/json',
            'Prefer: resolution=merge-duplicates',
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function loadSedes(): array {
    $url = SB_URL . '/rest/v1/sedes?activa=eq.true&select=nombre,ciudad,municipio,municipio_norm,direccion,telefono,lat,lng,eps,horario,modelo';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . SB_KEY,
            'Authorization: Bearer ' . SB_KEY,
        ],
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return is_array($res) ? $res : [];
}

// ── Consultar radicado PQRSFD ─────────────────────────────────
function consultarRadicado(string $valor): string {
    $valor = trim($valor);
    if (!$valor) return 'Por favor indique el número de radicado (formato TD-xxxxx) o su correo electrónico.';

    $campo = str_contains($valor, '@') ? 'correo' : 'radicado';
    $url   = SB_URL . '/rest/v1/correos?' . $campo . '=eq.' . urlencode($valor) . '&select=radicado,estado,tipo,fecha_creacion,descripcion&limit=1';
    $ch    = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . SB_KEY,
            'Authorization: Bearer ' . SB_KEY,
        ],
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (empty($res)) return "No encontré ninguna PQRSFD con el dato: $valor\n¿Desea radicar una nueva? Escríbame su solicitud.";

    $r = $res[0];
    return "📋 *Radicado:* " . ($r['radicado'] ?? '-') . "\n"
         . "📌 *Estado:* "   . ($r['estado']   ?? '-') . "\n"
         . "📁 *Tipo:* "     . ($r['tipo']     ?? '-') . "\n"
         . "📅 *Fecha:* "    . substr($r['fecha_creacion'] ?? '-', 0, 10);
}

// ── Buscar sedes ───────────────────────────────────────────────
function buscarSedes(string $municipio, string $eps, array $sedes): string {
    if (!$municipio) return "¿En qué municipio se encuentra para mostrarle las sedes más cercanas?";

    $munNorm = strtoupper(trim(preg_replace('/[áàä]/u','A', preg_replace('/[éèë]/u','E', preg_replace('/[íìï]/u','I', preg_replace('/[óòö]/u','O', preg_replace('/[úùü]/u','U', $municipio)))))));
    $epsNorm = strtoupper(trim($eps));

    $encontradas = array_filter($sedes, function($s) use ($munNorm, $epsNorm) {
        $sm = strtoupper($s['municipio_norm'] ?? $s['municipio'] ?? '');
        if ($sm !== $munNorm) return false;
        if (!$epsNorm || $epsNorm === 'TODAS') return true;
        $epsArr = is_array($s['eps']) ? $s['eps'] : json_decode($s['eps'] ?? '[]', true);
        foreach ($epsArr as $e) {
            if (strtoupper(trim($e)) === $epsNorm || strtoupper(trim($e)) === 'TODAS') return true;
        }
        return false;
    });

    if (empty($encontradas)) {
        return "No encontré sedes de *$eps* en *$municipio*. Llame al 604 322 2432 para más información.";
    }

    $txt = "📍 *Sedes en " . ucfirst(strtolower($municipio)) . "*:\n\n";
    foreach (array_slice($encontradas, 0, 3) as $s) {
        $txt .= "🏥 *" . ($s['nombre'] ?? '') . "*\n";
        $txt .= "📌 " . ($s['direccion'] ?? '') . "\n";
        $txt .= "📞 " . ($s['telefono'] ?? '') . "\n";
        if ($s['lat'] && $s['lng']) {
            $txt .= "🗺️ https://maps.google.com/?q=" . $s['lat'] . "," . $s['lng'] . "\n";
        }
        $txt .= "\n";
    }
    return rtrim($txt);
}

// ── Parsear tags de acción ────────────────────────────────────
function parseAction(string $raw): array {
    $tags = ['FORMULARIO', 'ESCALAR', 'ENCUESTA', 'MENU', 'MEDICAMENTOS', 'REQUISITOS', 'CAMBIAR_EPS'];

    if (preg_match('/\[CONSULTAR:([^\]]+)\]/i', $raw, $m)) {
        $text = trim(preg_replace('/\[CONSULTAR:[^\]]+\]/i', '', $raw));
        return ['text' => $text, 'action' => 'CONSULTAR', 'data' => trim($m[1])];
    }
    if (preg_match('/\[SEDES:([^\]]+)\]/i', $raw, $m)) {
        $text = trim(preg_replace('/\[SEDES:[^\]]+\]/i', '', $raw));
        return ['text' => $text, 'action' => 'SEDES', 'data' => trim($m[1])];
    }
    foreach ($tags as $tag) {
        if (stripos($raw, "[$tag]") !== false) {
            $text = trim(str_ireplace("[$tag]", '', $raw));
            return ['text' => $text, 'action' => $tag, 'data' => ''];
        }
    }
    return ['text' => trim($raw), 'action' => '', 'data' => ''];
}
