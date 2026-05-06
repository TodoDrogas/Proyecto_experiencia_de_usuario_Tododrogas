<?php
/**
 * nova-wa.php — Nova TD completa para WhatsApp
 * Replica exactamente el comportamiento de nova.html
 * Fases: politica → ident_ced → libre
 * Usa: validar-paciente.php, nova-proxy.php, nova-consulta.php
 */
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$SB_URL     = '__SB_URL__';
$SB_KEY     = '__SB_KEY__';
$NOVA_TOKEN = '__NOVA_TOKEN__';
$BASE_URL   = 'https://tododrogas.online';

// ── Autenticación ─────────────────────────────────────────────────────
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

// ── Helpers HTTP ──────────────────────────────────────────────────────
function httpPost(string $url, array $data, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($resp, true) ?? [], 'raw' => $resp];
}

function sbGet(string $path): array {
    global $SB_URL, $SB_KEY;
    $ch = curl_init("$SB_URL/rest/v1/$path");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ["apikey: $SB_KEY","Authorization: Bearer $SB_KEY",'Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch); curl_close($ch);
    return json_decode($resp, true) ?? [];
}

function sbPatch(string $tabla, string $filtro, array $data): void {
    global $SB_URL, $SB_KEY;
    $ch = curl_init("$SB_URL/rest/v1/$tabla?$filtro");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ["apikey: $SB_KEY","Authorization: Bearer $SB_KEY",'Content-Type: application/json','Prefer: return=minimal'],
    ]);
    curl_exec($ch); curl_close($ch);
}

function sbInsert(string $tabla, array $data): array {
    global $SB_URL, $SB_KEY;
    $ch = curl_init("$SB_URL/rest/v1/$tabla");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ["apikey: $SB_KEY","Authorization: Bearer $SB_KEY",'Content-Type: application/json','Prefer: return=representation'],
    ]);
    $resp = curl_exec($ch); curl_close($ch);
    return json_decode($resp, true) ?? [];
}

// ── Normalizar texto ──────────────────────────────────────────────────
function ntdNorm(string $s): string {
    $s = mb_strtoupper(trim($s), 'UTF-8');
    $s = str_replace(['Á','À','Â','Ä','É','È','Ê','Ë','Í','Ì','Î','Ï','Ó','Ò','Ô','Ö','Ú','Ù','Û','Ü','Ñ'],
                     ['A','A','A','A','E','E','E','E','I','I','I','I','O','O','O','O','U','U','U','U','N'], $s);
    return $s;
}

function primerNombre(string $nombre): string {
    $partes = explode(' ', ucwords(strtolower(trim($nombre))));
    return $partes[0] ?? $nombre;
}

// ── Datos de sesión ───────────────────────────────────────────────────
$cedula        = trim($sesion['cedula']          ?? '');
$nombre        = trim($sesion['nombre']          ?? '');
$eps           = trim($sesion['eps']             ?? '');
$ciudad        = trim($sesion['ciudad']          ?? '');
$fase          = trim($sesion['fase']            ?? 'politica');
$intentos      = (int)($sesion['intentos_nova']  ?? 0);
$noAcepCnt     = (int)($sesion['no_acepto_count']?? 0);
$origenCanal   = trim($sesion['origen_canal']    ?? 'whatsapp_directo');
$history       = is_array($sesion['history'])    ? $sesion['history'] : [];
$vip           = (bool)($sesion['vip']           ?? false);

$pn      = primerNombre($nombre);
$msgUp   = ntdNorm($mensaje);
$telEnc  = urlencode($telefono);

// ── Historial para GPT ────────────────────────────────────────────────
$histGPT = [];
foreach ($history as $m) {
    $role = $m['role'] ?? '';
    if ($role === 'user')                          $histGPT[] = ['role'=>'user',      'content'=>$m['content']??''];
    elseif (in_array($role, ['nova','assistant'])) $histGPT[] = ['role'=>'assistant', 'content'=>$m['content']??''];
}
$histGPT = array_slice($histGPT, -20);

