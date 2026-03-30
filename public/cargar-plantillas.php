<?php
/**
 * cargar-plantillas.php — Carga plantillas de respuestas a knowledge_base
 * ─────────────────────────────────────────────────────────────────────
 * Ejecutar UNA VEZ desde el navegador con token de admin
 * GET /cargar-plantillas.php?token=ADMIN_SYNC_TOKEN&modo=preview  (ver sin insertar)
 * GET /cargar-plantillas.php?token=ADMIN_SYNC_TOKEN&modo=insertar (insertar en BD)
 * GET /cargar-plantillas.php?token=ADMIN_SYNC_TOKEN&modo=limpiar  (borrar todas las KB y reinsertar)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$ADMIN_TOKEN = '__ADMIN_SYNC_TOKEN__';
$SB_URL      = '__SB_URL__';
$SB_KEY      = '__SB_KEY__';

$token = $_GET['token'] ?? '';
$modo  = $_GET['modo']  ?? 'preview';

if ($token !== $ADMIN_TOKEN) {
    http_response_code(403);
    echo json_encode(['error' => 'Token inválido']);
    exit;
}

// ── PLANTILLAS ────────────────────────────────────────────────────────
// Extraídas del documento PLANTILLAS_RESPUESTAS_CASOS_JURIDICOS
// Variables dinámicas marcadas con [X] para que el agente las complete

$plantillas = [

    // ═══ MUNICIPIOS NO PROPIOS ═══════════════════════════════════════
    [
        'tipo_pqr'        => 'peticion',
        'categoria'       => 'Municipios No Propios',
        'situacion'       => 'Envío de medicamento hoy a municipio no propio',
        'respuesta_modelo'=> "Buen día,\n\nSe informa que el medicamento [MEDICAMENTO] será enviado el día de hoy para el municipio de [MUNICIPIO], para su entrega efectiva el día [FECHA] en horas de la tarde. Adjunto formato de entrega como evidencia de facturación.\n\nQuedo atenta, feliz día.",
        'tags'            => ['municipio no propio', 'envío', 'medicamento', 'entrega'],
    ],
    [
        'tipo_pqr'        => 'peticion',
        'categoria'       => 'Municipios No Propios',
        'situacion'       => 'Medicamento solicitado a compras - municipio no propio',
        'respuesta_modelo'=> "Buen día,\n\nSe informa que el medicamento [MEDICAMENTO] fue solicitado al área de compras, tiene fecha de llegada para el día [FECHA_LLEGADA], para su entrega efectiva el día [FECHA_ENTREGA] en horas de la tarde. Adjunto formato de pendientes como evidencia de facturación.\n\nQuedo atenta, feliz día.",
        'tags'            => ['municipio no propio', 'compras', 'pendiente', 'medicamento'],
    ],

    // ═══ MUNICIPIOS PROPIOS ═══════════════════════════════════════════
    [
        'tipo_pqr'        => 'peticion',
        'categoria'       => 'Municipios Propios',
        'situacion'       => 'Traslado de medicamento a municipio propio hoy',
        'respuesta_modelo'=> "Buen día,\n\nSe informa que el medicamento [MEDICAMENTO] será enviado el día de hoy en traslado para el municipio [MUNICIPIO], para su entrega efectiva el día [FECHA] en horas de la tarde. Adjunto formato de pendientes como evidencia de facturación.\n\nQuedo atenta, feliz día.",
        'tags'            => ['municipio propio', 'traslado', 'medicamento', 'envío'],
    ],
    [
        'tipo_pqr'        => 'peticion',
        'categoria'       => 'Municipios Propios',
        'situacion'       => 'Medicamento disponible en servicio farmacéutico',
        'respuesta_modelo'=> "Buen día,\n\nSe informa que el medicamento [MEDICAMENTO] ya se encuentra disponible en el servicio farmacéutico de [SEDE] listo para ser entregado al usuario. Adjunto formato de entrega como evidencia de facturación.\n\nQuedo atenta, feliz día.",
        'tags'            => ['municipio propio', 'disponible', 'medicamento', 'sede'],
    ],
    [
        'tipo_pqr'        => 'queja',
        'categoria'       => 'Municipios Propios',
        'situacion'       => 'Fórmula médica errada',
        'respuesta_modelo'=> "Buen día,\n\nSe informa que la fórmula del medicamento [MEDICAMENTO] se encuentra errada: [DESCRIPCIÓN_ERROR]. Por favor corregir para su respectiva gestión.\n\nQuedo atenta, feliz día.",
        'tags'            => ['formula errada', 'medicamento', 'corrección'],
    ],
    [
        'tipo_pqr'        => 'peticion',
        'categoria'       => 'Municipios Propios',
        'situacion'       => 'Medicamento nivel 1 - corresponde a ESE del municipio',
        'respuesta_modelo'=> "Buen día,\n\nSe informa que el medicamento [MEDICAMENTO] es de nivel 1, por lo tanto, le corresponde a la ESE del municipio.\n\nQuedo atenta, feliz día.",
        'tags'            => ['nivel 1', 'ESE', 'municipio', 'medicamento'],
    ],

    // ═══ RUTA AUTOINMUNE / SAVIA ══════════════════════════════════════
    [
        'tipo_pqr'        => 'peticion',
        'categoria'       => 'Ruta Autoinmune',
        'situacion'       => 'Usuario activo en ruta autoinmune - respuesta a SAVIA',
        'respuesta_modelo'=> "Buen día,\n\nEl usuario se encuentra activo en la ruta AUTOINMUNE.\n\nQuedo atenta, feliz día.",
        'tags'            => ['autoinmune', 'ruta', 'SAVIA', 'activo'],
    ],
    [
        'tipo_pqr'        => 'peticion',
        'categoria'       => 'Ruta Autoinmune',
        'situacion'       => 'Comité autoinmune - dispensar sin importar nivel',
        'respuesta_modelo'=> "Buen día,\n\nPara comité dispensamos todos los medicamentos que estén contratados, sin importar si es de nivel 1 ya que ellos no manejan nivel 1. Por favor ayúdame con la dispensa y si no tienes los medicamentos ya te los envío en traslado.\n\nQuedo atenta, feliz día.",
        'tags'            => ['comité autoinmune', 'nivel 1', 'dispensa', 'traslado'],
    ],

    // ═══ DOMICILIOS ═══════════════════════════════════════════════════
    [
        'tipo_pqr'        => 'peticion',
        'categoria'       => 'Domicilios',
        'situacion'       => 'Envío de medicamento a domicilio',
        'respuesta_modelo'=> "Buen día,\n\nSe informa que el medicamento [MEDICAMENTO] será enviado el día de hoy a domicilio a la dirección [DIRECCIÓN]. Inmediatamente sea entregado el medicamento, el acta de entrega firmada será enviada por este mismo medio.\n\nQuedo atenta, feliz día.",
        'tags'            => ['domicilio', 'entrega', 'medicamento', 'acta'],
    ],

    // ═══ STOCK / AGOTADOS ═════════════════════════════════════════════
    [
        'tipo_pqr'        => 'queja',
        'categoria'       => 'Stock y Disponibilidad',
        'situacion'       => 'Medicamento agotado',
        'respuesta_modelo'=> "Buen día,\n\nSe informa que el medicamento [MEDICAMENTO] se encuentra agotado, adjunto carta por parte del laboratorio.\n\nQuedo atenta, feliz día.",
        'tags'            => ['agotado', 'medicamento', 'laboratorio', 'stock'],
    ],
    [
        'tipo_pqr'        => 'peticion',
        'categoria'       => 'Stock y Disponibilidad',
        'situacion'       => 'Sin fórmula médica del usuario - intentamos contactar',
        'respuesta_modelo'=> "Buen día,\n\nSe informa que no contamos con fórmula médica del usuario, tratamos de comunicarnos al número [TELÉFONO] para brindar la información, pero no obtuvimos respuesta. Adjunto evidencia de las llamadas. Por favor compartir fórmula para su respectiva gestión.\n\nQuedo atenta, feliz día.",
        'tags'            => ['formula medica', 'sin formula', 'contacto', 'llamadas'],
    ],
    [
        'tipo_pqr'        => 'peticion',
        'categoria'       => 'Stock y Disponibilidad',
        'situacion'       => 'Medicamento no contratado con la EPS',
        'respuesta_modelo'=> "Buen día,\n\nSe informa que el medicamento [MEDICAMENTO] no se encuentra dentro del listado de medicamentos contratados con la EPS [EPS].\n\nQuedo atenta, feliz día.",
        'tags'            => ['no contratado', 'EPS', 'medicamento', 'listado'],
    ],

    // ═══ COBERTURA ════════════════════════════════════════════════════
    [
        'tipo_pqr'        => 'peticion',
        'categoria'       => 'Cobertura',
        'situacion'       => 'Sin cobertura en municipio y usuario no puede desplazarse',
        'respuesta_modelo'=> "Buen día,\n\nEl usuario es del municipio [MUNICIPIO], en el momento no contamos con cobertura al municipio. En comunicación con el usuario le informamos que, si se podía acercar al SF de [SF_MÁS_CERCANO] que es el más cercano, y nos informa que no puede ir a reclamarlos por [MOTIVO].\n\nQuedo atenta, feliz día.",
        'tags'            => ['sin cobertura', 'municipio', 'desplazamiento', 'usuario'],
    ],

    // ═══ VENCIMIENTOS ═════════════════════════════════════════════════
    [
        'tipo_pqr'        => 'peticion',
        'categoria'       => 'Vencimientos y Fechas',
        'situacion'       => 'Direccionamiento vencido - solicitar extensión',
        'respuesta_modelo'=> "Buen día,\n\nSe informa que el direccionamiento [NÚMERO_DIRECCIONAMIENTO] se encuentra vencido desde el [FECHA], por favor extender fecha para realizar la respectiva gestión.\n\nQuedo atenta, feliz día.",
        'tags'            => ['vencido', 'direccionamiento', 'extensión', 'fecha'],
    ],
    [
        'tipo_pqr'        => 'peticion',
        'categoria'       => 'Vencimientos y Fechas',
        'situacion'       => 'Fórmula médica vencida',
        'respuesta_modelo'=> "Buen día,\n\nSe informa que la fórmula se encuentra vencida, es del mes de [MES] por [DURACIÓN], por ende, ya se encuentra vencida, por lo que no se puede realizar la dispensación. Por favor adjuntar fórmula vigente para su respectiva gestión.\n\nQuedo atenta, feliz día.",
        'tags'            => ['formula vencida', 'dispensación', 'vigente'],
    ],

    // ═══ GLP1 / HIPOLIPEMIANTES ═══════════════════════════════════════
    [
        'tipo_pqr'        => 'peticion',
        'categoria'       => 'GLP1 y Hipolipemiantes',
        'situacion'       => 'Medicamento GLP1 rechazado en estudio',
        'respuesta_modelo'=> "Buen día,\n\nSe realizó el estudio para el medicamento [MEDICAMENTO] y este fue rechazado.\n\nQuedo atenta, feliz día.",
        'tags'            => ['GLP1', 'rechazado', 'estudio', 'medicamento'],
    ],
    [
        'tipo_pqr'        => 'peticion',
        'categoria'       => 'GLP1 y Hipolipemiantes',
        'situacion'       => 'Hipolipemiante rechazado en estudio',
        'respuesta_modelo'=> "Buen día,\n\nEl medicamento es HIPOLIPEMIANTE, se realizó el estudio para el medicamento [MEDICAMENTO] y este fue rechazado.\n\nQuedo atenta, feliz día.",
        'tags'            => ['hipolipemiante', 'rechazado', 'estudio', 'GLP1'],
    ],

    // ═══ COTIZACIÓN ═══════════════════════════════════════════════════
    [
        'tipo_pqr'        => 'peticion',
        'categoria'       => 'Cotización',
        'situacion'       => 'Medicamento pasa a proceso de cotización',
        'respuesta_modelo'=> "Cordial saludo,\n\nSe informa que el medicamento [MEDICAMENTO] no se encuentra dentro de los medicamentos contratados, por lo tanto, pasa a proceso de cotización.\n\nQuedo atenta, feliz día.",
        'tags'            => ['cotización', 'no contratado', 'medicamento', 'proceso'],
    ],
    [
        'tipo_pqr'        => 'peticion',
        'categoria'       => 'Cotización',
        'situacion'       => 'Medicamento ROMOSOZUMAB a cotización - portabilidad',
        'respuesta_modelo'=> "Buen día,\n\nEl medicamento [MEDICAMENTO] pasa a proceso de cotización dado que no se encuentra dentro del listado de medicamentos contratados con la EPS.\n\nQuedo atenta, feliz día.",
        'tags'            => ['cotización', 'portabilidad', 'EPS', 'contratado'],
    ],

    // ═══ COMPRAS ══════════════════════════════════════════════════════
    [
        'tipo_pqr'        => 'peticion',
        'categoria'       => 'Compras',
        'situacion'       => 'Medicamento solicitado a compras con fecha estimada',
        'respuesta_modelo'=> "Buen día,\n\nEl medicamento [MEDICAMENTO] fue solicitado al área de compras, tiene fecha estimada de llegada para el día [FECHA_LLEGADA], para su entrega efectiva el día [FECHA_ENTREGA] en horas de la tarde. Adjunto formato de pendiente y orden de compra.\n\nQuedo atenta, feliz día.",
        'tags'            => ['compras', 'pedido', 'fecha llegada', 'orden de compra'],
    ],
    [
        'tipo_pqr'        => 'peticion',
        'categoria'       => 'Compras',
        'situacion'       => 'Entrega no corresponde a Tododrogas - corresponde a otro proveedor',
        'respuesta_modelo'=> "Buen día,\n\nInformamos que la entrega del medicamento [MEDICAMENTO] no nos corresponde, la dispensación le corresponde al proveedor [PROVEEDOR].\n\nQuedo atenta, feliz día.",
        'tags'            => ['proveedor', 'no corresponde', 'dispensación', 'entrega'],
    ],

    // ═══ PREPARADOS ═══════════════════════════════════════════════════
    [
        'tipo_pqr'        => 'peticion',
        'categoria'       => 'Preparados',
        'situacion'       => 'Solicitud de preparado magistral',
        'respuesta_modelo'=> "Buen día,\n\nSolicito de su colaboración con la preparación del medicamento [MEDICAMENTO] para el usuario [ID_USUARIO]-[NOMBRE_USUARIO] cantidad [CANTIDAD], adjunto fórmula médica, historia clínica.\n\nNota: prioridad por favor.\n\nQuedo atenta, feliz tarde.",
        'tags'            => ['preparado', 'magistral', 'fórmula médica', 'historia clínica'],
    ],

    // ═══ AUTORIZACIÓN SAVIA-CAPITA ════════════════════════════════════
    [
        'tipo_pqr'        => 'peticion',
        'categoria'       => 'Autorizaciones',
        'situacion'       => 'Solicitar autorización para dispensar en SAVIA-CAPITA',
        'respuesta_modelo'=> "Buen día,\n\nPor favor solicito AUTORIZACIÓN PARA DISPENSAR EN SAVIA-CAPITA el medicamento [MEDICAMENTO] para el usuario [NOMBRE_USUARIO] CC [CÉDULA].\n\nQuedo atenta, feliz día.",
        'tags'            => ['autorización', 'SAVIA', 'capita', 'dispensar'],
    ],

    // ═══ CAPITA NIVEL 1 COOSALUD ══════════════════════════════════════
    [
        'tipo_pqr'        => 'peticion',
        'categoria'       => 'Cápita y Evento',
        'situacion'       => 'Medicamento nivel 1 COOSALUD - validar en Excel CAPITA 1',
        'respuesta_modelo'=> "Buen día,\n\nCuando el medicamento es de nivel 1 y es de MED RIONEGO O ITAGUI, se valida en el Excel TODO DROGAS CAPITA 1. Si está ahí se le dispensa cápita; en caso de que no, se rechaza porque le toca a la ESE del municipio.\n\nQuedo atenta, feliz día.",
        'tags'            => ['capita nivel 1', 'COOSALUD', 'ESE', 'medicamento'],
    ],
];

// ── HELPERS ──────────────────────────────────────────────────────────
function sbPost(string $SB_URL, string $SB_KEY, string $endpoint, array $data): array {
    $ch = curl_init("$SB_URL/rest/v1/$endpoint");
    curl_setopt_array($ch, [
        CURLOPT_POST        => true,
        CURLOPT_POSTFIELDS  => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT     => 15,
        CURLOPT_HTTPHEADER  => [
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

// ── MODO LIMPIAR ─────────────────────────────────────────────────────
if ($modo === 'limpiar') {
    $r = curl_init("$SB_URL/rest/v1/knowledge_base?activo=eq.true");
    curl_setopt_array($r, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "apikey: $SB_KEY",
            "Authorization: Bearer $SB_KEY",
            'Prefer: return=minimal',
        ],
    ]);
    curl_exec($r);
    curl_close($r);
}

// ── MODO INSERTAR ─────────────────────────────────────────────────────
if ($modo === 'insertar' || $modo === 'limpiar') {
    $ok = 0; $err = 0; $errores = [];
    foreach ($plantillas as $p) {
        $r = sbPost($SB_URL, $SB_KEY, 'knowledge_base', [
            'tipo_pqr'        => $p['tipo_pqr'],
            'categoria'       => $p['categoria'],
            'situacion'       => $p['situacion'],
            'respuesta_modelo'=> $p['respuesta_modelo'],
            'tags'            => json_encode($p['tags']),
            'activo'          => true,
            'veces_usado'     => 0,
            'calificacion'    => 0,
            'creado_por'      => 'sistema',
            'aprobado_por'    => 'admin',
        ]);
        if ($r['code'] >= 200 && $r['code'] < 300) $ok++;
        else { $err++; $errores[] = $p['situacion'] . ' → HTTP ' . $r['code']; }
    }
    echo json_encode([
        'ok'      => true,
        'modo'    => $modo,
        'total'   => count($plantillas),
        'insertadas' => $ok,
        'errores' => $err,
        'detalle_errores' => $errores,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── MODO PREVIEW ──────────────────────────────────────────────────────
echo json_encode([
    'ok'         => true,
    'modo'       => 'preview',
    'total'      => count($plantillas),
    'categorias' => array_values(array_unique(array_column($plantillas, 'categoria'))),
    'plantillas' => array_map(fn($p) => [
        'categoria' => $p['categoria'],
        'situacion' => $p['situacion'],
        'tipo_pqr'  => $p['tipo_pqr'],
        'tags'      => $p['tags'],
        'preview'   => mb_substr($p['respuesta_modelo'], 0, 80) . '...',
    ], $plantillas),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
