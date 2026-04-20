<?php
/**
 * sincronizar-correos.php v3 — Tododrogas CIA SAS
 * ─────────────────────────────────────────────────────────────────────
 * CAMBIOS v3 vs v2:
 *  - Cursor persistente en configuracion_sistema.data.sync.cursor
 *    → nunca pierde correos aunque el cron falle varios minutos
 *  - Cron cada minuto (en vez de 5)
 *  - Botón "Iniciar sync" en admin activa el sistema y fija ciclo_inicio
 *  - Botón "Nuevo ciclo" en admin genera backup JSON en Storage y limpia tablas
 *  - Orden garantizado: receivedDateTime ASC (igual que Outlook)
 *  - Adjuntos procesados DENTRO del loop de cada correo antes de avanzar cursor
 *  - Fix: historial_eventos usa correos.id (UUID), no ticket_id
 *  - Auto-cierre de tickets positivos/felicitaciones con evento 'cerrado_automatico'
 *  - CORS restringido a tododrogas.online
 *  - FIX: to_recipients, cc_recipients, bcc_recipients ahora se guardan correctamente
 *
 * ENDPOINTS:
 *  - Cron cada minuto: php sincronizar-correos.php
 *  - Iniciar sync:     GET ?accion=iniciar&token=ADMIN_TOKEN
 *  - Nuevo ciclo:      GET ?accion=nuevo_ciclo&token=ADMIN_TOKEN
 *  - Estado:           GET ?accion=estado&token=ADMIN_TOKEN
 *  - Forzar ventana:   GET ?accion=sync&horas=24&token=ADMIN_TOKEN
 */

date_default_timezone_set('America/Bogota');

