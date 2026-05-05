<?php
/**
 * nova-wa.php — Nova TD completa para WhatsApp
 * Mismo sistema de prompt que nova.html, adaptado para canal de texto
 */
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$SB_URL     = '__SB_URL__';
$SB_KEY     = '__SB_KEY__';
$OPENAI_KEY = '__OPENAI_KEY__';
$NOVA_TOKEN = '__NOVA_TOKEN__';

$token = $_SERVER['HTTP_X_NOVA_TOKEN'] ?? '';
if ($NOVA_TOKEN !== '__NOVA_TOKEN__' && $token !== $NOVA_TOKEN) {
    http_response_code(401); echo json_encode(['error'=>'Token inválido']); exit;
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$telefono = trim($body['telefono'] ?? '');
$mensaje  = trim($body['mensaje']  ?? '');
$sesion   = $body['sesion']        ?? [];

if (!$telefono || !$mensaje) {
    http_response_code(400); echo json_encode(['error'=>'telefono y mensaje requeridos']); exit;
}

function httpPost(string $url, array $data, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json'],$headers)]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code'=>$code,'body'=>json_decode($resp,true)??[],'raw'=>$resp];
}

function sbGet(string $sb_url,string $sb_key,string $path): array {
    $ch = curl_init("$sb_url/rest/v1/$path");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_HTTPHEADER=>["apikey: $sb_key","Authorization: Bearer $sb_key",'Content-Type: application/json']]);
    $resp = curl_exec($ch); curl_close($ch);
    return json_decode($resp,true)??[];
}

function sbPatch(string $sb_url,string $sb_key,string $tabla,string $filtro,array $data): void {
    $ch = curl_init("$sb_url/rest/v1/$tabla?$filtro");
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>'PATCH',CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_HTTPHEADER=>["apikey: $sb_key","Authorization: Bearer $sb_key",'Content-Type: application/json','Prefer: return=minimal']]);
    curl_exec($ch); curl_close($ch);
}

$cedula       = trim($sesion['cedula']        ?? '');
$nombre       = trim($sesion['nombre']        ?? '');
$eps          = trim($sesion['eps']           ?? '');
$ciudad       = trim($sesion['ciudad']        ?? '');
$intentos     = (int)($sesion['intentos_nova'] ?? 0);
$origen_canal = trim($sesion['origen_canal']  ?? 'whatsapp_directo');
$history      = is_array($sesion['history']) ? $sesion['history'] : [];
$primerNombre = $nombre ? explode(' ',trim($nombre))[0] : '';
$msgUpper     = mb_strtoupper($mensaje,'UTF-8');

$histGPT = array_map(fn($m)=>['role'=>in_array($m['role']??'',['assistant','nova'])?'assistant':'user','content'=>$m['content']??''],
    array_filter($history,fn($m)=>in_array($m['role']??'',['user','nova','assistant'])));
$histGPT = array_slice(array_values($histGPT),-12);

