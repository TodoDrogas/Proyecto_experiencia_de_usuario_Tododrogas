<?php
// ══════════════════════════════════════════════════════════════
//  Nova TD — WhatsApp Webhook (PHP)
//  Tododrogas CIA SAS
//
//  RESPONSABILIDAD DE ESTE ARCHIVO:
//  - Recibir mensaje del usuario desde server.js (POST interno)
//  - Llamar a GPT con el historial y contexto
//  - Devolver JSON: { respuesta, accion }
//  - accion puede ser: DEFAULT | ESCALADO | ENCUESTA | MENU
//
//  NOTA: Este archivo NO envía mensajes directamente al usuario.
//        server.js maneja todos los envíos y el estado de la sesión.
//        El menú M/A lo agrega server.js automáticamente en DEFAULT.
// ══════════════════════════════════════════════════════════════

define('WA_TOKEN',        '__TOKEN_WHATSAPP__');
define('WA_VERIFY_TOKEN', '__WA_VERIFY_TOKEN__');
define('WA_PHONE_ID',     '1004615859412103');
define('OPENAI_KEY',      '__OPENAI_KEY__');
define('SB_URL',          '__SB_URL__');
define('SB_KEY',          '__SB_KEY__');
define('NOVA_TOKEN',      '__NOVA_TOKEN__');

header('Content-Type: application/json; charset=utf-8');

// ── Verificación de token ──────────────────────────────────────
// Llamadas vienen de server.js con el header X-Nova-Token
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Verificar token interno
$novaToken = $_SERVER['HTTP_X_NOVA_TOKEN'] ?? '';
if ($novaToken !== NOVA_TOKEN && NOVA_TOKEN !== '') {
    http_response_code(403);
    echo json_encode(['error' => 'Token inválido']);
    exit;
}

$body    = file_get_contents('php://input');
$payload = json_decode($body, true);

$from    = $payload['telefono'] ?? '';
$userMsg = $payload['mensaje']  ?? '';
$sesion  = $payload['sesion']   ?? [];

if (!$from || !$userMsg) {
    http_response_code(400);
    echo json_encode(['error' => 'telefono y mensaje requeridos']);
    exit;
}

// ── Cargar datos de sesión ─────────────────────────────────────
$history  = $sesion['history']  ?? [];
$userData = [];
$eps      = $sesion['eps']      ?? '';
$sedes    = loadSedes();

// Si hay cédula en la sesión, buscar datos del usuario
if (!empty($sesion['cedula'])) {
    $userData = buscarUsuarioPorCedula($sesion['cedula']);
}

// ── System prompt ──────────────────────────────────────────────
$systemPrompt = buildSystemPrompt($userData, $eps, $sedes);

// ── Construir historial filtrado para GPT ──────────────────────
// Solo mensajes role=user y role=assistant (no nova/system) para no confundir al modelo
$gptHistory = [];
foreach ($history as $h) {
    $role = $h['role'] ?? '';
    if ($role === 'user') {
        $gptHistory[] = ['role' => 'user', 'content' => $h['content'] ?? ''];
    } elseif (in_array($role, ['assistant', 'nova'])) {
        $gptHistory[] = ['role' => 'assistant', 'content' => $h['content'] ?? ''];
    }
}

// Agregar mensaje actual
$gptHistory[] = ['role' => 'user', 'content' => strtoupper($userMsg)];
if (count($gptHistory) > 40) {
    $gptHistory = array_slice($gptHistory, -40);
}

// ── Llamar a GPT ───────────────────────────────────────────────
$raw = callGPT($systemPrompt, $gptHistory);

if (!$raw) {
    echo json_encode([
        'respuesta' => 'No pude conectarme en este momento. Por favor llame al PBX 604 322 2432 o WhatsApp 304 341 2431.',
        'accion'    => 'DEFAULT'
    ]);
    exit;
}

// ── Parsear acción ─────────────────────────────────────────────
$parsed = parseAction($raw);
$text   = $parsed['text'];
$action = $parsed['action'];
$data   = $parsed['data'];