// ── CORS ──────────────────────────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed_origins = ['https://tododrogas.online', 'https://www.tododrogas.online'];
if ($origin && in_array($origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Content-Type: application/json; charset=utf-8');

// ── CREDENCIALES ──────────────────────────────────────────────────────
$SB_URL        = '__SB_URL__';
$SB_KEY        = '__SB_KEY__';
$OPENAI_KEY    = '__OPENAI_KEY__';
$GRAPH_MAILBOX = 'pqrsfd@tododrogas.com.co';
$INBOX_ID      = '__INBOX_FOLDER_ID__';
$AZURE_TENANT  = '__AZURE_TENANT_ID__';
$AZURE_CLIENT  = '__AZURE_CLIENT_ID__';
$AZURE_SECRET  = '__AZURE_CLIENT_SECRET__';
$ADMIN_TOKEN   = '__ADMIN_TOKEN__';

// ── LOCK — evitar ejecuciones solapadas ──────────────────────────────
define('LOCK_FILE', '/tmp/sync-correos-v3.lock');
define('LOCK_MAX_AGE', 180); // 3 min — si el lock tiene más, está muerto

$accion   = $_GET['accion'] ?? 'sync';
$token_ok = isset($_GET['token']) && $_GET['token'] === $ADMIN_TOKEN;
$es_web   = PHP_SAPI !== 'cli';
$log      = [];

// Acciones web requieren token
if ($es_web && !$token_ok) {
    http_response_code(403);
    echo json_encode(['error' => 'Token requerido']);
    exit;
}

// ── HELPERS BÁSICOS ───────────────────────────────────────────────────
function log_msg(string $msg): void {
    global $log;
    $ts   = date('H:i:s');
    $line = "[$ts] $msg";
    $log[] = $line;
    error_log("[sync-v3] $msg");
}

function finalizar(bool $ok, string $motivo, array $extra = []): void {
    global $log, $es_web;
    $out = array_merge(['ok' => $ok, 'motivo' => $motivo, 'log' => $log, 'ts' => date('c')], $extra);
    if ($es_web) {
        http_response_code($ok ? 200 : 500);
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    exit;
}

// ── HELPERS SUPABASE ──────────────────────────────────────────────────
function sbGet(string $endpoint): ?array {
    global $SB_URL, $SB_KEY;
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
    return ($code >= 200 && $code < 300) ? (json_decode($resp, true) ?? []) : null;
}

function sbPost(string $endpoint, array $data): array {
    global $SB_URL, $SB_KEY;
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
            'Prefer: return=representation',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $resp, 'data' => json_decode($resp, true)];
}

function sbPatch(string $endpoint, string $filter, array $data): array {
    global $SB_URL, $SB_KEY;
    $ch = curl_init("$SB_URL/rest/v1/$endpoint?$filter");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
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

function sbUpsert(string $endpoint, array $rows, string $conflict): array {
    global $SB_URL, $SB_KEY;
    $ch = curl_init("$SB_URL/rest/v1/$endpoint");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($rows),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            "apikey: $SB_KEY",
            "Authorization: Bearer $SB_KEY",
            'Content-Type: application/json',
            "Prefer: resolution=merge-duplicates,return=representation,missing=default",
            "on_conflict: $conflict",
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $resp, 'data' => json_decode($resp, true) ?? []];
}

function sbDelete(string $endpoint, string $filter): array {
    global $SB_URL, $SB_KEY;
    $ch = curl_init("$SB_URL/rest/v1/$endpoint?$filter");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            "apikey: $SB_KEY",
            "Authorization: Bearer $SB_KEY",
            'Prefer: return=minimal',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code];
}

// ── CURSOR Y CONFIG ───────────────────────────────────────────────────
function getConfig(): array {
    $rows = sbGet('configuracion_sistema?id=eq.main&select=data');
    return $rows[0]['data'] ?? [];
}

function getSyncConfig(): array {
    $cfg = getConfig();
    return $cfg['sync'] ?? [];
}

function saveSyncConfig(array $sync_data): void {
    $cfg         = getConfig();
    $cfg['sync'] = array_merge($cfg['sync'] ?? [], $sync_data);
    sbPatch('configuracion_sistema', 'id=eq.main', [
        'data'       => $cfg,
        'updated_at' => date('c'),
    ]);
}

// ── TOKEN GRAPH ───────────────────────────────────────────────────────
function getGraphToken(): ?string {
    global $AZURE_TENANT, $AZURE_CLIENT, $AZURE_SECRET;
    $ch = curl_init("https://login.microsoftonline.com/{$AZURE_TENANT}/oauth2/v2.0/token");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $AZURE_CLIENT,
            'client_secret' => $AZURE_SECRET,
            'scope'         => 'https://graph.microsoft.com/.default',
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return $data['access_token'] ?? null;
}

// ══════════════════════════════════════════════════════════════════════
// ACCIÓN: ESTADO
// ══════════════════════════════════════════════════════════════════════
if ($accion === 'estado') {
    $sync = getSyncConfig();
    finalizar(true, 'ok', ['sync' => $sync]);
}

// ══════════════════════════════════════════════════════════════════════
// ACCIÓN: INICIAR
// ══════════════════════════════════════════════════════════════════════
if ($accion === 'iniciar') {
    $ahora  = date('c');
    $cursor = date('c', strtotime('-1 minute'));

    saveSyncConfig([
        'activo'        => true,
        'cursor'        => $cursor,
        'ciclo_inicio'  => $ahora,
        'ciclo_id'      => 'ciclo_' . date('Y_m_d'),
        'iniciado_en'   => $ahora,
        'ultimo_sync'   => null,
        'total_correos' => 0,
        'total_adjuntos'=> 0,
    ]);

    log_msg("✅ Sincronización iniciada. Cursor: $cursor");
    finalizar(true, 'iniciado', ['cursor' => $cursor, 'ciclo_inicio' => $ahora]);
}

// ══════════════════════════════════════════════════════════════════════
// ACCIÓN: NUEVO CICLO
// ══════════════════════════════════════════════════════════════════════
if ($accion === 'nuevo_ciclo') {
    log_msg("=== INICIANDO NUEVO CICLO ===");

    $sync     = getSyncConfig();
    $ciclo_id = $sync['ciclo_id'] ?? 'ciclo_desconocido';
    $ahora    = date('c');

    log_msg("Exportando correos del ciclo $ciclo_id...");
    $correos_backup = sbGet('correos?select=*&order=received_at.asc&limit=10000') ?? [];
    $adj_backup     = sbGet('adjuntos?select=*&order=created_at.asc&limit=50000') ?? [];
    $hist_backup    = sbGet('historial_eventos?select=*&order=created_at.asc&limit=50000') ?? [];

    $backup_data = json_encode([
        'ciclo_id'        => $ciclo_id,
        'exported_at'     => $ahora,
        'ciclo_inicio'    => $sync['ciclo_inicio'] ?? null,
        'total_correos'   => count($correos_backup),
        'total_adjuntos'  => count($adj_backup),
        'total_historial' => count($hist_backup),
        'correos'         => $correos_backup,
        'adjuntos'        => $adj_backup,
        'historial'       => $hist_backup,
    ], JSON_UNESCAPED_UNICODE);

    $backup_path = "backups/{$ciclo_id}_" . date('Ymd_His') . ".json";
    $ch = curl_init("$SB_URL/storage/v1/object/backups-pqr/$backup_path");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $backup_data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            "apikey: $SB_KEY",
            "Authorization: Bearer $SB_KEY",
            'Content-Type: application/json',
            'x-upsert: true',
        ],
    ]);
    $up_resp = curl_exec($ch);
    $up_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($up_code < 200 || $up_code >= 300) {
        log_msg("❌ ERROR subiendo backup: HTTP $up_code — " . substr($up_resp, 0, 200));
        finalizar(false, 'backup_fallido', ['http_code' => $up_code]);
    }
    log_msg("✅ Backup subido: $backup_path (" . round(strlen($backup_data)/1024) . " KB)");

    log_msg("Limpiando adjuntos de Storage...");
    $eliminados_storage = 0;
    $por_bucket = [];
    foreach ($adj_backup as $adj) {
        $bucket = str_starts_with($adj['storage_path'] ?? '', 'adjuntos/')
            ? 'adjuntos-pqr'
            : 'audios';
        $por_bucket[$bucket][] = $adj['storage_path'];
    }

    foreach ($por_bucket as $bucket => $paths) {
        $ch = curl_init("$SB_URL/storage/v1/object/delete");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'bucket'   => $bucket,
                'prefixes' => array_values($paths),
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                "apikey: $SB_KEY",
                "Authorization: Bearer $SB_KEY",
                'Content-Type: application/json',
            ],
        ]);
        $del_resp = curl_exec($ch);
        $del_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $eliminados_storage += count($paths);
        log_msg("  Storage $bucket: $del_code (" . count($paths) . " archivos)");
        usleep(500_000);
    }

    log_msg("Limpiando tabla historial_eventos...");
    sbDelete('historial_eventos', 'id=not.is.null');
    usleep(300_000);

    log_msg("Limpiando tabla adjuntos...");
    sbDelete('adjuntos', 'id=not.is.null');
    usleep(300_000);

    log_msg("Limpiando tabla correos...");
    sbDelete('correos', 'id=not.is.null');
    usleep(500_000);

    $nuevo_cursor   = date('c', strtotime('-1 minute'));
    $nuevo_ciclo_id = 'ciclo_' . date('Y_m_d_His');

    saveSyncConfig([
        'activo'               => true,
        'cursor'               => $nuevo_cursor,
        'ciclo_inicio'         => $ahora,
        'ciclo_id'             => $nuevo_ciclo_id,
        'ultimo_sync'          => null,
        'total_correos'        => 0,
        'total_adjuntos'       => 0,
        'ultimo_ciclo_backup'  => $backup_path,
        'ultimo_ciclo_correos' => count($correos_backup),
    ]);

    log_msg("✅ Nuevo ciclo iniciado: $nuevo_ciclo_id");
    finalizar(true, 'nuevo_ciclo_ok', [
        'ciclo_anterior'      => $ciclo_id,
        'backup_path'         => $backup_path,
        'correos_backup'      => count($correos_backup),
        'adjuntos_backup'     => count($adj_backup),
        'eliminados_storage'  => $eliminados_storage,
        'nuevo_ciclo_id'      => $nuevo_ciclo_id,
    ]);
}

