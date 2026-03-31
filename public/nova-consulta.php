<?php
/**
 * nova-consulta.php — Backend inteligente de Nova TD
 * Consulta Supabase server-side con service_role (seguro)
 * Nova llama este endpoint para obtener datos reales antes de responder
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$SB_URL = '__SB_URL__';
$SB_KEY = '__SB_KEY__'; // service_role — solo en PHP, nunca en JS

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); echo json_encode(['error'=>'Invalid JSON']); exit; }

$accion = $body['accion'] ?? '';

// ── Helper: query a Supabase ─────────────────────────────────
function sb_get(string $endpoint, string $SB_URL, string $SB_KEY): ?array {
    $ch = curl_init($SB_URL . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "apikey: $SB_KEY",
            "Authorization: Bearer $SB_KEY",
            'Accept: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return null;
    return json_decode($resp, true);
}

switch ($accion) {

    // ══════════════════════════════════════════════════════════
    // 1. ESTADO DE RADICADO — por ticket_id o por correo
    // ══════════════════════════════════════════════════════════
    case 'radicado':
        $ticket_id = trim($body['ticket_id'] ?? '');
        $correo    = trim($body['correo']    ?? '');

        if (!$ticket_id && !$correo) {
            echo json_encode(['error' => 'Se requiere ticket_id o correo']); exit;
        }

        $filtro = $ticket_id
            ? "ticket_id=eq.{$ticket_id}"
            : "from_email=eq." . urlencode($correo);

        $correos = sb_get(
            "/rest/v1/correos?{$filtro}&select=ticket_id,estado,prioridad,tipo_pqr,categoria_ia,sentimiento,nivel_riesgo,resumen_corto,agente_id,fecha_limite_sla,sla_vencido,created_at,updated_at,canal_contacto,nombre,correo,telefono_contacto&order=created_at.desc&limit=5",
            $SB_URL, $SB_KEY
        );

        if (empty($correos)) {
            echo json_encode(['encontrado' => false, 'mensaje' => 'No se encontró ningún radicado con ese número o correo.']);
            exit;
        }

        $c = $correos[0];

        // Obtener último evento del historial
        $eventos = sb_get(
            "/rest/v1/historial_eventos?correo_id=eq." . $c['ticket_id'] . "&select=evento,descripcion,created_at&order=created_at.desc&limit=3",
            $SB_URL, $SB_KEY
        );

        // Obtener nombre del agente si está asignado
        $agente_nombre = null;
        if (!empty($c['agente_id'])) {
            $agentes = sb_get(
                "/rest/v1/agentes?id=eq.{$c['agente_id']}&select=nombre,email",
                $SB_URL, $SB_KEY
            );
            $agente_nombre = $agentes[0]['nombre'] ?? null;
        }

        echo json_encode([
            'encontrado'    => true,
            'ticket_id'     => $c['ticket_id'],
            'estado'        => $c['estado'],
            'prioridad'     => $c['prioridad'],
            'tipo'          => $c['tipo_pqr'],
            'categoria'     => $c['categoria_ia'],
            'resumen'       => $c['resumen_corto'],
            'canal'         => $c['canal_contacto'],
            'agente'        => $agente_nombre,
            'fecha_limite'  => $c['fecha_limite_sla'],
            'sla_vencido'   => $c['sla_vencido'],
            'creado'        => $c['created_at'],
            'actualizado'   => $c['updated_at'],
            'ultimo_evento' => $eventos[0] ?? null,
            'historial'     => $eventos,
            'otros_radicados' => count($correos) > 1 ? array_slice($correos, 1) : [],
        ]);
        break;

    // ══════════════════════════════════════════════════════════
    // 2. SEDE POR EPS + MUNICIPIO
    // ══════════════════════════════════════════════════════════
    case 'sede':
        $eps      = strtoupper(trim($body['eps']      ?? 'TODAS'));
        $municipio = strtoupper(trim($body['municipio'] ?? ''));

        // Normalizar: quitar tildes
        $municipio = iconv('UTF-8', 'ASCII//TRANSLIT', $municipio);
        $municipio = preg_replace('/[^A-Z0-9\s\-]/', '', $municipio);
        $municipio = trim($municipio);

        if (!$municipio) {
            echo json_encode(['error' => 'municipio requerido']); exit;
        }

        $sedes = sb_get(
            "/rest/v1/sedes?activa=eq.true&municipio_norm=eq." . urlencode($municipio) .
            "&select=id,nombre,ciudad,direccion,telefono,horario,modelo,eps,lat,lng,encargado&limit=5",
            $SB_URL, $SB_KEY
        );

        if (empty($sedes)) {
            echo json_encode(['encontrado' => false, 'municipio' => $municipio,
                'mensaje' => "No encontré sedes activas en {$municipio}."]);
            exit;
        }

        // Filtrar por EPS en PHP
        $coinciden = array_filter($sedes, function($s) use ($eps) {
            $epsArr = is_array($s['eps']) ? $s['eps'] : json_decode($s['eps'] ?? '[]', true);
            return in_array($eps, $epsArr) || in_array('TODAS', $epsArr);
        });

        $resultado = array_values($coinciden ?: $sedes); // fallback: todas del municipio

        echo json_encode([
            'encontrado' => true,
            'municipio'  => $municipio,
            'eps'        => $eps,
            'sedes'      => $resultado,
            'total'      => count($resultado),
        ]);
        break;

    // ══════════════════════════════════════════════════════════
    // 3. KNOWLEDGE BASE — por texto libre
    // ══════════════════════════════════════════════════════════
    case 'knowledge':
        $texto = strtolower(trim($body['texto'] ?? ''));
        $texto = iconv('UTF-8', 'ASCII//TRANSLIT', $texto);

        $items = sb_get(
            "/rest/v1/knowledge_base?activo=eq.true&select=tipo_pqr,categoria,situacion,respuesta_modelo,tags,veces_usado",
            $SB_URL, $SB_KEY
        );

        if (empty($items)) { echo json_encode(['encontrado' => false]); exit; }

        $mejor = null;
        $score_max = 0;

        foreach ($items as $item) {
            $score = 0;
            $tags = is_array($item['tags']) ? $item['tags'] : json_decode($item['tags'] ?? '[]', true);
            $situacion = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $item['situacion'] ?? ''));

            foreach ($tags as $tag) {
                $tagNorm = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $tag));
                if (str_contains($texto, $tagNorm)) $score += 3;
            }
            $palabras = preg_split('/\s+/', $situacion);
            foreach ($palabras as $p) {
                if (strlen($p) > 4 && str_contains($texto, $p)) $score += 1;
            }
            if ($score > $score_max) { $score_max = $score; $mejor = $item; }
        }

        if ($score_max < 2) { echo json_encode(['encontrado' => false]); exit; }

        // Incrementar veces_usado
        $ch = curl_init("{$SB_URL}/rest/v1/knowledge_base?tipo_pqr=eq.{$mejor['tipo_pqr']}&categoria=eq.{$mejor['categoria']}");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PATCH',
            CURLOPT_POSTFIELDS     => json_encode(['veces_usado' => ($mejor['veces_usado'] ?? 0) + 1]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["apikey: $SB_KEY","Authorization: Bearer $SB_KEY",'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 5,
        ]);
        curl_exec($ch); curl_close($ch);

        echo json_encode(['encontrado' => true, 'score' => $score_max, 'item' => $mejor]);
        break;

    // ══════════════════════════════════════════════════════════
    // 4. AGENTES DISPONIBLES AHORA
    // ══════════════════════════════════════════════════════════
    case 'agentes':
        $agentes = sb_get(
            "/rest/v1/agentes?activo=eq.true&select=nombre,sede,casos_activos,rol&order=casos_activos.asc&limit=5",
            $SB_URL, $SB_KEY
        );

        $hora = (int) date('G', strtotime('now -5 hours')); // hora Colombia
        $dia  = (int) date('N', strtotime('now -5 hours')); // 1=lun, 7=dom
        $en_horario = ($dia >= 1 && $dia <= 5 && $hora >= 7 && $hora < 17);

        echo json_encode([
            'en_horario'   => $en_horario,
            'hora_colombia'=> date('H:i', strtotime('now -5 hours')),
            'horario'      => 'Lun-Vie 7:00 a.m - 5:30 p.m.',
            'agentes'      => $agentes ?? [],
            'disponibles'  => count(array_filter($agentes ?? [], fn($a) => ($a['casos_activos'] ?? 0) < 10)),
        ]);
        break;

    // ══════════════════════════════════════════════════════════
    // 5. CANALES DE CONTACTO (desde configuracion_sistema)
    // ══════════════════════════════════════════════════════════
    case 'canales':
        $cfg = sb_get(
            "/rest/v1/configuracion_sistema?id=eq.main&select=data",
            $SB_URL, $SB_KEY
        );

        $canales = $cfg[0]['data']['canales'] ?? [
            'whatsapp'     => '573043412431',
            'whatsappLabel'=> '304 341 2431',
            'correo'       => 'pqrsfd@tododrogas.com.co',
            'pbx'          => '6043222432',
            'pbxLabel'     => '604 322 2432',
            'horario'      => 'Lun-Vier 7 a.m-5:30 p.m. · Nova 24/7',
        ];

        echo json_encode(['canales' => $canales]);
        break;

    // ══════════════════════════════════════════════════════════
    // 6. CONTEXTO COMPLETO — todo junto para Nova al iniciar
    // ══════════════════════════════════════════════════════════
    case 'contexto_completo':
        $eps      = strtoupper(trim($body['eps']       ?? 'TODAS'));
        $municipio = trim($body['municipio'] ?? '');
        $munNorm  = strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT', $municipio));
        $munNorm  = preg_replace('/[^A-Z0-9\s\-]/', '', $munNorm);

        // Sede
        $sedes = $munNorm ? sb_get(
            "/rest/v1/sedes?activa=eq.true&municipio_norm=eq." . urlencode(trim($munNorm)) .
            "&select=id,nombre,ciudad,direccion,telefono,horario,modelo,eps,encargado&limit=5",
            $SB_URL, $SB_KEY
        ) : [];

        $sedesFiltradas = array_values(array_filter($sedes ?? [], function($s) use ($eps) {
            $epsArr = is_array($s['eps']) ? $s['eps'] : json_decode($s['eps'] ?? '[]', true);
            return in_array($eps, $epsArr) || in_array('TODAS', $epsArr);
        })) ?: ($sedes ?? []);

        // Canales
        $cfg = sb_get("/rest/v1/configuracion_sistema?id=eq.main&select=data", $SB_URL, $SB_KEY);
        $canales = $cfg[0]['data']['canales'] ?? [];

        // Agentes
        $hora = (int) date('G', strtotime('now -5 hours'));
        $dia  = (int) date('N', strtotime('now -5 hours'));
        $en_horario = ($dia >= 1 && $dia <= 5 && $hora >= 7 && $hora < 17);

        // Knowledge base completa (para que JS filtre)
        $kb = sb_get(
            "/rest/v1/knowledge_base?activo=eq.true&select=tipo_pqr,categoria,situacion,respuesta_modelo,tags",
            $SB_URL, $SB_KEY
        );

        echo json_encode([
            'sedes'       => $sedesFiltradas,
            'canales'     => $canales,
            'en_horario'  => $en_horario,
            'knowledge'   => $kb ?? [],
            'timestamp'   => date('c'),
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => "Acción '{$accion}' no reconocida"]);
}