// ── Construir respuesta final según acción ─────────────────────
$respuesta = '';

switch ($action) {

    case 'FORMULARIO':
        $url = 'https://tododrogas.online/pqr_form.html';
        if (!empty($userData['cedula'])) {
            $url .= '?cedula=' . urlencode($userData['cedula']);
        }
        $respuesta = $text . "\n\n📋 *Formulario PQRSFD:*\n" . $url;
        $action    = 'DEFAULT'; // server.js agregará el menú M/A
        break;

    case 'ESCALAR':
        // server.js maneja el escalado — solo devolvemos el texto de despedida
        $respuesta = $text;
        // accion = 'ESCALADO' → server.js busca agente o muestra opciones
        break;

    case 'ENCUESTA':
        // server.js envía la encuesta
        $respuesta = $text;
        // accion = 'ENCUESTA' → server.js cambia estado a esperando_encuesta
        break;

    case 'MENU':
        $menu  = $text . "\n\n";
        $menu .= "1️⃣ Información sobre mi medicamento\n";
        $menu .= "2️⃣ Puntos de dispensación / Sedes\n";
        $menu .= "3️⃣ Requisitos para reclamar\n";
        $menu .= "4️⃣ Radicar PQRSFD\n";
        $menu .= "5️⃣ Estado de mi radicado PQRSFD\n";
        $menu .= "6️⃣ Hablar con un asesor";
        $respuesta = $menu;
        $action    = 'DEFAULT';
        break;

    case 'CONSULTAR':
        $radicado  = consultarRadicado($data);
        $respuesta = $text . "\n\n" . $radicado;
        $action    = 'DEFAULT';
        break;

    case 'SEDES':
        $partes      = explode(':', $data ?? '');
        $municipio   = trim($partes[0] ?? '');
        $epsOverride = isset($partes[1]) ? strtoupper(trim($partes[1])) : null;
        // Fallback: si GPT no pasó municipio, usar ciudad del usuario en sesión
        if (!$municipio && !empty($sesion['ciudad'])) {
            $municipio = $sesion['ciudad'];
        }
        if (!$municipio) {
            $respuesta = "¿En qué municipio se encuentra para mostrarle las sedes disponibles?";
            $action    = 'DEFAULT';
            break;
        }
        $epsParaBuscar = $epsOverride ?: $eps;
        $sedesText     = buscarSedes($municipio, $epsParaBuscar, $sedes);
        $respuesta     = ($text ? rtrim($text) . "\n\n" : '') . $sedesText;
        $action        = 'DEFAULT';
        break;

    case 'REQUISITOS':
        $req  = "📋 *Requisitos para reclamar medicamentos:*\n\n";
        $req .= "• Fórmula médica vigente\n";
        $req .= "• Documento de identidad\n";
        $req .= "• Carné de afiliación a la EPS\n";
        $req .= "• En caso de representante: autorización escrita + copia del documento del paciente\n\n";
        $req .= "¿Necesita más información? 📞 604 322 2432";
        $respuesta = $text . "\n\n" . $req;
        $action    = 'DEFAULT';
        break;

    case 'MEDICAMENTOS':
        // BUG FIX: Nova NO genera menús numerados propios (*1*/*2*)
        // porque server.js no sabe interceptarlos — el usuario escribe "1"
        // y GPT lo trata como nuevo mensaje, repitiendo la respuesta anterior.
        // En su lugar: si Nova detecta que el usuario quiere verificar con un asesor,
        // emite [ESCALAR] directamente y server.js maneja el flujo.
        // El texto de GPT ya incluye la info — solo agregar la invitación a asesor.
        $respuesta = $text;
        $action    = 'DEFAULT'; // server.js agrega el menú M/A → A lleva al asesor
        break;

    case 'CAMBIAR_EPS':
        $respuesta = $text . "\n\nPor favor indíqueme el nombre de su EPS para actualizar sus datos.";
        $action    = 'DEFAULT';
        break;

    default:
        // DEFAULT: server.js agregará el menú M/A después de esta respuesta.
        // NO incluir el menú aquí — evitar duplicados.
        $respuesta = $text;
        $action    = 'DEFAULT';
        break;
}