// ══════════════════════════════════════════════════════════════════════
// ACCIÓN: SYNC — sincronización principal (cron cada minuto)
// ══════════════════════════════════════════════════════════════════════

$sync = getSyncConfig();

if (empty($sync['activo'])) {
    log_msg("Sync no activo. Usa ?accion=iniciar&token=TOKEN para activar.");
    finalizar(true, 'inactivo');
}

// ── Lock ──────────────────────────────────────────────────────────────
if (file_exists(LOCK_FILE)) {
    $pid      = (int)file_get_contents(LOCK_FILE);
    $lock_age = time() - filemtime(LOCK_FILE);
    if ($lock_age < LOCK_MAX_AGE && posix_kill($pid, 0)) {
        log_msg("Ya hay sync corriendo (PID: $pid, hace {$lock_age}s). Saltando.");
        finalizar(true, 'ya_corriendo');
    }
    log_msg("Lock muerto ($lock_age s). Continuando.");
}
file_put_contents(LOCK_FILE, getmypid());
register_shutdown_function(fn() => @unlink(LOCK_FILE));

// ── Cursor ────────────────────────────────────────────────────────────
$cursor_actual = $sync['cursor'] ?? null;
if (!$cursor_actual) {
    log_msg("Sin cursor. Inicia el sync con ?accion=iniciar.");
    finalizar(false, 'sin_cursor');
}

