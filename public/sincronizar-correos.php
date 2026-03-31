<?php
$LOCK_FILE = '/tmp/sync-correos.lock';
$LOCK_TTL  = 4 * 60; // 4 minutos: si el lock tiene más de esto, es un proceso muerto

if (file_exists($LOCK_FILE)) {
    $lock_age = time() - filemtime($LOCK_FILE);
    if ($lock_age < $LOCK_TTL) {
        // Proceso anterior sigue activo → abortar silenciosamente
        $pid = @file_get_contents($LOCK_FILE);
        error_log("[sync-correos] Ya hay sync corriendo (PID: $pid, hace {$lock_age}s). Abortando.");
        exit(0);
    }
    // Lock viejo (proceso muerto) → lo eliminamos y continuamos
    @unlink($LOCK_FILE);
}

// Crear lock
file_put_contents($LOCK_FILE, getmypid());
// Limpiar lock siempre al terminar (éxito, error o exit)
register_shutdown_function(function () use ($LOCK_FILE) {
    @unlink($LOCK_FILE);
});

// ── Modo de ejecución ─────────────────────────────────────────────────
$es_web    = php_sapi_name() !== 'cli';
$es_manual = $es_web && isset($_GET['token']);

if ($es_web) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    $ADMIN_TOKEN = '__ADMIN_SYNC_TOKEN__';
    if ($es_manual && ($_GET['token'] ?? '') !== $ADMIN_TOKEN) {
        http_response_code(403);
        echo json_encode(['error' => 'Token inválido']);
        exit;
    }
    if (!$es_manual) {
        http_response_code(403);
        echo json_encode(['error' => 'Solo accesible con token o desde cron']);
        exit;
    }
}

date_default_timezone_set('America/Bogota');

// ── CREDENCIALES ──────────────────────────────────────────────────────
$SB_URL        = '__SB_URL__';
$SB_KEY        = '__SB_KEY__';
$OPENAI_KEY    = '__OPENAI_KEY__';
$TENANT_ID     = '__AZURE_TENANT_ID__';
$CLIENT_ID     = '__AZURE_CLIENT_ID__';
$CLIENT_SECRET = '__AZURE_CLIENT_SECRET__';
$GRAPH_MAILBOX = 'pqrsfd@tododrogas.com.co';
$HORAS_VENTANA = (int)($_GET['horas'] ?? 12); // 12h = ventana por defecto (cron cada 5min cubre bien sin duplicar)

// Tamaño de lote para batch upsert — no subir de 25 en Nano
const BATCH_SIZE = 20;

// ── LOG ───────────────────────────────────────────────────────────────
$log = [];
function log_msg(string $msg): void {
    global $log;
    $ts = date('H:i:s');
    $log[] = "[$ts] $msg";
    if (php_sapi_name() === 'cli') echo "[$ts] $msg\n";
}

log_msg("=== SYNC START v2 — ventana: {$HORAS_VENTANA}h | PID: " . getmypid() . " ===");

// ── HELPERS ───────────────────────────────────────────────────────────

function curlJson(string $url, array $opts = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, $opts + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,   // aumentado de 30 a 45s
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $resp, 'err' => $err];
}

function sbGet(string $SB_URL, string $SB_KEY, string $endpoint): ?array {
    $r = curlJson("$SB_URL/rest/v1/$endpoint", [
        CURLOPT_HTTPHEADER => [
            "apikey: $SB_KEY",
            "Authorization: Bearer $SB_KEY",
            'Accept: application/json',
        ],
    ]);
    return ($r['code'] >= 200 && $r['code'] < 300) ? json_decode($r['body'], true) : null;
}


function sbUpsert(string $SB_URL, string $SB_KEY, string $endpoint, array $rows, string $conflict): array {
    $r = curlJson("$SB_URL/rest/v1/$endpoint?on_conflict=$conflict", [
        CURLOPT_POST        => true,
        CURLOPT_POSTFIELDS  => json_encode($rows),   // array de filas
        CURLOPT_HTTPHEADER  => [
            "apikey: $SB_KEY",
            "Authorization: Bearer $SB_KEY",
            'Content-Type: application/json',
            'Prefer: resolution=merge-duplicates,return=representation',
        ],
    ]);
    return $r;
}

