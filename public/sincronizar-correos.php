<?php
/**
 * sincronizar-correos.php — Sincronizador Graph API → Supabase
 * ─────────────────────────────────────────────────────────────
 * Reemplaza el workflow N8N F00_correos_adjuntos
 *
 * FLUJO:
 *   1. Obtiene token Azure (client_credentials)
 *   2. Trae correos de las últimas 24h de pqrsfd@tododrogas.com.co
 *   3. Upsert en tabla correos (on_conflict=message_id → nunca duplica)
 *   4. Clasifica con GPT-4o-mini los correos externos sin sentimiento
 *   5. Descarga y sube adjuntos que no están en tabla adjuntos
 *   6. Registra resumen en historial_eventos
 *
 * USO:
 *   - Cron cada 5 min: * /5 * * * * php /var/www/pqr/sincronizar-correos.php >> /var/log/sync-correos.log 2>&1
 *   - Manual desde admin: GET /sincronizar-correos.php?token=ADMIN_TOKEN
 *   - Forzar ventana: GET /sincronizar-correos.php?horas=48&token=ADMIN_TOKEN
 */

// ── Modo de ejecución ─────────────────────────────────────────────────
$es_web    = php_sapi_name() !== 'cli';
$es_manual = $es_web && isset($_GET['token']);