$cursor_dt  = new DateTime($cursor_actual, new DateTimeZone('UTC'));
$cursor_iso = $cursor_dt->format('Y-m-d\TH:i:s\Z');

log_msg("=== SYNC v3 === Cursor: $cursor_iso");

// ── Token Graph ───────────────────────────────────────────────────────
$token = getGraphToken();
if (!$token) {
    log_msg("❌ Error obteniendo token Graph.");
    finalizar(false, 'token_error');
}

// ── Traer correos nuevos ──────────────────────────────────────────────
$filter   = urlencode("isDraft eq false and receivedDateTime gt $cursor_iso");
$url_base = "https://graph.microsoft.com/v1.0/users/{$GRAPH_MAILBOX}/mailFolders/{$INBOX_ID}/messages"
          . "?\$filter=$filter"
          . "&\$orderby=" . urlencode('receivedDateTime asc')
          . "&\$count=true"
          . "&\$top=50"
          . "&\$select=id,subject,from,toRecipients,ccRecipients,bccRecipients,receivedDateTime,hasAttachments,bodyPreview,body,importance,isRead,conversationId,internetMessageId,flag";

$todos_correos = [];
$paginas       = 0;
$next_link     = $url_base;

while ($next_link) {
    $ch = curl_init($next_link);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", 'ConsistencyLevel: eventual'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        log_msg("❌ Graph API error $code en página $paginas");
        break;
    }

    $data          = json_decode($resp, true);
    $pagina_items  = $data['value'] ?? [];
    $todos_correos = array_merge($todos_correos, $pagina_items);
    $paginas++;

    log_msg("  Página $paginas: " . count($pagina_items) . " correos (total: " . count($todos_correos) . ")");

    $next_link = $data['@odata.nextLink'] ?? null;
    if ($next_link) usleep(200_000);
}

log_msg("Total correos nuevos: " . count($todos_correos));

if (empty($todos_correos)) {
    saveSyncConfig(['ultimo_sync' => date('c')]);
    log_msg("Sin correos nuevos. Fin.");
    finalizar(true, 'sin_correos_nuevos');
}

// ── Procesar correos uno a uno ────────────────────────────────────────
$stats = [
    'insertados'   => 0,
    'actualizados' => 0,
    'errores'      => 0,
    'adjuntos'     => 0,
    'clasificados' => 0,
    'auto_cerrados'=> 0,
];

$nuevo_cursor = $cursor_actual;