function sbPost(string $SB_URL, string $SB_KEY, string $endpoint, array $data): array {
    $r = curlJson("$SB_URL/rest/v1/$endpoint", [
        CURLOPT_POST        => true,
        CURLOPT_POSTFIELDS  => json_encode($data),
        CURLOPT_HTTPHEADER  => [
            "apikey: $SB_KEY",
            "Authorization: Bearer $SB_KEY",
            'Content-Type: application/json',
            'Prefer: return=minimal',
        ],
    ]);
    return $r;
}

function sbPatch(string $SB_URL, string $SB_KEY, string $endpoint, string $filter, array $data): void {
    curlJson("$SB_URL/rest/v1/$endpoint?$filter", [
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS    => json_encode($data),
        CURLOPT_HTTPHEADER    => [
            "apikey: $SB_KEY",
            "Authorization: Bearer $SB_KEY",
            'Content-Type: application/json',
            'Prefer: return=minimal',
        ],
    ]);
}

// ── 1. TOKEN AZURE ────────────────────────────────────────────────────
log_msg("Obteniendo token Azure...");
$tok_r = curlJson(
    "https://login.microsoftonline.com/{$TENANT_ID}/oauth2/v2.0/token",
    [
        CURLOPT_POST        => true,
        CURLOPT_POSTFIELDS  => http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $CLIENT_ID,
            'client_secret' => $CLIENT_SECRET,
            'scope'         => 'https://graph.microsoft.com/.default',
        ]),
        CURLOPT_HTTPHEADER  => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT     => 15,
    ]
);

$tok_data     = json_decode($tok_r['body'], true);
$access_token = $tok_data['access_token'] ?? null;

if (!$access_token) {
    log_msg("ERROR: No se pudo obtener token Azure: " . ($tok_data['error_description'] ?? $tok_r['body']));
    finalizar(false, 'token_error');
}
log_msg("Token OK");

// ── 2. TRAER CORREOS ──────────────────────────────────────────────────
// ✅ FIX #B1 — Watermark: usar fecha del último correo procesado guardada en Supabase
// En vez de siempre traer desde las 00:00, solo traer desde el último sync exitoso.
// Esto evita reprocesar los mismos 200 correos en cada ciclo de cron.

$WATERMARK_KEY = 'sync_watermark_correos'; // clave en configuration_sistema

$desde = null;