// ── FASE 1: Validación ────────────────────────────────────────────────
if ($origen_canal === 'whatsapp_directo' && !$cedula) {
    $posibleCedula = preg_replace('/\D/','',$mensaje);
    $esCedula = strlen($posibleCedula)>=6 && strlen($posibleCedula)<=12;
    if ($esCedula) {
        $r = httpPost('https://tododrogas.online/validar-paciente.php',['cedula'=>$posibleCedula,'telefono'=>preg_replace('/\D/','',$telefono)]);
        if ($r['code']===200 && ($r['body']['ok']??false)) {
            $p=$r['body'];
            sbPatch($SB_URL,$SB_KEY,'wa_sesiones',"telefono=eq.".urlencode($telefono),['cedula'=>$posibleCedula,'nombre'=>$p['nombre']??'','eps'=>$p['eps']??'','ciudad'=>$p['ciudad']??'','updated_at'=>date('c')]);
            $cedula=$posibleCedula; $nombre=$p['nombre']??''; $eps=$p['eps']??''; $ciudad=$p['ciudad']??'';
            $primerNombre=explode(' ',trim($nombre))[0];
            $saludo=($p['vip']??false)
                ?($p['saludo']??"¡Bienvenida, *$primerNombre*! Nova TD te reconoce. ¿En qué te puedo ayudar hoy?")
                :"¡Hola, *$primerNombre*! Bienvenida a *Tododrogas*. Soy *Nova TD*. ¿En qué te puedo ayudar?\n\n1️⃣ Estado o entrega de medicamentos\n2️⃣ Puntos de dispensación\n3️⃣ Requisitos para reclamar\n4️⃣ Radicar PQRSFD\n5️⃣ Estado de mi radicado\n6️⃣ Horarios y canales\n7️⃣ Encuesta de satisfacción\n8️⃣ Pregunta libre a Nova TD";
            echo json_encode(['respuesta'=>$saludo,'accion'=>'CONTINUAR','cedula'=>$cedula,'nombre'=>$nombre,'eps'=>$eps,'ciudad'=>$ciudad,'intentos'=>0]); exit;
        } else {
            echo json_encode(['respuesta'=>"No encontré su registro con ese documento. Por favor verifíquelo e intente nuevamente, o llame al *604 322 2432*.",'accion'=>'PEDIR_CEDULA','intentos'=>$intentos+1]); exit;
        }
    } else {
        echo json_encode(['respuesta'=>count($history)<=1?"¡Hola! Bienvenido/a a *Tododrogas*. Soy *Nova TD*.\n\nPara brindarle atención personalizada, por favor indíqueme su *número de documento* (sin puntos ni espacios).":"Para continuar necesito verificar su identidad. Por favor indíqueme su *número de documento* (sin puntos ni espacios).",'accion'=>'PEDIR_CEDULA','intentos'=>$intentos]); exit;
    }
}

// ── FASE 2: Detección inmediata ───────────────────────────────────────
$urgencia   = preg_match('/VENCIDO|DETERIORAD|REACCI[OÓ]N|GRAVE|EMERGENCIA|INTOXICACI[OÓ]N|DA[ÑN]ADO|MAL ESTADO/u',$msgUpper);
$pideAsesor = preg_match('/ASESOR|AGENTE HUMANO|HUMANO|PERSONA REAL|HABLAR CON|QUIERO UN ASESOR|ME COMUNICA|OPERADOR|COMUNICA.*ASESOR/u',$msgUpper);

if ($urgencia||$pideAsesor) {
    $resumen=generarResumen($histGPT,$nombre,$eps,$mensaje,$OPENAI_KEY);
    escalarSesion($SB_URL,$SB_KEY,$telefono,$resumen);
    echo json_encode(['respuesta'=>$urgencia?"⚠️ Entiendo que es urgente, *$primerNombre*. Lo conecto de inmediato con un asesor.":"Por supuesto, *$primerNombre*. Le conecto con un asesor. En un momento le atienden. 🙂",'accion'=>'ESCALADO','resumen'=>$resumen,'intentos'=>$intentos]); exit;
}

// ── Reglas dinámicas Supabase ─────────────────────────────────────────
$reglas = sbGet($SB_URL,$SB_KEY,'nova_reglas?activo=eq.true&select=triggers,instruccion,prioridad&order=prioridad.desc&limit=50');
$reglasMatch=[];
foreach($reglas as $r){
    $trigs=is_array($r['triggers'])?$r['triggers']:[];
    foreach($trigs as $t){if($t&&mb_strpos($msgUpper,mb_strtoupper($t,'UTF-8'),0,'UTF-8')!==false){$reglasMatch[]=$r['instruccion'];break;}}
}

