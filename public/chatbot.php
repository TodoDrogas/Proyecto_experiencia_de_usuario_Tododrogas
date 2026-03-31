<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── Credenciales (inyectadas por deploy.yml) ──
$OPENAI_KEY = '__OPENAI_KEY__';
$SB_URL     = '__SB_URL__';
$SB_KEY     = '__SB_KEY__';

// ── Leer body ──
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); echo json_encode(['error'=>'Invalid JSON']); exit; }

$messages     = $body['messages']      ?? [];
$model        = $body['model']         ?? 'gpt-4o-mini';
$max_tokens   = $body['max_tokens']    ?? 400;
$temperature  = $body['temperature']   ?? 0.7;

// ── Contexto de sesión Nova (enviado desde JS) ──
$session_id        = $body['session_id']        ?? null;
$eps_usuario       = $body['eps_usuario']       ?? null;
$municipio_usuario = $body['municipio_usuario'] ?? null;
$estado_flujo      = $body['estado_flujo']      ?? 'libre';
$escalado          = $body['escalado']          ?? false;
$contexto_asesor   = $body['contexto_asesor']   ?? null;

// ── Llamar a OpenAI ──
$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'model'       => $model,
        'max_tokens'  => $max_tokens,
        'messages'    => $messages,
        'temperature' => $temperature,
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer {$OPENAI_KEY}",
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT => 30,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ── Guardar sesión en Supabase (si viene session_id y credenciales inyectadas) ──
if ($session_id && $SB_URL && $SB_KEY && $code === 200) {
    $respData       = json_decode($resp, true);
    $reply          = $respData['choices'][0]['message']['content'] ?? '';
    $escalado_final = $escalado || str_starts_with(trim($reply), 'ESCALAR');

    // Solo guardar user/assistant (sin system prompt)
    $msgs_guardados = array_values(array_filter($messages, fn($m) => $m['role'] !== 'system'));
    $msgs_guardados[] = ['role' => 'assistant', 'content' => $reply];

    $payload = json_encode([
        'session_id'        => $session_id,
        'eps_usuario'       => $eps_usuario,
        'municipio_usuario' => $municipio_usuario,
        'mensajes'          => $msgs_guardados,
        'estado'            => 'activa',
        'estado_flujo'      => $estado_flujo,
        'escalado'          => $escalado_final,
        'contexto_asesor'   => $escalado_final
            ? ($contexto_asesor ?? "EPS:{$eps_usuario} Municipio:{$municipio_usuario}")
            : null,
        'updated_at'        => date('c'),
    ]);

    $sb = curl_init("{$SB_URL}/rest/v1/chatbot_sesiones");
    curl_setopt_array($sb, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "apikey: {$SB_KEY}",
            "Authorization: Bearer {$SB_KEY}",
            'Content-Type: application/json',
            'Prefer: resolution=merge-duplicates',
        ],
        CURLOPT_TIMEOUT => 5,
    ]);
    curl_exec($sb);
    curl_close($sb);
}

http_response_code($code);
echo $resp;
