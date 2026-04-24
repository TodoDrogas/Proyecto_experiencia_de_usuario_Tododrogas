<?php
/**
 * sincronizar-correos.php v3.1 — Tododrogas CIA SAS
 * FIXES v3.1:
 *  - FIX 1: Ignorar correos del propio buzon (evita duplicados graph_sync)
 *  - FIX 2: Estado inicial 'sin_asignar' en vez de 'pendiente'
 *  - FIX 3: Guardar to_recipients y cc_recipients
 */

date_default_timezone_set('America/Bogota');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed_origins = ['https://tododrogas.online', 'https://www.tododrogas.online'];
if ($origin && in_array($origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Content-Type: application/json; charset=utf-8');

$SB_URL        = '__SB_URL__';
$SB_KEY        = '__SB_KEY__';
$OPENAI_KEY    = '__OPENAI_KEY__';
$GRAPH_MAILBOX = 'pqrsfd@tododrogas.com.co';
$INBOX_ID      = '__INBOX_FOLDER_ID__';
$AZURE_TENANT  = '__AZURE_TENANT_ID__';
$AZURE_CLIENT  = '__AZURE_CLIENT_ID__';
$AZURE_SECRET  = '__AZURE_CLIENT_SECRET__';
$ADMIN_TOKEN   = '__ADMIN_TOKEN__';

define('LOCK_FILE', '/tmp/sync-correos-v3.lock');
define('LOCK_MAX_AGE', 180);

$accion   = $_GET['accion'] ?? 'sync';
$token_ok = isset($_GET['token']) && $_GET['token'] === $ADMIN_TOKEN;
$es_web   = PHP_SAPI !== 'cli';
$log      = [];

if ($es_web && !$token_ok) {
    http_response_code(403);
    echo json_encode(['error' => 'Token requerido']);
    exit;
}

function log_msg(string $msg): void {
    global $log;
    $ts   = date('H:i:s');
    $line = "[$ts] $msg";
    $log[] = $line;
    error_log("[sync-v3.1] $msg");
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

if ($accion === 'estado') {
    $sync = getSyncConfig();
    finalizar(true, 'ok', ['sync' => $sync]);
}

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
    log_msg("Sincronizacion iniciada. Cursor: $cursor");
    finalizar(true, 'iniciado', ['cursor' => $cursor, 'ciclo_inicio' => $ahora]);
}

if ($accion === 'nuevo_ciclo') {
    log_msg("=== INICIANDO NUEVO CICLO ===");
    $sync     = getSyncConfig();
    $ciclo_id = $sync['ciclo_id'] ?? 'ciclo_desconocido';
    $ahora    = date('c');

    $correos_backup = sbGet('correos?select=*&order=received_at.asc&limit=10000') ?? [];
    $adj_backup     = sbGet('adjuntos?select=*&order=created_at.asc&limit=50000') ?? [];
    $hist_backup    = sbGet('historial_eventos?select=*&order=created_at.asc&limit=50000') ?? [];

    $backup_data = json_encode([
        'ciclo_id'       => $ciclo_id,
        'exported_at'    => $ahora,
        'ciclo_inicio'   => $sync['ciclo_inicio'] ?? null,
        'total_correos'  => count($correos_backup),
        'total_adjuntos' => count($adj_backup),
        'total_historial'=> count($hist_backup),
        'correos'        => $correos_backup,
        'adjuntos'       => $adj_backup,
        'historial'      => $hist_backup,
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
        finalizar(false, 'backup_fallido', ['http_code' => $up_code]);
    }
    log_msg("Backup subido: $backup_path");

    $por_bucket = [];
    foreach ($adj_backup as $adj) {
        $bucket = str_starts_with($adj['storage_path'] ?? '', 'adjuntos/') ? 'adjuntos-pqr' : 'audios';
        $por_bucket[$bucket][] = $adj['storage_path'];
    }
    $eliminados_storage = 0;
    foreach ($por_bucket as $bucket => $paths) {
        $ch = curl_init("$SB_URL/storage/v1/object/delete");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['bucket' => $bucket, 'prefixes' => array_values($paths)]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ["apikey: $SB_KEY", "Authorization: Bearer $SB_KEY", 'Content-Type: application/json'],
        ]);
        curl_exec($ch); curl_close($ch);
        $eliminados_storage += count($paths);
        usleep(500_000);
    }

    sbDelete('historial_eventos', 'id=not.is.null'); usleep(300_000);
    sbDelete('adjuntos', 'id=not.is.null'); usleep(300_000);
    sbDelete('correos', 'id=not.is.null'); usleep(500_000);

    $nuevo_cursor   = date('c', strtotime('-1 minute'));
    $nuevo_ciclo_id = 'ciclo_' . date('Y_m_d_His');
    saveSyncConfig([
        'activo'              => true,
        'cursor'              => $nuevo_cursor,
        'ciclo_inicio'        => $ahora,
        'ciclo_id'            => $nuevo_ciclo_id,
        'ultimo_sync'         => null,
        'total_correos'       => 0,
        'total_adjuntos'      => 0,
        'ultimo_ciclo_backup' => $backup_path,
        'ultimo_ciclo_correos'=> count($correos_backup),
    ]);
    finalizar(true, 'nuevo_ciclo_ok', [
        'ciclo_anterior' => $ciclo_id, 'backup_path' => $backup_path,
        'correos_backup' => count($correos_backup), 'nuevo_ciclo_id' => $nuevo_ciclo_id,
    ]);
}


// ══ RECONCILIACIÓN HORARIA ════════════════════════════════════════════
// Compara Graph vs Supabase en ventana de 2h y recupera correos perdidos
if ($accion === 'reconciliar') {
    log_msg("=== RECONCILIACIÓN HORARIA ===");

    $token = getGraphToken();
    if (!$token) finalizar(false, 'token_error');

    // Ventana: últimas 2 horas con overlap para no perder nada en bordes
    $desde = date('Y-m-d\TH:i:s\Z', strtotime('-2 hours'));
    $hasta = date('Y-m-d\TH:i:s\Z');
    log_msg("Ventana: $desde → $hasta");

    // ── Paso 1: Obtener message_ids de Graph ──────────────────────────
    $graphMsgs = [];
    $nextLink  = "https://graph.microsoft.com/v1.0/users/{$GRAPH_MAILBOX}/mailFolders/{$INBOX_ID}/messages"
               . "?\$filter=" . urlencode("isDraft eq false and receivedDateTime ge {$desde} and receivedDateTime le {$hasta}")
               . "&\$select=id,subject,from,toRecipients,ccRecipients,receivedDateTime,hasAttachments,bodyPreview,body,importance,isRead,conversationId,internetMessageId"
               . "&\$top=100";

    $paginas = 0;
    while ($nextLink && $paginas < 5) {
        $ch = curl_init($nextLink);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $token", 'ConsistencyLevel: eventual'],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) { log_msg("Graph error $code"); break; }
        $data = json_decode($resp, true);
        foreach ($data['value'] ?? [] as $m) {
            $graphMsgs[$m['id']] = $m;
        }
        $nextLink = $data['@odata.nextLink'] ?? null;
        $paginas++;
        if ($nextLink) usleep(200_000);
    }
    log_msg("Graph: " . count($graphMsgs) . " correos en ventana");

    // ── Paso 2: Obtener message_ids de Supabase ───────────────────────
    $sbRows = sbGet(
        "correos?received_at=gte.{$desde}&received_at=lte.{$hasta}&select=message_id&limit=500"
    ) ?? [];
    $sbIds = array_flip(array_column($sbRows, 'message_id')); // flip para búsqueda O(1)
    log_msg("Supabase: " . count($sbIds) . " correos en ventana");

    // ── Paso 3: Detectar faltantes ────────────────────────────────────
    $faltantes = [];
    foreach ($graphMsgs as $gid => $gdata) {
        if (!isset($sbIds[$gid])) {
            $faltantes[] = $gdata;
        }
    }
    log_msg("Faltantes detectados: " . count($faltantes));

    if (empty($faltantes)) {
        saveSyncConfig(['ultimo_reconciliacion' => date('c')]);
        finalizar(true, 'sin_faltantes', [
            'graph_total'    => count($graphMsgs),
            'supabase_total' => count($sbIds),
            'faltantes'      => 0,
            'recuperados'    => 0,
            'ventana_desde'  => $desde,
            'ventana_hasta'  => $hasta,
        ]);
    }

    // ── Paso 4: Insertar los faltantes usando misma lógica que sync ──
    $recuperados = 0;
    $errores_rec = 0;

    foreach ($faltantes as $c) {
        $msg_id       = $c['id'] ?? '';
        $received_raw = $c['receivedDateTime'] ?? '';
        if (!$msg_id || !$received_raw) continue;

        $from_email = strtolower($c['from']['emailAddress']['address'] ?? '');
        $from_name  = $c['from']['emailAddress']['name'] ?? '';
        $subject    = $c['subject'] ?? '(sin asunto)';

        // Ignorar correos del propio buzón
        if ($from_email === strtolower($GRAPH_MAILBOX)) {
            log_msg("  Ignorado propio: " . substr($subject, 0, 50));
            continue;
        }

        $body_cont = $c['body']['content'] ?? $c['bodyPreview'] ?? '';
        $body_prev = mb_substr($c['bodyPreview'] ?? '', 0, 500);
        $body_type = $c['body']['contentType'] ?? 'text';
        $has_adj   = (bool)($c['hasAttachments'] ?? false);
        $conv_id   = $c['conversationId'] ?? null;
        $internet_id = $c['internetMessageId'] ?? null;
        $importance  = strtolower($c['importance'] ?? 'normal');
        $is_read     = (bool)($c['isRead'] ?? false);
        $to_recipients = json_encode($c['toRecipients'] ?? []);
        $cc_recipients = json_encode($c['ccRecipients'] ?? []);

        $origen = 'graph_sync';
        if (preg_match('/NOVA\s+TD\s+DIRECTO/ui', $subject))  $origen = 'nova_directo';
        elseif (preg_match('/NOVA\s+TD/ui', $subject))         $origen = 'nova_web';
        elseif (preg_match('/QR\b/u', $subject))               $origen = 'qr';

        $ticket_id = null;
        if (preg_match('/\[?(TD-\d{8}-\d{4})\]?/i', $subject, $mt)) $ticket_id = $mt[1];

        $payload = [
            'message_id'          => $msg_id,
            'internet_message_id' => $internet_id,
            'conversation_id'     => $conv_id,
            'from_email'          => $from_email,
            'from_name'           => $from_name,
            'subject'             => $subject,
            'body_preview'        => $body_prev,
            'body_content'        => $body_cont,
            'body_type'           => $body_type,
            'has_attachments'     => $has_adj,
            'importance'          => $importance,
            'is_read'             => $is_read,
            'received_at'         => $received_raw,
            'origen'              => $origen,
            'canal_contacto'      => 'correo',
            'to_recipients'       => $to_recipients,
            'cc_recipients'       => $cc_recipients,
        ];
        if ($ticket_id) $payload['ticket_id'] = $ticket_id;

        $r = sbUpsert('correos', [$payload], 'message_id');

        if ($r['code'] >= 200 && $r['code'] < 300) {
            $corr_id = $r['data'][0]['id'] ?? null;
            // Asignar estado inicial
            if ($corr_id) {
                sbPatch('correos', "id=eq.$corr_id", [
                    'estado'    => 'sin_asignar',
                    'prioridad' => 'media',
                    'created_at'=> date('c'),
                ]);
                // Registrar en historial para auditoría
                sbPost('historial_eventos', [
                    'correo_id'  => $corr_id,
                    'evento'     => 'sync_correos',
                    'descripcion'=> 'Correo recuperado por reconciliación horaria — no estaba en Supabase',
                    'datos_extra'=> json_encode([
                        'message_id'     => $msg_id,
                        'subject'        => $subject,
                        'received_at'    => $received_raw,
                        'ventana_desde'  => $desde,
                        'ventana_hasta'  => $hasta,
                    ]),
                    'created_at' => date('c'),
                ]);
            }
            $recuperados++;
            log_msg("  RECUPERADO: " . substr($subject, 0, 60));
        } else {
            $errores_rec++;
            log_msg("  ERROR al recuperar: " . substr($subject, 0, 60) . " code=" . $r['code']);
        }
        usleep(100_000);
    }

    saveSyncConfig(['ultimo_reconciliacion' => date('c')]);
    log_msg("=== RECONCILIACIÓN FIN: $recuperados recuperados, $errores_rec errores ===");
    finalizar(true, 'reconciliacion_ok', [
        'graph_total'    => count($graphMsgs),
        'supabase_total' => count($sbIds),
        'faltantes'      => count($faltantes),
        'recuperados'    => $recuperados,
        'errores'        => $errores_rec,
        'ventana_desde'  => $desde,
        'ventana_hasta'  => $hasta,
    ]);
}

// ══ SYNC PRINCIPAL ════════════════════════════════════════════════════
$sync = getSyncConfig();
if (empty($sync['activo'])) { log_msg("Sync no activo."); finalizar(true, 'inactivo'); }

if (file_exists(LOCK_FILE)) {
    $pid = (int)file_get_contents(LOCK_FILE);
    $lock_age = time() - filemtime(LOCK_FILE);
    if ($lock_age < LOCK_MAX_AGE && posix_kill($pid, 0)) { finalizar(true, 'ya_corriendo'); }
}
file_put_contents(LOCK_FILE, getmypid());
register_shutdown_function(fn() => @unlink(LOCK_FILE));

$cursor_actual = $sync['cursor'] ?? null;
if (!$cursor_actual) { finalizar(false, 'sin_cursor'); }

$cursor_dt  = new DateTime($cursor_actual, new DateTimeZone('UTC'));
$cursor_iso = $cursor_dt->format('Y-m-d\TH:i:s\Z');
log_msg("=== SYNC v3.1 === Cursor: $cursor_iso");

$token = getGraphToken();
if (!$token) { finalizar(false, 'token_error'); }

$filter   = urlencode("isDraft eq false and receivedDateTime gt $cursor_iso");
$url_base = "https://graph.microsoft.com/v1.0/users/{$GRAPH_MAILBOX}/mailFolders/{$INBOX_ID}/messages"
          . "?\$filter=$filter"
          . "&\$orderby=" . urlencode('receivedDateTime asc')
          . "&\$count=true&\$top=50"
          . "&\$select=id,subject,from,toRecipients,ccRecipients,receivedDateTime,hasAttachments,bodyPreview,body,importance,isRead,conversationId,internetMessageId,flag";

$todos_correos = [];
$next_link     = $url_base;
$paginas       = 0;

while ($next_link) {
    $ch = curl_init($next_link);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token", 'ConsistencyLevel: eventual'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) { log_msg("Graph API error $code en pagina $paginas"); break; }
    $data          = json_decode($resp, true);
    $todos_correos = array_merge($todos_correos, $data['value'] ?? []);
    $paginas++;
    $next_link = $data['@odata.nextLink'] ?? null;
    if ($next_link) usleep(200_000);
}

log_msg("Total correos nuevos: " . count($todos_correos));

if (empty($todos_correos)) {
    saveSyncConfig(['ultimo_sync' => date('c')]);
    finalizar(true, 'sin_correos_nuevos');
}

$stats = ['insertados' => 0, 'actualizados' => 0, 'errores' => 0,
          'adjuntos' => 0, 'clasificados' => 0, 'auto_cerrados' => 0,
          'ignorados_propios' => 0];
$nuevo_cursor = $cursor_actual;

foreach ($todos_correos as $c) {
    $msg_id       = $c['id'] ?? '';
    $received_raw = $c['receivedDateTime'] ?? '';
    if (!$msg_id || !$received_raw) continue;

    $from_email = strtolower($c['from']['emailAddress']['address'] ?? '');
    $from_name  = $c['from']['emailAddress']['name'] ?? '';
    $subject    = $c['subject'] ?? '(sin asunto)';

    // ── FIX 1: Ignorar correos del propio buzon ───────────────────────
    if ($from_email === strtolower($GRAPH_MAILBOX)) {
        log_msg("  Ignorado correo propio: " . substr($subject, 0, 50));
        $stats['ignorados_propios']++;
        $nuevo_cursor = $received_raw;
        continue;
    }

    $body_cont  = $c['body']['content'] ?? $c['bodyPreview'] ?? '';
    $body_prev  = mb_substr($c['bodyPreview'] ?? '', 0, 500);
    $body_type  = $c['body']['contentType'] ?? 'text';
    $has_adj    = (bool)($c['hasAttachments'] ?? false);
    $conv_id    = $c['conversationId'] ?? null;
    $internet_id= $c['internetMessageId'] ?? null;
    $importance = strtolower($c['importance'] ?? 'normal');
    $is_read    = (bool)($c['isRead'] ?? false);
    $flag_st    = $c['flag']['flagStatus'] ?? 'notFlagged';

    // ── FIX 3: Guardar Para y CC ──────────────────────────────────────
    $to_recipients = json_encode($c['toRecipients'] ?? []);
    $cc_recipients = json_encode($c['ccRecipients'] ?? []);

    $ticket_id = null;
    if (preg_match('/\[?(TD-\d{8}-\d{4})\]?/i', $subject, $m)) $ticket_id = $m[1];

    $origen = 'graph_sync';
    if (preg_match('/NOVA\s+TD\s+DIRECTO/ui', $subject))  $origen = 'nova_directo';
    elseif (preg_match('/NOVA\s+TD/ui', $subject))          $origen = 'nova_web';
    elseif (preg_match('/QR\b/u', $subject))                $origen = 'qr';
    elseif ($ticket_id)                                     $origen = 'web';

    $payload = [
        'message_id'          => $msg_id,
        'internet_message_id' => $internet_id,
        'conversation_id'     => $conv_id,
        'from_email'          => $from_email,
        'from_name'           => $from_name,
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
        'to_recipients'       => $to_recipients,  // FIX 3
        'cc_recipients'       => $cc_recipients,  // FIX 3
    ];
    if ($ticket_id) $payload['ticket_id'] = $ticket_id;

    if ($ticket_id) {
        $body_texto = strip_tags($body_cont ?? '');
        if (preg_match('/(?:C[ee]dula|Documento|C\.C\.|Doc\.?)[:\s#]*(\d{6,12})/i', $body_texto, $mc))
            $payload['cedula'] = preg_replace('/\D/', '', $mc[1]);
    }

    $r = sbUpsert('correos', [$payload], 'message_id');

    if ($r['code'] === 409) { $nuevo_cursor = $received_raw; continue; }
    if ($r['code'] < 200 || $r['code'] >= 300) { $stats['errores']++; continue; }

    $fila    = $r['data'][0] ?? null;
    $corr_id = $fila['id'] ?? null;
    $es_nuevo = empty($fila['sentimiento']);

    if (!$corr_id) { $nuevo_cursor = $received_raw; continue; }

    if ($es_nuevo) {
        $stats['insertados']++;
        // ── FIX 2: Estado 'sin_asignar' en vez de 'pendiente' ────────
        sbPatch('correos', "id=eq.$corr_id", [
            'estado'    => 'sin_asignar',
            'prioridad' => 'media',
            'created_at'=> date('c'),
        ]);
        usleep(80_000);
    } else {
        $stats['actualizados']++;
    }

    $adj_count = procesarAdjuntos($corr_id, $msg_id, $token, $body_cont);
    $stats['adjuntos'] += $adj_count;
    if ($adj_count > 0 && !$has_adj) sbPatch('correos', "id=eq.$corr_id", ['has_attachments' => true]);

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
        $clasificado_asunto = ['sentimiento'=>$sent,'tipo_pqr'=>$tipo,'prioridad'=>$prio,
            'categoria'=>'Extraido del asunto','resumen'=>mb_substr($subject,0,100),'horas_sla'=>360];
        sbPatch('correos', "id=eq.$corr_id", [
            'tipo_pqr'=>$tipo,'sentimiento'=>$sent,'prioridad'=>$prio,
            'horas_sla'=>360,'resumen_corto'=>mb_substr($subject,0,150),'updated_at'=>date('c'),
        ]);
    }

    if ($es_nuevo && $OPENAI_KEY) {
        $clasificado = $clasificado_asunto ?? clasificarCorreo($corr_id, $subject, $body_cont, $from_email);
        if ($clasificado) {
            $stats['clasificados']++;
            if (in_array($clasificado['sentimiento']??'',['positivo']) &&
                in_array($clasificado['tipo_pqr']??'',['felicitacion','sugerencia'])) {
                sbPatch('correos', "id=eq.$corr_id", ['estado'=>'solucionado','fecha_resolucion'=>date('c')]);
                sbPost('historial_eventos', [
                    'correo_id'=>$corr_id,'evento'=>'cerrado_automatico',
                    'descripcion'=>'Cerrado automatico - mensaje positivo/felicitacion.',
                    'datos_extra'=>json_encode(['sentimiento'=>$clasificado['sentimiento'],'tipo_pqr'=>$clasificado['tipo_pqr']]),
                    'created_at'=>date('c'),
                ]);
                $stats['auto_cerrados']++;
            }
        }
    }

    $nuevo_cursor = $received_raw;
    log_msg("  OK $msg_id | " . substr($subject, 0, 50));
    usleep(50_000);
}