foreach ($todos_correos as $c) {
    $msg_id       = $c['id'] ?? '';
    $received_raw = $c['receivedDateTime'] ?? '';
    if (!$msg_id || !$received_raw) continue;

    // ── Datos básicos ─────────────────────────────────────────────────
    $from_email  = strtolower($c['from']['emailAddress']['address'] ?? '');
    $from_name   = $c['from']['emailAddress']['name'] ?? '';
    $subject     = $c['subject'] ?? '(sin asunto)';
    $body_cont   = $c['body']['content'] ?? $c['bodyPreview'] ?? '';
    $body_prev   = mb_substr($c['bodyPreview'] ?? '', 0, 500);
    $body_type   = $c['body']['contentType'] ?? 'text';
    $has_adj     = (bool)($c['hasAttachments'] ?? false);
    $conv_id     = $c['conversationId'] ?? null;
    $internet_id = $c['internetMessageId'] ?? null;
    $importance  = strtolower($c['importance'] ?? 'normal');
    $is_read     = (bool)($c['isRead'] ?? false);
    $flag_st     = $c['flag']['flagStatus'] ?? 'notFlagged';

    // ── Destinatarios — FIX: guardar to/cc/bcc ────────────────────────
    $to_recipients = json_encode(array_map(fn($r) => [
        'name'    => $r['emailAddress']['name']    ?? '',
        'address' => $r['emailAddress']['address'] ?? '',
    ], $c['toRecipients'] ?? []));

    $cc_recipients = json_encode(array_map(fn($r) => [
        'name'    => $r['emailAddress']['name']    ?? '',
        'address' => $r['emailAddress']['address'] ?? '',
    ], $c['ccRecipients'] ?? []));

    $bcc_recipients = json_encode(array_map(fn($r) => [
        'name'    => $r['emailAddress']['name']    ?? '',
        'address' => $r['emailAddress']['address'] ?? '',
    ], $c['bccRecipients'] ?? []));

    // ── Ticket ID ─────────────────────────────────────────────────────
    $ticket_id = null;
    if (preg_match('/\[?(TD-\d{8}-\d{4})\]?/i', $subject, $m)) {
        $ticket_id = $m[1];
    }

    // ── Origen ────────────────────────────────────────────────────────
    $origen = 'graph_sync';
    if (preg_match('/NOVA\s+TD\s+DIRECTO/ui', $subject))    $origen = 'nova_directo';
    elseif (preg_match('/NOVA\s+TD/ui', $subject))           $origen = 'nova_web';
    elseif (preg_match('/📷\s*QR|\bQR\b/u', $subject))      $origen = 'qr';
    elseif ($ticket_id)                                       $origen = 'web';

    // ── Payload ───────────────────────────────────────────────────────
    $payload = [
        'message_id'          => $msg_id,
        'internet_message_id' => $internet_id,
        'conversation_id'     => $conv_id,
        'from_email'          => $from_email,
        'from_name'           => $from_name,
        'to_recipients'       => $to_recipients,   // ← FIX
        'cc_recipients'       => $cc_recipients,   // ← FIX
        'bcc_recipients'      => $bcc_recipients,  // ← FIX
        'subject'             => $subject,
        'body_preview'        => $body_prev,
        'body_content'        => $body_cont,
        'body_type'           => $body_type,
        'has_attachments'     => $has_adj,
        'importance'          => $importance,
        'is_read'             => $is_read,
        'flag_status'         => $flag_st,
        'received_at'         => $received_raw,
        'origen'              => $origen,
        'canal_contacto'      => 'correo',
    ];

    if ($ticket_id) $payload['ticket_id'] = $ticket_id;

    // Extraer cédula del body
    if ($ticket_id) {
        $body_texto = strip_tags($body_cont ?? '');
        if (preg_match('/(?:C[ée]dula|Documento|C\.C\.|Doc\.?)[:\s#]*(\d{6,12})/i', $body_texto, $mc)) {
            $payload['cedula'] = preg_replace('/\D/', '', $mc[1]);
        }
    }

    // ── Upsert ────────────────────────────────────────────────────────
    $r = sbUpsert('correos', [$payload], 'message_id');

    if ($r['code'] === 409) {
        log_msg("  ⚠️  Duplicado ignorado {$msg_id}");
        $nuevo_cursor = $received_raw;
        continue;
    }
if ($r['code'] === 409) {
    // Actualizar destinatarios aunque el correo ya exista
    sbPatch('correos', "message_id=eq.$msg_id", [
        'to_recipients'  => $to_recipients,
        'cc_recipients'  => $cc_recipients,
        'bcc_recipients' => $bcc_recipients,
    ]);
    log_msg("  ⚠️  Duplicado — destinatarios actualizados {$msg_id}");
    $nuevo_cursor = $received_raw;
    continue;
}
    }

    $fila    = $r['data'][0] ?? null;
    $corr_id = $fila['id'] ?? null;
    $es_nuevo = empty($fila['sentimiento']);

    if (!$corr_id) {
        log_msg("  ⚠️  Sin correo_id en respuesta (ya existía) — avanzando cursor: {$msg_id}");
        $nuevo_cursor = $received_raw;
        continue;
    }

    if ($es_nuevo) {
        $stats['insertados']++;
        sbPatch('correos', "id=eq.$corr_id", [
            'estado'     => 'pendiente',
            'prioridad'  => 'media',
            'created_at' => date('c'),
        ]);
        usleep(80_000);
    } else {
        $stats['actualizados']++;
    }

    // ── Adjuntos ──────────────────────────────────────────────────────
    $adj_count = procesarAdjuntos($corr_id, $msg_id, $token, $body_cont);
    $stats['adjuntos'] += $adj_count;
    if ($adj_count > 0 && !$has_adj) {
        sbPatch('correos', "id=eq.$corr_id", ['has_attachments' => true]);
    }

    // ── Clasificación por asunto ──────────────────────────────────────
    $clasificado_asunto = null;
    if ($es_nuevo && preg_match('/FELICIT|SUGERENCI|AGRADEC/i', $subject)) {
        $sent = 'neutro'; $tipo = 'peticion'; $prio = 'media';
        if (preg_match('/POSITIVO/i', $subject))  $sent = 'positivo';
        if (preg_match('/NEGATIVO/i', $subject))  $sent = 'negativo';
        if (preg_match('/URGENTE/i', $subject))   $sent = 'urgente';
        if (preg_match('/FELICIT/i', $subject))   $tipo = 'felicitacion';
        if (preg_match('/SUGERENCI/i', $subject)) $tipo = 'sugerencia';
        if (preg_match('/BAJA/i', $subject))      $prio = 'baja';
        if (preg_match('/ALTA/i', $subject))      $prio = 'alta';
        $clasificado_asunto = [
            'sentimiento' => $sent,
            'tipo_pqr'    => $tipo,
            'prioridad'   => $prio,
            'categoria'   => 'Extraído del asunto',
            'resumen'     => mb_substr($subject, 0, 100),
            'horas_sla'   => 360,
        ];
        sbPatch('correos', "id=eq.$corr_id", [
            'tipo_pqr'      => $tipo,
            'sentimiento'   => $sent,
            'prioridad'     => $prio,
            'horas_sla'     => 360,
            'resumen_corto' => mb_substr($subject, 0, 150),
            'updated_at'    => date('c'),
        ]);
        log_msg("  📋 Clasificado del asunto: $sent / $tipo");
    }

    // ── Clasificación IA ──────────────────────────────────────────────
    if ($es_nuevo && $OPENAI_KEY) {
        $clasificado = $clasificado_asunto ?? clasificarCorreo($corr_id, $subject, $body_cont, $from_email);
        if ($clasificado) {
            $stats['clasificados']++;

            if (in_array($clasificado['sentimiento'] ?? '', ['positivo']) &&
                in_array($clasificado['tipo_pqr'] ?? '', ['felicitacion', 'sugerencia'])) {

                sbPatch('correos', "id=eq.$corr_id", [
                    'estado'           => 'solucionado',
                    'fecha_resolucion' => date('c'),
                ]);

                sbPost('historial_eventos', [
                    'correo_id'   => $corr_id,
                    'evento'      => 'cerrado_automatico',
                    'descripcion' => 'Cerrado por Nova TD — mensaje positivo/felicitación. No requiere gestión.',
                    'datos_extra' => json_encode([
                        'sentimiento' => $clasificado['sentimiento'],
                        'tipo_pqr'    => $clasificado['tipo_pqr'],
                        'categoria'   => $clasificado['categoria'] ?? '',
                    ]),
                    'created_at'  => date('c'),
                ]);

                $stats['auto_cerrados']++;
                log_msg("  ✅ Auto-cerrado (positivo): $corr_id");
            }
        }
    }

    $nuevo_cursor = $received_raw;
    log_msg("  ✅ $msg_id | " . substr($subject, 0, 50) . " | $received_raw");
    usleep(50_000);
}