// ── Sistema de prompt completo ────────────────────────────────────────
$system = "Eres *Nova TD*, asistente virtual de *Tododrogas* por WhatsApp.\n";
$system.= "Usuario: $nombre | Cédula: $cedula | EPS: $eps | Ciudad: $ciudad\n";
$system.= "Canal WhatsApp — usa *negritas* con asteriscos. Sin # de encabezados. Máx 120 palabras. 1 emoji máximo.\n\n";
$system.= "REGLAS:\n- Dirígete por el primer nombre: $primerNombre\n";
$system.= "- PBX: 604 322 2432 | WA agentes: 304 341 2431 | Email: pqrsfd@tododrogas.com.co\n";
$system.= "- Horario: Lun-Vie 7:00am-5:30pm | Sáb 8:00am-12:00m\n\n";
$system.= "REGLA MEDICAMENTOS: Para preguntas sobre medicamentos/dosis/enfermedades responde con conocimiento médico-farmacéutico. Si mencionan medicamento vencido/malo/dañado → di SIEMPRE primero: 'No lo consuma. Por su seguridad NO utilice ese medicamento.' → contacto → [SEDES:municipio].\n\n";
$system.= "REGLA SEDES: Si preguntan por sedes → [SEDES:municipio] o [SEDES:municipio:EPS]. Sin municipio → preguntar primero.\n\n";
$system.= "REGLA SEDES — EPS MENCIONADA: Si menciona EPS diferente a la suya → [SEDES:municipio:EPSmencionada]. Si pregunta qué EPS atienden en X → [SEDES:X:TODAS].\n\n";
$system.= "REGLA RADICAR: Quiere radicar PQRSFD → [FORMULARIO].\n";
$system.= "REGLA CONSULTAR: Escribe número radicado (TD-xxxxx) → [CONSULTAR:valor].\n";
$system.= "REGLA ENCUESTA: Quiere calificar → [ENCUESTA].\n";
$system.= "REGLA ESCALAR: Pide asesor/agente/humano o no puedes resolver → [ESCALAR].\n";
$system.= "REGLA MENU: No entiende o pide opciones → [MENU].\n\n";
if(!empty($reglasMatch)){$system.="INSTRUCCIONES ESPECÍFICAS (PRIORIDAD MÁXIMA):\n";foreach($reglasMatch as $r)$system.="- $r\n";$system.="\n";}
$system.="TAGS: [MENU][FORMULARIO][ESCALAR][ENCUESTA][CONSULTAR:valor][SEDES:municipio][MEDICAMENTOS][REQUISITOS]";

$histGPT[]=['role'=>'user','content'=>$mensaje];
$payload=['model'=>'gpt-4o-mini','max_tokens'=>600,'temperature'=>0.3,'messages'=>array_merge([['role'=>'system','content'=>$system]],$histGPT)];

$ch=curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>["Authorization: Bearer $OPENAI_KEY",'Content-Type: application/json']]);
$resp=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);

if($code!==200){echo json_encode(['respuesta'=>"Tuve un problema técnico. Llame al *604 322 2432*.",'accion'=>'ERROR','intentos'=>$intentos]);exit;}

$data=json_decode($resp,true);
$rawMsg=trim($data['choices'][0]['message']['content']??'');
$accion='CONTINUAR'; $resumen='';

