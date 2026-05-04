<?php
/**
 * nova-wa.php — Motor de Nova TD para WhatsApp
 * Procesa mensajes entrantes de WhatsApp con IA (GPT-4o-mini)
 * Maneja validación de identidad, conteo de intentos y escalamiento
 */
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$SB_URL      = '__SB_URL__';
$SB_KEY      = '__SB_KEY__';
$OPENAI_KEY  = '__OPENAI_KEY__';
$NOVA_TOKEN  = '__NOVA_TOKEN__';
$VALIDAR_URL = 'http://localhost/validar-paciente.php';
$CONSULTA_URL= 'http://localhost/nova-consulta.php';

// ── Autenticación interna ─────────────────────────────────────────────
$token = $_SERVER['HTTP_X_NOVA_TOKEN'] ?? '';
if ($NOVA_TOKEN !== '__NOVA_TOKEN__' && $token !== $NOVA_TOKEN) {
    http_response_code(401); echo json_encode(['error'=>'Token inválido']); exit;
}

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$telefono = trim($body['telefono'] ?? '');
$mensaje  = trim($body['mensaje']  ?? '');
$sesion   = $body['sesion']        ?? [];

if (!$telefono || !$mensaje) {
    http_response_code(400); echo json_encode(['error'=>'telefono y mensaje requeridos']); exit;
}

// ── Helper HTTP ───────────────────────────────────────────────────────
function httpPost(string $url, array $data, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($resp, true) ?? []];
}

