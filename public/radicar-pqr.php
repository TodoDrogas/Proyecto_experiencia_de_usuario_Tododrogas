<?php
/**
 * radicar-pqr.php v5 — Sistema PQR Tododrogas CIA SAS
 * Mejoras v5:
 * - Sentimiento detallado: enojado/frustrado/triste/preocupado/tranquilo/satisfecho/neutro/urgente
 * - Correo ciudadano: diseño limpio con logo, Franklin Gothic, sin azul corporativo
 * - Correo pqrsfd: más datos, adjuntos audio/canvas/pdf del lápiz
 * - Audios guardados en bucket 'audios', canvas en 'canvas-images', registrados en tabla adjuntos
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

date_default_timezone_set('America/Bogota');

// ── CREDENCIALES ────────────────────────────────────────────────────
$SB_URL        = '__SB_URL__';
$SB_KEY        = '__SB_KEY__';
$OPENAI_KEY    = '__OPENAI_KEY__';
$TENANT_ID     = '__AZURE_TENANT_ID__';
$CLIENT_ID     = '__AZURE_CLIENT_ID__';
$CLIENT_SECRET = '__AZURE_CLIENT_SECRET__';
$BUZON_PQRS    = 'pqrsfd@tododrogas.com.co';
$GRAPH_USER_ID = '__GRAPH_USER_ID__';

// Logo Tododrogas embebido como base64
$LOGO_B64 = '/9j/4AAQSkZJRgABAQAAAQABAAD/4gHYSUNDX1BST0ZJTEUAAQEAAAHIAAAAAAQwAABtbnRyUkdCIFhZWiAH4AABAAEAAAAAAABhY3NwAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAQAA9tYAAQAAAADTLQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAlkZXNjAAAA8AAAACRyWFlaAAABFAAAABRnWFlaAAABKAAAABRiWFlaAAABPAAAABR3dHB0AAABUAAAABRyVFJDAAABZAAAAChnVFJDAAABZAAAAChiVFJDAAABZAAAAChjcHJ0AAABjAAAADxtbHVjAAAAAAAAAAEAAAAMZW5VUwAAAAgAAAAcAHMAUgBHAEJYWVogAAAAAAAAb6IAADj1AAADkFhZWiAAAAAAAABimQAAt4UAABjaWFlaIAAAAAAAACSgAAAPhAAAts9YWVogAAAAAAAA9tYAAQAAAADTLXBhcmEAAAAAAAQAAAACZmYAAPKnAAANWQAAE9AAAApbAAAAAAAAAABtbHVjAAAAAAAAAAEAAAAMZW5VUwAAACAAAAAcAEcAbwBvAGcAbABlACAASQBuAGMALgAgADIAMAAxADb/2wBDAAUDBAQEAwUEBAQFBQUGBwwIBwcHBw8LCwkMEQ8SEhEPERETFhwXExQaFRERGCEYGh0dHx8fExciJCIeJBweHx7/2wBDAQUFBQcGBw4ICA4eFBEUHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh7/wAARCADRAyADASIAAhEBAxEB/8QAHQABAAICAwEBAAAAAAAAAAAAAAcIBQYCAwQBCf/EAFsQAAEDAwECBwsECw4EBgIDAAIAAwQBBQYSBxEIEyEiMkJSFDFBUWFicoKSorIjcYGhFSQzNTZzdJGxwtIWFzQ3Q1NWdZOzwcPR8CWDlOIYRFVjpOFUhKPx8v/EABsBAQEAAgMBAAAAAAAAAAAAAAACBQYBAwQH/8QALBEBAAICAQIEBQQDAQAAAAAAAAECAwQFERIGEyExFCIyQaFRYXGxIzNCgf/aAAwDAQACEQMRAD8AuWiIgIiICIiAiIgIiIONeWnKvlOTvUXKqxOQ3y22KHWVcpAtB3hpv5Tr4qUVVra89KoyZK469156Qyne8NEqQ9qihDJdq91mGTNnaGEz1TIdbpfqj/vlWjz7xdp5VrNuMh6lfA46VVmsHA58te689rW9nxRr47duOO78LS0cbrXdRwa1+dctXi5VUoTcEtQlpJZi2ZVkNsMaxbrIoI9Qj1B+Yuau6/h3JEfJd5qeLKTPz4/ys9vovqifEdq4PGEW/M0ZrXmjIapzfpp/p+ZSjFkMymBfjug42dN4kJb6VosLs6mXXt25IbHp8hr7leuKz0IiLzvaIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiJWtKUrVBg8vv8PHbM5cJRb9NNzTdK85w+zRV2yS+z7/cTmznSOpdAOqA9kVntrV/cvOTOxmj+1YZEy0PaLrF7X1DRaWVdw6lufD8fXDi8y31S+dc7yltrPOKn0R+WYxrHrrkMqse2R9ej7q4Zbgb+eq3Utj13oxqG8Qye7FWa0H2v+1SNs9tTFmxGBHaoNHDbF14u2ZDvL/fkWzb91Fidrm8/mzGL0hm9Hw3r+TFs3rMqsX6y3Kw3AoV0jkw5u3iXSE6eOhdZY9WB2xWli44ZLlGI8dBGshsvFp79PpHfRV+We4vd+Lxd1vqaxzHH/A7HZX2n2FumzbNZGPTRiTDN22u1549/ivLRaWi9mxr02Kdl/Z4dbZy62WMmOfWFsY7zUhkHmTE2jpQhKleStKrvqou2F5BWVBesclze5GHjGd9ep4R9Uv0qUVoG1r218tsdn1LR267eCuav3fURF53tEREBERAREQEREBERAREQEREBERAREQEREBERAREQEREBERAREQEREBERAREQEREBERAREQEREBERAREQEREBERAREQEREBERAREQEREBERAREQEREBERAREQEREBERAREQEREBERAREQEREHAv8Vi8omnbscuE4K89mOZB6W7k+tZQipTfUq7qLV9qla0wO56e/QA+Oi7desXy0if1h5tu80173j7RKuThb3CLtL53xRF9H+z5HM+vVYTZVkEe94vHYqY91xGxZfb8NK05KF81aU3rcS7+7fTcqo26ZLt0sJcGU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';

// ── SLA NORMATIVA COLOMBIANA ─────────────────────────────────────────
function getSLA($tipo, $prioridad, $nivel_riesgo) {
    $sla = [
        'peticion'   => ['default'=>[360,'15 días hábiles','Ley 1755/2015']],
        'queja'      => ['critica'=>[24,'Riesgo vital — 24h','Ley 1755/2015'],
                         'alta'   =>[48,'Riesgo priorizado — 48h','Ley 1755/2015'],
                         'media'  =>[72,'Riesgo simple — 72h','Ley 1755/2015'],
                         'default'=>[360,'15 días hábiles','Ley 1755/2015']],
        'reclamo'    => ['default'=>[360,'15 días hábiles','Ley 1755/2015 / Ley 1751/2015']],
        'sugerencia' => ['default'=>[360,'15 días hábiles','Ley 1755/2015']],
        'solicitud'  => ['default'=>[192,'8 días hábiles','Ley 1755/2015']],
        'solicitud_copias'=>['default'=>[240,'10 días hábiles','Ley 1755/2015']],
        'consulta'   => ['default'=>[720,'30 días hábiles','Ley 1755/2015']],
        'felicitacion'=>['default'=>[360,'15 días hábiles','Ley 1755/2015']],
        'denuncia'   => ['default'=>[360,'15 días hábiles','Ley 1755/2015 / Vigilancia Sanitaria']],
    ];
    $tk = strtolower($tipo);
    $map=['peticion'=>'peticion','queja'=>'queja','reclamo'=>'reclamo','sugerencia'=>'sugerencia',
          'solicitud'=>'solicitud','felicitacion'=>'felicitacion','denuncia'=>'denuncia','consulta'=>'consulta'];
    $tk = $map[$tk] ?? 'peticion';
    $r = $sla[$tk] ?? $sla['peticion'];
    if($tk==='queja'){
        if($prioridad==='critica'||$nivel_riesgo==='critico') return $r['critica'];
        if($prioridad==='alta'||$nivel_riesgo==='alto') return $r['alta'];
        if($prioridad==='media'||$nivel_riesgo==='medio') return $r['media'];
    }
    return $r['default'];
}

// ── HELPERS ──────────────────────────────────────────────────────────
function sbPost($url,$key,$ep,$data,$pref='return=representation'){
    $ch=curl_init("$url/rest/v1/$ep");
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data),
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,
        CURLOPT_HTTPHEADER=>["apikey: $key","Authorization: Bearer $key",
            'Content-Type: application/json',"Prefer: $pref"]]);
    $r=curl_exec($ch);$c=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    return['code'=>$c,'body'=>$r];
}
function sbPatch($url,$key,$ep,$filter,$data){
    $ch=curl_init("$url/rest/v1/$ep?$filter");
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>'PATCH',CURLOPT_POSTFIELDS=>json_encode($data),
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,
        CURLOPT_HTTPHEADER=>["apikey: $key","Authorization: Bearer $key",
            'Content-Type: application/json','Prefer: return=minimal']]);
    curl_exec($ch);curl_close($ch);
}
function getToken($t,$ci,$cs){
    $ch=curl_init("https://login.microsoftonline.com/$t/oauth2/v2.0/token");
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,
        CURLOPT_POSTFIELDS=>http_build_query(['grant_type'=>'client_credentials','client_id'=>$ci,
            'client_secret'=>$cs,'scope'=>'https://graph.microsoft.com/.default']),
        CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
    $r=curl_exec($ch);curl_close($ch);
    return json_decode($r,true)['access_token']??null;
}
function sendMail($tok,$uid,$payload){
    $ch=curl_init("https://graph.microsoft.com/v1.0/users/$uid/sendMail");
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,
        CURLOPT_POSTFIELDS=>json_encode(['message'=>$payload,'saveToSentItems'=>true]),
        CURLOPT_HTTPHEADER=>["Authorization: Bearer $tok",'Content-Type: application/json']]);
    $r=curl_exec($ch);$c=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    return $c===202;
}

// Guardar archivo en Supabase Storage y registrar en tabla adjuntos
function guardarAdjunto($SB_URL,$SB_KEY,$correo_id,$ticket_id,$bucket,$nombre,$mime,$datos_bin){
    if(!is_string($datos_bin)||strlen($datos_bin)<100) return null;
    $ts = round(microtime(true)*1000);
    $safe = preg_replace('/[^a-zA-Z0-9._-]/','_',$nombre);
    $path = "$correo_id/${ts}_$safe";
    $url_storage = "$SB_URL/storage/v1/object/$bucket/$path";

    $ch=curl_init($url_storage);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$datos_bin,
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,
        CURLOPT_HTTPHEADER=>["apikey: $SB_KEY","Authorization: Bearer $SB_KEY",
            "Content-Type: $mime","x-upsert: true"]]);
    $r=curl_exec($ch);$c=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($c>=400) return null;

    $public_url = "$SB_URL/storage/v1/object/public/$bucket/$path";

    // Registrar en tabla adjuntos
    sbPost($SB_URL,$SB_KEY,'adjuntos',[
        'correo_id'     => $correo_id,
        'attachment_id' => "form_${ticket_id}_$ts",
        'nombre'        => $nombre,
        'tipo_contenido'=> $mime,
        'tamano_bytes'  => strlen($datos_bin),
        'es_inline'     => false,
        'storage_url'   => $public_url,
        'storage_path'  => $path,
        'direccion'     => 'entrante',
        'enviado_por'   => 'formulario_web',
    ],'return=minimal');

    return $public_url;
}

// ── INPUT ────────────────────────────────────────────────────────────
$body=json_decode(file_get_contents('php://input'),true);
if(!$body){http_response_code(400);echo json_encode(['error'=>'Invalid JSON']);exit;}

$now      = new DateTime('now',new DateTimeZone('America/Bogota'));
$fecha_iso= $now->format('c');
$fecha_fmt= $now->format('d/m/Y H:i');
$ticket_id= 'TD-'.$now->format('Ymd').'-'.str_pad(rand(1000,9999),4,'0',STR_PAD_LEFT);

$nombre      = trim($body['nombre']       ?? '');
$documento   = trim($body['documento']    ?? '');
$correo      = trim($body['correo']       ?? '');
$telefono    = trim($body['telefono']     ?? '');
$descripcion = trim($body['descripcion']  ?? '');
$tipo_pqr    = strtolower(trim($body['tipo_pqr'] ?? $body['tipo'] ?? 'peticion'));
$transcripcion=trim($body['transcripcion']?? '');
$audio_url   = trim($body['audio_url']    ?? '');
$canvas_url  = trim($body['canvas_url']   ?? '');
$sede_nombre = trim($body['sede_nombre']  ?? '');
$sede_ciudad = trim($body['sede_ciudad']  ?? '');
$canal_pref  = trim($body['contacto_preferido'] ?? $body['canal'] ?? 'formulario_web');
$sin_correo  = (bool)($body['sin_correo'] ?? empty($correo));

$canal = 'escrito';
if($audio_url||$transcripcion) $canal='audio';
elseif($canvas_url) $canal='canvas';
$texto_pqr = $transcripcion ?: $descripcion;

// ── CLASIFICACIÓN IA DETALLADA ───────────────────────────────────────
$sentimiento='neutro'; $emocion_detalle='tranquilo/a';
$prioridad='media'; $categoria_ia=ucfirst($tipo_pqr);
$nivel_riesgo='bajo'; $resumen_corto=mb_substr($texto_pqr,0,120);
$riesgo_vital=false; $ley_aplicable='Ley 1755/2015';

if($OPENAI_KEY&&$texto_pqr){
    $prompt="Eres experto en atención al usuario en droguerías colombianas. Analiza esta PQR y responde SOLO JSON válido (sin markdown).

TIPO: $tipo_pqr | SEDE: $sede_nombre | CIUDAD: $sede_ciudad
TEXTO: $texto_pqr

Responde exactamente:
{
  \"sentimiento\": \"enojado|frustrado|triste|preocupado|tranquilo|satisfecho|neutro|urgente\",
  \"emocion_detalle\": \"descripción de 3-5 palabras del estado emocional detectado\",
  \"intensidad\": \"baja|media|alta\",
  \"prioridad\": \"baja|media|alta|critica\",
  \"categoria\": \"categoría breve (ej: Dispensación medicamentos, Trato del personal)\",
  \"nivel_riesgo\": \"bajo|medio|alto|critico\",
  \"riesgo_vital\": true/false,
  \"resumen\": \"máximo 80 caracteres\",
  \"ley\": \"ley colombiana aplicable\",
  \"tipo_confirmado\": \"peticion|queja|reclamo|sugerencia|solicitud|felicitacion|denuncia\",
  \"keywords\": [\"palabra1\",\"palabra2\",\"palabra3\"]
}";

    $ch=curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,
        CURLOPT_POSTFIELDS=>json_encode(['model'=>'gpt-4o-mini','max_tokens'=>300,'temperature'=>0.1,
            'messages'=>[['role'=>'system','content'=>'Clasificador PQRs farmacéutico Colombia. Solo JSON.'],
                         ['role'=>'user','content'=>$prompt]]]),
        CURLOPT_HTTPHEADER=>["Authorization: Bearer $OPENAI_KEY",'Content-Type: application/json']]);
    $ai_resp=curl_exec($ch);curl_close($ch);
    $ai_data=json_decode($ai_resp,true);
    $ai_text=preg_replace('/```json|```/','',$ai_data['choices'][0]['message']['content']??'');
    $ia=json_decode(trim($ai_text),true);
    if($ia){
        $sentimiento   =$ia['sentimiento']    ??'neutro';
        $emocion_detalle=$ia['emocion_detalle']??'tranquilo/a';
        $prioridad     =$ia['prioridad']      ??'media';
        $categoria_ia  =$ia['categoria']      ??ucfirst($tipo_pqr);
        $nivel_riesgo  =$ia['nivel_riesgo']   ??'bajo';
        $riesgo_vital  =!empty($ia['riesgo_vital']);
        $resumen_corto =mb_substr($ia['resumen']??'',0,150);
        $ley_aplicable =$ia['ley']            ??'Ley 1755/2015';
        if(!empty($ia['tipo_confirmado'])) $tipo_pqr=$ia['tipo_confirmado'];
    }
}

[$horas_sla,$sla_desc,$ley_sla]=getSLA($tipo_pqr,$prioridad,$nivel_riesgo);
if($ley_sla&&!$ley_aplicable) $ley_aplicable=$ley_sla;

$limite=clone $now; $limite->modify("+${horas_sla} hours");
$fecha_limite_iso=$limite->format('c');
$fecha_limite_fmt=$limite->format('d/m/Y H:i');

// Emojis sentimiento detallado
$emoji_sent=['enojado'=>'😠','frustrado'=>'😤','triste'=>'😢','preocupado'=>'😟',
              'tranquilo'=>'😌','satisfecho'=>'😊','neutro'=>'😐','urgente'=>'🚨'][$sentimiento]??'😐';
$emoji_prio=['baja'=>'🟢','media'=>'🟡','alta'=>'🟠','critica'=>'🔴'][$prioridad]??'🟡';
$emoji_canal=['audio'=>'🎤','canvas'=>'✏️','escrito'=>'📝'][$canal]??'📝';

$subject="[$ticket_id] $emoji_canal ".strtoupper($canal)." | ".strtoupper($tipo_pqr)." | $emoji_sent ".strtoupper($sentimiento)." | $emoji_prio ".strtoupper($prioridad);

// ── SUPABASE INSERT ──────────────────────────────────────────────────
$sb_r=sbPost($SB_URL,$SB_KEY,'correos',[
    'ticket_id'=>$ticket_id,'from_email'=>$correo?:($telefono.'@whatsapp'),
    'from_name'=>$nombre,'nombre'=>$nombre,'correo'=>$correo?:null,
    'telefono_contacto'=>$telefono,'subject'=>$subject,
    'descripcion'=>$descripcion,'body_preview'=>mb_substr($texto_pqr,0,200),
    'body_content'=>$texto_pqr,'body_type'=>'text',
    'transcripcion'=>$transcripcion?:null,'audio_url'=>$audio_url?:null,
    'canvas_url'=>$canvas_url?:null,'tipo_pqr'=>$tipo_pqr,
    'categoria_ia'=>$categoria_ia,'sentimiento'=>$sentimiento,
    'nivel_riesgo'=>$nivel_riesgo,'resumen_corto'=>$resumen_corto,
    'ley_aplicable'=>$ley_aplicable,'canal_contacto'=>$canal_pref,
    'origen'=>'formulario_web','estado'=>'pendiente','prioridad'=>$prioridad,
    'es_urgente'=>$riesgo_vital||$prioridad==='critica',
    'horas_sla'=>$horas_sla,'fecha_limite_sla'=>$fecha_limite_iso,
    'has_attachments'=>!empty($audio_url)||!empty($canvas_url),
    'is_read'=>false,'acuse_enviado'=>null,
    'datos_legales'=>['documento'=>$documento,'sede'=>$sede_nombre,
        'sede_ciudad'=>$sede_ciudad,'riesgo_vital'=>$riesgo_vital,
        'sla_desc'=>$sla_desc,'emocion_detalle'=>$emocion_detalle,
        'sentimiento_raw'=>$sentimiento],
    'received_at'=>$fecha_iso,'created_at'=>$fecha_iso,'updated_at'=>$fecha_iso,
]);

if($sb_r['code']>=400){http_response_code(502);echo json_encode(['error'=>'supabase_error','detalle'=>$sb_r['body']]);exit;}
$ins=json_decode($sb_r['body'],true);
$correo_id=$ins[0]['id']??null;

// ── GUARDAR ADJUNTOS EN STORAGE ──────────────────────────────────────
$audio_storage_url=null; $canvas_storage_url=null;
$attachments_mail=[];

if($correo_id){
    // Audio → bucket 'audios'
    if($audio_url){
        $audio_data=@file_get_contents($audio_url);
        if($audio_data){
            $audio_storage_url=guardarAdjunto($SB_URL,$SB_KEY,$correo_id,$ticket_id,
                'audios',"audio_$ticket_id.webm",'audio/webm',$audio_data);
            if($audio_storage_url&&strlen($audio_data)<5*1024*1024)
                $attachments_mail[]=['@odata.type'=>'#microsoft.graph.fileAttachment',
                    'name'=>"audio_$ticket_id.webm",'contentType'=>'audio/webm',
                    'contentBytes'=>base64_encode($audio_data)];
        }
    }
    // Canvas → bucket 'canvas-images'
    if($canvas_url){
        if(strpos($canvas_url,'data:image')===0){
            preg_match('/data:image\/(\w+);base64,(.+)/s',$canvas_url,$m);
            if($m){
                $img_data=base64_decode($m[2]);
                $canvas_storage_url=guardarAdjunto($SB_URL,$SB_KEY,$correo_id,$ticket_id,
                    'canvas-images',"canvas_$ticket_id.{$m[1]}","image/{$m[1]}",$img_data);
                if($img_data&&strlen($img_data)<5*1024*1024)
                    $attachments_mail[]=['@odata.type'=>'#microsoft.graph.fileAttachment',
                        'name'=>"canvas_$ticket_id.{$m[1]}",'contentType'=>"image/{$m[1]}",
                        'contentBytes'=>$m[2]];
            }
        } else {
            $img_data=@file_get_contents($canvas_url);
            if($img_data){
                $canvas_storage_url=guardarAdjunto($SB_URL,$SB_KEY,$correo_id,$ticket_id,
                    'canvas-images',"canvas_$ticket_id.png",'image/png',$img_data);
                if(strlen($img_data)<5*1024*1024)
                    $attachments_mail[]=['@odata.type'=>'#microsoft.graph.fileAttachment',
                        'name'=>"canvas_$ticket_id.png",'contentType'=>'image/png',
                        'contentBytes'=>base64_encode($img_data)];
            }
        }
    }
    // Actualizar URLs en BD si se guardaron en storage
    if($audio_storage_url||$canvas_storage_url){
        $upd=[];
        if($audio_storage_url) $upd['audio_url']=$audio_storage_url;
        if($canvas_storage_url) $upd['canvas_url']=$canvas_storage_url;
        $upd['updated_at']=$fecha_iso;
        sbPatch($SB_URL,$SB_KEY,'correos',"id=eq.$correo_id",$upd);
    }
}

// ── TOKEN GRAPH ──────────────────────────────────────────────────────
$token=getToken($TENANT_ID,$CLIENT_ID,$CLIENT_SECRET);

// ── CORREO INTERNO A pqrsfd ──────────────────────────────────────────
$interno_ok=false;
if($token){
    $riesgo_alert=$riesgo_vital?"<div style='background:#fff0f0;border-left:4px solid #dc2626;padding:12px 16px;margin-bottom:16px;font-weight:700;color:#991b1b;font-family:Arial,sans-serif'>🚨 RIESGO VITAL — Respuesta máximo 24 horas</div>":'';
    $canal_txt=['audio'=>'Mensaje de voz (Whisper IA)','canvas'=>'Lápiz inteligente (GPT-4o Vision)','escrito'=>'Texto escrito'][$canal]??'Formulario web';
    
    $html_int="<div style='font-family:Arial,sans-serif;max-width:700px;margin:0 auto;color:#222'>
  <div style='background:#f8f9fa;border-bottom:3px solid #1e40af;padding:20px 28px;text-align:center'>
    <img src='data:image/png;base64,$LOGO_B64' alt='Tododrogas' style='height:50px'>
    <p style='color:#6b7280;font-size:12px;margin:8px 0 0'>Plataforma Inteligente Nova TD · Nueva PQR Recibida</p>
  </div>
  <div style='padding:24px 28px;background:#fff;border:1px solid #e5e7eb'>
    $riesgo_alert
    <table style='width:100%;border-collapse:collapse;margin-bottom:20px;font-size:13px'>
      <tr style='background:#f1f5f9'><td style='padding:8px 12px;font-weight:700;border:1px solid #e2e8f0;width:150px'>Radicado</td><td style='padding:8px 12px;border:1px solid #e2e8f0;font-weight:800;color:#1e40af;font-size:15px'>$ticket_id</td></tr>
      <tr><td style='padding:8px 12px;font-weight:700;background:#f8fafc;border:1px solid #e2e8f0'>Fecha / Hora</td><td style='padding:8px 12px;border:1px solid #e2e8f0'>$fecha_fmt · Colombia</td></tr>
      <tr style='background:#f1f5f9'><td style='padding:8px 12px;font-weight:700;border:1px solid #e2e8f0'>Canal</td><td style='padding:8px 12px;border:1px solid #e2e8f0'>$emoji_canal $canal_txt</td></tr>
      <tr><td style='padding:8px 12px;font-weight:700;background:#f8fafc;border:1px solid #e2e8f0'>Tipo</td><td style='padding:8px 12px;border:1px solid #e2e8f0'><b>".strtoupper($tipo_pqr)."</b> — $categoria_ia</td></tr>
      <tr style='background:#f1f5f9'><td style='padding:8px 12px;font-weight:700;border:1px solid #e2e8f0'>Estado emocional</td><td style='padding:8px 12px;border:1px solid #e2e8f0'>$emoji_sent <b>".strtoupper($sentimiento)."</b> — <em>$emocion_detalle</em></td></tr>
      <tr><td style='padding:8px 12px;font-weight:700;background:#f8fafc;border:1px solid #e2e8f0'>Prioridad</td><td style='padding:8px 12px;border:1px solid #e2e8f0'>$emoji_prio ".strtoupper($prioridad)."</td></tr>
      <tr style='background:#f1f5f9'><td style='padding:8px 12px;font-weight:700;border:1px solid #e2e8f0'>SLA</td><td style='padding:8px 12px;border:1px solid #e2e8f0'>$sla_desc · Límite: <b>$fecha_limite_fmt</b></td></tr>
      <tr><td style='padding:8px 12px;font-weight:700;background:#f8fafc;border:1px solid #e2e8f0'>Ley</td><td style='padding:8px 12px;border:1px solid #e2e8f0'>$ley_aplicable</td></tr>
      ".($sede_nombre?"<tr style='background:#f1f5f9'><td style='padding:8px 12px;font-weight:700;border:1px solid #e2e8f0'>Sede</td><td style='padding:8px 12px;border:1px solid #e2e8f0'><b>$sede_nombre</b> — $sede_ciudad</td></tr>":'')."
    </table>
    <div style='background:#f0f4ff;border-left:4px solid #1e40af;padding:14px 18px;margin-bottom:16px;font-size:13px'>
      <b>👤 Paciente/Ciudadano</b><br>
      Nombre: <b>$nombre</b>".($documento?" · Documento: $documento":'')."
      ".($correo?"<br>Correo: $correo":'')."
      ".($telefono?"<br>Celular: $telefono":'')."
      <br>Canal preferido: $canal_pref
    </div>
    <div style='background:#faf5ff;border-left:4px solid #7c3aed;padding:14px 18px;margin-bottom:16px;font-size:13px'>
      <b>$emoji_canal Mensaje ($canal_txt)</b><br><br>
      ".nl2br(htmlspecialchars($texto_pqr))."
      ".($resumen_corto?"<br><br><span style='color:#9333ea;font-size:11px'>📌 Resumen IA: $resumen_corto</span>":'')."
    </div>
    ".(!empty($attachments_mail)?"<div style='background:#fefce8;border:1px solid #fde68a;padding:10px 14px;border-radius:4px;font-size:12px'>📎 Adjunto incluido: ".($audio_url?'🎤 Audio (webm)':'')." ".($canvas_url?'✏️ Imagen canvas':'')."</div>":'')."
    <p style='font-size:11px;color:#9ca3af;margin-top:20px;border-top:1px solid #e5e7eb;padding-top:12px'>
      Nova TD v5 · Tododrogas CIA SAS · $fecha_fmt · ID: $ticket_id
    </p>
  </div>
</div>";

    $interno_ok=sendMail($token,$GRAPH_USER_ID,[
        'subject'=>$subject,
        'importance'=>in_array($prioridad,['alta','critica'])?'high':'normal',
        'body'=>['contentType'=>'HTML','content'=>$html_int],
        'toRecipients'=>[['emailAddress'=>['address'=>$BUZON_PQRS]]],
        'attachments'=>$attachments_mail,
    ]);
}

// ── CORREO CIUDADANO — Diseño limpio con logo, Franklin Gothic ────────
$acuse_ok=false;
if($token&&$correo&&!$sin_correo){
    $html_ciudadano="<!DOCTYPE html>
<html><head><meta charset='UTF-8'>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;600;700&display=swap');
  body { margin:0; padding:0; background:#f5f5f5; font-family: 'Franklin Gothic Book', 'Open Sans', Arial, sans-serif; }
  .wrap { max-width:600px; margin:0 auto; background:#ffffff; }
  .header { padding:32px 36px 24px; text-align:center; border-bottom:1px solid #eeeeee; }
  .body { padding:32px 36px; color:#333333; }
  .radicado-box { background:#f9f9f9; border:1px solid #e8e8e8; border-radius:6px; padding:24px; text-align:center; margin:24px 0; }
  .radicado-num { font-size:30px; font-weight:700; color:#333333; letter-spacing:3px; font-family:'Courier New',monospace; }
  .tabla td { padding:10px 14px; border-bottom:1px solid #f0f0f0; font-size:14px; vertical-align:top; }
  .tabla td:first-child { font-weight:600; color:#555555; width:160px; }
  .pasos { background:#f9f9f9; border-radius:6px; padding:20px 24px; margin:20px 0; }
  .footer { padding:20px 36px; text-align:center; border-top:1px solid #eeeeee; color:#999999; font-size:12px; }
</style>
</head>
<body>
<div class='wrap'>
  <div class='header'>
    <img src='data:image/png;base64,$LOGO_B64' alt='Tododrogas' style='height:55px; margin-bottom:8px'>
  </div>
  <div class='body'>
    <p style='font-size:15px; margin:0 0 8px'>Estimado/a <strong>$nombre</strong>,</p>
    <p style='font-size:14px; line-height:1.8; color:#555555; margin:0 0 20px'>
      Hemos recibido su solicitud. Queremos que sepa que para nosotros su bienestar 
      es lo más importante y estamos comprometidos a darle una respuesta oportuna y de calidad.
    </p>
    <div class='radicado-box'>
      <p style='margin:0 0 6px; font-size:12px; color:#888888; text-transform:uppercase; letter-spacing:1px'>Su número de radicado</p>
      <div class='radicado-num'>$ticket_id</div>
      <p style='margin:8px 0 0; font-size:12px; color:#888888'>Guárdelo para hacer seguimiento</p>
    </div>
    <table class='tabla' style='width:100%; border-collapse:collapse; margin-bottom:24px'>
      <tr><td>Fecha de radicado</td><td>$fecha_fmt (hora Colombia)</td></tr>
      <tr><td>Tipo de solicitud</td><td>".strtoupper($tipo_pqr)."".($categoria_ia?" — $categoria_ia":'')."</td></tr>
      ".($sede_nombre?"<tr><td>Sede relacionada</td><td>$sede_nombre<br><span style='color:#888;font-size:12px'>$sede_ciudad</span></td></tr>":'')."
      <tr><td>Tiempo de respuesta</td><td>$sla_desc<br><span style='color:#888;font-size:12px'>Fecha límite: $fecha_limite_fmt</span></td></tr>
    </table>
    <div class='pasos'>
      <p style='margin:0 0 12px; font-weight:600; color:#333333; font-size:14px'>¿Qué sigue?</p>
      <p style='margin:0 0 8px; font-size:13px; color:#555555'>→ Su caso será revisado por uno de nuestros asesores especializados.</p>
      <p style='margin:0 0 8px; font-size:13px; color:#555555'>→ Recibirá respuesta a este correo en el plazo indicado.</p>
      <p style='margin:0; font-size:13px; color:#555555'>→ Si necesita información urgente, responda este correo con su número de radicado.</p>
    </div>
    <p style='font-size:14px; color:#555555; margin:20px 0 4px'>Con gusto le atendemos,</p>
    <p style='font-size:14px; font-weight:600; color:#333333; margin:0'>Equipo de Atención al Usuario</p>
    <p style='font-size:13px; color:#888888; margin:2px 0'>Tododrogas CIA SAS</p>
    <p style='font-size:13px; color:#888888; margin:2px 0'>📧 pqrsfd@tododrogas.com.co</p>
  </div>
  <div class='footer'>
    <p style='margin:0'>Este mensaje es generado automáticamente por la Plataforma Nova TD.</p>
    <p style='margin:4px 0 0'>© 2026 Tododrogas CIA SAS · Todos los derechos reservados.</p>
  </div>
</div>
</body></html>";

    $acuse_ok=sendMail($token,$GRAPH_USER_ID,[
        'subject'=>"Su solicitud fue recibida · Radicado $ticket_id · Tododrogas CIA SAS",
        'body'=>['contentType'=>'HTML','content'=>$html_ciudadano],
        'toRecipients'=>[['emailAddress'=>['address'=>$correo,'name'=>$nombre]]],
        'attachments'=>[],
    ]);
    if($acuse_ok&&$correo_id)
        sbPatch($SB_URL,$SB_KEY,'correos',"id=eq.$correo_id",
            ['acuse_enviado'=>$fecha_iso,'updated_at'=>$fecha_iso]);
}

// ── HISTORIAL ────────────────────────────────────────────────────────
if($correo_id)
    sbPost($SB_URL,$SB_KEY,'historial_eventos',[
        'correo_id'=>$correo_id,'evento'=>'pqr_recibida',
        'descripcion'=>"Radicado $ticket_id vía $canal. $emoji_sent $sentimiento / $emoji_prio $prioridad. Audio: ".($audio_storage_url?'✅':'—')." Canvas: ".($canvas_storage_url?'✅':'—')." Acuse: ".($acuse_ok?'✅':'—'),
        'from_email'=>$correo?:$telefono,'subject'=>$subject,
        'datos_extra'=>json_encode(['ticket_id'=>$ticket_id,'canal'=>$canal,
            'sentimiento'=>$sentimiento,'emocion'=>$emocion_detalle,
            'prioridad'=>$prioridad,'categoria_ia'=>$categoria_ia,
            'riesgo_vital'=>$riesgo_vital,'horas_sla'=>$horas_sla,
            'audio_storage'=>$audio_storage_url,'canvas_storage'=>$canvas_storage_url,
            'interno_ok'=>$interno_ok,'acuse_ok'=>$acuse_ok]),
        'created_at'=>$fecha_iso,
    ],'return=minimal');

// ── RESPUESTA ────────────────────────────────────────────────────────
echo json_encode(['ok'=>true,'radicado'=>$ticket_id,'ticket_id'=>$ticket_id,
    'canal'=>$canal,'sentimiento'=>$sentimiento,'emocion'=>$emocion_detalle,
    'prioridad'=>$prioridad,'categoria'=>$categoria_ia,
    'sla_desc'=>$sla_desc,'fecha_limite'=>$fecha_limite_fmt,
    'correo_enviado'=>$interno_ok,'acuse_enviado'=>$acuse_ok,
    'mensaje'=>"Su solicitud fue recibida. Radicado: $ticket_id · $sla_desc"]);