if(preg_match('/\[ESCALAR\]/',$rawMsg)){
    $accion='ESCALADO'; $resumen=generarResumen($histGPT,$nombre,$eps,$mensaje,$OPENAI_KEY);
    escalarSesion($SB_URL,$SB_KEY,$telefono,$resumen);
    $rawMsg=preg_replace('/\[ESCALAR\]/','i',$rawMsg);
} elseif(preg_match('/\[FORMULARIO\]/',$rawMsg)){
    $accion='FORMULARIO'; $rawMsg=preg_replace('/\[FORMULARIO\]/','i',$rawMsg);
    $rawMsg=trim($rawMsg)."\n\n📋 Radique su PQRSFD en:\n🔗 https://tododrogas.online/pqr_form.html";
} elseif(preg_match('/\[ENCUESTA\]/',$rawMsg)){
    $accion='ENCUESTA'; $rawMsg=preg_replace('/\[ENCUESTA\]/','i',$rawMsg);
    $rawMsg=trim($rawMsg)."\n\n⭐ Califique aquí:\n🔗 https://tododrogas.online/pqr_encuesta.html";
} elseif(preg_match('/\[MENU\]/',$rawMsg)){
    $accion='MENU'; $rawMsg=preg_replace('/\[MENU\]/','i',$rawMsg);
    $rawMsg=trim($rawMsg)."\n\n1️⃣ Estado o entrega de medicamentos\n2️⃣ Puntos de dispensación\n3️⃣ Requisitos para reclamar\n4️⃣ Radicar PQRSFD\n5️⃣ Estado de mi radicado\n6️⃣ Horarios y canales\n7️⃣ Encuesta de satisfacción\n8️⃣ Pregunta libre a Nova TD";
} elseif(preg_match('/\[SEDES:([^\]]+)\]/',$rawMsg,$m)){
    $rawMsg=preg_replace('/\[SEDES:[^\]]+\]/','i',$rawMsg);
    $partes=explode(':',$m[1]); $municipio=strtoupper(trim($partes[0])); $epsF=isset($partes[1])?strtoupper(trim($partes[1])):strtoupper($eps);
    $rawMsg=trim($rawMsg)."\n\n".obtenerSedes($SB_URL,$SB_KEY,$municipio,$epsF);
} elseif(preg_match('/\[CONSULTAR:([^\]]+)\]/',$rawMsg,$m)){
    $rawMsg=preg_replace('/\[CONSULTAR:[^\]]+\]/','i',$rawMsg);
    $rawMsg=trim($rawMsg)."\n\n".consultarRadicado($SB_URL,$SB_KEY,trim($m[1]));
} elseif(preg_match('/\[MEDICAMENTOS\]/',$rawMsg)){
    $rawMsg=preg_replace('/\[MEDICAMENTOS\]/','i',$rawMsg);
    $rawMsg=trim($rawMsg)."\n\nConsulte estado de su medicamento:\n📞 *604 322 2432*\n💬 *304 341 2431*";
}

$rawMsg=preg_replace('/\[[A-Za-z_:0-9]+\]/','i',$rawMsg);
$rawMsg=str_replace('i','',$rawMsg); // limpiar reemplazos vacíos
$rawMsg=trim(preg_replace('/\s{3,}/u',"\n\n",$rawMsg));

$limite=detectarLimite($histGPT);
if($intentos>=$limite && $accion==='CONTINUAR'){
    $accion='OFRECER_ASESOR';
    $rawMsg.="\n\n¿Prefiere que le conecte con un asesor? Responda *Sí* o *No*.";
}
if(in_array(trim($msgUpper),['SI','SÍ','SI POR FAVOR','SÍ POR FAVOR','QUIERO','OK','CLARO'])&&$intentos>=$limite-1){
    $accion='ESCALADO'; $resumen=generarResumen($histGPT,$nombre,$eps,$mensaje,$OPENAI_KEY);
    escalarSesion($SB_URL,$SB_KEY,$telefono,$resumen);
    $rawMsg="Perfecto, *$primerNombre*. Le conecto con un asesor. En breve le atienden. 🙂";
}

echo json_encode(['respuesta'=>trim($rawMsg),'accion'=>$accion,'resumen'=>$resumen,'intentos'=>$intentos+1]);

function generarResumen(array $hist,string $nombre,string $eps,string $msg,string $key):string{
    $conv=implode("\n",array_map(fn($m)=>($m['role']==='user'?'Usuario':'Nova').': '.($m['content']??''),array_slice($hist,-8)));
    $pl=['model'=>'gpt-4o-mini','max_tokens'=>120,'temperature'=>0,'messages'=>[['role'=>'system','content'=>'Resumen de 2 líneas para el agente. Solo el resumen.'],['role'=>'user','content'=>"Usuario: $nombre | EPS: $eps\n\n$conv\n\nResumen:"]]];
    $ch=curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($pl),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>["Authorization: Bearer $key",'Content-Type: application/json']]);
    $r=curl_exec($ch);curl_close($ch);
    $d=json_decode($r,true);return trim($d['choices'][0]['message']['content']??'Sin resumen.');
}