$total_correos  = ($sync['total_correos']  ?? 0) + $stats['insertados'];
$total_adjuntos = ($sync['total_adjuntos'] ?? 0) + $stats['adjuntos'];
saveSyncConfig(['cursor'=>$nuevo_cursor,'ultimo_sync'=>date('c'),'total_correos'=>$total_correos,'total_adjuntos'=>$total_adjuntos]);

sbPost('historial_eventos', [
    'correo_id'  => null,
    'evento'     => 'sync_correos',
    'descripcion'=> "Sync v3.1 OK. Insertados:{$stats['insertados']}, Actualizados:{$stats['actualizados']}, Adjuntos:{$stats['adjuntos']}, Ignorados propios:{$stats['ignorados_propios']}, Errores:{$stats['errores']}",
    'datos_extra'=> json_encode(['cursor_anterior'=>$cursor_actual,'cursor_nuevo'=>$nuevo_cursor,'stats'=>$stats,'total_acumulado'=>$total_correos]),
    'created_at' => date('c'),
]);

log_msg("=== SYNC END v3.1 === " . json_encode($stats));
finalizar(true, 'ok', ['stats' => $stats, 'cursor' => $nuevo_cursor]);


// ══ PROCESAR ADJUNTOS ═════════════════════════════════════════════════
function procesarAdjuntos(string $correo_id, string $msg_id, string $token, string $body_html = ''): int {
    global $SB_URL, $SB_KEY, $GRAPH_MAILBOX;
    $existentes     = sbGet("adjuntos?correo_id=eq.{$correo_id}&select=attachment_id");
    $ids_existentes = array_column($existentes ?? [], 'attachment_id');
    $r = curlGet("https://graph.microsoft.com/v1.0/users/{$GRAPH_MAILBOX}/messages/" . urlencode($msg_id) . "/attachments?\$top=50", $token);
    if ($r['code'] !== 200) return 0;
    $adjuntos = json_decode($r['body'], true)['value'] ?? [];
    $adjuntos = array_filter($adjuntos, function ($adj) {
        $ct=$adj['contentType']??''; $nombre=$adj['name']??''; $tam=$adj['size']??0;
        $inline=(bool)($adj['isInline']??false); $es_img=str_starts_with(strtolower($ct),'image/');
        $cont_id=trim($adj['contentId']??'');
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
        $nombre=$adj['name']??'adjunto'; $ct=$adj['contentType']??'application/octet-stream';
        $tam=$adj['size']??0; $inline=(bool)($adj['isInline']??false);
        $content_id=preg_replace('/[<>]/','',trim($adj['contentId']??''));
        if ($inline && !$content_id && $body_html) {
            preg_match_all('/src=["\']cid:([^"\'>\s]+)["\']/', $body_html, $cid_matches);
            if (!empty($cid_matches[1])) $content_id = preg_replace('/[<>]/','',trim($cid_matches[1][0]));
        }
        if ($tam > 52_428_800) continue;
        $dl = curlGet("https://graph.microsoft.com/v1.0/users/{$GRAPH_MAILBOX}/messages/".urlencode($msg_id)."/attachments/{$adj_id}/\$value", $token, 120);
        if ($dl['code'] !== 200 || !$dl['body']) continue;
        $bytes=$dl['body'];
        $es_audio=str_starts_with(strtolower($ct),'audio/');
        $bucket=$es_audio?'audios':'adjuntos-pqr';
        $safe_nombre=preg_replace('/[^a-zA-Z0-9.\-_]/','_',$nombre);
        $ts=round(microtime(true)*1000);
        $path=$es_audio?"$correo_id/{$ts}_{$safe_nombre}":($inline?"inline/$correo_id/{$ts}_{$safe_nombre}":"adjuntos/$correo_id/{$ts}_{$safe_nombre}");
        if (in_array($ct,['application/x-zip-compressed','application/x-zip'])) $ct='application/zip';
        $ch=curl_init("$SB_URL/storage/v1/object/$bucket/$path");
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$bytes,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,
            CURLOPT_HTTPHEADER=>["apikey: $SB_KEY","Authorization: Bearer $SB_KEY","Content-Type: $ct",'x-upsert: true']]);
        $up_resp=curl_exec($ch); $up_code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        if ($up_code < 200 || $up_code >= 300) continue;
        $public_url="$SB_URL/storage/v1/object/public/$bucket/$path";
        sbPost('adjuntos',['correo_id'=>$correo_id,'attachment_id'=>$content_id?:$adj_id,'message_id'=>$msg_id,
            'nombre'=>$nombre,'tipo_contenido'=>$ct,'tamano_bytes'=>$tam,'es_inline'=>$inline,
            'storage_url'=>$public_url,'storage_path'=>$path,'direccion'=>'entrante','enviado_por'=>'php-sync-v3.1','created_at'=>date('c')]);
        $count++;
        usleep(100_000);
    }
    return $count;
}