// ── Guardar cursor y estadísticas ─────────────────────────────────────
$total_correos  = ($sync['total_correos']  ?? 0) + $stats['insertados'];
$total_adjuntos = ($sync['total_adjuntos'] ?? 0) + $stats['adjuntos'];

saveSyncConfig([
    'cursor'        => $nuevo_cursor,
    'ultimo_sync'   => date('c'),
    'total_correos' => $total_correos,
    'total_adjuntos'=> $total_adjuntos,
]);

sbPost('historial_eventos', [
    'correo_id'   => null,
    'evento'      => 'sync_correos',
    'descripcion' => "Sync v3 OK. Insertados: {$stats['insertados']}, Actualizados: {$stats['actualizados']}, Adjuntos: {$stats['adjuntos']}, Clasificados: {$stats['clasificados']}, Auto-cerrados: {$stats['auto_cerrados']}, Errores: {$stats['errores']}",
    'datos_extra' => json_encode([
        'cursor_anterior' => $cursor_actual,
        'cursor_nuevo'    => $nuevo_cursor,
        'stats'           => $stats,
        'total_acumulado' => $total_correos,
    ]),
    'created_at'  => date('c'),
]);

log_msg("=== SYNC END v3 === " . json_encode($stats));
finalizar(true, 'ok', ['stats' => $stats, 'cursor' => $nuevo_cursor]);