// ── Devolver a server.js ──────────────────────────────────────
echo json_encode([
    'respuesta' => $respuesta,
    'accion'    => $action,   // DEFAULT | ESCALADO | ENCUESTA
    'data'      => $data
], JSON_UNESCAPED_UNICODE);

exit;

// ══════════════════════════════════════════════════════════════
//  FUNCIONES
// ══════════════════════════════════════════════════════════════

function buildSystemPrompt(array $usuario, string $eps, array $sedes): string {
    $hoy    = (new DateTime('now', new DateTimeZone('America/Bogota')))->format('l, d \d\e F \d\e Y');
    $nombre = $usuario['nombre'] ?? '?';
    $epsU   = strtoupper($eps);

    // ── Catálogo completo: dirección, teléfono, horario, EPS por sede ──────────
    // GPT necesita la dirección completa para poder responder directamente
    // sin necesidad de emitir el tag [SEDES] en consultas sencillas de ubicación
    $catalogo = "CATÁLOGO COMPLETO DE SEDES ACTIVAS:\n";
    foreach ($sedes as $s) {
        $epsArr  = is_array($s['eps']) ? $s['eps'] : json_decode($s['eps'] ?? '[]', true);
        $modelo  = strtoupper($s['modelo'] ?? '');
        $horario = $s['horario'] ?? '';
        if (!$horario) {
            $horario = ($modelo === 'IN HOUSE')
                ? 'Lun-Vie 7:00am-3:30pm | Sáb 8:00am-11:00am'
                : 'Lun-Vie 7:00am-5:30pm | Sáb 8:00am-12:00m';
        }
        $epsList = implode(' / ', array_map('trim', $epsArr));
        $linea   = '• ' . ($s['nombre'] ?? '');
        $linea  .= ' | ' . ($s['municipio'] ?? $s['ciudad'] ?? '');
        $linea  .= ' | Dir: '  . ($s['direccion'] ?? 'consultar');
        if (!empty($s['telefono'])) $linea .= ' | Tel: ' . $s['telefono'];
        $linea  .= ' | Horario: ' . $horario;
        $linea  .= ' | EPS: ' . ($epsList ?: 'TODAS');
        if (!empty($s['lat']) && !empty($s['lng'])) {
            $linea .= ' | Maps: https://maps.google.com/?q=' . $s['lat'] . ',' . $s['lng'];
        }
        $catalogo .= $linea . "\n";
    }

    $s  = "Eres Nova TD, asistente virtual de Tododrogas CIA SAS.\n";
    $s .= "FECHA DE HOY: $hoy\n";
    $s .= "USUARIO: $nombre | EPS: " . ($epsU ?: 'Sin EPS') . "\n";
    $s .= "CANAL: WhatsApp — texto plano, sin HTML. Emojis con moderación. Trato de USTED.\n\n";
    $s .= $catalogo . "\n";

    $s .= "REGLA SEDES — OBLIGATORIA Y PRIORITARIA:\n";
    $s .= "  Activa [SEDES:municipio] ante CUALQUIERA de estas preguntas (aunque el usuario no diga 'sede'):\n";
    $s .= "  - ¿Dónde recojo/retiro/reclamo mi medicamento?\n";
    $s .= "  - ¿Dónde queda Tododrogas en [ciudad]?\n";
    $s .= "  - ¿Dónde me atienden?\n";
    $s .= "  - ¿Cuál es la dirección/ubicación?\n";
    $s .= "  - ¿Tienen sede en [ciudad]?\n";
    $s .= "  - Cualquier pregunta sobre puntos de dispensación, puntos de atención, farmacias, dónde ir\n";
    $s .= "  REGLA EPS EN SEDES:\n";
    $s .= "  - Si el usuario tiene EPS conocida → usa [SEDES:municipio] (filtra por su EPS automáticamente)\n";
    $s .= "  - Si menciona una EPS diferente → usa [SEDES:municipio:EPSMENCIONADA]\n";
    $s .= "  - Si pregunta por todas las sedes sin importar EPS → usa [SEDES:municipio:TODAS]\n";
    $s .= "  NUNCA respondas con una dirección inventada. SIEMPRE usa el tag [SEDES] para consultas de ubicación.\n";
    $s .= "  El sistema buscará en la base de datos real y devolverá la dirección exacta con mapa.\n\n";

    $s .= "REGLA FUNDAMENTAL (CRÍTICA): NUNCA generes menús numerados propios (*1*, *2*, etc.) en tus respuestas.\n";
    $s .= "El sistema agrega automáticamente el menú M/A al final de cada DEFAULT. Si el usuario quiere asesor, usa [ESCALAR].\n";
    $s .= "Ejemplo PROHIBIDO: '¿Desea asesor? *1* Sí *2* No' — NUNCA hagas esto.\n";
    $s .= "Ejemplo CORRECTO: Responde la consulta + usa el tag apropiado ([ESCALAR], [MEDICAMENTOS], [SEDES], etc.)\n";
    $s .= "Si el usuario escribe '1' o '2' como respuesta a tu respuesta anterior, es porque confundiste el sistema — NO lo hagas.\n";
    $s .= "REGLA CONSULTAR: Si el usuario escribe SOLO un número TD-xxxxx o un correo → usa [CONSULTAR:valor].\n";
    $s .= "REGLA RADICAR: Si el usuario quiere radicar una PQRSFD → usa [FORMULARIO].\n";
    $s .= "REGLA MEDICAMENTOS: Para estado/demora de medicamentos → [MEDICAMENTOS]. Para qué llevar → [REQUISITOS].\n";
    $s .= "REGLA DOMICILIOS: Tododrogas NO hace domicilios ni envíos. Si preguntan → explicar y ofrecer sede con [SEDES:municipio].\n";
    $s .= "REGLA MEDICAMENTO MALO/VENCIDO: 'No lo consuma. Por su seguridad NO utilice ese medicamento.' + PBX 604 322 2432.\n";

    // Detectar horario para instrucción de escalado
    $horaBogota = new DateTime('now', new DateTimeZone('America/Bogota'));
    $dow        = (int)$horaBogota->format('N');
    $mins       = (int)$horaBogota->format('H') * 60 + (int)$horaBogota->format('i');
    $enHorario  = ($dow >= 1 && $dow <= 5 && $mins >= 420 && $mins < 1050)
               || ($dow === 6 && $mins >= 480 && $mins < 720);

    if ($enHorario) {
        $s .= "HORARIO ASESORES: Estamos DENTRO del horario. Si el usuario pide asesor → usa [ESCALAR].\n";
    } else {
        $s .= "HORARIO ASESORES: Estamos FUERA del horario (Lun-Vie 7am-5:30pm / Sáb 8am-12m). ";
        $s .= "Si el usuario pide asesor → usa [ESCALAR]. El server.js mostrará el menú fuera de horario.\n";
    }

    $s .= "REGLA SATISFACCIÓN (MÁXIMA PRIORIDAD): Cualquier señal de que el usuario ya no necesita ayuda → responde brevemente y usa [ENCUESTA].\n";
    $s .= "Ejemplos: 'no gracias', 'ya está bien', 'gracias', 'muchas gracias', 'perfecto', 'listo', 'ok gracias', 'eso era todo', 'ya quedé'.\n";
    $s .= "TAGS disponibles: [MENU][FORMULARIO][ESCALAR][ENCUESTA][MEDICAMENTOS][REQUISITOS][CAMBIAR_EPS][CONSULTAR:v][SEDES:m][SEDES:m:EPS]\n";
    $s .= "Max 100 palabras por respuesta. Un solo tag al final.\n";
    $s .= "PBX 604 322 2432 | WA 304 341 2431 | pqrsfd@tododrogas.com.co\n";

    return $s;
}