// ══ CLASIFICAR CON GPT ════════════════════════════════════════════════
function clasificarCorreo(string $correo_id, string $subject, string $body, string $from_email): ?array {
    global $OPENAI_KEY;
    $from_lower=strtolower($from_email); $subj_lower=strtolower($subject);
    if (str_contains($from_lower,'tododrogas.com.co')) return null;
    if (str_contains($subj_lower,'su solicitud fue')) return null;
    if (str_contains($subj_lower,'encuesta')) return null;
    if (preg_match('/^rv:|^re:/i',trim($subject))) return null;
    if (preg_match('/\[td-\d{8}-\d{4}\]/i',$subject)) return null;
    $texto=mb_substr(trim(preg_replace('/\s+/',' ',strip_tags($body))),0,1500);
    if (strlen($texto)<10) return null;
    $prompt="Analiza este correo de drogueria colombiana. Responde SOLO JSON valido sin markdown.\n\nASUNTO: $subject\nTEXTO: $texto\n\nJSON requerido:\n{\"tipo_pqr\":\"peticion|queja|reclamo|sugerencia|felicitacion|denuncia|informacion\",\"sentimiento\":\"positivo|neutro|negativo|urgente\",\"tono\":\"enojado|frustrado|triste|ansioso|neutro|satisfecho|agradecido\",\"prioridad\":\"baja|media|alta|critica\",\"nivel_riesgo\":\"bajo|medio|alto|critico\",\"resumen\":\"maximo 100 caracteres\",\"ley\":\"ley colombiana aplicable o N/A\",\"horas_sla\":120,\"categoria\":\"descripcion breve\"}";
    $ch=curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,
        CURLOPT_POSTFIELDS=>json_encode(['model'=>'gpt-4o-mini','max_tokens'=>300,'temperature'=>0.1,
            'messages'=>[['role'=>'system','content'=>'Clasificador de correos de drogueria colombiana. Solo JSON valido.'],['role'=>'user','content'=>$prompt]]]),
        CURLOPT_HTTPHEADER=>["Authorization: Bearer $OPENAI_KEY",'Content-Type: application/json']]);
    $resp=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code!==200) return null;
    $ai_text=json_decode($resp,true)['choices'][0]['message']['content']??'';
    $ia=json_decode(trim(preg_replace('/```json|```/','',$ai_text)),true);
    if (!$ia) return null;
    $horas_sla=intval($ia['horas_sla']??120);
    $fecha_limite_sla=date('c',strtotime("+{$horas_sla} hours"));
    $ticket_id_ext="EXT-".date('Ymd')."-".str_pad(rand(1000,9999),4,'0',STR_PAD_LEFT);
    sbPatch('correos',"id=eq.$correo_id",[
        'tipo_pqr'=>$ia['tipo_pqr']??'peticion','sentimiento'=>$ia['sentimiento']??'neutro',
        'datos_legales'=>json_encode(['tono'=>$ia['tono']??'neutro']),'prioridad'=>$ia['prioridad']??'media',
        'nivel_riesgo'=>$ia['nivel_riesgo']??'bajo','resumen_corto'=>mb_substr($ia['resumen']??'',0,150),
        'ley_aplicable'=>$ia['ley']??'Ley 1755/2015','categoria_ia'=>$ia['categoria']??'',
        'horas_sla'=>$horas_sla,'fecha_limite_sla'=>$fecha_limite_sla,
        'es_urgente'=>($ia['sentimiento']==='urgente'||$ia['prioridad']==='critica'),
        'ticket_id'=>$ticket_id_ext,'updated_at'=>date('c'),
    ]);
    usleep(300_000);
    return $ia;
}

function curlGet(string $url, string $token, int $timeout = 30): array {
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>$timeout,CURLOPT_HTTPHEADER=>["Authorization: Bearer $token"]]);
    $body=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code'=>$code,'body'=>$body];
}