// ══════════════════════════════════════════════════════════════════════
// FUNCIÓN: PROCESAR ADJUNTOS
// ══════════════════════════════════════════════════════════════════════
function procesarAdjuntos(string $correo_id, string $msg_id, string $token, string $body_html = ''): int {
    global $SB_URL, $SB_KEY, $GRAPH_MAILBOX;

    $existentes     = sbGet("adjuntos?correo_id=eq.{$correo_id}&select=attachment_id");
    $ids_existentes = array_column($existentes ?? [], 'attachment_id');

    $r = curlGet(
        "https://graph.microsoft.com/v1.0/users/{$GRAPH_MAILBOX}/messages/" . urlencode($msg_id) . "/attachments?\$top=50",
        $token
    );
    if ($r['code'] !== 200) return 0;

    $adjuntos = json_decode($r['body'], true)['value'] ?? [];

    $adjuntos = array_filter($adjuntos, function ($adj) {
        $ct      = strtolower($adj['contentType'] ?? '');
        $nombre  = $adj['name'] ?? '';
        $tam     = $adj['size'] ?? 0;
        $inline  = (bool)($adj['isInline'] ?? false);
        $es_img  = str_starts_with($ct, 'image/');
        $cont_id = trim($adj['contentId'] ?? '');

        if (!$inline) return true;
        if ($es_img && $cont_id) return true;
        if ($es_img && $tam < 150_000) return false;
        if ($es_img && preg_match('/^Outlook-[a-z0-9]+\.(png|gif|jpg|jpeg|bmp|webp)$/i', $nombre)) return false;
        if ($es_img && (!$nombre || preg_match('/^\.[a-z]+$/i', $nombre))) return false;
        return true;
    });

    $count = 0;
    foreach ($adjuntos as $adj) {
        $adj_id = $adj['id'] ?? '';
        if (!$adj_id || in_array($adj_id, $ids_existentes)) continue;

        $nombre     = $adj['name']        ?? 'adjunto';
        $ct         = $adj['contentType'] ?? 'application/octet-stream';
        $tam        = $adj['size']        ?? 0;
        $inline     = (bool)($adj['isInline'] ?? false);
        $content_id = trim($adj['contentId'] ?? '');
        $content_id = preg_replace('/[<>]/', '', $content_id);

        if ($inline && !$content_id && $body_html) {
            preg_match_all('/src=["\']cid:([^"\'>\s]+)["\']/', $body_html, $cid_matches);
            if (!empty($cid_matches[1])) {
                foreach ($cid_matches[1] as $cid_candidate) {
                    $cid_candidate = preg_replace('/[<>]/', '', trim($cid_candidate));
                    if ($cid_candidate) {
                        $content_id = $cid_candidate;
                        log_msg("    🔗 contentId extraído del body HTML: $content_id");
                        break;
                    }
                }
            }
        }
        log_msg("    🔍 adj: $nombre | inline=" . ($inline ? 'si' : 'no') . " | contentId=$content_id");

        if ($tam > 52_428_800) {
            log_msg("    ⚠️  Adjunto >50MB omitido: $nombre");
            continue;
        }

        $dl = curlGet(
            "https://graph.microsoft.com/v1.0/users/{$GRAPH_MAILBOX}/messages/" . urlencode($msg_id) . "/attachments/{$adj_id}/\$value",
            $token,
            120
        );

        if ($dl['code'] !== 200 || !$dl['body']) {
            log_msg("    ❌ Error descargando adjunto $nombre: HTTP {$dl['code']}");
            continue;
        }

        $bytes = $dl['body'];

        $es_audio    = str_starts_with(strtolower($ct), 'audio/');
        $bucket      = $es_audio ? 'audios' : 'adjuntos-pqr';
        $safe_nombre = preg_replace('/[^a-zA-Z0-9.\-_]/', '_', $nombre);
        $ts          = round(microtime(true) * 1000);
        $path        = $es_audio
            ? "$correo_id/{$ts}_{$safe_nombre}"
            : ($inline
                ? "inline/$correo_id/{$ts}_{$safe_nombre}"
                : "adjuntos/$correo_id/{$ts}_{$safe_nombre}"
              );

        if (in_array($ct, ['application/x-zip-compressed', 'application/x-zip'])) $ct = 'application/zip';
        if ($ct === 'application/x-rar-compressed') $ct = 'application/octet-stream';

        $ch = curl_init("$SB_URL/storage/v1/object/$bucket/$path");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $bytes,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                "apikey: $SB_KEY",
                "Authorization: Bearer $SB_KEY",
                "Content-Type: $ct",
                'x-upsert: true',
            ],
        ]);
        $up_resp = curl_exec($ch);
        $up_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($up_code < 200 || $up_code >= 300) {
            log_msg("    ❌ Error Storage $nombre: HTTP $up_code");
            continue;
        }

        $public_url = "$SB_URL/storage/v1/object/public/$bucket/$path";

        sbPost('adjuntos', [
            'correo_id'      => $correo_id,
            'attachment_id'  => $content_id ?: $adj_id,
            'message_id'     => $msg_id,
            'nombre'         => $nombre,
            'tipo_contenido' => $ct,
            'tamano_bytes'   => $tam,
            'es_inline'      => $inline,
            'storage_url'    => $public_url,
            'storage_path'   => $path,
            'direccion'      => 'entrante',
            'enviado_por'    => 'php-sync-v3',
            'created_at'     => date('c'),
        ]);

        $count++;
        log_msg("    ✅ Adjunto: $nombre ($bucket)");
        usleep(100_000);
    }

    return $count;
}