// Si se pasa ?horas=N manualmente (ej: para carga histórica), forzar eso
if (isset($_GET['horas'])) {
    $horas_manual = (int)$_GET['horas'];
    $desde = (new DateTime("-{$horas_manual} hours", new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    log_msg("Modo manual: ventana de {$horas_manual}h");
} else {
    // Leer watermark desde Supabase
    $wm_row = sbGet($SB_URL, $SB_KEY, "configuration_sistema?id=eq.main&select=data");
    $wm_data = $wm_row[0]['data'] ?? [];
    $ultima_fecha = $wm_data[$WATERMARK_KEY] ?? null;

    if ($ultima_fecha) {
        // Traer desde última fecha procesada menos 2 min de margen (evita gaps por latencia)
        $dt = new DateTime($ultima_fecha, new DateTimeZone('UTC'));
        $dt->modify('-2 minutes');
        $desde = $dt->format('Y-m-d\TH:i:s\Z');
        log_msg("Watermark encontrado: desde $desde (última sync: $ultima_fecha)");
    } else {
        // Primera vez o sin watermark: traer el día de hoy
        $hoy_bogota = new DateTime('today', new DateTimeZone('America/Bogota'));
        $hoy_utc    = clone $hoy_bogota;
        $hoy_utc->setTimezone(new DateTimeZone('UTC'));
        $desde = $hoy_utc->format('Y-m-d\TH:i:s\Z');
        log_msg("Sin watermark — usando inicio del día: $desde");
    }
}
$select   = 'id,subject,from,toRecipients,ccRecipients,bccRecipients,replyTo,receivedDateTime,sentDateTime,bodyPreview,body,hasAttachments,isRead,isDraft,conversationId,importance,internetMessageId,categories,flag';
// ID directo de Bandeja de entrada (más confiable que el alias 'Inbox' con 19k correos)
$INBOX_FOLDER_ID = 'AAMkAGQ2YzJjNGI2LTMzMGEtNDU0NC04ZThmLTQyZmE3OWE3Y2Q2MQAuAAAAAACxND13kBixQbsfNf09-SJMAQDfGH-v2n7eT5N6GkJDeq_8AAAAAAEMAAA=';

$filter   = urlencode("isDraft eq false and receivedDateTime ge {$desde}");
// Usar ID de carpeta directo en vez de alias 'Inbox' — más confiable en buzones grandes
$url_base = "https://graph.microsoft.com/v1.0/users/{$GRAPH_MAILBOX}/mailFolders/{$INBOX_FOLDER_ID}/messages";
// Sin $orderby — puede limitar resultados en filtros sin ConsistencyLevel
$url      = "{$url_base}?\$filter={$filter}&\$top=50&\$select={$select}";

log_msg("Trayendo correos desde $desde...");

$todos_correos = [];
$paginas       = 0;

while ($url) {
    $r = curlJson($url, [
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $access_token",
            // Sin ConsistencyLevel:eventual — ese header puede causar que Graph
            // omita mensajes recientes en índices no actualizados
        ],
        CURLOPT_TIMEOUT => 60,
    ]);

    if ($r['code'] !== 200) {
        log_msg("ERROR Graph: HTTP {$r['code']} — " . substr($r['body'], 0, 200));
        break;
    }

    $data          = json_decode($r['body'], true);
    $correos_pag   = $data['value'] ?? [];
    $todos_correos = array_merge($todos_correos, $correos_pag);
    $url           = $data['@odata.nextLink'] ?? null;
    $paginas++;
    log_msg("  Página $paginas: " . count($correos_pag) . " correos (total: " . count($todos_correos) . ")");
}

log_msg("Total correos a procesar: " . count($todos_correos));

if (empty($todos_correos)) {
    log_msg("Sin correos nuevos en la ventana. Fin.");
    finalizar(true, 'sin_correos');
}

// ── 3. PREPARAR PAYLOADS ──────────────────────────────────────────────
$stats    = ['insertados' => 0, 'actualizados' => 0, 'errores' => 0, 'clasificados' => 0, 'adjuntos' => 0];
$payloads = [];  // todos los payloads para batch upsert

// Mapa message_id → datos crudos del correo (para procesar adjuntos después)
$map_adjuntos = []; // message_id => ['has_attachments', 'raw']

foreach ($todos_correos as $c) {
    $msg_id      = $c['id']                ?? '';
    $inet_msg_id = $c['internetMessageId'] ?? '';

    if (!$msg_id) continue;

    $payloads[] = [
        'message_id'           => $msg_id,
        'internet_message_id'  => $inet_msg_id ?: null,
        'conversation_id'      => $c['conversationId']               ?? null,
        'subject'              => $c['subject']                       ?? '',
        'from_email'           => $c['from']['emailAddress']['address'] ?? '',
        'from_name'            => $c['from']['emailAddress']['name']    ?? '',
        'to_recipients'        => json_encode($c['toRecipients']        ?? []),
        'cc_recipients'        => json_encode($c['ccRecipients']        ?? []),
        'bcc_recipients'       => json_encode($c['bccRecipients']       ?? []),
        'reply_to'             => json_encode($c['replyTo']             ?? []),
        'received_at'          => $c['receivedDateTime']               ?? null,
        'sent_at'              => $c['sentDateTime']                   ?? null,
        'body_preview'         => mb_substr($c['bodyPreview'] ?? '', 0, 500),
        'body_content'         => $c['body']['content']                ?? '',
        'body_type'            => strtolower($c['body']['contentType'] ?? 'text'),
        'has_attachments'      => (bool)($c['hasAttachments']          ?? false),
        'is_read'              => (bool)($c['isRead']                  ?? false),
        'is_draft'             => (bool)($c['isDraft']                 ?? false),
        'importance'           => $c['importance']                     ?? 'normal',
        'categories'           => json_encode($c['categories']         ?? []),
        'flag_status'          => $c['flag']['flagStatus']             ?? 'notFlagged',
        'raw_payload'          => json_encode($c),
        'origen'               => 'graph_sync',
        'canal_contacto'       => 'correo',
        'updated_at'           => date('c'),
    ];

    if (!empty($c['hasAttachments'])) {
        $map_adjuntos[$msg_id] = $c;
    }
}

// ── 4. BATCH UPSERT EN SUPABASE ───────────────────────────────────────
// En lugar de 1 request por correo, enviamos lotes de BATCH_SIZE.
// Esto reduce dramaticamente las conexiones simultáneas a Postgres.
log_msg("Iniciando batch upsert (" . count($payloads) . " correos en lotes de " . BATCH_SIZE . ")...");

$lotes           = array_chunk($payloads, BATCH_SIZE);
$correos_db_map  = []; // message_id => fila devuelta por Supabase

foreach ($lotes as $idx => $lote) {
    $r = sbUpsert($SB_URL, $SB_KEY, 'correos', $lote, 'message_id');

    if ($r['code'] >= 200 && $r['code'] < 300) {
        $inserted = json_decode($r['body'], true) ?? [];
        foreach ($inserted as $row) {
            $correos_db_map[$row['message_id']] = $row;
        }
        log_msg("  Lote " . ($idx + 1) . "/" . count($lotes) . ": OK (" . count($inserted) . " filas)");
    } else {
        $stats['errores'] += count($lote);
        log_msg("  ERROR lote " . ($idx + 1) . ": HTTP {$r['code']} — " . substr($r['body'], 0, 150));
    }

    // Pausa entre lotes: evitar saturar Postgres en Nano
    if ($idx < count($lotes) - 1) {
        usleep(150_000); // 150ms entre lotes
    }
}

// ── 5. PATCH ESTADO INICIAL + ARMAR LISTA PARA CLASIFICAR ────────────
// Solo tocamos filas nuevas (sin sentimiento). Un PATCH por correo nuevo,
// pero con pausa entre cada uno para no apilar locks.
$correos_para_clasificar = [];

foreach ($correos_db_map as $msg_id => $row) {
    $correo_id  = $row['id']          ?? null;
    $es_nuevo   = empty($row['sentimiento']);
    $tiene_adj  = (bool)($row['has_attachments'] ?? false);

    if (!$correo_id) continue;

    if ($es_nuevo) {
        $stats['insertados']++;

        // Patch estado inicial (solo correos nuevos)
        sbPatch($SB_URL, $SB_KEY, 'correos', "id=eq.$correo_id", [
            'estado'     => 'pendiente',
            'prioridad'  => 'media',
            'created_at' => date('c'),
        ]);
        usleep(80_000); // 80ms entre patches para no apilar locks

        // Buscar datos crudos originales para clasificación
        $raw = null;
        foreach ($todos_correos as $c) {
            if (($c['id'] ?? '') === $msg_id) { $raw = $c; break; }
        }

        if ($raw) {
            $correos_para_clasificar[] = [
                'correo_id'  => $correo_id,
                'msg_id'     => $msg_id,
                'subject'    => $raw['subject'] ?? '',
                'body'       => mb_substr($raw['body']['content'] ?? $raw['bodyPreview'] ?? '', 0, 2000),
                'tiene_adj'  => $tiene_adj,
                'from_email' => $raw['from']['emailAddress']['address'] ?? '',
            ];
        }
    } else {
        $stats['actualizados']++;
    }
}

log_msg("Upsert completo — insertados: {$stats['insertados']}, actualizados: {$stats['actualizados']}, errores: {$stats['errores']}");

// ── 6. ADJUNTOS ───────────────────────────────────────────────────────
// Procesar adjuntos con pausa entre correos para no saturar Storage.
if (!empty($map_adjuntos)) {
    log_msg("Procesando adjuntos de " . count($map_adjuntos) . " correo(s)...");
    $adj_idx = 0;
    foreach ($map_adjuntos as $msg_id => $raw_correo) {
        $row       = $correos_db_map[$msg_id] ?? null;
        $correo_id = $row['id'] ?? null;
        if (!$correo_id) continue;

        $adj_count = procesarAdjuntos($correo_id, $msg_id, $access_token, $SB_URL, $SB_KEY);
        $stats['adjuntos'] += $adj_count;

        $adj_idx++;
        // Pausa entre correos con adjuntos (no entre cada adjunto individual;
        // procesarAdjuntos ya tiene su propia pausa interna)
        if ($adj_idx < count($map_adjuntos)) {
            usleep(200_000); // 200ms entre correos
        }
    }
    log_msg("Adjuntos procesados: {$stats['adjuntos']}");
}

// ── 7. CLASIFICACIÓN IA — solo correos externos nuevos ────────────────
// Esta sección ya NO bloquea el upsert — corre después de que todo
// está guardado en BD, con pausa generosa entre llamadas a OpenAI.
if ($OPENAI_KEY && !empty($correos_para_clasificar)) {
    log_msg("Clasificando " . count($correos_para_clasificar) . " correo(s) con GPT-4o-mini...");

    foreach ($correos_para_clasificar as $item) {
        $from_email   = strtolower($item['from_email'] ?? '');
        $texto        = strip_tags($item['body']);
        $texto        = preg_replace('/\s+/', ' ', $texto);
        $texto        = mb_substr(trim($texto), 0, 1500);

        if (strlen($texto) < 10) continue;

        // Excluir correos del propio dominio (notificaciones internas)
        if (str_contains($from_email, 'tododrogas.com.co') || str_contains($from_email, 'pqrsfd@')) continue;

        $asunto_lower = strtolower($item['subject']);
        $es_interno   = (
            str_contains($asunto_lower, 'encuesta')                    ||
            str_contains($item['subject'], '⭐')                       ||
            str_contains($item['subject'], '✅')                       ||
            str_contains($asunto_lower, 'su solicitud fue')            ||
            str_contains($asunto_lower, 'gracias por su opinión')      ||
            preg_match('/^\[td-\d{8}-\d{4}\]/i', $item['subject'])    ||
            str_starts_with(trim($item['subject']), 'RV:')             ||
            str_starts_with(trim($item['subject']), 'RE:')
        );
        if ($es_interno) continue;

        $prompt = <<<PROMPT
Analiza este correo recibido en una droguería colombiana. Responde SOLO JSON válido sin markdown.

ASUNTO: {$item['subject']}
TEXTO: $texto

JSON requerido:
{
  "tipo_pqr": "peticion|queja|reclamo|sugerencia|felicitacion|denuncia|informacion",
  "sentimiento": "positivo|neutro|negativo|urgente",
  "tono": "enojado|frustrado|triste|ansioso|neutro|satisfecho|agradecido",
  "prioridad": "baja|media|alta|critica",
  "nivel_riesgo": "bajo|medio|alto|critico",
  "resumen": "máximo 100 caracteres",
  "ley": "ley colombiana aplicable o N/A",
  "horas_sla": numero entero,
  "categoria": "descripción breve de la categoría"
}

REGLAS:
- Si el texto es positivo/agradecido → sentimiento=positivo, prioridad=baja, horas_sla=360
- Si hay urgencia médica o riesgo de salud → sentimiento=urgente, prioridad=critica, horas_sla=4
- Si es queja/reclamo real → prioridad=alta, horas_sla=72
- Si es informativo/petición → prioridad=media, horas_sla=120
PROMPT;

        $ai_r = curlJson('https://api.openai.com/v1/chat/completions', [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model'       => 'gpt-4o-mini',
                'max_tokens'  => 300,
                'temperature' => 0.1,
                'messages'    => [
                    ['role' => 'system', 'content' => 'Clasificador de correos de droguería colombiana. Responde SOLO JSON válido.'],
                    ['role' => 'user',   'content' => $prompt],
                ],
            ]),
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $OPENAI_KEY",
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);

        if ($ai_r['code'] === 200) {
            $ai_data = json_decode($ai_r['body'], true);
            $ai_text = $ai_data['choices'][0]['message']['content'] ?? '';
            $ai_text = preg_replace('/```json|```/', '', $ai_text);
            $ia      = json_decode(trim($ai_text), true);

            if ($ia) {
                $horas_sla        = intval($ia['horas_sla'] ?? 120);
                $fecha_limite_sla = date('c', strtotime("+{$horas_sla} hours"));

                $fecha_hoy = date('Ymd');
                $rand      = str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                $ticket_id = "EXT-{$fecha_hoy}-{$rand}";

                sbPatch($SB_URL, $SB_KEY, 'correos', "id=eq.{$item['correo_id']}", [
                    'tipo_pqr'         => $ia['tipo_pqr']     ?? 'peticion',
                    'sentimiento'      => $ia['sentimiento']  ?? 'neutro',
                    'datos_legales'    => json_encode(['tono' => $ia['tono'] ?? 'neutro']),
                    'prioridad'        => $ia['prioridad']    ?? 'media',
                    'nivel_riesgo'     => $ia['nivel_riesgo'] ?? 'bajo',
                    'resumen_corto'    => mb_substr($ia['resumen'] ?? '', 0, 150),
                    'ley_aplicable'    => $ia['ley']          ?? 'Ley 1755/2015',
                    'categoria_ia'     => $ia['categoria']    ?? '',
                    'horas_sla'        => $horas_sla,
                    'fecha_limite_sla' => $fecha_limite_sla,
                    'es_urgente'       => ($ia['sentimiento'] === 'urgente' || $ia['prioridad'] === 'critica'),
                    'estado'           => 'pendiente',
                    'ticket_id'        => $ticket_id,
                    'updated_at'       => date('c'),
                ]);

                $stats['clasificados']++;
                log_msg("  Clasificado {$item['correo_id']}: {$ia['sentimiento']} / {$ia['prioridad']}");
            } else {
                log_msg("  WARN: GPT devolvió JSON inválido para {$item['correo_id']}");
            }
        } else {
            log_msg("  ERROR GPT {$item['correo_id']}: HTTP {$ai_r['code']}");
        }

        usleep(300_000); // 300ms entre llamadas a OpenAI (rate limit seguro)
    }
}