// ── Catálogo de sedes LOCAL (mismo que NTD_SEDES_LOCAL en nova.html) ──
$NTD_SEDES_LOCAL = [
  ["nombre"=>"DROGUERIA BIENESTAR CACERES - COOSALUD","municipio"=>"CACERES","direccion"=>"Calle 49 N 51-36 Barrio Centro","lat"=>7.5742,"lng"=>-75.3464,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL"]],
  ["nombre"=>"Servicio Farmaceutico Tododrogas Girardota","municipio"=>"GIRARDOTA","direccion"=>"Calle 9 #14-62 Local Comercial Primer Piso Edificio Los Angeles","lat"=>6.3789,"lng"=>-75.4458,"eps"=>["SALUD TOTAL","NUEVA EPS"]],
  ["nombre"=>"E.S.E. Hospital San Juan De Dios De Marinilla","municipio"=>"MARINILLA","direccion"=>"Carrera 36 # 28-85 Marinilla","lat"=>6.1775,"lng"=>-75.3336,"eps"=>["SALUD TOTAL","ANGIOSUR"]],
  ["nombre"=>"DROGUERIA SAN RAFAEL ANORI","municipio"=>"ANORI","direccion"=>"Carrera 30 # 30-27 Nucleo Zonal 01","lat"=>7.0714,"lng"=>-75.1403,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS YARUMAL","municipio"=>"YARUMAL","direccion"=>"Carrera 21 # 17-17 Local 102 Mall Comercial Cubox","lat"=>7.0,"lng"=>-75.4194,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL","CEM"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS CHIGORODO","municipio"=>"CHIGORODO","direccion"=>"Calle 95 # 97-04 Apto 202","lat"=>7.6728,"lng"=>-76.6836,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL","CEM"]],
  ["nombre"=>"SERVICIO FARMACEUTICO TODO DROGAS SOPETRAN","municipio"=>"SOPETRAN","direccion"=>"Carrera 10 número 11-65 Primer Piso","lat"=>6.5036,"lng"=>-75.7403,"eps"=>["SAVIA"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS CAUCASIA","municipio"=>"CAUCASIA","direccion"=>"Carrera 20 # 3-76 CC Cauca Centro A1","lat"=>7.9839,"lng"=>-75.1953,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL","NUEVA EPS","CEM"]],
  ["nombre"=>"DROGUERIA COOPRIACHON ANGOSTURA","municipio"=>"ANGOSTURA","direccion"=>"CARRERA 10 # 9-20","lat"=>6.8722,"lng"=>-75.3406,"eps"=>["COOSALUD","SAVIA"]],
  ["nombre"=>"Servicio Farmaceutico Tododrogas La Union","municipio"=>"LA UNION","direccion"=>"Calle 7 A # 4ª – 05 Lote 8 # 102","lat"=>5.9747,"lng"=>-75.3625,"eps"=>["NUEVA EPS"]],
  ["nombre"=>"EMPRESA SOCIAL DEL ESTADO HOSPITAL SAN MIGUEL","municipio"=>"OLAYA - LLANADAS","direccion"=>"Carrera 10 #10-34 Corregimiento Llanadas","lat"=>6.6317,"lng"=>-75.8583,"eps"=>["SAVIA"]],
  ["nombre"=>"EMPRESA SOCIAL DEL ESTADO SAN MARTIN DE PORRES","municipio"=>"ARMENIA","direccion"=>"CALLE 11 # 6-69","lat"=>5.7447,"lng"=>-75.6822,"eps"=>["COOSALUD","SAVIA"]],
  ["nombre"=>"HOSPITAL OCTAVIO ALVAREZ","municipio"=>"PUERTO NARE","direccion"=>"CARRERA 5 # 45-103","lat"=>6.2064,"lng"=>-74.5906,"eps"=>["COOSALUD"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS SANTA BARBARA","municipio"=>"SANTA BARBARA","direccion"=>"Carrera 50 numero 48 – 17","lat"=>5.875,"lng"=>-75.5714,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL","CEM","ANGIOSUR"]],
  ["nombre"=>"DROGUERIA FARMAVIDA","municipio"=>"AMALFI","direccion"=>"Calle 23 # 29-054 Local 101","lat"=>6.9122,"lng"=>-75.0758,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL"]],
  ["nombre"=>"DROGUERIA LA AMISTAD","municipio"=>"SABANALARGA","direccion"=>"Calle 17# 18-06","lat"=>6.8856,"lng"=>-75.7128,"eps"=>["COOSALUD","SAVIA"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS CAREPA","municipio"=>"CAREPA","direccion"=>"Calle 81 con carrera 74-12/14 Apto 101","lat"=>7.7586,"lng"=>-76.6542,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL","NUEVA EPS","CEM"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS TURBO","municipio"=>"TURBO","direccion"=>"Calle 101 con carrera 15 Barrio Baltazar","lat"=>8.0972,"lng"=>-76.7294,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL","CEM"]],
  ["nombre"=>"DROGUERIA LA NUESTRA","municipio"=>"CONCORDIA","direccion"=>"Carrera 19 # 19 30/32","lat"=>6.0469,"lng"=>-75.9058,"eps"=>["SALUD TOTAL"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS APARTADO","municipio"=>"APARTADO","direccion"=>"Carrera 102 # 92-16/18 esquina","lat"=>7.88,"lng"=>-76.6258,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL","CEM"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS YOLOMBO","municipio"=>"YOLOMBO","direccion"=>"Carrera 24 Calle 18-04 Local # 4 Edificio S Y S","lat"=>6.5975,"lng"=>-75.0153,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL","CEM","ANGIOSUR"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS CIUDAD BOLIVAR","municipio"=>"CIUDAD BOLIVAR","direccion"=>"Calle 49 # 50-57 local # 104","lat"=>5.8597,"lng"=>-76.0186,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL","CEM","ANGIOSUR"]],
  ["nombre"=>"Servicio Farmaceutico Tododrogas Sabaneta","municipio"=>"SABANETA","direccion"=>"Carrera 45 A Calle 79 SUR 146 Prados De Sabaneta","lat"=>6.1519,"lng"=>-75.6175,"eps"=>["NUEVA EPS"]],
  ["nombre"=>"DROGUERIA FRONTIFARMA","municipio"=>"FRONTINO","direccion"=>"Calle 28 # 30-50","lat"=>6.7747,"lng"=>-76.1344,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL"]],
  ["nombre"=>"EMPRESA SOCIAL DEL ESTADO HOSPITAL SAN ISIDRO","municipio"=>"GIRALDO","direccion"=>"CARRERA 10 # 11-05","lat"=>6.4747,"lng"=>-75.9164,"eps"=>["COOSALUD","SAVIA"]],
  ["nombre"=>"DROGURIA SANTA TERESITA DE SAN JERONIMO","municipio"=>"SAN JERONIMO","direccion"=>"Carrera 11# 21-73 Sector La Carreterita","lat"=>6.4933,"lng"=>-75.7136,"eps"=>["SAVIA"]],
  ["nombre"=>"DROGUERIA LA PILDORA","municipio"=>"NECOCLI","direccion"=>"Calle 51 # 48-38 Centro","lat"=>8.4281,"lng"=>-76.7833,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL"]],
  ["nombre"=>"E.S.E HOSPITAL EL SAGRADO CORAZON","municipio"=>"BRICEÑO","direccion"=>"CALLE 11 # 8-31","lat"=>7.2861,"lng"=>-75.5233,"eps"=>["COOSALUD","SAVIA"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS PUERTO BERRIO","municipio"=>"PUERTO BERRIO","direccion"=>"Barrio el Hoyo Calle 48 con Carrera 5 # 4-56","lat"=>6.4914,"lng"=>-74.4083,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL","CEM"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS TAMESIS","municipio"=>"TAMESIS","direccion"=>"Carrera 10 # 9-47 Primer Piso Local # 1 Edificio Embera","lat"=>5.67,"lng"=>-75.7133,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL","NUEVA EPS","CEM","ANGIOSUR"]],
  ["nombre"=>"E.S.E Hospital Guillermo Gaviria Correa","municipio"=>"CAICEDO","direccion"=>"Carrera 5 # 3-23","lat"=>6.3933,"lng"=>-76.0433,"eps"=>["SAVIA"]],
  ["nombre"=>"DROGUERIA SAN GABRIEL","municipio"=>"LIBORINA","direccion"=>"Carrera 9 #8-4 Parque Principal","lat"=>6.6897,"lng"=>-75.8606,"eps"=>["SAVIA"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS AMAGA","municipio"=>"AMAGA","direccion"=>"Calle 52 con la 51 # 50","lat"=>6.0386,"lng"=>-75.7022,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL","CEM","ANGIOSUR"]],
  ["nombre"=>"GALLEGO SMV S.A.S. (Drogueria Familiar S.G.)","municipio"=>"PUEBLORRICO","direccion"=>"Carrera 30 # 30-56 Local 101","lat"=>5.7944,"lng"=>-75.9483,"eps"=>["COOSALUD","SAVIA"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS NECHI","municipio"=>"NECHI","direccion"=>"Barrio San Nicolás manzana 24 lote 02 Calle 25 N° 31 A-16","lat"=>8.1017,"lng"=>-74.77,"eps"=>["COOSALUD","SAVIA"]],
  ["nombre"=>"FARMACIA D'ANDREÉ","municipio"=>"PEQUE","direccion"=>"Carrera Bolivar 11-19","lat"=>6.9233,"lng"=>-76.0,"eps"=>["COOSALUD","SAVIA"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS MEDELLIN (BIC PISO 6)","municipio"=>"MEDELLIN","direccion"=>"Carrera 48 # 49-57","lat"=>6.2518,"lng"=>-75.5636,"eps"=>["SALUD TOTAL","CEM","PREVENTIVA"]],
  ["nombre"=>"DROGUERIA DONDE NATALIA","municipio"=>"DABEIBA","direccion"=>"Carrera 10 # 06-70","lat"=>7.005,"lng"=>-76.2644,"eps"=>["COOSALUD","SAVIA"]],
  ["nombre"=>"DROGUERIA SAN ANTONIO","municipio"=>"JERICO","direccion"=>"CARRERA 5 #6-09","lat"=>5.7897,"lng"=>-75.7792,"eps"=>["COOSALUD"]],
  ["nombre"=>"DROGUERIA SAN MARTIN 712","municipio"=>"ANZA","direccion"=>"Carrera 8 # 7-51","lat"=>6.3136,"lng"=>-75.9444,"eps"=>["SAVIA"]],
  ["nombre"=>"DROGUERIA FAMIDROGAS","municipio"=>"BETULIA","direccion"=>"Calle 21 # 21-42","lat"=>6.1097,"lng"=>-75.9792,"eps"=>["SAVIA"]],
  ["nombre"=>"DROGUERIA KIRIUS LA BOMBA TARAZA - COOSALUD","municipio"=>"TARAZA","direccion"=>"Carrera 28 #27-26","lat"=>7.5833,"lng"=>-75.4,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS SEGOVIA","municipio"=>"SEGOVIA","direccion"=>"Calle El Bolsillo O El Palo Primer piso","lat"=>7.0819,"lng"=>-74.7039,"eps"=>["COOSALUD","SAVIA"]],
  ["nombre"=>"DROGUERIA MACEFARMA","municipio"=>"VALDIVIA","direccion"=>"Calle Libertador # 9-92","lat"=>7.1656,"lng"=>-75.4411,"eps"=>["COOSALUD","SAVIA"]],
  ["nombre"=>"DROGUERIA BIOMEDIC","municipio"=>"HISPANIA","direccion"=>"CALLE 50 TOLEDO # 50-12","lat"=>5.8294,"lng"=>-75.9169,"eps"=>["COOSALUD","SAVIA"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS ANDES","municipio"=>"ANDES","direccion"=>"Carrera 50 # 49-68 Local 102 Edificio El Tesoro","lat"=>5.6558,"lng"=>-75.8786,"eps"=>["SAVIA","SALUD TOTAL","CEM","ANGIOSUR"]],
  ["nombre"=>"DROGUERIA EL REGALO DE DIOS","municipio"=>"YALI","direccion"=>"Calle 20 # 19-71 Barrio La Plaza","lat"=>6.8914,"lng"=>-74.8672,"eps"=>["COOSALUD","SAVIA"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS SANTA FE ANTIOQUIA","municipio"=>"SANTA FE DE ANTIOQUIA","direccion"=>"Calle 10 # 7-49 Primer piso","lat"=>6.5558,"lng"=>-75.8258,"eps"=>["COOSALUD","SAVIA","NUEVA EPS","CEM"]],
  ["nombre"=>"DROGUERIA MACRODESCUENTOS # 9","municipio"=>"JARDIN","direccion"=>"Calle 9 # 2-59","lat"=>5.5975,"lng"=>-75.8181,"eps"=>["SAVIA"]],
  ["nombre"=>"FARMAX LA DROGUERIA S.A.S","municipio"=>"REMEDIOS","direccion"=>"CALLE 11 # 8-12 CALLE SAN ANTONIO","lat"=>7.0275,"lng"=>-74.6906,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS ZARAGOZA","municipio"=>"ZARAGOZA","direccion"=>"Calle 35 # 36-17 Barrio San Gregorio","lat"=>7.495,"lng"=>-74.8656,"eps"=>["COOSALUD","SAVIA","CEM"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS CEJA","municipio"=>"LA CEJA","direccion"=>"Carrera 24 # 19-40 sector Fátima","lat"=>6.0225,"lng"=>-75.4303,"eps"=>["NUEVA EPS"]],
  ["nombre"=>"EMPRESA SOCIAL DEL ESTADO SAN JOSE SAMANÁ","municipio"=>"SAMANA","direccion"=>"Carrera 9 # 4-79 Calle De La Vida","lat"=>5.9008,"lng"=>-74.9956,"eps"=>["COOSALUD"]],
  ["nombre"=>"Servicio Farmaceutico Tododrogas Barbosa","municipio"=>"BARBOSA","direccion"=>"Calle 17 N° 9-44 local 103","lat"=>6.1886,"lng"=>-75.3316,"eps"=>["NUEVA EPS"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS EL BAGRE","municipio"=>"EL BAGRE","direccion"=>"Calle 50 número 47 A 31 Barrio Bijao","lat"=>7.5917,"lng"=>-74.8097,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL","CEM","ANGIOSUR"]],
  ["nombre"=>"DROGUERIA H. RAMIREZ","municipio"=>"URAMITA","direccion"=>"CARRERA 20 #20-67","lat"=>6.8669,"lng"=>-76.1747,"eps"=>["COOSALUD"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS RIONEGRO","municipio"=>"RIONEGRO","direccion"=>"Calle 52 #45-70 LC 2006 CC Rionegro Plaza","lat"=>6.155,"lng"=>-75.3736,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL","NUEVA EPS","CEM","ANGIOSUR"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS ABEJORRAL","municipio"=>"ABEJORRAL","direccion"=>"Carrera 50 Nro. 48-71","lat"=>5.7917,"lng"=>-75.4328,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL","CEM"]],
  ["nombre"=>"SERVICIO FARMACÉUTICO TODODROGAS MEDELLIN (PISO 1)","municipio"=>"MEDELLIN","direccion"=>"Carrera 48 # 49-57","lat"=>6.2518,"lng"=>-75.5636,"eps"=>["COOSALUD","SAVIA","ANGIOSUR","PREVENTIVA"]],
  ["nombre"=>"DROGUERIA ALMA SALUD 1","municipio"=>"COPACABANA","direccion"=>"Calle 40 Carrera # 84-18","lat"=>6.3503,"lng"=>-75.5103,"eps"=>["SALUD TOTAL"]],
  ["nombre"=>"DROGUERIA FAMILIAR EDDY","municipio"=>"MUTATA","direccion"=>"Carrera 10 # 10-41-43","lat"=>7.245,"lng"=>-76.435,"eps"=>["COOSALUD","SAVIA","SALUD TOTAL"]],
];

// ── URL política de privacidad ─────────────────────────────────────────
$POL_URL = 'https://lyosqaqhiwhgvjigvqtc.supabase.co/storage/v1/object/public/POLITICAS%20TRATAMIENTO%20DE%20DATOS/PO_GL_07_Politica_Uso_Chat_V03.pdf';
$FORM_URL = 'https://tododrogas.online/pqr_form.html';
$ENC_URL  = 'https://tododrogas.online/pqr_encuesta.html';
$MEDS_URL = 'https://dispensacion.tododrogas.com.co:8443/AppSolicitudesWebJavaSQLServer/com.appsolicitudesweb.appsolicitudweb';

// ── Actualizar sesión en Supabase ─────────────────────────────────────
function actualizarSesion(string $telefono, array $data): void {
    sbPatch('wa_sesiones', "telefono=eq.".urlencode($telefono), array_merge($data, ['updated_at'=>date('c')]));
}

// ── Escalar al agente ─────────────────────────────────────────────────
function escalar(string $telefono, string $resumen = ''): void {
    actualizarSesion($telefono, ['estado'=>'escalado','resumen_nova'=>$resumen,'fase'=>'escalado']);
}

// ── Registrar sesión en nova_sesiones (métricas) ──────────────────────
function registrarNovaSesion(string $telefono, string $cedula, string $nombre, string $eps, string $ciudad, bool $vip): void {
    global $SB_URL, $SB_KEY;
    // Verificar si ya existe sesión activa para este teléfono
    $ch = curl_init("$SB_URL/rest/v1/nova_sesiones?sesion_id=eq.".urlencode("WA-$telefono")."&select=id&limit=1");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_HTTPHEADER=>["apikey: $SB_KEY","Authorization: Bearer $SB_KEY"]]);
    $r = json_decode(curl_exec($ch),true)??[]; curl_close($ch);
    if (!empty($r)) return; // Ya existe
    sbInsert('nova_sesiones',[
        'sesion_id'  => "WA-$telefono",
        'cedula'     => $cedula,
        'nombre'     => $nombre,
        'eps'        => $eps,
        'municipio'  => $ciudad,
        'es_vip'     => $vip,
        'origen'     => 'whatsapp',
        'hora_inicio'=> date('c'),
        'updated_at' => date('c'),
    ]);
}

// ── Cerrar sesión en nova_sesiones ────────────────────────────────────
function cerrarNovaSesion(string $telefono, string $motivo, int $consultas, string $calificacion = ''): void {
    global $SB_URL, $SB_KEY;
    $dur = 0; // Se calcula desde hora_inicio si existe
    $ch = curl_init("$SB_URL/rest/v1/nova_sesiones?sesion_id=eq.".urlencode("WA-$telefono")."&select=hora_inicio&limit=1");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_HTTPHEADER=>["apikey: $SB_KEY","Authorization: Bearer $SB_KEY"]]);
    $r = json_decode(curl_exec($ch),true)??[]; curl_close($ch);
    if (!empty($r[0]['hora_inicio'])) {
        $dur = max(0, (int)((time() - strtotime($r[0]['hora_inicio'])) ));
    }
    sbPatch('nova_sesiones',"sesion_id=eq.".urlencode("WA-$telefono"),[
        'motivo_cierre'   => $motivo,
        'calificacion'    => $calificacion,
        'duracion_seg'    => $dur,
        'consultas_count' => $consultas,
        'updated_at'      => date('c'),
    ]);
}

// ── Generar resumen GPT ───────────────────────────────────────────────
function generarResumen(array $hist, string $nombre, string $eps, string $openaiKey = ''): string {
    $conv = implode("\n", array_map(fn($m) =>
        ($m['role']==='user'?'Usuario':'Nova').': '.($m['content']??''),
        array_slice($hist,-8)
    ));
    $r = httpPost('https://tododrogas.online/nova-proxy.php',
        ['system'=>'Resumen de 2 líneas para el agente. Solo el resumen, sin preámbulos.',
         'messages'=>[['role'=>'user','content'=>"Usuario: $nombre | EPS: $eps\n\n$conv\n\nResumen para agente:"]],'max_tokens'=>120],
        ['X-Nova-Token: '.(defined('NOVA_TOKEN')?NOVA_TOKEN:'')]
    );
    return trim($r['body']['choices'][0]['message']['content'] ?? 'Sin resumen.');
}

// ── Llamar a nova-proxy.php (igual que nova.html) ─────────────────────
function llamarProxy(string $system, array $messages, string $novaToken): string {
    $r = httpPost('https://tododrogas.online/nova-proxy.php',
        ['system'=>$system,'messages'=>$messages,'max_tokens'=>1500],
        ["X-Nova-Token: $novaToken"]
    );
    if ($r['code']!==200) return '';
    return trim($r['body']['choices'][0]['message']['content'] ?? '');
}

// ── Construir sistema de prompt (idéntico a ntdSys() en nova.html) ────
function construirSistema(string $nombre, string $eps, string $ciudad, string $vip, array $sedes, array $reglas, string $msgUpper): string {
    $hoy = (new DateTime())->format('l, d \d\e F \d\e Y');
    $s  = "Eres Nova TD, asistente virtual de Tododrogas.\n";
    $s .= "FECHA DE HOY: $hoy\n";
    $s .= "USUARIO: $nombre | EPS: $eps (SAVIA SALUD = SAVIA, PREVENTIVA SALUD = PREVENTIVA)\n";
    $s .= "VIP: $vip\n";
    $s .= "TRATO: Siempre de USTED.\n";

    // Cobertura dinámica desde catálogo
    $mapa = [];
    foreach ($sedes as $sede) {
        $mun = strtoupper(trim($sede['municipio'] ?? ''));
        if (!$mun) continue;
        $epsArr = is_array($sede['eps']) ? $sede['eps'] : [$sede['eps']];
        foreach ($epsArr as $e) {
            $en = strtoupper(trim(str_replace(['SAVIA SALUD','PREVENTIVA SALUD'],['SAVIA','PREVENTIVA'], $e)));
            if (!$en || $en==='TODAS') continue;
            $mapa[$en][] = $mun;
        }
    }
    foreach ($mapa as $k => $v) $mapa[$k] = implode(', ', array_unique($v));

    $s .= "COBERTURA:\n";
    foreach ($mapa as $epsKey => $municipios) $s .= "  $epsKey: $municipios\n";
    if ($eps && isset($mapa[$eps])) $s .= "EPS $eps cubre: {$mapa[$eps]}. Municipio fuera=sin cobertura.\n";

    $s .= "HORARIOS SEDES: Propias: Lun-Vie 7:00am-5:30pm | Sáb 8:00am-12:00m. In House: Lun-Vie 7:00am-3:30pm | Sáb 8:00am-11:00am. TODAS abren sábados.\n";

    // Catálogo completo de sedes
    $s .= "CATÁLOGO DE SEDES:\n";
    foreach ($sedes as $sede) {
        $epsArr = is_array($sede['eps']) ? implode(', ', $sede['eps']) : ($sede['eps']??'');
        $s .= "- {$sede['nombre']} | Municipio: {$sede['municipio']} | EPS: $epsArr\n";
    }

    $s .= "REGLA SEDES ESPECIALES MEDELLÍN: Hay DOS sedes en Medellín misma dirección. Consulta catálogo para saber qué EPS atiende cada una.\n";
    $s .= "REGLA MEDICAMENTOS Y MEDICINA: Para cualquier pregunta sobre medicamentos, dosis, enfermedades — responde con conocimiento médico-farmacéutico. Si menciona medicamento malo/vencido/deteriorado → di SIEMPRE primero: 'No lo consuma. Por su seguridad NO utilice ese medicamento.' → PBX 604 322 2432 / WA 304 341 2431 → [SEDES:municipio].\n";
    $s .= "PBX 604 322 2432|WA 304 341 2431|pqrsfd@tododrogas.com.co\n";
    $s .= "Canal WhatsApp — usa *negritas* con asteriscos. Sin # encabezados. Máx 120 palabras. 1 emoji. NUNCA el menú completo, usa [MENU].\n";
    $s .= "REGLA MEDICAMENTOS DEFECTUOSOS: medicamento vencido/malo/deteriorado → 'No lo consuma.' siempre primero.\n";
    $s .= "REGLA SEDES — OBLIGATORIA:\n";
    $s .= "  PASO 1: Si usuario menciona EPS explícita → usar ESA EPS. Si no → usar EPS del usuario.\n";
    $s .= "  PASO 2: EPS mencionada ≠ EPS usuario → [SEDES:municipio:EPSMENCIONADA]. Igual → [SEDES:municipio].\n";
    $s .= "  PASO 3: NUNCA omitir el tag.\n";
    $s .= "  CASO ESPECIAL: '¿qué EPS atienden en X?' → [SEDES:X:TODAS]\n";
    $s .= "  EJEMPLOS: 'NUEVA EPS en NECHI' → [SEDES:NECHI:NUEVA EPS]. 'puedo reclamar en RIONEGRO' (COOSALUD) → [SEDES:RIONEGRO]\n";
    $s .= "REGLA CONSULTAR: Si usuario escribe solo número de radicado (TD-xxxxx) o correo → [CONSULTAR:valor].\n";
    $s .= "REGLA MEDICAMENTOS VS REQUISITOS: QUÉ LLEVAR/DOCUMENTOS/REQUISITOS → [REQUISITOS]. ESTADO/DEMORA/CUÁNDO LLEGA → [MEDICAMENTOS].\n";
    $s .= "REGLA RADICAR: Quiere radicar PQRSFD → [FORMULARIO].\n";
    $s .= "REGLA ESCALAR: Pide asesor/agente/humano → [ESCALAR].\n";

    // Reglas dinámicas
    if (!empty($reglas)) {
        $reglasMatch = array_filter($reglas, function($r) use ($msgUpper) {
            $trigs = is_array($r['triggers']) ? $r['triggers'] : [];
            foreach ($trigs as $t) {
                if ($t && mb_strpos($msgUpper, strtoupper($t), 0, 'UTF-8') !== false) return true;
            }
            return false;
        });
        if (!empty($reglasMatch)) {
            $s .= "INSTRUCCIONES ESPECÍFICAS (PRIORIDAD MÁXIMA):\n";
            foreach ($reglasMatch as $r) $s .= "- {$r['instruccion']}\n";
        }
    }

    $s .= "TAGS:[MENU][FORMULARIO][ESCALAR][ENCUESTA][MEDICAMENTOS][REQUISITOS][CAMBIAR_EPS][CONSULTAR:v][SEDES:m]";
    return $s;
}


// ── Menú mini post-respuesta ──────────────────────────────────────────
// USA LETRAS para evitar colisión con el menú numérico principal
function menuMini(int $intentos, int $limite): string {
    $base = "

*¿En qué más le puedo ayudar?*
🏠 Escriba *M* → Menú principal
💬 Escriba *P* → Tengo otra pregunta";
    if ($intentos >= $limite) {
        $base .= "
📞 Escriba *A* → Hablar con un asesor";
    }
    return $base;
}

// ── Límite de intentos según contexto ────────────────────────────────
function limiteIntentos(array $histGPT): int {
    $txt = mb_strtoupper(implode(' ', array_column($histGPT, 'content')), 'UTF-8');
    if (preg_match('/MEDICAMENTO|ENTREGA|DISPENSACI[OÓ]N/u', $txt)) return 1;
    if (preg_match('/RADICADO|PQRS|QUEJA|RECLAMO/u', $txt)) return 3;
    if (preg_match('/SEDE|HORARIO|DIRECCI[OÓ]N/u', $txt)) return 3;
    return 2;
}

// ── Buscar sedes en catálogo local ────────────────────────────────────
function ntdNormMun(string $s): string {
    $s = mb_strtoupper(trim($s), 'UTF-8');
    return strtr($s, ['Á'=>'A','À'=>'A','É'=>'E','È'=>'E','Í'=>'I','Ì'=>'I','Ó'=>'O','Ò'=>'O','Ú'=>'U','Ù'=>'U','Ñ'=>'N']);
}

// Cargar sedes desde Supabase (igual que ntdCargarSedes en nova.html)
// Fallback al catálogo local si falla
function cargarSedesActualizadas(array $sedesLocal): array {
    global $SB_URL, $SB_KEY;
    try {
        $ch = curl_init("$SB_URL/rest/v1/sedes?activa=eq.true&select=nombre,municipio_norm,direccion,telefono,lat,lng,eps,horario&limit=200");
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>false,
            CURLOPT_HTTPHEADER=>["apikey: $SB_KEY","Authorization: Bearer $SB_KEY"]]);
        $resp = curl_exec($ch); curl_close($ch);
        $data = json_decode($resp, true) ?? [];
        if (empty($data)) return $sedesLocal;
        // Normalizar campo municipio desde municipio_norm
        foreach ($data as &$s) {
            if (!isset($s['municipio'])) $s['municipio'] = $s['municipio_norm'] ?? '';
            $s['municipio'] = strtoupper(trim($s['municipio']));
        }
        return $data;
    } catch(\Exception $e) {
        return $sedesLocal;
    }
}

function buscarSedes(string $municipio, string $epsFilter, array $sedes): string {
    $munN = ntdNormMun($municipio);
    $epsN = strtoupper(trim(str_replace(['SAVIA SALUD','PREVENTIVA SALUD'],['SAVIA','PREVENTIVA'], $epsFilter)));
    $todas = strtoupper($epsFilter) === 'TODAS';

    $encontradas = array_filter($sedes, function($s) use ($munN, $epsN, $todas) {
        $sM = ntdNormMun($s['municipio']??'');
        // Coincidencia exacta o contenida — evita falsos positivos
        if ($sM !== $munN && !str_contains($sM, $munN) && !str_contains($munN, $sM)) return false;
        // Si municipio tiene menos de 4 chars, exigir coincidencia exacta
        if (strlen($munN) < 4 && $sM !== $munN) return false;
        if ($todas) return true;
        $epsArr = is_array($s['eps']) ? $s['eps'] : [$s['eps']];
        foreach ($epsArr as $e) {
            $en = strtoupper(str_replace(['SAVIA SALUD','PREVENTIVA SALUD'],['SAVIA','PREVENTIVA'], $e));
            if (str_contains($en, $epsN) || str_contains($epsN, $en)) return true;
        }
        return false;
    });

    if (empty($encontradas)) {
        return "📍 No encontré sedes en *$municipio*".($todas?'':' para su EPS *'.$epsFilter.'*').". Verifique el municipio o llame al *604 322 2432*.";
    }

    $munDisplay = ucwords(strtolower($municipio));
    $txt = "📍 *Sedes en $munDisplay";
    if (!$todas && $epsN) $txt .= " para *$epsN*";
    $txt .= ":*\n\n";
    $i = 1;
    foreach (array_slice(array_values($encontradas), 0, 4) as $s) {
        $txt .= "$i. *{$s['nombre']}*\n";
        if (!empty($s['direccion'])) $txt .= "   📌 {$s['direccion']}\n";
        // Si es consulta de EPS específica, NO mostrar todas las EPS (solo la dirección importa)
        // Si es TODAS, sí mostrar las EPS disponibles
        if ($todas) {
            $epsArr = is_array($s['eps']) ? implode(', ', $s['eps']) : ($s['eps']??'');
            $txt .= "   🏥 EPS: $epsArr\n";
        }
        $txt .= "\n";
        $i++;
    }
    // Nota especial para Medellín (misma dirección, diferente EPS)
    $munUP = strtoupper($municipio);
    if (str_contains($munUP, 'MEDELLIN') || str_contains($munUP, 'MEDELLÍN')) {
        $txt .= "_Nota: En Medellín hay dos sedes en la misma dirección que atienden diferentes EPS._\n";
    }
    return trim($txt);
}

// ── Consultar radicado via nova-consulta.php ──────────────────────────
function consultarRadicado(string $valor, string $novaToken): string {
    $esRadicado = preg_match('/^TD-[\d\-]+$/i', trim($valor));
    $esCorreo   = filter_var(trim($valor), FILTER_VALIDATE_EMAIL);
    $esCedula   = preg_match('/^\d{6,12}$/', preg_replace('/\D/','',$valor));

    $payload = ['accion' => 'radicado'];
    if ($esRadicado) $payload['ticket_id'] = trim($valor);
    elseif ($esCorreo) $payload['correo'] = trim($valor);
    elseif ($esCedula) { $payload['accion'] = 'radicados_cedula'; $payload['cedula'] = preg_replace('/\D/','',$valor); }

    $r = httpPost('https://tododrogas.online/nova-consulta.php', $payload, ["X-Nova-Token: $novaToken"]);
    if ($r['code']!==200 || empty($r['body'])) return "No pude consultar el radicado. Llame al *604 322 2432*.";

    $d = $r['body'];
    if (!($d['encontrado']??false)) return "No encontré radicados para *$valor*. Verifique el dato o llame al *604 322 2432*.";

    $estados = ['pendiente'=>'Pendiente','gestion'=>'En gestión','gestionado'=>'Gestionado','solucionado'=>'Solucionado','pendiente_firma'=>'Pendiente de firma'];
    $ticket = $d['ticket_id'] ?? $d['radicados'][0]['ticket_id'] ?? '—';
    $estado = $estados[$d['estado']??''] ?? ($d['estado']??'En proceso');
    $txt = "📋 *Radicado $ticket*\nEstado: *$estado*\n";
    if (!empty($d['tipo'])) $txt .= "Tipo: {$d['tipo']}\n";
    if (!empty($d['fecha'])) $txt .= "Fecha: ".date('d/m/Y', strtotime($d['fecha']));
    return $txt;
}

// ── Cargar sedes actualizadas desde Supabase (fallback local) ─────────
$NTD_SEDES_LOCAL = cargarSedesActualizadas($NTD_SEDES_LOCAL);

// ── Cargar reglas dinámicas ───────────────────────────────────────────
$reglas = sbGet('nova_reglas?activo=eq.true&select=triggers,instruccion,prioridad&order=prioridad.desc&limit=50');

// ════════════════════════════════════════════════════════════════════════
// FASE POLÍTICA DE PRIVACIDAD
// ════════════════════════════════════════════════════════════════════════
if ($fase === 'politica' || (!$cedula && $origenCanal==='whatsapp_directo' && $fase!=='ident_ced' && $fase!=='libre')) {

    $acepta = preg_match('/^(1|SI|SÍ|ACEPTO|ACEPTAR|ACEPTA|DE ACUERDO|OK|OKAY|CLARO|CORRECTO)$/', $msgUp);
    $noAcepta = preg_match('/^(2|NO|NO ACEPTO|RECHAZO|RECHAZAR)$/', $msgUp);

    // Primer mensaje — mostrar política
    $esMensajeInicial = count($history) <= 1;
    if ($esMensajeInicial && !$acepta && !$noAcepta) {
        $resp = "¡Hola! Bienvenido/a a *Tododrogas*. Soy *Nova TD*, su asistente virtual.\n\n"
              . "Antes de continuar, le informamos que sus datos personales serán tratados conforme a nuestra *Política de Tratamiento de Datos*:\n"
              . "📄 $POL_URL\n\n"
              . "Responda:\n*1* → ✅ Acepto\n*2* → ❌ No acepto";
        actualizarSesion($telefono, ['fase'=>'politica','no_acepto_count'=>0]);
        echo json_encode(['respuesta'=>$resp,'accion'=>'PEDIR_POLITICA','fase'=>'politica','intentos'=>0]);
        exit;
    }

    if ($acepta) {
        actualizarSesion($telefono, ['fase'=>'ident_ced','no_acepto_count'=>0]);
        $resp = "✅ ¡Gracias por aceptar!\n\nPara brindarle una atención personalizada, por favor indíqueme su *número de documento de identidad* (sin puntos ni espacios).";
        echo json_encode(['respuesta'=>$resp,'accion'=>'PEDIR_CEDULA','fase'=>'ident_ced','intentos'=>0]);
        exit;
    }

    if ($noAcepta) {
        $nuevoCnt = $noAcepCnt + 1;
        if ($nuevoCnt >= 2) {
            actualizarSesion($telefono, ['fase'=>'cerrado','estado'=>'cerrado']);
            echo json_encode(['respuesta'=>"Entendido. Sin aceptar las políticas no es posible continuar. ¡Hasta pronto! 👋",'accion'=>'CERRAR','fase'=>'cerrado','intentos'=>0]);
            exit;
        }
        actualizarSesion($telefono, ['no_acepto_count'=>$nuevoCnt]);
        $resp = "Entendido. Sin embargo, para continuar es necesario aceptar nuestra política de privacidad.\n\n"
              . "📄 $POL_URL\n\n"
              . "Responda:\n*1* → ✅ Acepto\n*2* → ❌ No acepto";
        echo json_encode(['respuesta'=>$resp,'accion'=>'PEDIR_POLITICA','fase'=>'politica','intentos'=>0,'no_acepto_count'=>$nuevoCnt]);
        exit;
    }

    // Si escribe otra cosa en fase política — recordar
    actualizarSesion($telefono, ['fase'=>'politica']);
    $resp = "Por favor responda *1* para aceptar o *2* para rechazar la política de privacidad.\n📄 $POL_URL";
    echo json_encode(['respuesta'=>$resp,'accion'=>'PEDIR_POLITICA','fase'=>'politica','intentos'=>0]);
    exit;
}

// ════════════════════════════════════════════════════════════════════════
// FASE IDENTIFICACIÓN — pedir y validar cédula
// ════════════════════════════════════════════════════════════════════════
if ($fase === 'ident_ced' || ($origenCanal==='whatsapp_directo' && !$cedula)) {

    $posibleCedula = preg_replace('/\D/', '', $mensaje);
    $esCedula = strlen($posibleCedula) >= 5 && strlen($posibleCedula) <= 12;

    if (!$esCedula) {
        actualizarSesion($telefono, ['fase'=>'ident_ced']);
        echo json_encode(['respuesta'=>"Número de documento inválido. Por favor escríbalo sin puntos ni comas:",'accion'=>'PEDIR_CEDULA','fase'=>'ident_ced','intentos'=>$intentos]);
        exit;
    }

    $r = httpPost("$BASE_URL/validar-paciente.php",['cedula'=>$posibleCedula,'telefono'=>preg_replace('/\D/','',$telefono)]);
    if ($r['code']===200 && ($r['body']['ok']??false)) {
        $p = $r['body'];
        $nombre  = $p['nombre'] ?? '';
        $eps     = str_replace(['SAVIA SALUD','PREVENTIVA SALUD'],['SAVIA','PREVENTIVA'], $p['eps']??'');
        $eps     = trim(preg_replace('/\s*\([^)]*\)\s*/','', $eps));
        $ciudad  = $p['ciudad'] ?? '';
        $vip     = (bool)($p['vip']??false);
        $pn      = primerNombre($nombre);

        actualizarSesion($telefono, [
            'cedula'=>$posibleCedula,'nombre'=>$nombre,'eps'=>$eps,
            'ciudad'=>$ciudad,'fase'=>'libre','intentos_nova'=>0,
        ]);
        // Registrar en nova_sesiones para métricas
        registrarNovaSesion($telefono, $posibleCedula, $nombre, $eps, $ciudad, $vip);

        if ($vip && !empty($p['saludo'])) {
            $saludo = $p['saludo']."\n\n¿En qué le puedo ayudar hoy, *$pn*?\n\n"
                    . "1️⃣ Estado o entrega de medicamentos\n"
                    . "2️⃣ Puntos de dispensación\n"
                    . "3️⃣ Requisitos para reclamar\n"
                    . "4️⃣ Radicar PQRSFD\n"
                    . "5️⃣ Estado de mi radicado\n"
                    . "6️⃣ Horarios y canales\n"
                    . "7️⃣ Encuesta de satisfacción\n"
                    . "8️⃣ 💬 Pregunta a Nova TD";
        } else {
            $saludo = "✅ *¡Bienvenido/a, $pn!*\n\n"
                    . "Es un gusto atenderle. Soy *Nova TD*.\n\n"
                    . "Identificamos que usted está afiliado/a a *$eps*.\n\n"
                    . "⏱ Si hay más de 5 minutos de inactividad, la conversación se cerrará automáticamente.\n\n"
                    . "¿En qué le puedo ayudar?\n\n"
                    . "1️⃣ Estado o entrega de medicamentos\n"
                    . "2️⃣ Puntos de dispensación\n"
                    . "3️⃣ Requisitos para reclamar\n"
                    . "4️⃣ Radicar PQRSFD\n"
                    . "5️⃣ Estado de mi radicado\n"
                    . "6️⃣ Horarios y canales\n"
                    . "7️⃣ Encuesta de satisfacción\n"
                    . "8️⃣ 💬 Pregunta a Nova TD";
        }
        echo json_encode(['respuesta'=>$saludo,'accion'=>'BIENVENIDA','cedula'=>$posibleCedula,'nombre'=>$nombre,'eps'=>$eps,'ciudad'=>$ciudad,'fase'=>'libre','intentos'=>0]);
        exit;
    }

    // Cédula no encontrada
    $nuevoIntentos = $intentos + 1;
    actualizarSesion($telefono, ['intentos_nova'=>$nuevoIntentos,'fase'=>'ident_ced']);
    $resp = "No encontramos su registro con ese número de documento. Verifíquelo e intente nuevamente, o comuníquese al *604 322 2432* o WhatsApp *304 341 2431*.";
    if ($nuevoIntentos > 1) $resp .= "\n\nIntento de nuevo. Por favor ingrese su *número de documento*:";
    echo json_encode(['respuesta'=>$resp,'accion'=>'PEDIR_CEDULA','fase'=>'ident_ced','intentos'=>$nuevoIntentos]);
    exit;
}

// ════════════════════════════════════════════════════════════════════════
// FASE LIBRE — usuario ya identificado
// ════════════════════════════════════════════════════════════════════════

// ── Detección urgencia/asesor/enojo inmediata ────────────────────────
$urgencia   = preg_match('/VENCIDO|DETERIORAD|REACCI[OÓ]N|GRAVE|EMERGENCIA|INTOXICACI[OÓ]N|DA[ÑN]ADO|MAL ESTADO/u', $msgUp);
$pideAsesor = preg_match('/ASESOR|AGENTE HUMANO|HABLAR CON|QUIERO UN ASESOR|ME COMUNICA|OPERADOR/u', $msgUp);
$enojado    = preg_match('/RABIA|ENOJADO|FURIOSO|INDIGNADO|P[EÉ]SIMO|TERRIBLE|ASCO|MAL SERVICIO|INCOMPETENTE|NO SIRVE|HORRIBLE|DISGUSTAD|MOLESTOS?|INDIGNAD|QUÉ MALA|QUE MALA|MUY MAL|MALÍSIMO|INACEPTABLE|INCUMPLIMIENTO|INCUMPLE/u', $msgUp);

// Urgencia médica → escalar inmediato
if ($urgencia) {
    $histGPT[] = ['role'=>'user','content'=>$mensaje];
    $resumen = generarResumen($histGPT, $nombre, $eps);
    escalar($telefono, $resumen);
    cerrarNovaSesion($telefono, 'urgencia_medica', $intentos+1);
    echo json_encode(['respuesta'=>"⚠️ Entiendo que es urgente, *$pn*. Le conecto de inmediato con un asesor especializado.",'accion'=>'ESCALADO','resumen'=>$resumen,'fase'=>'escalado','intentos'=>$intentos]);
    exit;
}

// Solicitud directa de asesor
if ($pideAsesor) {
    $histGPT[] = ['role'=>'user','content'=>$mensaje];
    $resumen = generarResumen($histGPT, $nombre, $eps);
    escalar($telefono, $resumen);
    cerrarNovaSesion($telefono, 'solicitud_asesor', $intentos+1);
    echo json_encode(['respuesta'=>"Por supuesto, *$pn*. Le conecto con un asesor. En un momento le atienden. 🙂",'accion'=>'ESCALADO','resumen'=>$resumen,'fase'=>'escalado','intentos'=>$intentos]);
    exit;
}

// Usuario enojado — 3 niveles según intentos
if ($enojado) {
    $histGPT[] = ['role'=>'user','content'=>$mensaje];
    if ($intentos >= 2) {
        // Nivel 3 — escalar automáticamente
        $resumen = generarResumen($histGPT, $nombre, $eps);
        escalar($telefono, $resumen.'  [URGENTE: usuario molesto]');
        echo json_encode(['respuesta'=>"*$pn*, lamentamos profundamente su experiencia. Le conecto de inmediato con un asesor especializado que podrá atenderle de forma personalizada. 🙏",'accion'=>'ESCALADO','resumen'=>$resumen,'fase'=>'escalado','intentos'=>$intentos]);
        exit;
    } elseif ($intentos >= 1) {
        // Nivel 2 — ofrecer con énfasis
        actualizarSesion($telefono, ['intentos_nova'=>$intentos+1]);
        echo json_encode(['respuesta'=>"*$pn*, su caso es muy importante para nosotros y lamentamos no haber podido resolverlo a su satisfacción. Un asesor puede atenderle de forma más personalizada. ¿Le conecto ahora?

*1* → Sí, conécteme con un asesor
*2* → No, continúe con Nova TD",'accion'=>'OFRECER_ASESOR','fase'=>'libre','intentos'=>$intentos+1]);
        exit;
    } else {
        // Nivel 1 — reconocer y ofrecer
        actualizarSesion($telefono, ['intentos_nova'=>$intentos+1]);
        echo json_encode(['respuesta'=>"Entiendo su situación, *$pn*, y lamentamos los inconvenientes. Permítame ayudarle de la mejor manera. ¿Desea que le conecte con uno de nuestros asesores especializados?

*1* → Sí, hablar con un asesor
*2* → No, continuar con Nova TD",'accion'=>'OFRECER_ASESOR','fase'=>'libre','intentos'=>$intentos+1]);
        exit;
    }
}

// Respuesta a ofrecimiento de asesor (sí/no)
$respondeSiAsesor = preg_match('/^(SI|SÍ|SI POR FAVOR|SÍ POR FAVOR|QUIERO|CONECTAR|CONECTAME|ASESOR|OK|DALE)$/', $msgUp);
$respondeNoAsesor = preg_match('/^(2|NO|NO GRACIAS|CONTINUAR|NOVA|SIGUE)$/', $msgUp);
$fasePrevOferAsesor = ($fase === 'libre' && isset($sesion['ofrece_asesor']) && $sesion['ofrece_asesor']);
// Detectar si el mensaje anterior fue OFRECER_ASESOR revisando el último mensaje en history
$ultimaAccion = '';
foreach (array_reverse($history) as $h) {
    if (($h['role']??'') === 'nova') { break; }
}
// Si responde sí a un ofrecimiento anterior
if ($respondeSiAsesor && $intentos >= 1) {
    $histGPT[] = ['role'=>'user','content'=>$mensaje];
    $resumen = generarResumen($histGPT, $nombre, $eps);
    escalar($telefono, $resumen);
    echo json_encode(['respuesta'=>"Perfecto, *$pn*. Le conecto con un asesor. En breve le atienden. 🙂",'accion'=>'ESCALADO','resumen'=>$resumen,'fase'=>'escalado','intentos'=>$intentos]);
    exit;
}

// ── Respuestas al menú mini (M=menú, P=pregunta, A=asesor) ──────────
$msgTrim = strtoupper(trim($mensaje));
if (in_array($msgTrim, ['M','MENU','MENÚ','MENÚ PRINCIPAL','MENU PRINCIPAL'])) {
    $menuCompleto = "¿En qué le puedo ayudar, *$pn*?\n\n"
        . "1️⃣ Estado o entrega de medicamentos\n"
        . "2️⃣ Puntos de dispensación\n"
        . "3️⃣ Requisitos para reclamar\n"
        . "4️⃣ Radicar PQRSFD\n"
        . "5️⃣ Estado de mi radicado\n"
        . "6️⃣ Horarios y canales\n"
        . "7️⃣ Encuesta de satisfacción\n"
        . "8️⃣ 💬 Pregunta a Nova TD";
    actualizarSesion($telefono, ['fase'=>'libre']);
    echo json_encode(['respuesta'=>$menuCompleto,'accion'=>'MENU','fase'=>'libre','intentos'=>$intentos]);
    exit;
}
if (in_array($msgTrim, ['P','PREGUNTA','OTRA PREGUNTA','TENGO OTRA PREGUNTA'])) {
    $limite = limiteIntentos($histGPT);
    $resp = "Con gusto, *$pn*. ¿Cuál es su pregunta?".menuMini($intentos, $limite);
    actualizarSesion($telefono, ['fase'=>'libre']);
    echo json_encode(['respuesta'=>$resp,'accion'=>'CONTINUAR','fase'=>'libre','intentos'=>$intentos]);
    exit;
}
if (in_array($msgTrim, ['A','ASESOR'])) {
    $histGPT[] = ['role'=>'user','content'=>$mensaje];
    $resumen = generarResumen($histGPT, $nombre, $eps);
    escalar($telefono, $resumen);
    cerrarNovaSesion($telefono, 'solicitud_asesor', $intentos+1);
    echo json_encode(['respuesta'=>"Por supuesto, *$pn*. Le conecto con un asesor. En un momento le atienden. 🙂",'accion'=>'ESCALADO','resumen'=>$resumen,'fase'=>'escalado','intentos'=>$intentos]);
    exit;
}

// ── Menú numérico — opciones hardcodeadas igual que nova.html ─────────
$numOpc = trim(preg_replace('/\D/','',$mensaje));
if (strlen($mensaje) <= 2 && $numOpc && in_array($numOpc,['1','2','3','4','5','6','7','8','9'])) {

    $pn2 = $pn ?: 'estimado/a usuario/a';

    switch($numOpc) {
        case '1': // Medicamentos
            $resp = "*$pn2*, para consultar el estado de sus medicamentos, entregas pendientes o historial de dispensación, ingrese a nuestra plataforma *App Solicitudes Web*:\n\n"
                  . "🔗 $MEDS_URL\n\n"
                  . "¿Desea que un asesor verifique el estado en el sistema?\n*1* → Sí\n*2* → No, continuar con Nova TD";
            actualizarSesion($telefono, ['fase'=>'ofrece_asesor_meds']);
            echo json_encode(['respuesta'=>$resp,'accion'=>'CONTINUAR','fase'=>'ofrece_asesor_meds','intentos'=>$intentos]);
            exit;

        case '2': // Sedes — SIEMPRE preguntar municipio
            $resp = "Con gusto le indico los puntos de dispensación disponibles, *$pn2*.\n\n¿En qué *municipio* desea consultar?\n_(puede ser diferente a su ciudad de registro)_";
            actualizarSesion($telefono, ['fase'=>'municipio_sedes']);
            echo json_encode(['respuesta'=>$resp,'accion'=>'PEDIR_MUNICIPIO','fase'=>'municipio_sedes','intentos'=>$intentos]);
            exit;

        case '3': // Requisitos
            $resp = "Con mucho gusto, *$pn2*. Para retirar sus medicamentos en nuestros puntos de dispensación necesita:\n\n"
                  . "📋 Fórmula médica vigente\n"
                  . "📁 Historia clínica\n"
                  . "🪪 Documento de identidad *original*\n\n"
                  . "*¿Va a recoger en nombre de alguien?*\n"
                  . "📋 Fórmula médica + Historia clínica\n"
                  . "🪪 Su documento original + documento original del usuario\n"
                  . "📝 Carta de autorización firmada por el usuario\n\n"
                  . "Puede consultar el estado de sus medicamentos en:\n🔗 $MEDS_URL";
            break;

        case '4': // Radicar PQRSFD
            $resp = "*$pn2*, puede radicar su solicitud PQRSFD directamente en nuestro formulario:\n\n"
                  . "📋 $FORM_URL\n\n"
                  . "También puede escribirnos a *pqrsfd@tododrogas.com.co* o llamar al *604 322 2432*.";
            break;

        case '5': // Estado radicado
            $resp = "Para consultar el estado de su solicitud PQRSFD, por favor *digita su número de cédula*:\n\n"
                  . "También puede escribir directamente un número de radicado (ej: TD-20260401-1234) o su correo electrónico.";
            actualizarSesion($telefono, ['fase'=>'consulta_cedula']);
            echo json_encode(['respuesta'=>$resp,'accion'=>'PEDIR_RADICADO','fase'=>'consulta_cedula','intentos'=>$intentos]);
            exit;

        case '6': // Horarios
            $resp = "📞 *Canales de contacto PQRSFD · Tododrogas:*\n\n"
                  . "📞 PBX: *604 322 2432* · Opción #2\n"
                  . "📧 pqrsfd@tododrogas.com.co\n"
                  . "🤖 Nova TD: 24/7\n\n"
                  . "🕐 *Horario asesores:*\n"
                  . "Lunes a viernes 7:00 a.m. – 5:30 p.m.\n"
                  . "Sábados 8:00 a.m. – 12:00 m.\n\n"
                  . "¿Desea comunicarse con un asesor ahora?\n*1* → Sí\n*2* → No";
            actualizarSesion($telefono, ['fase'=>'ofrece_asesor_horario']);
            echo json_encode(['respuesta'=>$resp,'accion'=>'CONTINUAR','fase'=>'ofrece_asesor_horario','intentos'=>$intentos]);
            exit;

        case '7': // Encuesta
            $encUrl = $ENC_URL.'?origen=nova_wa&nombre='.urlencode($nombre).'&cedula='.urlencode($cedula).'&eps='.urlencode($eps);
            $resp = "Su opinión es muy importante para nosotros. Puede realizar la *encuesta de satisfacción* aquí:\n\n"
                  . "⭐ $encUrl";
            break;

        case '8': // Pregunta libre
            $resp = "Con gusto, *$pn2*. ¿Cuál es su pregunta? Estoy aquí para ayudarle. 🙂";
            break;

        case '9': // Finalizar
            $encUrl = $ENC_URL.'?origen=nova_wa&nombre='.urlencode($nombre).'&cedula='.urlencode($cedula).'&eps='.urlencode($eps);
            actualizarSesion($telefono, ['fase'=>'cerrado','estado'=>'cerrado','encuesta_enviada'=>true]);
            cerrarNovaSesion($telefono, 'usuario', $intentos+1, '');
            $resp = "¡Hasta luego, *$pn2*! Ha sido un placer atenderle. Espero haber resuelto su consulta. 😊\n\n"
                  . "📊 *¿Cómo calificaría la atención de Nova TD hoy?*\n"
                  . "Su opinión nos ayuda a mejorar:\n⭐ $encUrl";
            echo json_encode(['respuesta'=>$resp,'accion'=>'CERRAR','fase'=>'cerrado','intentos'=>0]);
            exit;
    }

    $limiteSwitch = limiteIntentos($histGPT);
    $resp .= menuMini($intentos, $limiteSwitch);
    actualizarSesion($telefono, ['fase'=>'libre']);
    echo json_encode(['respuesta'=>$resp,'accion'=>'CONTINUAR','fase'=>'libre','intentos'=>$intentos+1]);
    exit;
}

// ── Fase municipio_sedes — usuario ingresó municipio ─────────────────
if ($fase === 'municipio_sedes') {
    $municipio = trim($mensaje);
    $sedesRes = buscarSedes($municipio, $eps, $NTD_SEDES_LOCAL);
    $limite = limiteIntentos($histGPT);
    $resp = $sedesRes.menuMini($intentos, $limite);
    actualizarSesion($telefono, ['fase'=>'libre']);
    echo json_encode(['respuesta'=>$resp,'accion'=>'CONTINUAR','fase'=>'libre','intentos'=>$intentos+1]);
    exit;
}

// ── Fases de ofrecimiento de asesor ──────────────────────────────────
$esOfreceAsesor = in_array($fase, ['ofrece_asesor_horario','ofrece_asesor_meds']);
if ($esOfreceAsesor) {
    $siAsesor = preg_match('/^(1|SI|SÍ|SI POR FAVOR|SÍ POR FAVOR|QUIERO|OK|DALE|CLARO|SÍ GRACIAS|SI GRACIAS)$/', $msgUp);
    if ($siAsesor) {
        $histGPT[] = ['role'=>'user','content'=>$mensaje];
        $resumen = generarResumen($histGPT, $nombre, $eps);
        escalar($telefono, $resumen);
        cerrarNovaSesion($telefono, 'solicitud_asesor', $intentos+1);
        echo json_encode(['respuesta'=>"Perfecto, *$pn*. Le conecto con un asesor. En breve le atienden. 🙂",'accion'=>'ESCALADO','resumen'=>$resumen,'fase'=>'escalado','intentos'=>$intentos]);
        exit;
    }
    // Respondió No — mostrar menú completo y esperar selección
    $menuCompleto = "Entendido, *$pn*. ¿En qué más le puedo ayudar?\n\n"
        . "1️⃣ Estado o entrega de medicamentos\n"
        . "2️⃣ Puntos de dispensación\n"
        . "3️⃣ Requisitos para reclamar\n"
        . "4️⃣ Radicar PQRSFD\n"
        . "5️⃣ Estado de mi radicado\n"
        . "6️⃣ Horarios y canales\n"
        . "7️⃣ Encuesta de satisfacción\n"
        . "8️⃣ 💬 Pregunta a Nova TD";
    actualizarSesion($telefono, ['fase'=>'libre']);
    echo json_encode(['respuesta'=>$menuCompleto,'accion'=>'MENU','fase'=>'libre','intentos'=>$intentos]);
    exit;
}

// ── Fase consulta_cedula — consultar radicado ─────────────────────────
if ($fase === 'consulta_cedula') {
    $resp = consultarRadicado($mensaje, $NOVA_TOKEN);
    actualizarSesion($telefono, ['fase'=>'libre']);
    echo json_encode(['respuesta'=>$resp,'accion'=>'CONTINUAR','fase'=>'libre','intentos'=>$intentos+1]);
    exit;
}

// ── Detección inline de radicado o correo ─────────────────────────────
if (preg_match('/^TD-[\d\-]+$/i', trim($mensaje)) || filter_var(trim($mensaje), FILTER_VALIDATE_EMAIL)) {
    $resp = consultarRadicado($mensaje, $NOVA_TOKEN);
    echo json_encode(['respuesta'=>$resp,'accion'=>'CONTINUAR','fase'=>'libre','intentos'=>$intentos+1]);
    exit;
}

// ── Pregunta libre — llamar a nova-proxy.php (igual que ntdFLibre) ────
$histGPT[] = ['role'=>'user','content'=>$mensaje];
$system = construirSistema($nombre, $eps, $ciudad, $vip?'SI':'No', $NTD_SEDES_LOCAL, $reglas, $msgUp);
$rawMsg = llamarProxy($system, $histGPT, $NOVA_TOKEN);

if (!$rawMsg) {
    echo json_encode(['respuesta'=>"No pude conectarme. Llame al *604 322 2432* o WhatsApp *304 341 2431*.",'accion'=>'ERROR','fase'=>'libre','intentos'=>$intentos]);
    exit;
}

// ── Parsear tags (igual que ntdParseAct) ─────────────────────────────
$accion  = 'CONTINUAR';
$faseSig = 'libre';
$resumen = '';

if (preg_match('/\[ESCALAR\]/', $rawMsg)) {
    $accion  = 'ESCALADO'; $faseSig = 'escalado';
    $histGPT[] = ['role'=>'assistant','content'=>$rawMsg];
    $resumen = generarResumen($histGPT, $nombre, $eps);
    escalar($telefono, $resumen);
    $rawMsg = preg_replace('/\[ESCALAR\]/', '', $rawMsg);

} elseif (preg_match('/\[FORMULARIO\]/', $rawMsg)) {
    $rawMsg = preg_replace('/\[FORMULARIO\]/', '', $rawMsg);
    $rawMsg = trim($rawMsg)."\n\n📋 Radique su PQRSFD en:\n🔗 $FORM_URL";

} elseif (preg_match('/\[ENCUESTA\]/', $rawMsg)) {
    $encUrl = $ENC_URL.'?origen=nova_wa&nombre='.urlencode($nombre).'&cedula='.urlencode($cedula).'&eps='.urlencode($eps);
    $rawMsg = preg_replace('/\[ENCUESTA\]/', '', $rawMsg);
    $rawMsg = trim($rawMsg)."\n\n⭐ $encUrl";

} elseif (preg_match('/\[MENU\]/', $rawMsg)) {
    $rawMsg = preg_replace('/\[MENU\]/', '', $rawMsg);
    $rawMsg = trim($rawMsg)."\n\n1️⃣ Estado o entrega de medicamentos\n2️⃣ Puntos de dispensación\n3️⃣ Requisitos para reclamar\n4️⃣ Radicar PQRSFD\n5️⃣ Estado de mi radicado\n6️⃣ Horarios y canales\n7️⃣ Encuesta de satisfacción\n8️⃣ 💬 Pregunta a Nova TD";

} elseif (preg_match('/\[SEDES:([^\]]+)\]/', $rawMsg, $m)) {
    $rawMsg = preg_replace('/\[SEDES:[^\]]+\]/', '', $rawMsg);
    $partes = explode(':', $m[1]);
    $municipio = trim($partes[0]);
    $epsFilter = isset($partes[1]) ? trim($partes[1]) : $eps;
    if ($municipio) {
        $sedesRes = buscarSedes($municipio, $epsFilter, $NTD_SEDES_LOCAL);
        $rawMsg = trim($rawMsg)."\n\n".$sedesRes;
    } else {
        $rawMsg = trim($rawMsg)."\n\n¿En qué municipio se encuentra para indicarle la sede más cercana?";
        $faseSig = 'municipio_sedes';
    }

} elseif (preg_match('/\[CONSULTAR:([^\]]+)\]/', $rawMsg, $m)) {
    $rawMsg = preg_replace('/\[CONSULTAR:[^\]]+\]/', '', $rawMsg);
    $consultaRes = consultarRadicado(trim($m[1]), $NOVA_TOKEN);
    $rawMsg = trim($rawMsg)."\n\n".$consultaRes;

} elseif (preg_match('/\[MEDICAMENTOS\]/', $rawMsg)) {
    $rawMsg = preg_replace('/\[MEDICAMENTOS\]/', '', $rawMsg);
    $rawMsg = trim($rawMsg)."\n\n🔗 $MEDS_URL\n📞 *604 322 2432* | 💬 *304 341 2431*";

} elseif (preg_match('/\[REQUISITOS\]/', $rawMsg)) {
    $rawMsg = preg_replace('/\[REQUISITOS\]/', '', $rawMsg);
    $rawMsg = trim($rawMsg)."\n\n📋 Fórmula médica vigente\n📁 Historia clínica\n🪪 Documento de identidad *original*";

} elseif (preg_match('/\[CAMBIAR_EPS\]/', $rawMsg)) {
    $rawMsg = preg_replace('/\[CAMBIAR_EPS\]/', '', $rawMsg);
    $rawMsg = trim($rawMsg)."\n\n¿Con cuál EPS desea consultar?\n1. COOSALUD\n2. SAVIA\n3. SALUD TOTAL\n4. NUEVA EPS\n5. PREVENTIVA\n6. CEM";
}

// Limpiar tags residuales
$rawMsg = preg_replace('/\[[A-Za-z_:0-9]+\]/', '', $rawMsg);
$rawMsg = trim(preg_replace('/\n{3,}/', "\n\n", $rawMsg));

// Agregar menú mini si no escaló
if ($accion !== 'ESCALADO' && $accion !== 'CERRAR') {
    $limiteFinal = limiteIntentos($histGPT);
    $rawMsg .= menuMini($intentos, $limiteFinal);
}

actualizarSesion($telefono, ['fase'=>$faseSig,'intentos_nova'=>$intentos+1]);
echo json_encode([
    'respuesta' => $rawMsg,
    'accion'    => $accion,
    'resumen'   => $resumen,
    'fase'      => $faseSig,
    'intentos'  => $intentos + 1,
]);
