<?php
/**
 * nova-td-respuesta.php — Reemplaza webhook N8N nova-td-respuesta
 * ─────────────────────────────────────────────────────────────────
 * Recibe datos del ticket → busca en knowledge_base → genera respuesta con GPT-4o-mini
 * Mismo contrato de respuesta JSON que el webhook N8N anterior
 *
 * POST /nova-td-respuesta.php
 * Body JSON: { correo_id, asunto, body_content, tipo_pqr, categoria_ia,
 *              prioridad, es_urgente, ley_aplicable, fecha_limite_sla,
 *              nivel_riesgo, horas_sla, agente_nombre, remitente_nombre,
 *              remitente, conversation_id, ticket_id, estado }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Solo POST']);
    exit;
}

// ── CREDENCIALES ──────────────────────────────────────────────────────
$SB_URL     = '__SB_URL__';
$SB_KEY     = '__SB_KEY__';
$OPENAI_KEY = '__OPENAI_KEY__';

// ── INPUT ─────────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true) ?? [];

$correo_id        = $body['correo_id']        ?? '';
$asunto           = $body['asunto']           ?? '';
$body_content     = $body['body_content']     ?? '';
$tipo_pqr         = $body['tipo_pqr']         ?? 'peticion';
$categoria_ia     = $body['categoria_ia']     ?? '';
$prioridad        = $body['prioridad']        ?? 'media';
$es_urgente       = (bool)($body['es_urgente'] ?? false);
$ley_aplicable    = $body['ley_aplicable']    ?? 'Ley 1755/2015';
$fecha_limite_sla = $body['fecha_limite_sla'] ?? '';
$nivel_riesgo     = $body['nivel_riesgo']     ?? 'bajo';
$horas_sla        = intval($body['horas_sla'] ?? 120);
$agente_nombre    = $body['agente_nombre']    ?? 'Equipo PQRSFD';
$remitente_nombre = $body['remitente_nombre'] ?? '';
$remitente        = $body['remitente']        ?? '';
$ticket_id        = $body['ticket_id']        ?? $correo_id;
$estado           = $body['estado']           ?? 'pendiente';

if (!$correo_id) {
    http_response_code(400);
    echo json_encode(['error' => 'correo_id requerido']);
    exit;
}

// ── HELPERS ───────────────────────────────────────────────────────────
function sbGet(string $SB_URL, string $SB_KEY, string $endpoint): ?array {
    $ch = curl_init("$SB_URL/rest/v1/$endpoint");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            "apikey: $SB_KEY",
            "Authorization: Bearer $SB_KEY",
            'Accept: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 300) ? json_decode($resp, true) : null;
}

function sbPost(string $SB_URL, string $SB_KEY, string $endpoint, array $data): array {
    $ch = curl_init("$SB_URL/rest/v1/$endpoint");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            "apikey: $SB_KEY",
            "Authorization: Bearer $SB_KEY",
            'Content-Type: application/json',
            'Prefer: return=minimal',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $resp];
}

function curlPost(string $url, array $headers, string $body_json): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body_json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $resp];
}

// ── 1. BUSCAR EN KNOWLEDGE BASE ───────────────────────────────────────
// Buscar por tipo_pqr y/o categoría
$kb_results = [];
$fuente_kb  = false;

// Búsqueda por tipo_pqr
$kb_tipo = sbGet($SB_URL, $SB_KEY,
    "knowledge_base?activo=eq.true&tipo_pqr=eq.{$tipo_pqr}&order=veces_usado.desc&limit=5"
) ?? [];

// Búsqueda por categoría si hay
$kb_cat = [];
if ($categoria_ia) {
    $cat_encoded = urlencode($categoria_ia);
    $kb_cat = sbGet($SB_URL, $SB_KEY,
        "knowledge_base?activo=eq.true&categoria=ilike.*{$cat_encoded}*&order=veces_usado.desc&limit=3"
    ) ?? [];
}

// Unir resultados eliminando duplicados
$kb_ids_vistos = [];
foreach (array_merge($kb_tipo, $kb_cat) as $item) {
    if (!in_array($item['id'], $kb_ids_vistos)) {
        $kb_results[]   = $item;
        $kb_ids_vistos[] = $item['id'];
    }
}

$fuente_kb = !empty($kb_results);

// Formatear plantillas para el prompt
$plantillas_texto = '';
if ($fuente_kb) {
    $plantillas_texto = "\n\n=== PLANTILLAS DE RESPUESTA DISPONIBLES ===\n";
    foreach (array_slice($kb_results, 0, 5) as $i => $kb) {
        $n = $i + 1;
        $plantillas_texto .= "\n[Plantilla {$n}] Categoría: {$kb['categoria']} | Situación: {$kb['situacion']}\n{$kb['respuesta_modelo']}\n---";
    }
}

// ── 2. LIMPIAR BODY CONTENT PARA EL PROMPT ───────────────────────────
$texto_caso = strip_tags($body_content);
$texto_caso = preg_replace('/\s+/', ' ', $texto_caso);
$texto_caso = mb_substr(trim($texto_caso), 0, 2000);

// ── 3. HISTORIAL DEL CORREO (contexto adicional) ──────────────────────
$historial_texto = '';
$fuente_historial = false;

$historial = sbGet($SB_URL, $SB_KEY,
    "historial_eventos?correo_id=eq.{$correo_id}&order=created_at.desc&limit=5&select=evento,descripcion,created_at"
) ?? [];

if (!empty($historial)) {
    $fuente_historial = true;
    $historial_texto  = "\n\n=== HISTORIAL DEL TICKET ===\n";
    foreach ($historial as $h) {
        $fecha = date('d/m/Y H:i', strtotime($h['created_at'] ?? ''));
        $historial_texto .= "[{$fecha}] {$h['evento']}: {$h['descripcion']}\n";
    }
}

// ── 4. CONSTRUCCIÓN DEL PROMPT ────────────────────────────────────────
$fecha_sla = $fecha_limite_sla
    ? date('d/m/Y H:i', strtotime($fecha_limite_sla))
    : 'No definida';

$urgente_txt = $es_urgente ? 'SÍ — requiere respuesta inmediata' : 'No';

$prompt = <<<PROMPT
Eres Nova TD, el asistente IA de Tododrogas CIA SAS para respuestas a casos PQRSFD.
Genera una respuesta profesional, cálida y concreta para el siguiente caso.

=== DATOS DEL CASO ===
Ticket: {$ticket_id}
Asunto: {$asunto}
Tipo: {$tipo_pqr}
Categoría: {$categoria_ia}
Prioridad: {$prioridad}
Urgente: {$urgente_txt}
Ley aplicable: {$ley_aplicable}
SLA: {$horas_sla}h · Límite: {$fecha_sla}
Nivel de riesgo: {$nivel_riesgo}
Remitente: {$remitente_nombre} <{$remitente}>
Agente: {$agente_nombre}

=== CONTENIDO DEL CASO ===
{$texto_caso}
{$plantillas_texto}
{$historial_texto}

=== INSTRUCCIONES ===
1. Si hay plantillas disponibles, úsalas como base y adáptalas al caso específico
2. Reemplaza todas las variables [X], [MEDICAMENTO], [FECHA], etc. con valores concretos si los conoces del texto del caso, o déjalos como [COMPLETAR] si no
3. Mantén tono profesional y cálido — "Buen día," al inicio, "Quedo atenta, feliz día." al final
4. Si el caso es urgente, reconócelo explícitamente
5. La respuesta debe ser lista para enviar, NO incluyas meta-comentarios
6. Máximo 300 palabras

Responde SOLO con la respuesta para enviar al usuario/EPS, sin explicaciones adicionales.
PROMPT;

// ── 5. LLAMAR A GPT-4o-mini ───────────────────────────────────────────
$respuesta_html = '';
$confianza      = 50;
$advertencias   = [];
$fuente_ia      = false;

if ($OPENAI_KEY) {
    $ai_r = curlPost(
        'https://api.openai.com/v1/chat/completions',
        ["Authorization: Bearer $OPENAI_KEY", 'Content-Type: application/json'],
        json_encode([
            'model'       => 'gpt-4o-mini',
            'max_tokens'  => 600,
            'temperature' => 0.3,
            'messages'    => [
                ['role' => 'system', 'content' => 'Eres Nova TD, asistente de respuestas PQRSFD de Tododrogas Colombia. Generas respuestas precisas, profesionales y listas para enviar. Nunca incluyes meta-comentarios ni explicaciones.'],
                ['role' => 'user', 'content' => $prompt],
            ],
        ])
    );

    if ($ai_r['code'] === 200) {
        $ai_data       = json_decode($ai_r['body'], true);
        $respuesta_txt = trim($ai_data['choices'][0]['message']['content'] ?? '');
        $fuente_ia     = true;

        if ($respuesta_txt) {
            // Convertir texto a HTML con formato básico
            // GPT devuelve texto plano — convertir a HTML limpio para el contenteditable
            // 1. Escapar caracteres especiales
            // 2. Convertir párrafos (doble salto) en <p>
            // 3. Convertir saltos simples en <br>
            $parrafos = explode("\n\n", $respuesta_txt);
            $parrafos_html = array_map(function($p) {
                $p = trim($p);
                if (!$p) return '';
                // Convertir **texto** en <strong>texto</strong>
                $p = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $p);
                // Saltos simples dentro del párrafo → <br>
                $p = nl2br(htmlspecialchars($p, ENT_QUOTES, 'UTF-8', false));
                // Restaurar strong tags que htmlspecialchars escapó
                $p = str_replace(['&lt;strong&gt;', '&lt;/strong&gt;'], ['<strong>', '</strong>'], $p);
                return "<p style='margin:0 0 10px 0'>$p</p>";
            }, $parrafos);
            $respuesta_html = implode('', array_filter($parrafos_html));

            // Calcular confianza basada en fuentes usadas
            $confianza = 60;
            if ($fuente_kb)      $confianza += 20;
            if ($fuente_historial) $confianza += 10;
            if (strlen($texto_caso) > 100) $confianza += 10;
            $confianza = min(95, $confianza);

            // Advertencias si hay variables sin completar
            if (preg_match('/\[COMPLETAR\]|\[MEDICAMENTO\]|\[FECHA\]|\[MUNICIPIO\]|\[X\]/i', $respuesta_txt)) {
                $advertencias[] = 'Hay variables [COMPLETAR] que debes reemplazar antes de enviar';
            }
            if ($es_urgente) {
                $advertencias[] = 'Caso URGENTE — revisa prioridad de envío';
            }
            if ($confianza < 70) {
                $advertencias[] = 'Confianza media — verifica que la respuesta sea apropiada para este caso';
            }
        }
    } else {
        $advertencias[] = 'Error al conectar con IA — usa una plantilla manualmente';
        $respuesta_html = '<p style="color:#dc2626">⚠️ Error al generar respuesta con IA. Por favor usa una plantilla de respuestas rápidas.</p>';
        $confianza      = 0;
    }
} else {
    $advertencias[] = 'OpenAI no configurado — usa respuestas rápidas';
    $confianza      = 0;
}

// ── 6. EXTRACCIÓN DE DATOS DEL CASO ──────────────────────────────────
$extraccion = [];

// Extraer nombre de paciente
if (preg_match('/(?:usuario|paciente|señor|señora|ciudadano)[:\s]+([A-ZÁÉÍÓÚÑ][a-záéíóúñ]+(?:\s[A-ZÁÉÍÓÚÑ][a-záéíóúñ]+){1,3})/i', $texto_caso, $m)) {
    $extraccion['paciente'] = trim($m[1]);
}
// Extraer CC/documento
if (preg_match('/(?:CC|cédula|documento|cc\.?)[:\s#]*(\d{6,12})/i', $texto_caso, $m)) {
    $extraccion['documento'] = trim($m[1]);
}
// Extraer medicamento
if (preg_match('/(?:medicamento|med|fármaco)[:\s]+([A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑa-záéíóúñ\s\d]+?)(?:\s+(?:fue|es|se|para|no|,|\.))/i', $texto_caso, $m)) {
    $extraccion['medicamento'] = trim($m[1]);
}
// Extraer radicado
if (preg_match('/(?:radicado|ticket|TD-|rad\.?)[:\s#]*([A-Z0-9\-]{6,25})/i', $ticket_id . ' ' . $texto_caso, $m)) {
    $extraccion['radicado'] = trim($m[1]);
}
// Urgencia
$extraccion['urgencia'] = $es_urgente ? 'urgente' : 'normal';

// ── 7. ACTUALIZAR veces_usado EN knowledge_base ───────────────────────
if ($fuente_kb && !empty($kb_results)) {
    $kb_id = $kb_results[0]['id'];
    $usos  = intval($kb_results[0]['veces_usado'] ?? 0) + 1;
    $ch = curl_init("$SB_URL/rest/v1/knowledge_base?id=eq.$kb_id");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => json_encode(['veces_usado' => $usos]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "apikey: $SB_KEY",
            "Authorization: Bearer $SB_KEY",
            'Content-Type: application/json',
            'Prefer: return=minimal',
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ── 8. REGISTRAR EN HISTORIAL ─────────────────────────────────────────
sbPost($SB_URL, $SB_KEY, 'historial_eventos', [
    'correo_id'   => $correo_id,
    'evento'      => 'nova_td_generado',
    'descripcion' => "Nova TD generó respuesta. Confianza: {$confianza}%. Fuentes: KB=" . ($fuente_kb?'Sí':'No') . " IA=" . ($fuente_ia?'Sí':'No'),
    'created_at'  => date('c'),
]);

// ── 9. RESPUESTA FINAL ────────────────────────────────────────────────
echo json_encode([
    'respuesta_html'  => $respuesta_html,
    'confianza'       => $confianza,
    'advertencias'    => $advertencias,
    'fuentes_usadas'  => [
        'kb'           => $fuente_kb,
        'extraccion_ia'=> $fuente_ia,
        'historial'    => $fuente_historial,
    ],
    'extraccion'      => $extraccion,
    'plantillas_kb'   => array_map(fn($k) => [
        'id'        => $k['id'],
        'categoria' => $k['categoria'],
        'situacion' => $k['situacion'],
    ], array_slice($kb_results, 0, 3)),
], JSON_UNESCAPED_UNICODE);