function callGPT(string $system, array $messages): string {
    $payload = json_encode([
        'model'       => 'gpt-4o',
        'messages'    => array_merge([['role' => 'system', 'content' => $system]], $messages),
        'max_tokens'  => 500,
        'temperature' => 0.3,
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

function parseAction(string $raw): array {
    $tags = ['FORMULARIO','ESCALAR','ENCUESTA','MENU','MEDICAMENTOS','REQUISITOS','CAMBIAR_EPS'];

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
    return ['text' => trim($raw), 'action' => 'DEFAULT', 'data' => ''];
}

function buscarUsuarioPorCedula(string $cedula): array {
    if (!$cedula) return [];
    $url = SB_URL . '/rest/v1/tabla_usuarios?Cedula%20Pacientes=eq.' . urlencode($cedula) . '&select=*&limit=1';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['apikey: ' . SB_KEY, 'Authorization: Bearer ' . SB_KEY],
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    $u = $res[0] ?? [];
    if (empty($u)) return [];
    return [
        'nombre'  => $u['Nombre Paciente'] ?? '',
        'cedula'  => $u['Cedula Pacientes'] ?? '',
        'eps'     => $u['EPS'] ?? '',
        'ciudad'  => $u['Ciudad'] ?? '',
    ];
}

function loadSedes(): array {
    // Traer TODOS los campos necesarios para dar dirección directa al usuario
    $url = SB_URL . '/rest/v1/sedes?activa=eq.true&select=nombre,ciudad,municipio,municipio_norm,direccion,telefono,lat,lng,eps,horario,modelo,codigo,encargado&order=municipio.asc';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['apikey: ' . SB_KEY, 'Authorization: Bearer ' . SB_KEY],
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return is_array($res) ? $res : [];
}

function consultarRadicado(string $valor): string {
    $valor = trim($valor);
    if (!$valor) return 'Por favor indique el número de radicado (formato TD-xxxxx) o su correo electrónico.';

    $campo = str_contains($valor, '@') ? 'correo' : 'radicado';
    $url   = SB_URL . '/rest/v1/correos?' . $campo . '=eq.' . urlencode($valor) . '&select=radicado,estado,tipo,fecha_creacion&limit=1';
    $ch    = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['apikey: ' . SB_KEY, 'Authorization: Bearer ' . SB_KEY],
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (empty($res)) return "No encontré ninguna PQRSFD con el dato: $valor\n¿Desea radicar una nueva?";

    $r = $res[0];
    return "📋 *Radicado:* " . ($r['radicado'] ?? '-') . "\n"
         . "📌 *Estado:* "   . ($r['estado']   ?? '-') . "\n"
         . "📁 *Tipo:* "     . ($r['tipo']     ?? '-') . "\n"
         . "📅 *Fecha:* "    . substr($r['fecha_creacion'] ?? '-', 0, 10);
}

function buscarSedes(string $municipio, string $eps, array $sedes): string {
    if (!$municipio) return "¿En qué municipio se encuentra para mostrarle las sedes más cercanas?";

    $norm = fn($s) => strtoupper(trim(preg_replace(
        ['/[áàäâã]/u','/[éèëê]/u','/[íìïî]/u','/[óòöôõ]/u','/[úùüû]/u'],
        ['A','E','I','O','U'], $s
    )));
    $munNorm = $norm($municipio);
    $epsNorm = strtoupper(trim($eps));

    // ── Paso 1: filtrar por municipio + EPS ───────────────────────────────
    $encontradas = array_filter($sedes, function($s) use ($munNorm, $epsNorm, $norm) {
        // Match municipio
        $smNorm  = $norm($s['municipio_norm'] ?? '');
        $smMun   = $norm($s['municipio'] ?? '');
        $smCiu   = $norm($s['ciudad'] ?? '');
        $munMatch = $smNorm === $munNorm || $smMun === $munNorm || $smCiu === $munNorm
                 || str_contains($smNorm, $munNorm) || str_contains($smMun, $munNorm)
                 || str_contains($munNorm, $smNorm) && strlen($smNorm) > 3;
        if (!$munMatch) return false;

        // Sin filtro de EPS → mostrar todo
        if (!$epsNorm || $epsNorm === 'TODAS') return true;

        // Match EPS
        $epsArr = is_array($s['eps']) ? $s['eps'] : json_decode($s['eps'] ?? '[]', true);
        foreach ($epsArr as $e) {
            $eNorm = $norm(trim($e));
            if ($eNorm === 'TODAS') return true;
            if ($eNorm === $epsNorm) return true;
            if (str_contains($eNorm, $epsNorm)) return true;
            if (str_contains($epsNorm, $eNorm) && strlen($eNorm) > 4) return true;
        }
        return false;
    });

    // ── Paso 2: si no hay con EPS específica → mostrar todas del municipio ─
    $avisarEpsNoEncontrada = false;
    if (empty($encontradas) && $epsNorm && $epsNorm !== 'TODAS') {
        $avisarEpsNoEncontrada = true;
        $encontradas = array_filter($sedes, function($s) use ($munNorm, $norm) {
            $smNorm = $norm($s['municipio_norm'] ?? '');
            $smMun  = $norm($s['municipio'] ?? '');
            $smCiu  = $norm($s['ciudad'] ?? '');
            return $smNorm === $munNorm || $smMun === $munNorm || $smCiu === $munNorm
                || str_contains($smNorm, $munNorm) || str_contains($smMun, $munNorm);
        });
    }

    if (empty($encontradas)) {
        return "No encontré sedes de *" . ucfirst(strtolower($eps)) . "* en *" . ucfirst(strtolower($municipio)) . "*.\n"
             . "Para más información llame al 📞 604 322 2432.";
    }

    // ── Encabezado ────────────────────────────────────────────────────────
    $munLabel = ucfirst(strtolower($municipio));
    if ($avisarEpsNoEncontrada) {
        $txt  = "📍 No encontré sede exclusiva de *" . ucfirst(strtolower($eps)) . "* en *$munLabel*.\n";
        $txt .= "Estas sedes del municipio pueden atenderle (verifique su EPS):\n\n";
    } else {
        $epsLabel = ($epsNorm && $epsNorm !== 'TODAS') ? " para *" . ucfirst(strtolower($eps)) . "*" : '';
        $txt = "📍 *Sedes en $munLabel*$epsLabel:\n\n";
    }

    foreach (array_slice($encontradas, 0, 4) as $s) {
        $txt .= _formatearSede($s, $epsNorm);
    }
    return rtrim($txt);
}

function _formatearSede(array $s, string $epsUsuario = ''): string {
    $horario = $s['horario'] ?? '';
    $modelo  = strtoupper($s['modelo'] ?? '');
    if (!$horario) {
        $horario = ($modelo === 'IN HOUSE')
            ? 'Lun-Vie 7:00am-3:30pm | Sáb 8:00am-11:00am'
            : 'Lun-Vie 7:00am-5:30pm | Sáb 8:00am-12:00m';
    }

    // Lista de EPS que atiende esta sede
    $epsArr  = is_array($s['eps']) ? $s['eps'] : json_decode($s['eps'] ?? '[]', true);
    $epsArr  = array_map('trim', $epsArr);
    $esTotal = in_array('TODAS', array_map('strtoupper', $epsArr));

    $txt  = "🏥 *" . ($s['nombre'] ?? '') . "*\n";
    $txt .= "📌 " . ($s['direccion'] ?? 'Consultar dirección') . "\n";
    if (!empty($s['telefono'])) $txt .= "📞 " . $s['telefono'] . "\n";
    $txt .= "🕐 " . $horario . "\n";

    // Mostrar EPS atendidas — si es TODAS simplificarlo
    if ($esTotal) {
        $txt .= "✅ Atiende todas las EPS\n";
    } else {
        $epsMostrar = array_slice($epsArr, 0, 5);
        $txt .= "✅ EPS: " . implode(', ', $epsMostrar);
        if (count($epsArr) > 5) $txt .= " y más";
        $txt .= "\n";
    }

    if (!empty($s['lat']) && !empty($s['lng'])) {
        $txt .= "🗺️ https://maps.google.com/?q=" . $s['lat'] . "," . $s['lng'] . "\n";
    }
    return $txt . "\n";
}