function escalarSesion(string $su,string $sk,string $tel,string $res):void{
    $ch=curl_init("$su/rest/v1/wa_sesiones?telefono=eq.".urlencode($tel));
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>'PATCH',CURLOPT_POSTFIELDS=>json_encode(['estado'=>'escalado','resumen_nova'=>$res,'updated_at'=>date('c')]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_HTTPHEADER=>["apikey: $sk","Authorization: Bearer $sk",'Content-Type: application/json','Prefer: return=minimal']]);
    curl_exec($ch);curl_close($ch);
}

function detectarLimite(array $hist):int{
    $t=mb_strtoupper(implode(' ',array_column($hist,'content')),'UTF-8');
    if(preg_match('/MEDICAMENTO|ENTREGA|DISPENSACI[OÓ]N/u',$t))return 1;
    if(preg_match('/RADICADO|PQRS|QUEJA|RECLAMO/u',$t))return 3;
    if(preg_match('/SEDE|HORARIO|DIRECCI[OÓ]N/u',$t))return 3;
    return 2;
}

function obtenerSedes(string $su,string $sk,string $mun,string $eps):string{
    $enc=urlencode(strtolower($mun));
    $sedes=sbGet($su,$sk,"sedes?municipio_norm=ilike.*$enc*&activa=is.true&select=nombre,direccion,telefono,eps,horario&limit=5");
    if(empty($sedes))return "📍 No encontré sedes en *$mun*. Llame al: *604 322 2432*";
    if($eps&&$eps!=='TODAS'){$sedes=array_filter($sedes,function($s)use($eps){$ea=is_array($s['eps'])?$s['eps']:[$s['eps']];foreach($ea as $e){if(mb_strpos(mb_strtoupper($e??'','UTF-8'),mb_strtoupper($eps,'UTF-8'))!==false)return true;}return false;});}
    if(empty($sedes))return "📍 No encontré sedes en *$mun* para su EPS. Llame al: *604 322 2432*";
    $txt="📍 *Sedes en $mun:*\n\n";
    foreach(array_slice(array_values($sedes),0,3) as $s){$txt.="• *{$s['nombre']}*\n";if($s['direccion'])$txt.="  📌 {$s['direccion']}\n";if($s['telefono'])$txt.="  📞 {$s['telefono']}\n";if($s['horario'])$txt.="  🕐 {$s['horario']}\n";$txt.="\n";}
    return trim($txt);
}

function consultarRadicado(string $su,string $sk,string $val):string{
    $enc=urlencode($val);
    $r=sbGet($su,$sk,"correos?ticket_id=eq.$enc&select=ticket_id,estado,created_at,fecha_resolucion,nombre_solicitud&limit=1");
    if(empty($r))return "No encontré el radicado *$val*. Verifique o llame al *604 322 2432*.";
    $c=$r[0];$ests=['pendiente'=>'Pendiente','gestion'=>'En gestión','gestionado'=>'Gestionado','solucionado'=>'Solucionado','pendiente_firma'=>'Pendiente de firma'];
    $est=$ests[$c['estado']??'']??($c['estado']??'En proceso');
    $txt="📋 *Radicado {$c['ticket_id']}*\nEstado: *$est*\n";
    if($c['nombre_solicitud'])$txt.="Tipo: {$c['nombre_solicitud']}\n";
    if($c['created_at'])$txt.="Radicado: ".date('d/m/Y',strtotime($c['created_at']));
    if($c['fecha_resolucion'])$txt.="\nResuelto: ".date('d/m/Y',strtotime($c['fecha_resolucion']));
    return $txt;
}