// ── 8. REGISTRAR RESUMEN EN HISTORIAL ────────────────────────────────
sbPost($SB_URL, $SB_KEY, 'historial_eventos', [
    'evento'      => 'sync_correos',
    'descripcion' => "Sync v2 completado. Insertados: {$stats['insertados']}, Actualizados: {$stats['actualizados']}, Adjuntos: {$stats['adjuntos']}, Clasificados: {$stats['clasificados']}, Errores: {$stats['errores']}",
    'datos_extra' => json_encode([
        'ventana_horas' => $HORAS_VENTANA,
        'desde'         => $desde,
        'total_correos' => count($todos_correos),
        'lotes'         => count($lotes ?? []),
        'stats'         => $stats,
    ]),
    'created_at' => date('c'),
]);

// ✅ FIX #B1 — Guardar watermark: fecha del correo más reciente procesado
if (!empty($todos_correos) && !isset($_GET['horas'])) {
    // Encontrar la receivedDateTime más reciente del lote procesado
    $max_fecha = null;
    foreach ($todos_correos as $c) {
        $f = $c['receivedDateTime'] ?? null;
        if ($f && (!$max_fecha || $f > $max_fecha)) {
            $max_fecha = $f;
        }
    }
    if ($max_fecha) {
        // Leer config actual y hacer merge para no sobreescribir otras claves
        $cfg_actual = sbGet($SB_URL, $SB_KEY, "configuration_sistema?id=eq.main&select=data");
        $cfg_data   = $cfg_actual[0]['data'] ?? [];
        $cfg_data[$WATERMARK_KEY] = $max_fecha;
        sbPatch($SB_URL, $SB_KEY, 'configuration_sistema', 'id=eq.main', [
            'data'       => $cfg_data,
            'updated_at' => date('c'),
        ]);
        log_msg("Watermark actualizado: $max_fecha");
    }
}