function sbPatch(string $sb_url, string $sb_key, string $tabla, string $filtro, array $data): void {
    $ch = curl_init("$sb_url/rest/v1/$tabla?$filtro");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "apikey: $sb_key",
            "Authorization: Bearer $sb_key",
            'Content-Type: application/json',
            'Prefer: return=minimal',
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ── Datos de la sesión actual ─────────────────────────────────────────
$cedula        = trim($sesion['cedula']        ?? '');
$nombre        = trim($sesion['nombre']        ?? '');
$eps           = trim($sesion['eps']           ?? '');
$ciudad        = trim($sesion['ciudad']        ?? '');
$intentos      = (int)($sesion['intentos_nova'] ?? 0);
$origen_canal  = trim($sesion['origen_canal']  ?? 'whatsapp_directo');
$history       = is_array($sesion['history']) ? $sesion['history'] : [];

// Filtrar solo mensajes de usuario y nova para el contexto de GPT
$histGPT = array_filter($history, fn($m) => in_array($m['role'] ?? '', ['user', 'nova', 'assistant']));
$histGPT = array_map(fn($m) => [
    'role'    => $m['role'] === 'nova' ? 'assistant' : $m['role'],
    'content' => $m['content'] ?? ''
], array_values($histGPT));

// ── FASE 1: Validación de identidad ──────────────────────────────────
// Solo si es whatsapp_directo y no tiene cédula aún
if ($origen_canal === 'whatsapp_directo' && !$cedula) {

    // ¿El mensaje parece una cédula? (6-12 dígitos)
    $posibleCedula = preg_replace('/\D/', '', $mensaje);
    $esCedula = strlen($posibleCedula) >= 6 && strlen($posibleCedula) <= 12;

    if ($esCedula) {
        // Validar contra la BD
        $r = httpPost($VALIDAR_URL, [
            'cedula'   => $posibleCedula,
            'telefono' => preg_replace('/\D/', '', $telefono),
        ]);

        if ($r['code'] === 200 && ($r['body']['ok'] ?? false)) {
            $p = $r['body'];
            // Guardar en wa_sesiones
            sbPatch($SB_URL, $SB_KEY, 'wa_sesiones', "telefono=eq." . urlencode($telefono), [
                'cedula'       => $posibleCedula,
                'nombre'       => $p['nombre'] ?? '',
                'eps'          => $p['eps']    ?? '',
                'ciudad'       => $p['ciudad'] ?? '',
                'updated_at'   => date('c'),
            ]);
            $cedula = $posibleCedula;
            $nombre = $p['nombre'] ?? '';
            $eps    = $p['eps']    ?? '';
            $ciudad = $p['ciudad'] ?? '';

            $primerNombre = explode(' ', trim($nombre))[0];
            $saludo = $p['vip'] ?? false
                ? ($p['saludo'] ?? "¡Bienvenido/a, $primerNombre! Es un honor atenderle.")
                : "¡Hola, $primerNombre! Bienvenido/a a Tododrogas. Soy Nova TD, su asistente virtual. ¿En qué le puedo ayudar hoy?";

            echo json_encode([
                'respuesta'    => $saludo,
                'accion'       => 'CONTINUAR',
                'cedula'       => $cedula,
                'nombre'       => $nombre,
                'eps'          => $eps,
                'ciudad'       => $ciudad,
                'intentos'     => 0,
            ]);
            exit;
        } else {
            // Cédula no encontrada
            echo json_encode([
                'respuesta' => "No encontré su registro con ese número de documento. Por favor verifique e intente nuevamente, o comuníquese al *604 322 2432*.",
                'accion'    => 'PEDIR_CEDULA',
                'intentos'  => $intentos,
            ]);
            exit;
        }
    } else {
        // Pedir cédula — primer contacto
        $esPrimerMensaje = count($history) <= 1;
        $respValidar = $esPrimerMensaje
            ? "¡Hola! Bienvenido/a a *Tododrogas CIA SAS*. Soy *Nova TD*, su asistente virtual 🤖\n\nPara brindarle una atención personalizada, por favor indíqueme su *número de documento de identidad* (sin puntos ni espacios)."
            : "Para continuar necesito verificar su identidad. Por favor indíqueme su *número de documento* (sin puntos ni espacios).";

        echo json_encode([
            'respuesta' => $respValidar,
            'accion'    => 'PEDIR_CEDULA',
            'intentos'  => $intentos,
        ]);
        exit;
    }
}

// ── FASE 2: Nova responde con IA ──────────────────────────────────────
$primerNombre = $nombre ? (explode(' ', trim($nombre))[0]) : 'usuario';

// Detectar urgencia o solicitud de asesor inmediata
$msgUpper = mb_strtoupper($mensaje, 'UTF-8');
$urgencia = preg_match('/VENCIDO|DETERIORAD|REACCI[OÓ]N|GRAVE|URGENTE|EMERGENCIA|INTOXICACI[OÓ]N/', $msgUpper);
$pideAsesor = preg_match('/ASESOR|AGENTE|HUMANO|PERSONA|HABLAR CON|QUIERO UN|NECESITO UN|LLAMAR|NO ME SIRVE|NO ENTIENDO|NO FUNCIONA/', $msgUpper);

if ($urgencia || $pideAsesor) {
    $resumen = generarResumen($histGPT, $nombre, $eps, $mensaje, $OPENAI_KEY);
    escalarSesion($SB_URL, $SB_KEY, $telefono, $resumen);

    $resp = $urgencia
        ? "⚠️ Entiendo que es una situación urgente, *$primerNombre*. Lo estoy conectando de inmediato con uno de nuestros asesores especializados. En breve le atenderán."
        : "Por supuesto, *$primerNombre*. Le conecto con uno de nuestros asesores. En un momento le atienden.";

    echo json_encode([
        'respuesta' => $resp,
        'accion'    => 'ESCALADO',
        'resumen'   => $resumen,
        'intentos'  => $intentos,
    ]);
    exit;
}

// Detectar si Nova debe ofrecer asesor (según intentos y tipo)
$ofrecerAsesor = false;
$intentosLimite = detectarLimiteIntentos($histGPT);
if ($intentos >= $intentosLimite) {
    $ofrecerAsesor = true;
}

// ── Sistema de prompt de Nova para WhatsApp ───────────────────────────
$system = construirSistema($nombre, $eps, $ciudad, $cedula, $ofrecerAsesor);

// Agregar mensaje actual al historial GPT
$histGPT[] = ['role' => 'user', 'content' => $mensaje];

// Llamar a GPT-4o-mini
$payload = [
    'model'       => 'gpt-4o-mini',
    'max_tokens'  => 800,
    'temperature' => 0.3,
    'messages'    => array_merge(
        [['role' => 'system', 'content' => $system]],
        array_slice($histGPT, -12) // últimos 12 mensajes
    ),
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer $OPENAI_KEY",
        'Content-Type: application/json',
    ],
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) {
    echo json_encode([
        'respuesta' => "Tuve un problema técnico. Llame al *604 322 2432* o escriba al WhatsApp *304 341 2431*.",
        'accion'    => 'ERROR',
        'intentos'  => $intentos,
    ]);
    exit;
}

$data   = json_decode($resp, true);
$rawMsg = trim($data['choices'][0]['message']['content'] ?? '');

// ── Detectar acción en la respuesta ──────────────────────────────────
$accion = 'CONTINUAR';
$resumen = '';

if (str_contains($rawMsg, '[ESCALAR]') || str_contains($rawMsg, '[ASESOR]')) {
    $accion  = 'ESCALADO';
    $resumen = generarResumen($histGPT, $nombre, $eps, $mensaje, $OPENAI_KEY);
    escalarSesion($SB_URL, $SB_KEY, $telefono, $resumen);
    $rawMsg  = str_replace(['[ESCALAR]','[ASESOR]'], '', $rawMsg);
} elseif (str_contains($rawMsg, '[FORMULARIO]')) {
    $accion = 'FORMULARIO';
    $rawMsg = str_replace('[FORMULARIO]', '', $rawMsg);
} elseif (str_contains($rawMsg, '[OFRECER_ASESOR]') || $ofrecerAsesor) {
    $accion = 'OFRECER_ASESOR';
    $rawMsg = str_replace('[OFRECER_ASESOR]', '', $rawMsg);
    $rawMsg .= "\n\n¿Prefiere que uno de nuestros asesores le ayude directamente? Responda *Sí* o *No*.";
}