if ($es_web) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    // Protección básica para ejecución web manual
    $ADMIN_TOKEN = '__ADMIN_SYNC_TOKEN__'; // inyectado por deploy.yml
    if ($es_manual && ($_GET['token'] ?? '') !== $ADMIN_TOKEN) {
        http_response_code(403);
        echo json_encode(['error' => 'Token inválido']);
        exit;
    }
    if (!$es_manual) {
        // Si se llama sin token desde web, rechazar
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
$GRAPH_MAILBOX = 'pqrsfd@tododrogas.com.co'; // buzón a leer
$HORAS_VENTANA = (int)($_GET['horas'] ?? 2);   // ventana de tiempo (default 2h — cron cada 5min cubre bien)

// ── LOG ───────────────────────────────────────────────────────────────
$log = [];
function log_msg(string $msg) {
    global $log;
    $ts = date('H:i:s');
    $log[] = "[$ts] $msg";
    if (php_sapi_name() === 'cli') echo "[$ts] $msg\n";
}

log_msg("=== SYNC START — ventana: {$HORAS_VENTANA}h ===");

// ── HELPERS ───────────────────────────────────────────────────────────
function curlJson(string $url, array $opts = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, $opts + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
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

function sbUpsert(string $SB_URL, string $SB_KEY, string $endpoint, array $data, string $conflict): array {
    $r = curlJson("$SB_URL/rest/v1/$endpoint?on_conflict=$conflict", [
        CURLOPT_POST        => true,
        CURLOPT_POSTFIELDS  => json_encode($data),
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

$tok_data    = json_decode($tok_r['body'], true);
$access_token = $tok_data['access_token'] ?? null;

if (!$access_token) {
    log_msg("ERROR: No se pudo obtener token Azure: " . ($tok_data['error_description'] ?? $tok_r['body']));
    finalizar(false, 'token_error');
}
log_msg("Token OK");

// ── 2. TRAER CORREOS DE LAS ÚLTIMAS 24H ──────────────────────────────
$desde     = (new DateTime("-{$HORAS_VENTANA} hours", new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
$select    = 'id,subject,from,toRecipients,ccRecipients,bccRecipients,replyTo,receivedDateTime,sentDateTime,bodyPreview,body,hasAttachments,isRead,isDraft,conversationId,importance,internetMessageId,categories,flag';
$filter    = urlencode("isDraft eq false and receivedDateTime ge {$desde}");
$url_base  = "https://graph.microsoft.com/v1.0/users/{$GRAPH_MAILBOX}/mailFolders/Inbox/messages"; // Solo Inbox = igual que Outlook
$url       = "{$url_base}?\$filter={$filter}&\$orderby=receivedDateTime+desc&\$top=999&\$select={$select}";

log_msg("Trayendo correos desde $desde...");

$todos_correos = [];
$paginas       = 0;

// Paginación completa con @odata.nextLink
while ($url) {
    $r = curlJson($url, [
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $access_token",
            'ConsistencyLevel: eventual',
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

// ── 3. UPSERT EN SUPABASE ─────────────────────────────────────────────
$stats = ['insertados' => 0, 'actualizados' => 0, 'errores' => 0, 'clasificados' => 0, 'adjuntos' => 0];
$correos_para_clasificar = []; // IDs que necesitan clasificación IA

foreach ($todos_correos as $c) {
    $msg_id      = $c['id']                ?? '';
    $inet_msg_id = $c['internetMessageId'] ?? '';

    if (!$msg_id) continue;

    $payload = [
        'message_id'           => $msg_id,
        'internet_message_id'  => $inet_msg_id ?: null,
        'conversation_id'      => $c['conversationId']     ?? null,
        'subject'              => $c['subject']             ?? '',
        'from_email'           => $c['from']['emailAddress']['address'] ?? '',
        'from_name'            => $c['from']['emailAddress']['name']    ?? '',
        'to_recipients'        => json_encode($c['toRecipients']        ?? []),
        'cc_recipients'        => json_encode($c['ccRecipients']        ?? []),
        'bcc_recipients'       => json_encode($c['bccRecipients']       ?? []),
        'reply_to'             => json_encode($c['replyTo']             ?? []),
        'received_at'          => $c['receivedDateTime']   ?? null,
        'sent_at'              => $c['sentDateTime']        ?? null,
        'body_preview'         => mb_substr($c['bodyPreview'] ?? '', 0, 500),
        'body_content'         => $c['body']['content']     ?? '',
        'body_type'            => strtolower($c['body']['contentType'] ?? 'text'),
        'has_attachments'      => (bool)($c['hasAttachments'] ?? false),
        'is_read'              => (bool)($c['isRead']          ?? false),
        'is_draft'             => (bool)($c['isDraft']         ?? false),
        'importance'           => $c['importance']             ?? 'normal',
        'categories'           => json_encode($c['categories'] ?? []),
        'flag_status'          => $c['flag']['flagStatus']     ?? 'notFlagged',
        'raw_payload'          => json_encode($c),
        'origen'               => 'graph_sync',
        'canal_contacto'       => 'correo',
        'updated_at'           => date('c'),
    ];

    // IMPORTANTE: NO incluir estado/prioridad/sentimiento/tipo_pqr en el payload
    // merge-duplicates actualizaría registros ya gestionados manualmente

    // Solo setear estado/prioridad si no existen aún (no sobreescribir gestión manual)
    // Esto se logra con merge-duplicates: si ya existe, no toca los campos omitidos

    $r = sbUpsert($SB_URL, $SB_KEY, 'correos', $payload, 'message_id');

    if ($r['code'] >= 200 && $r['code'] < 300) {
        $inserted = json_decode($r['body'], true);
        $correo_db = $inserted[0] ?? null;
        $correo_id = $correo_db['id'] ?? null;

        // Detectar si es nuevo (sin sentimiento = nunca clasificado)
        $es_nuevo       = !empty($correo_id) && empty($correo_db['sentimiento']);
        $tiene_adjuntos = (bool)($c['hasAttachments'] ?? false);

        if ($es_nuevo) {
            $stats['insertados']++;
            // Setear estado inicial solo en registros nuevos
            if ($correo_id) {
                sbPatch($SB_URL, $SB_KEY, 'correos', "id=eq.$correo_id", [
                    'estado'    => 'pendiente',
                    'prioridad' => 'media',
                    'created_at'=> date('c'),
                ]);
            }
            if ($correo_id) {
                $correos_para_clasificar[] = [
                    'correo_id'  => $correo_id,
                    'msg_id'     => $msg_id,
                    'subject'    => $c['subject'] ?? '',
                    'body'       => mb_substr($c['body']['content'] ?? $c['bodyPreview'] ?? '', 0, 2000),
                    'tiene_adj'  => $tiene_adjuntos,
                    'from_email' => $c['from']['emailAddress']['address'] ?? '',
                ];
            }
        } else {
            $stats['actualizados']++;
        }

        // Procesar adjuntos si los tiene
        if ($tiene_adjuntos && $correo_id) {
            $adj_count = procesarAdjuntos($correo_id, $msg_id, $access_token, $SB_URL, $SB_KEY);
            $stats['adjuntos'] += $adj_count;
        }

    } else {
        $stats['errores']++;
        log_msg("ERROR upsert {$msg_id}: HTTP {$r['code']} — " . substr($r['body'], 0, 150));
    }
}

log_msg("Upsert completo — insertados: {$stats['insertados']}, actualizados: {$stats['actualizados']}, errores: {$stats['errores']}, adjuntos: {$stats['adjuntos']}");

// ── 4. CLASIFICACIÓN IA — solo correos nuevos externos ────────────────
if ($OPENAI_KEY && !empty($correos_para_clasificar)) {
    log_msg("Clasificando " . count($correos_para_clasificar) . " correos con GPT...");

    foreach ($correos_para_clasificar as $item) {
        $from_email = strtolower($item['from_email'] ?? '');
        $texto = strip_tags($item['body']);
        $texto = preg_replace('/\s+/', ' ', $texto);
        $texto = mb_substr(trim($texto), 0, 1500);

        if (strlen($texto) < 10) continue; // sin contenido clasificable

        // No clasificar correos de nuestro propio dominio (notificaciones internas)
        if (str_contains($from_email, 'tododrogas.com.co') || str_contains($from_email, 'pqrsfd@')) continue;

        // No clasificar correos internos del sistema
        $asunto_lower = strtolower($item['subject']);
        $es_interno = (
            str_contains($asunto_lower, 'encuesta')           || // notificación encuesta
            str_contains($item['subject'], '⭐')              || // encuesta emoji
            str_contains($item['subject'], '✅')              || // acuse al usuario
            str_contains($asunto_lower, 'su solicitud fue')   || // acuse
            str_contains($asunto_lower, 'gracias por su opinión') || // confirmación encuesta
            preg_match('/^\[td-\d{8}-\d{4}\]/i', $item['subject']) || // radicado formulario
            str_starts_with(trim($item['subject']), 'RV:')    || // reenvío interno
            str_starts_with(trim($item['subject']), 'RE:')       // respuesta interna
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
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $OPENAI_KEY", 'Content-Type: application/json'],
            CURLOPT_TIMEOUT    => 20,
        ]);

        if ($ai_r['code'] === 200) {
            $ai_data = json_decode($ai_r['body'], true);
            $ai_text = $ai_data['choices'][0]['message']['content'] ?? '';
            $ai_text = preg_replace('/```json|```/', '', $ai_text);
            $ia      = json_decode(trim($ai_text), true);

            if ($ia) {
                $horas_sla        = intval($ia['horas_sla'] ?? 120);
                $fecha_limite_sla = date('c', strtotime("+{$horas_sla} hours"));

                // Generar ticket_id si no tiene
                $fecha_hoy  = date('Ymd');
                $rand       = str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                $ticket_id  = "EXT-{$fecha_hoy}-{$rand}"; // EXT = correo externo

                sbPatch($SB_URL, $SB_KEY, 'correos', "id=eq.{$item['correo_id']}", [
                    'tipo_pqr'        => $ia['tipo_pqr']     ?? 'peticion',
                    'sentimiento'     => $ia['sentimiento']  ?? 'neutro',
                    'datos_legales'   => json_encode(['tono' => $ia['tono'] ?? 'neutro']),
                    'prioridad'       => $ia['prioridad']    ?? 'media',
                    'nivel_riesgo'    => $ia['nivel_riesgo'] ?? 'bajo',
                    'resumen_corto'   => mb_substr($ia['resumen'] ?? '', 0, 150),
                    'ley_aplicable'   => $ia['ley']          ?? 'Ley 1755/2015',
                    'categoria_ia'    => $ia['categoria']    ?? '',
                    'horas_sla'       => $horas_sla,
                    'fecha_limite_sla'=> $fecha_limite_sla,
                    'es_urgente'      => ($ia['sentimiento'] === 'urgente' || $ia['prioridad'] === 'critica'),
                    'estado'          => 'pendiente',
                    'ticket_id'       => $ticket_id,
                    'updated_at'      => date('c'),
                ]);

                $stats['clasificados']++;
                log_msg("  Clasificado {$item['correo_id']}: {$ia['sentimiento']} / {$ia['prioridad']}");
            }
        }

        usleep(200000); // 200ms entre llamadas a OpenAI
    }
}

// ── 5. REGISTRAR RESUMEN EN HISTORIAL ────────────────────────────────
sbPost($SB_URL, $SB_KEY, 'historial_eventos', [
    'evento'      => 'sync_correos',
    'descripcion' => "Sync Graph completado. Insertados: {$stats['insertados']}, Actualizados: {$stats['actualizados']}, Adjuntos: {$stats['adjuntos']}, Clasificados: {$stats['clasificados']}, Errores: {$stats['errores']}",
    'datos_extra' => json_encode([
        'ventana_horas' => $HORAS_VENTANA,
        'desde'         => $desde,
        'total_correos' => count($todos_correos),
        'stats'         => $stats,
    ]),
    'created_at' => date('c'),
]);

log_msg("=== SYNC END ===");
finalizar(true, 'ok');


// ══════════════════════════════════════════════════════════════════════
// FUNCIÓN: PROCESAR ADJUNTOS
// ══════════════════════════════════════════════════════════════════════
function procesarAdjuntos(string $correo_id, string $msg_id, string $token, string $SB_URL, string $SB_KEY): int {
    // Verificar si ya tiene adjuntos registrados
    $existentes = sbGet($SB_URL, $SB_KEY, "adjuntos?correo_id=eq.{$correo_id}&select=attachment_id");
    $ids_existentes = array_column($existentes ?? [], 'attachment_id');

    // Traer lista de adjuntos de Graph
    $GRAPH_MAILBOX = 'pqrsfd@tododrogas.com.co';
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

    // Filtrar: excluir inline imágenes pequeñas (logos, firmas HTML)
    $adjuntos = array_filter($adjuntos, function($adj) {
        $ct      = strtolower($adj['contentType'] ?? '');
        $nombre  = $adj['name'] ?? '';
        $tam     = $adj['size'] ?? 0;
        $inline  = (bool)($adj['isInline'] ?? false);
        $es_img  = str_starts_with($ct, 'image/');

        if (!$inline) return true;
        if ($es_img && $tam < 150000)  return false;
        if ($es_img && preg_match('/^Outlook-[a-z0-9]+\.(png|gif|jpg|jpeg|bmp|webp)$/i', $nombre)) return false;
        if ($es_img && (!$nombre || preg_match('/^\.[a-z]+$/i', $nombre))) return false;
        return true;
    });

    $count = 0;
    foreach ($adjuntos as $adj) {
        $adj_id = $adj['id'] ?? '';
        if (!$adj_id) continue;

        // Saltar si ya existe
        if (in_array($adj_id, $ids_existentes)) continue;

        $nombre     = $adj['name']        ?? 'adjunto_sin_nombre';
        $ct         = $adj['contentType'] ?? 'application/octet-stream';
        $tam        = $adj['size']        ?? 0;
        $inline     = (bool)($adj['isInline'] ?? false);

        // Saltar adjuntos > 50MB (límite bucket adjuntos-pqr en Supabase)
        if ($tam > 52_428_800) {
            log_msg("  Adjunto muy grande (>50MB), omitido: $nombre");
            continue;
        }

        // Descargar bytes del adjunto
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
        if ($ct === 'application/x-zip-compressed' || $ct === 'application/x-zip') $ct = 'application/zip';
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
            'enviado_por'    => 'php-sync',
            'created_at'     => date('c'),
        ]);

        $count++;
        log_msg("  ✅ Adjunto subido: $nombre ($bucket)");
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