log_msg("=== SYNC END v2 ===");
finalizar(true, 'ok');


// ══════════════════════════════════════════════════════════════════════
// FUNCIÓN: PROCESAR ADJUNTOS
// ══════════════════════════════════════════════════════════════════════
function procesarAdjuntos(string $correo_id, string $msg_id, string $token, string $SB_URL, string $SB_KEY): int {
    $GRAPH_MAILBOX = 'pqrsfd@tododrogas.com.co';

    // Verificar adjuntos ya registrados
    $existentes     = sbGet($SB_URL, $SB_KEY, "adjuntos?correo_id=eq.{$correo_id}&select=attachment_id");
    $ids_existentes = array_column($existentes ?? [], 'attachment_id');

    // Traer lista de adjuntos de Graph
    $r = curlJson(
        "https://graph.microsoft.com/v1.0/users/{$GRAPH_MAILBOX}/messages/" . urlencode($msg_id) . "/attachments?\$top=150&\$select=id,name,contentType,size,isInline",
        [
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
            CURLOPT_TIMEOUT    => 30,
        ]
    );

    if ($r['code'] !== 200) return 0;

    $adj_data = json_decode($r['body'], true);
    $adjuntos = $adj_data['value'] ?? [];

    // Filtrar imágenes inline pequeñas (logos, firmas HTML)
    $adjuntos = array_filter($adjuntos, function ($adj) {
        $ct     = strtolower($adj['contentType'] ?? '');
        $nombre = $adj['name'] ?? '';
        $tam    = $adj['size'] ?? 0;
        $inline = (bool)($adj['isInline'] ?? false);
        $es_img = str_starts_with($ct, 'image/');

        if (!$inline) return true;
        if ($es_img && $tam < 150000) return false;
        if ($es_img && preg_match('/^Outlook-[a-z0-9]+\.(png|gif|jpg|jpeg|bmp|webp)$/i', $nombre)) return false;
        if ($es_img && (!$nombre || preg_match('/^\.[a-z]+$/i', $nombre))) return false;
        return true;
    });

    $count = 0;
    foreach ($adjuntos as $adj) {
        $adj_id = $adj['id'] ?? '';
        if (!$adj_id) continue;
        if (in_array($adj_id, $ids_existentes)) continue;

        $nombre = $adj['name']        ?? 'adjunto_sin_nombre';
        $ct     = $adj['contentType'] ?? 'application/octet-stream';
        $tam    = $adj['size']        ?? 0;
        $inline = (bool)($adj['isInline'] ?? false);

        // Saltar adjuntos > 50MB
        if ($tam > 52_428_800) {
            log_msg("  Adjunto muy grande (>50MB), omitido: $nombre");
            continue;
        }

        // Descargar bytes
        $download_url = "https://graph.microsoft.com/v1.0/users/{$GRAPH_MAILBOX}/messages/" . urlencode($msg_id) . "/attachments/{$adj_id}/\$value";
        $dl = curlJson($download_url, [
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
            CURLOPT_TIMEOUT    => 120,
        ]);

        if ($dl['code'] !== 200 || !$dl['body']) {
            log_msg("  ERROR descargando adjunto $nombre: HTTP {$dl['code']}");
            continue;
        }

        $bytes = $dl['body'];

        // Determinar bucket y ruta
        $es_audio    = str_starts_with(strtolower($ct), 'audio/');
        $bucket      = $es_audio ? 'audios' : 'adjuntos-pqr';
        $safe_nombre = preg_replace('/[^a-zA-Z0-9.\-_]/', '_', $nombre);
        $ts          = round(microtime(true) * 1000);
        $folder      = $es_audio ? $correo_id : "adjuntos/$correo_id";
        $path        = "{$folder}/{$ts}_{$safe_nombre}";
        $storage_url = "{$SB_URL}/storage/v1/object/public/{$bucket}/{$path}";

        // Normalizar Content-Type
        if (in_array($ct, ['application/x-zip-compressed', 'application/x-zip'])) $ct = 'application/zip';
        if ($ct === 'application/x-rar-compressed') $ct = 'application/octet-stream';

        // Subir a Supabase Storage
        $upload_r = curlJson("{$SB_URL}/storage/v1/object/{$bucket}/{$path}", [
            CURLOPT_POST        => true,
            CURLOPT_POSTFIELDS  => $bytes,
            CURLOPT_HTTPHEADER  => [
                "apikey: $SB_KEY",
                "Authorization: Bearer $SB_KEY",
                "Content-Type: $ct",
                'x-upsert: true',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);

        if ($upload_r['code'] < 200 || $upload_r['code'] >= 300) {
            log_msg("  ERROR subiendo $nombre a Storage: HTTP {$upload_r['code']}");
            continue;
        }

        // Registrar en tabla adjuntos
        sbPost($SB_URL, $SB_KEY, 'adjuntos', [
            'correo_id'      => $correo_id,
            'attachment_id'  => $adj_id,
            'message_id'     => $msg_id,
            'nombre'         => $nombre,
            'tipo_contenido' => $ct,
            'tamano_bytes'   => $tam,
            'es_inline'      => $inline,
            'storage_url'    => $storage_url,
            'storage_path'   => $path,
            'direccion'      => 'entrante',
            'enviado_por'    => 'php-sync-v2',
            'created_at'     => date('c'),
        ]);

        $count++;
        log_msg("  ✅ Adjunto subido: $nombre ($bucket)");

        // Pausa entre adjuntos individuales
        usleep(100_000); // 100ms
    }

    return $count;
}


// ══════════════════════════════════════════════════════════════════════
// FINALIZAR
// ══════════════════════════════════════════════════════════════════════
function finalizar(bool $ok, string $motivo): void {
    global $log, $stats, $es_web;
    $out = [
        'ok'     => $ok,
        'motivo' => $motivo,
        'stats'  => $stats ?? [],
        'log'    => $log,
        'ts'     => date('c'),
    ];
    if ($es_web) {
        http_response_code($ok ? 200 : 500);
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    exit;
}