// Detectar si usuario respondió Sí al ofrecimiento de asesor
if (in_array(trim($msgUpper), ['SI', 'SÍ', 'SÍ POR FAVOR', 'SI POR FAVOR', 'QUIERO', 'OK', 'BUENO'])
    && $intentos >= $intentosLimite - 1) {
    $accion  = 'ESCALADO';
    $resumen = generarResumen($histGPT, $nombre, $eps, $mensaje, $OPENAI_KEY);
    escalarSesion($SB_URL, $SB_KEY, $telefono, $resumen);
    $rawMsg  = "Perfecto, *$primerNombre*. Le conecto con un asesor. En breve le atienden. 🙂";
}

echo json_encode([
    'respuesta' => trim($rawMsg),
    'accion'    => $accion,
    'resumen'   => $resumen,
    'intentos'  => $intentos + 1,
]);

// ── Funciones auxiliares ──────────────────────────────────────────────

function construirSistema(string $nombre, string $eps, string $ciudad, string $cedula, bool $ofrecerAsesor): string {
    $primerNombre = $nombre ? (explode(' ', trim($nombre))[0]) : 'usuario';
    $s  = "Eres Nova TD, asistente virtual de Tododrogas CIA SAS por WhatsApp.\n";
    $s .= "Usuario identificado: $nombre | Cédula: $cedula | EPS: $eps | Ciudad: $ciudad\n";
    $s .= "Canal: WhatsApp — responde de forma concisa, clara y cálida. Usa *negritas* con asteriscos para WhatsApp.\n";
    $s .= "No uses markdown de encabezados (#). Usa listas con guiones o números.\n\n";
    $s .= "REGLAS IMPORTANTES:\n";
    $s .= "- Dirígete siempre por el primer nombre: $primerNombre\n";
    $s .= "- Si preguntan por medicamentos específicos o entrega, responde lo que puedas y añade [OFRECER_ASESOR]\n";
    $s .= "- Si detectas urgencia médica, responde y añade [ESCALAR]\n";
    $s .= "- Para radicar PQRSFD, indica que pueden hacerlo en tododrogas.online y añade [FORMULARIO]\n";
    $s .= "- PBX: 604 322 2432 | WA agentes: 304 341 2431 | Email: pqrsfd@tododrogas.com.co\n";
    $s .= "- Horario: Lun-Vie 7:00am-5:30pm | Sáb 8:00am-12:00m\n";
    if ($ofrecerAsesor) {
        $s .= "\nHas intentado responder varias veces sin resolver. Ofrece un asesor añadiendo [OFRECER_ASESOR] al final.\n";
    }
    $s .= "\nTAGS disponibles: [ESCALAR] [FORMULARIO] [OFRECER_ASESOR]";
    return $s;
}

function detectarLimiteIntentos(array $hist): int {
    // Detectar tipo de consulta según historial
    $texto = implode(' ', array_column($hist, 'content'));
    $textoUp = mb_strtoupper($texto, 'UTF-8');
    if (preg_match('/MEDICAMENTO|ENTREGA|DISPENSACI[OÓ]N|DESPACHO/', $textoUp)) return 1;
    if (preg_match('/RADICADO|PQRS|QUEJA|RECLAMO|SOLICITUD/', $textoUp)) return 3;
    if (preg_match('/SEDE|HORARIO|DIRECCI[OÓ]N|D[OÓ]NDE/', $textoUp)) return 3;
    return 2; // default
}

function generarResumen(array $hist, string $nombre, string $eps, string $ultimoMsg, string $openaiKey): string {
    $conversacion = implode("\n", array_map(
        fn($m) => ($m['role'] === 'user' ? 'Usuario' : 'Nova') . ': ' . ($m['content'] ?? ''),
        array_slice($hist, -8)
    ));

    $payload = [
        'model'       => 'gpt-4o-mini',
        'max_tokens'  => 200,
        'temperature' => 0,
        'messages'    => [
            ['role' => 'system', 'content' => 'Eres un asistente que genera resúmenes concisos para agentes de atención al cliente. Responde solo con el resumen, sin preámbulos.'],
            ['role' => 'user',   'content' => "Genera un resumen de máximo 3 líneas para el agente que va a tomar esta conversación de WhatsApp.\nUsuario: $nombre | EPS: $eps\n\nConversación:\n$conversacion\n\nEl resumen debe indicar: qué necesita el usuario, qué intentó resolver Nova y por qué necesita un agente humano."],
        ],
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $openaiKey", 'Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return trim($data['choices'][0]['message']['content'] ?? 'Sin resumen disponible.');
}

function escalarSesion(string $sb_url, string $sb_key, string $telefono, string $resumen): void {
    $ch = curl_init("$sb_url/rest/v1/wa_sesiones?telefono=eq." . urlencode($telefono));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => json_encode([
            'estado'       => 'escalado',
            'resumen_nova' => $resumen,
            'updated_at'   => date('c'),
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "apikey: $sb_key",
            "Authorization: Bearer $sb_key",
            'Content-Type: application/json',
            'Prefer: return=minimal',
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
}