// ══════════════════════════════════════════════════════════════════════
// FUNCIÓN: CLASIFICAR CORREO CON GPT-4o-mini
// ══════════════════════════════════════════════════════════════════════
function clasificarCorreo(string $correo_id, string $subject, string $body, string $from_email): ?array {
    global $OPENAI_KEY, $SB_URL, $SB_KEY;

    $from_lower  = strtolower($from_email);
    $subj_lower  = strtolower($subject);
    if (str_contains($from_lower, 'tododrogas.com.co')) return null;
    if (str_contains($subj_lower, 'su solicitud fue'))   return null;
    if (str_contains($subj_lower, 'encuesta'))           return null;
    if (preg_match('/^rv:|^re:/i', trim($subject)))       return null;
    if (preg_match('/\[td-\d{8}-\d{4}\]/i', $subject))   return null;

    $texto = strip_tags($body);
    $texto = preg_replace('/\s+/', ' ', $texto);
    $texto = mb_substr(trim($texto), 0, 1500);
    if (strlen($texto) < 10) return null;

    $prompt = <<<PROMPT
Analiza este correo de droguería colombiana. Responde SOLO JSON válido sin markdown.

ASUNTO: $subject
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
  "categoria": "descripción breve"
}
REGLAS:
- Positivo/agradecido → sentimiento=positivo, prioridad=baja, horas_sla=360
- Urgencia médica → sentimiento=urgente, prioridad=critica, horas_sla=4
- Queja/reclamo → prioridad=alta, horas_sla=72
- Petición/info → prioridad=media, horas_sla=120
- felicitacion/sugerencia → horas_sla=360
PROMPT;

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'       => 'gpt-4o-mini',
            'max_tokens'  => 300,
            'temperature' => 0.1,
            'messages'    => [
                ['role' => 'system', 'content' => 'Clasificador de correos de droguería colombiana. Solo JSON válido.'],
                ['role' => 'user',   'content' => $prompt],
            ],
        ]),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $OPENAI_KEY",
            'Content-Type: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        log_msg("  ❌ GPT error $code para $correo_id");
        return null;
    }

    $ai_data = json_decode($resp, true);
    $ai_text = $ai_data['choices'][0]['message']['content'] ?? '';
    $ai_text = preg_replace('/```json|```/', '', $ai_text);
    $ia      = json_decode(trim($ai_text), true);

    if (!$ia) {
        log_msg("  ⚠️  GPT JSON inválido para $correo_id");
        return null;
    }

    $horas_sla        = intval($ia['horas_sla'] ?? 120);
    $fecha_limite_sla = date('c', strtotime("+{$horas_sla} hours"));
    $fecha_hoy        = date('Ymd');
    $rand             = str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
    $ticket_id_ext    = "EXT-{$fecha_hoy}-{$rand}";

    sbPatch('correos', "id=eq.$correo_id", [
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
        'ticket_id'        => $ticket_id_ext,
        'updated_at'       => date('c'),
    ]);

    log_msg("  🤖 Clasificado $correo_id: {$ia['sentimiento']} / {$ia['tipo_pqr']}");
    usleep(300_000);

    return $ia;
}

// ══════════════════════════════════════════════════════════════════════
// FUNCIÓN: CURL GET
// ══════════════════════════════════════════════════════════════════════
function curlGet(string $url, string $token, int $timeout = 30): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body];
}
