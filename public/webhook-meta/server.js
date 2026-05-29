const express  = require('express');
const { createClient } = require('@supabase/supabase-js');
const ws       = require('ws');
const crypto   = require('crypto');
const FormData  = require('form-data');
const multer    = require('multer');
const upload    = multer({ storage: multer.memoryStorage(), limits: { fileSize: 16*1024*1024 } });
require('dotenv').config();

const app = express();

// ── CORS ──────────────────────────────────────────────────────────────────
const ALLOWED_ORIGINS = ['https://tododrogas.online', 'https://www.tododrogas.online'];
app.use((req, res, next) => {
  const origin = req.headers.origin || '';
  if (ALLOWED_ORIGINS.includes(origin)) {
    res.setHeader('Access-Control-Allow-Origin', origin);
    res.setHeader('Access-Control-Allow-Methods', 'POST, GET, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  }
  if (req.method === 'OPTIONS') return res.sendStatus(200);
  next();
});

// Raw body para validar firma Meta
app.use((req, res, next) => {
  let data = '';
  req.on('data', chunk => { data += chunk; });
  req.on('end', () => {
    req.rawBody = data;
    try { req.body = JSON.parse(data); } catch { req.body = {}; }
    next();
  });
});

// ── Supabase ──────────────────────────────────────────────────────────────
const supabase = createClient(
  process.env.SUPABASE_URL,
  process.env.SUPABASE_KEY,
  { realtime: { transport: ws } }
);

const META_TOKEN        = process.env.META_TOKEN;
const META_PHONE_ID     = process.env.META_PHONE_NUMBER_ID;
const META_VERIFY_TOKEN = process.env.META_WA_VERIFY_TOKEN;
const META_APP_SECRET   = process.env.META_APP_SECRET;
const NOVA_TOKEN        = process.env.NOVA_TOKEN || '';
const OPENAI_KEY        = process.env.OPENAI_API_KEY || '';
const PORT              = process.env.PORT || 3000;


// ── Nombre seguro para mensajes ──────────────────────────────────────────
function nombreSeguro(nombre) {
  if (!nombre || nombre.trim().length < 3) return null;
  // Descartar si es solo números o parece teléfono
  if (/^[\d\s+\-()]+$/.test(nombre.trim())) return null;
  return nombre.trim();
}
function tratamiento(nombre) {
  const n = nombreSeguro(nombre);
  return n ? `*${n}*` : 'señor/a usuario/a';
}

// ── RATE LIMITING ──────────────────────────────────────────────────────────
const ratemap = new Map();
function rateLimit(telefono) {
  const now   = Date.now();
  const entry = ratemap.get(telefono) || { count: 0, ts: now };
  if (now - entry.ts > 60000) { entry.count = 0; entry.ts = now; }
  entry.count++;
  ratemap.set(telefono, entry);
  return entry.count > 30;
}

// ── VALIDAR FIRMA META ─────────────────────────────────────────────────────
function validarFirmaMeta(req) {
  return true; // temporalmente deshabilitado
}

// ── ENVIAR MENSAJE VIA META ───────────────────────────────────────────────
async function enviarMeta(telefono, mensaje) {
  const res = await fetch(
    `https://graph.facebook.com/v19.0/${META_PHONE_ID}/messages`,
    {
      method: 'POST',
      headers: {
        'Content-Type':  'application/json',
        'Authorization': `Bearer ${META_TOKEN}`
      },
      body: JSON.stringify({
        messaging_product: 'whatsapp',
        to:   telefono.replace('+', ''),
        type: 'text',
        text: { body: mensaje }
      })
    }
  );
  if (!res.ok) throw new Error(await res.text());
  return res.json();
}

// ── LOG CONVERSACIÓN (métricas SIGI) ─────────────────────────────────────
async function logConv(telefono, agenteId, agenteNombre, evento, duracionSeg = null, metadata = {}) {
  try {
    await supabase.from('logs_conversacion').insert({
      telefono,
      agente_id:    agenteId   || null,
      agente_nombre: agenteNombre || null,
      evento,
      duracion_seg: duracionSeg,
      metadata,
      created_at:   new Date().toISOString()
    });
  } catch (e) {
    console.error('❌ logConv error:', e.message);
  }
}

// ── GENERAR SALUDO INTELIGENTE CON OPENAI ────────────────────────────────
async function generarSaludo(agenteNombre, resumenNova, nombreUsuario, eps) {
  if (!OPENAI_KEY) return null;
  try {
    const primeiroNombre = agenteNombre.split(' ')[0];
    const prompt = `Eres un asistente que genera saludos de atención al cliente para asesores de Tododrogas (empresa de dispensación de medicamentos).

Genera UN saludo cálido, empático y profesional que el asesor enviará por WhatsApp al usuario. 

Datos:
- Nombre del asesor: ${primeiroNombre}
- Nombre del usuario: ${nombreUsuario || 'el usuario'}
- EPS del usuario: ${eps || 'no disponible'}
- Resumen del caso (generado por Nova TD): ${resumenNova || 'El usuario solicita atención de un asesor'}

Reglas:
- Máximo 3 líneas
- Tono muy cálido, humano y empático
- Mencionar el nombre del usuario
- Mencionar que revisará su caso en el sistema (2-3 minutos)
- Transmitir que su caso es importante y prioritario
- Usar "usted" (no tutear)
- Si el resumen sugiere urgencia, queja o inconformidad: agregar una frase de disculpa sincera
- Si es medicamentos o salud: transmitir comprensión de la importancia
- Terminar con algo positivo
- NO usar asteriscos ni markdown, es WhatsApp
- Solo devuelve el texto del saludo, sin comillas ni explicaciones`;

    const res = await fetch('https://api.openai.com/v1/chat/completions', {
      method: 'POST',
      headers: {
        'Content-Type':  'application/json',
        'Authorization': `Bearer ${OPENAI_KEY}`
      },
      body: JSON.stringify({
        model:       'gpt-4o-mini',
        max_tokens:  200,
        temperature: 0.7,
        messages: [{ role: 'user', content: prompt }]
      })
    });
    if (!res.ok) return null;
    const data = await res.json();
    return data.choices?.[0]?.message?.content?.trim() || null;
  } catch (e) {
    console.error('❌ generarSaludo OpenAI:', e.message);
    return null;
  }
}

// ── AUTOASIGNACIÓN ────────────────────────────────────────────────────────
async function autoAsignarAgente(telefono, sesion) {
  try {
    const { data: agentes } = await supabase
      .from('agentes')
      .select('id, nombre, carga_actual, pausado, en_linea, activo')
      .eq('activo', true)
      .eq('en_linea', true)
      .eq('pausado', false)
      .order('carga_actual', { ascending: true })
      .limit(1);

    if (!agentes?.length) {
      console.log(`⚠️  No hay agentes disponibles para asignar ${telefono}`);
      return null;
    }

    const agente   = agentes[0];
    const ahoraISO = new Date().toISOString();

    // Generar saludo inteligente con OpenAI
    const saludo = await generarSaludo(
      agente.nombre,
      sesion?.resumen_nova || '',
      sesion?.nombre || '',
      sesion?.eps    || ''
    );

    // Asignar agente + guardar saludo + asignado_at
    await supabase.from('wa_sesiones').update({
      agente_id:       agente.id,
      agente_nombre:   agente.nombre,
      estado:          'escalado',
      asignado_at:     ahoraISO,
      saludo_sugerido: saludo || null,
      updated_at:      ahoraISO
    }).eq('telefono', telefono);

    // Incrementar carga
    await supabase.from('agentes').update({
      carga_actual:     (agente.carga_actual || 0) + 1,
      ultima_actividad: ahoraISO
    }).eq('id', agente.id);

    // Log métricas
    await logConv(telefono, agente.id, agente.nombre, 'asignado', null, {
      resumen_nova: sesion?.resumen_nova || '',
      nombre_usuario: sesion?.nombre || '',
      eps: sesion?.eps || ''
    });

    console.log(`✅ Autoasignado ${telefono} → ${agente.nombre}`);
    return agente;
  } catch (err) {
    console.error('❌ Error autoasignar:', err.message);
    return null;
  }
}

// ── SISTEMA DE AUDIO ────────────────────────────────────────────────────────

// 1. Descargar media de Meta Graph API
async function descargarMediaMeta(mediaId) {
  const infoRes = await fetch(
    `https://graph.facebook.com/v19.0/${mediaId}`,
    { headers: { 'Authorization': `Bearer ${META_TOKEN}` } }
  );
  if (!infoRes.ok) throw new Error(`Meta media info: ${infoRes.status}`);
  const info = await infoRes.json();
  if (!info.url) throw new Error('Meta no devolvió URL del media');

  const fileRes = await fetch(info.url, {
    headers: { 'Authorization': `Bearer ${META_TOKEN}` }
  });
  if (!fileRes.ok) throw new Error(`Meta download: ${fileRes.status}`);

  const buffer   = Buffer.from(await fileRes.arrayBuffer());
  const mimeType = info.mime_type || fileRes.headers.get('content-type') || 'audio/ogg';
  return { buffer, mimeType };
}

// 2. Subir a Supabase Storage bucket: wa-media
async function subirASupabase(buffer, fileName, mimeType) {
  const { error } = await supabase.storage
    .from('wa-media')
    .upload(fileName, buffer, { contentType: mimeType, upsert: true });
  if (error) throw new Error(`Storage: ${error.message}`);
  const { data: pub } = supabase.storage.from('wa-media').getPublicUrl(fileName);
  return pub.publicUrl;
}

// 3. Transcribir con Whisper
async function transcribirAudio(buffer, mimeType) {
  if (!OPENAI_KEY) return '[Audio recibido]';
  const extMap = { 'audio/ogg':'ogg','audio/mpeg':'mp3','audio/mp4':'m4a',
                   'audio/wav':'wav','audio/webm':'webm','audio/aac':'aac' };
  const ext = extMap[mimeType] || 'ogg';

  const form = new FormData();
  form.append('file', buffer, { filename: `audio.${ext}`, contentType: mimeType });
  form.append('model', 'whisper-1');
  form.append('language', 'es');
  form.append('response_format', 'text');

  const res = await fetch('https://api.openai.com/v1/audio/transcriptions', {
    method:  'POST',
    headers: { 'Authorization': `Bearer ${OPENAI_KEY}`, ...form.getHeaders() },
    body:    form
  });
  if (!res.ok) { console.error('Whisper error:', await res.text()); return '[Audio recibido]'; }
  return (await res.text()).trim() || '[Audio sin voz]';
}

// 4. Orquestador principal
async function procesarAudio(msg, telefono) {
  const mediaId = msg.audio?.id;
  if (!mediaId) return { content:'[Audio recibido]', tipo:'audio', audio_url:null };

  console.log(`🎙️ Audio de ${telefono} — id:${mediaId}`);
  try {
    const { buffer, mimeType } = await descargarMediaMeta(mediaId);
    const extMap = { 'audio/ogg':'ogg','audio/mpeg':'mp3','audio/mp4':'m4a',
                     'audio/wav':'wav','audio/webm':'webm','audio/aac':'aac' };
    const ext      = extMap[mimeType] || 'ogg';
    const fileName = `audios/${telefono.replace('+','')}/${Date.now()}.${ext}`;

    const [audio_url, transcripcion] = await Promise.all([
      subirASupabase(buffer, fileName, mimeType),
      transcribirAudio(buffer, mimeType)
    ]);

    console.log(`✅ Audio: "${transcripcion.substring(0,80)}"`);
    return {
      content:   transcripcion,
      tipo:      'audio',
      audio_url,
      duracion:  msg.audio?.duration || null,
      mime_type: mimeType
    };
  } catch (err) {
    console.error('❌ procesarAudio:', err.message);
    return { content:'[Audio recibido — error al procesar]', tipo:'audio', audio_url:null };
  }
}

// ── SISTEMA DE ADJUNTOS (imágenes, documentos, video) ──────────────────────

// Descargar y subir cualquier media de Meta a Supabase
async function procesarMedia(msg, telefono, tipo) {
  const mediaObj = msg[tipo] || {};
  const mediaId  = mediaObj.id;
  const caption  = mediaObj.caption || '';
  const mimeType = mediaObj.mime_type || '';
  const fileName_orig = mediaObj.filename || '';

  if (!mediaId) return { content: caption || `[${tipo}]`, tipo, media_url: null };

  console.log(`📎 Procesando ${tipo} de ${telefono} — id:${mediaId}`);
  try {
    const { buffer, mimeType: mime } = await descargarMediaMeta(mediaId);

    // Determinar extensión
    const extMap = {
      'image/jpeg':'jpg','image/png':'png','image/gif':'gif','image/webp':'webp',
      'video/mp4':'mp4','video/3gpp':'3gp',
      'application/pdf':'pdf',
      'application/msword':'doc',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document':'docx',
      'application/vnd.ms-excel':'xls',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':'xlsx',
      'text/plain':'txt','text/csv':'csv',
    };
    const ext      = extMap[mime] || mime.split('/')[1] || 'bin';
    const folder   = tipo === 'image' ? 'imagenes' : tipo === 'video' ? 'videos' : 'documentos';
    const safeName = fileName_orig ? fileName_orig.replace(/[^a-zA-Z0-9._-]/g,'_') : `${Date.now()}.${ext}`;
    const fileName = `${folder}/${telefono.replace('+','')}/${safeName}`;

    const media_url = await subirASupabase(buffer, fileName, mime);
    const content   = caption || (tipo === 'image' ? '[Imagen]' : tipo === 'video' ? '[Video]' : `[Documento: ${safeName}]`);

    console.log(`✅ ${tipo} subido: ${media_url.substring(0,60)}`);
    return { content, tipo, media_url, caption, mime_type: mime, file_name: safeName };

  } catch(err) {
    console.error(`❌ procesarMedia(${tipo}):`, err.message);
    return { content: caption || `[${tipo} — error al procesar]`, tipo, media_url: null };
  }
}

// ── ENVIAR ADJUNTO DEL AGENTE → USUARIO ──────────────────────────────────────
// (endpoint /send-media — agente sube archivo, server lo envía por Meta API)

// ── HORARIO DE ATENCIÓN ─────────────────────────────────────────────────
function estaEnHorario() {
  const now = new Date(new Date().toLocaleString('en-US', { timeZone: 'America/Bogota' }));
  const dow  = now.getDay(); // 0=Dom, 1=Lun ... 6=Sab
  const h    = now.getHours();
  const m    = now.getMinutes();
  const mins = h * 60 + m;

  if (dow === 0) return false; // Domingo cerrado
  if (dow >= 1 && dow <= 5) {  // Lun-Vie 7:00-17:30
    return mins >= 7*60 && mins < 17*60+30;
  }
  if (dow === 6) {             // Sáb 8:00-12:00
    return mins >= 8*60 && mins < 12*60;
  }
  return false;
}

function mensajeFueraHorario(nombre) {
  const now  = new Date(new Date().toLocaleString('en-US', { timeZone: 'America/Bogota' }));
  const dow  = now.getDay();
  const trat = nombre ? `*${nombre.split(' ')[0]}*` : 'estimado usuario';
  const horario = dow === 6
    ? 'Los sábados atendemos de *8:00 a.m. a 12:00 m.*'
    : '*lunes a viernes de 7:00 a.m. a 5:30 p.m.* y *sábados de 8:00 a.m. a 12:00 m.*';
  return (
    `Ha sido un gusto acompañarle, ${trat}. 🤝

` +
    `Su caso es importante para nosotros y queda registrado como prioridad.

` +
    `En este momento nuestro equipo de asesores está fuera del horario de atención.
` +
    `🕐 Atendemos de ${horario}

` +
    `Un asesor especializado revisará su caso personalmente en el próximo horario hábil y le contactará.

` +
    `Mientras tanto, puede seguir escribiéndome y con gusto le ayudo con lo que esté a mi alcance. 😊

` +
    `*Tododrogas, siempre a su servicio.*`
  );
}

// ── PROCESAR ENCUESTA WA ─────────────────────────────────────────────────
async function procesarEncuesta(telefono, respuesta, sesion) {
  const cal = respuesta.trim();
  if (!['1', '2', '3'].includes(cal)) return false;

  const calNum = parseInt(cal);
  const textos = { 1: 'Mala', 2: 'Regular', 3: 'Buena' };
  const emojis = { 1: '😞', 2: '😐', 3: '😊' };

  const ahoraISO = new Date().toISOString();

  // Si no hay agente asignado, fue Nova quien gestionó → registrar como Nova TD
  const _agenteGestion = sesion.agente_nombre || 'Nova TD';
  const _agenteId      = sesion.agente_id || null;

  // Si no hay agente que haya gestionado → pasar a pte_gestion para que un agente cierre
  // Si ya había agente asignado → cerrar directo (el agente ya está en el chat)
  const _requiereGestion = !sesion.agente_id; // Nova sola = requiere que un agente gestione
  const _estadoFinal = _requiereGestion ? 'pte_gestion' : 'cerrado';

  // Si Nova resolvió sola → asignar automáticamente a un agente para la gestión
  let _agenteAsigPte = null;
  if (_requiereGestion) {
    _agenteAsigPte = await autoAsignarAgente(telefono, sesion);
  }

  await supabase.from('wa_sesiones').update({
    calificacion:       calNum,
    calificacion_texto: textos[calNum],
    fecha_calificacion: ahoraISO,
    estado:             _estadoFinal,
    cerrado_at:         _requiereGestion ? null : ahoraISO,
    motivo_cierre_wa:   'encuesta',
    agente_nombre:      _agenteAsigPte ? _agenteAsigPte.nombre : _agenteGestion,
    agente_id:          _agenteAsigPte ? _agenteAsigPte.id : (_agenteId || null),
    updated_at:         ahoraISO
  }).eq('telefono', telefono);

  // Agregar al historial
  const hist = Array.isArray(sesion.history) ? sesion.history : [];
  hist.push({ role: 'user', content: cal, ts: ahoraISO });
  await supabase.from('wa_sesiones').update({ history: hist }).eq('telefono', telefono);

  // Responder al usuario
  const msg = `${emojis[calNum]} Gracias por calificarnos. Hemos registrado su atención como *${textos[calNum]}*.\n\nGracias por contactarnos. *Tododrogas, siempre a su servicio.*`;
  await enviarMeta(telefono, msg);

  // Registrar origen y agente real en el log
  const _fueNova = !sesion.agente_id; // sin agente = Nova gestionó sola
  await logConv(telefono, _agenteId, _agenteGestion, 'cerrado', null, {
    motivo:          'encuesta',
    calificacion:    calNum,
    origen_nova:     _fueNova,
    agente_gestion:  _agenteGestion
  });

  console.log(`⭐ Encuesta WA respondida por ${telefono}: ${textos[calNum]}`);
  return true;
}

// ── CRON: DETECTAR AGENTES OFFLINE (sin heartbeat) ─────────────────────────
// Si un agente no actualizó ultima_actividad en 6+ min → marcarlo offline
// Cubre: PC bloqueado, navegador cerrado forzado, apagón, Ctrl+L olvidado
async function cronAgentesOffline() {
  try {
    const hace6min = new Date(Date.now() - 6 * 60 * 1000).toISOString();
    const { data: agentesViejos } = await supabase
      .from('agentes')
      .select('id, nombre, ultima_actividad')
      .eq('en_linea', true)
      .eq('activo', true)
      .lt('ultima_actividad', hace6min); // ultima_actividad > 6 min atrás

    if (!agentesViejos?.length) return;

    for (const ag of agentesViejos) {
      await supabase.from('agentes').update({
        en_linea:         false,
        pausado:          false,
        pausado_at:       null,
        carga_actual:     0,
        ultima_actividad: ag.ultima_actividad // no pisar, solo marcar offline
      }).eq('id', ag.id);
      console.log(`🔴 Agente offline (sin heartbeat): ${ag.nombre} — última actividad: ${ag.ultima_actividad}`);
    }
  } catch(e) {
    console.error('❌ cronAgentesOffline:', e.message);
  }
}

// Ejecutar cada 3 minutos
setInterval(cronAgentesOffline, 3 * 60 * 1000);
// También al arrancar el server (para limpiar sesiones viejas)
setTimeout(cronAgentesOffline, 10000);

// ── CRON: INACTIVIDAD Y ESTADOS ──────────────────────────────────────────
// REGLAS CLARAS:
// - updated_at = última actividad DEL USUARIO (no del sistema)
// - inactividad_aviso_at = cuándo se mandó el aviso "¿continúa?"
// - encuesta_enviada_at  = cuándo se mandó la encuesta (guard anti-loop)
// - El cron NO actualiza updated_at (para no resetear el timer del usuario)

async function cronInactividad() {
  try {
    const ahora    = Date.now();
    const ahoraISO = new Date(ahora).toISOString();

    const { data: sesiones } = await supabase
      .from('wa_sesiones')
      .select('telefono,estado,nombre,agente_id,agente_nombre,updated_at,inactividad_aviso_at,asignado_at,encuesta_enviada_at,primera_respuesta_at,tipificacion,observacion_cierre,eps')
      .in('estado', ['nova','escalado','activo','esperando','esperando_encuesta','pte_gestion'])
      .not('updated_at','is',null);

    if (!sesiones?.length) return;

    for (const s of sesiones) {
      try {
        const minsDesdeUser = (ahora - new Date(s.updated_at).getTime()) / 60000;

        // ════════════════════════════════════════════════════════════════════
        // ESTADO: esperando_encuesta → solo verificar timeout de 10 min
        // ════════════════════════════════════════════════════════════════════
        if (s.estado === 'esperando_encuesta') {
          if (!s.encuesta_enviada_at) continue; // sin fecha → no tocar
          const minsEnc = (ahora - new Date(s.encuesta_enviada_at).getTime()) / 60000;
          if (minsEnc >= 10) {
            const _agNombre = s.agente_nombre || 'Nova TD';
            const _msgDespedida =
              `Cerramos su consulta, ${tratamiento(s.nombre)}. 😊

` +
              `Si necesita ayuda nuevamente, con gusto le atendemos.

` +
              `*¡Hasta pronto! Tododrogas, siempre a su servicio.*`;
            await enviarMeta(s.telefono, _msgDespedida);
            const _sinAgente2 = !s.agente_id;
            // Si Nova resolvió sola → asignar automáticamente a un agente
            let _agenteAsigInact = null;
            if (_sinAgente2) {
              _agenteAsigInact = await autoAsignarAgente(s.telefono, s);
            }
            await supabase.from('wa_sesiones').update({
              estado:             _sinAgente2 ? 'pte_gestion' : 'cerrado',
              calificacion:       null,
              calificacion_texto: 'Sin calificación',
              cerrado_at:         _sinAgente2 ? null : ahoraISO,
              motivo_cierre_wa:   'inactividad',
              agente_nombre:      _agenteAsigInact ? _agenteAsigInact.nombre : _agNombre,
              agente_id:          _agenteAsigInact ? _agenteAsigInact.id : (s.agente_id || null),
              updated_at:         ahoraISO
            }).eq('telefono', s.telefono);
            await logConv(s.telefono, s.agente_id, _agNombre, 'cerrado', null,
              { motivo: 'sin_calificacion_inactividad', origen_nova: !s.agente_id });
          }
          continue; // no hacer nada más con esperando_encuesta
        }

        // ════════════════════════════════════════════════════════════════════
        // ESTADO: pte_gestion → no tocar, el agente lo gestiona al llegar
        // ════════════════════════════════════════════════════════════════════
        if (s.estado === 'pte_gestion') continue;

        // ════════════════════════════════════════════════════════════════════
        // GUARD GLOBAL: si ya se envió encuesta, no hacer nada
        // ════════════════════════════════════════════════════════════════════
        if (s.encuesta_enviada_at) continue;

        // ════════════════════════════════════════════════════════════════════
        // ESTADO: nova
        // Flujo: 2min sin actividad → aviso → 8min más → encuesta → cerrar
        // ════════════════════════════════════════════════════════════════════
        if (s.estado === 'nova') {
          if (!s.inactividad_aviso_at) {
            // Aún no se mandó aviso — esperar 2 min desde última actividad
            if (minsDesdeUser >= 2) {
              const _msgAviso =
                `¿${tratamiento(s.nombre)}, continúa en línea?

` +
                `Estamos disponibles para atenderle.

` +
                `Si ya no necesita asistencia, escriba *SALIR* para cerrar.`;
              await enviarMeta(s.telefono, _msgAviso);
              // NO actualizar updated_at — solo inactividad_aviso_at
              await supabase.from('wa_sesiones')
                .update({ inactividad_aviso_at: ahoraISO })
                .eq('telefono', s.telefono);
            }
          } else {
            // Ya se mandó aviso — esperar 8 min más desde el aviso
            const minsDesdeAviso = (ahora - new Date(s.inactividad_aviso_at).getTime()) / 60000;
            if (minsDesdeAviso >= 8) {
              const _msgEnc =
                `Ha sido un placer acompañarle, ${tratamiento(s.nombre)}. 😊

` +
                `Esperamos haber resuelto su consulta satisfactoriamente.

` +
                `Antes de despedirnos, ¿nos regala un momento para calificarnos?

` +
                `*1* → 😞 Mala
*2* → 😐 Regular
*3* → 😊 Buena

` +
                `*Tododrogas, siempre a su servicio.* 🌟`;
              await enviarMeta(s.telefono, _msgEnc);
              await pushHistoryNova(s.telefono, _msgEnc, 'nova');
              // Setear encuesta_enviada_at ANTES de cambiar estado (guard anti-loop)
              // Update atómico: solo si encuesta_enviada_at sigue siendo null
              const { error: _errEnc1 } = await supabase.from('wa_sesiones').update({
                estado:               'esperando_encuesta',
                encuesta_enviada_at:  ahoraISO,
                inactividad_aviso_at: null,
                motivo_cierre_wa:     'inactividad'
              }).eq('telefono', s.telefono).is('encuesta_enviada_at', null);
              if (_errEnc1) { console.warn('encuesta ya enviada (race condition):', s.telefono); continue; }
              await logConv(s.telefono, null, null, 'encuesta_enviada', null, { motivo: 'inactividad_nova' });
            }
          }
          continue;
        }

        // ════════════════════════════════════════════════════════════════════
        // ESTADOS CON AGENTE: escalado / activo / esperando
        // Flujo: agente no responde → avisos → reasignar
        //        usuario inactivo → aviso → encuesta → cerrar
        // ════════════════════════════════════════════════════════════════════
        if (['escalado','activo','esperando'].includes(s.estado)) {

          // ── Agente no responde (escalado sin primera respuesta) ──────────
          // Máximo 2 avisos al usuario, luego silencio hasta reasignación a los 12 min
          // Flag: resumen_nova = 'aviso1' | 'aviso2' para saber cuántos se mandaron
          if (s.estado === 'escalado' && s.asignado_at && !s.primera_respuesta_at) {
            const minsAsig = (ahora - new Date(s.asignado_at).getTime()) / 60000;
            const _trat    = s.nombre ? `*${s.nombre.split(' ')[0]}*` : 'estimado usuario';
            const avisoFlag = s.resumen_nova || '';

            // Primer aviso: 3+ min, sin avisos previos
            if (minsAsig >= 3 && avisoFlag !== 'aviso1' && avisoFlag !== 'aviso2') {
              const _m = `Agradecemos su paciencia, ${_trat}. 🙏\n\nSu caso es nuestra prioridad y un asesor estará con usted en breve.\n\n*Tododrogas, siempre a su servicio.*`;
              await enviarMeta(s.telefono, _m);
              await pushHistoryNova(s.telefono, _m, 'nova');
              await supabase.from('wa_sesiones')
                .update({ inactividad_aviso_at: ahoraISO, resumen_nova: 'aviso1' })
                .eq('telefono', s.telefono);
              continue;
            }
            // Segundo aviso: 3+ min después del primero, solo si ya se mandó aviso1
            if (avisoFlag === 'aviso1' && s.inactividad_aviso_at) {
              const minsDesdeAviso1 = (ahora - new Date(s.inactividad_aviso_at).getTime()) / 60000;
              if (minsDesdeAviso1 >= 3) {
                const _m = `Permítanos 2 o 3 minutos más, ${_trat}. ⏳\n\nSu solicitud tiene prioridad. Gracias por su comprensión. 🌟`;
                await enviarMeta(s.telefono, _m);
                await pushHistoryNova(s.telefono, _m, 'nova');
                await supabase.from('wa_sesiones')
                  .update({ inactividad_aviso_at: ahoraISO, resumen_nova: 'aviso2' })
                  .eq('telefono', s.telefono);
                continue;
              }
            }
            // Reasignación: 12+ min sin primera respuesta, ya se mandó aviso2
            if (avisoFlag === 'aviso2' && minsAsig >= 12) {
              if (s.agente_id) {
                const { data: ag } = await supabase.from('agentes').select('carga_actual').eq('id',s.agente_id).single();
                if (ag) await supabase.from('agentes').update({ carga_actual: Math.max(0,(ag.carga_actual||1)-1) }).eq('id',s.agente_id);
              }
              await supabase.from('wa_sesiones').update({
                agente_id: null, agente_nombre: null,
                asignado_at: null, inactividad_aviso_at: null,
                primera_respuesta_at: null, resumen_nova: null
              }).eq('telefono', s.telefono);
              const sesActual = {...s, agente_id:null, agente_nombre:null};
              const nuevoAg = await autoAsignarAgente(s.telefono, sesActual);
              const _trat2 = s.nombre ? `*${s.nombre.split(' ')[0]}*` : 'estimado usuario';
              const _m = nuevoAg
                ? `Hemos asignado un nuevo asesor para garantizar su atención, ${_trat2}. Estará con usted en un momento. 🤝\n\n*Tododrogas, siempre a su servicio.*`
                : `Le pedimos disculpas por la espera, ${_trat2}. Su caso queda registrado como prioridad y le contactaremos a la brevedad.\n\n*Tododrogas, siempre a su servicio.*`;
              await enviarMeta(s.telefono, _m);
              await pushHistoryNova(s.telefono, _m, 'nova');
              continue;
            }
            continue; // aún en tiempo de gracia — no hacer nada
          }

          // ── Usuario inactivo con agente ──────────────────────────────────
          // ── Usuario inactivo con agente ──────────────────────────────────
          // Solo aplica si el agente ya respondió (primera_respuesta_at existe)
          // o si el estado es activo/esperando
          if (!s.inactividad_aviso_at) {
            if (minsDesdeUser >= 2) {
              const _msgAviso =
                `¿${tratamiento(s.nombre)}, continúa en línea?

` +
                `*${s.agente_nombre || 'Su asesor'}* continúa disponible.

` +
                `Si ya resolvió su consulta, escriba *LISTO* para cerrar.`;
              await enviarMeta(s.telefono, _msgAviso);
              await pushHistoryNova(s.telefono, _msgAviso, 'nova');
              await supabase.from('wa_sesiones')
                .update({ inactividad_aviso_at: ahoraISO })
                .eq('telefono', s.telefono);
            }
          } else {
            const minsDesdeAviso = (ahora - new Date(s.inactividad_aviso_at).getTime()) / 60000;
            if (minsDesdeAviso >= 8) {
              const _msgEnc =
                `Ha sido un placer acompañarle, ${tratamiento(s.nombre)}. 😊

` +
                `Esperamos haber resuelto su consulta satisfactoriamente.

` +
                `Antes de despedirnos, ¿nos regala un momento para calificarnos?

` +
                `*1* → 😞 Mala
*2* → 😐 Regular
*3* → 😊 Buena

` +
                `*Tododrogas, siempre a su servicio.* 🌟`;
              await enviarMeta(s.telefono, _msgEnc);
              await pushHistoryNova(s.telefono, _msgEnc, 'nova');
              // Reducir carga del agente
              if (s.agente_id) {
                const { data: ag } = await supabase.from('agentes').select('carga_actual').eq('id',s.agente_id).single();
                if (ag) await supabase.from('agentes').update({ carga_actual: Math.max(0,(ag.carga_actual||1)-1) }).eq('id',s.agente_id);
              }
              const { error: _errEnc2 } = await supabase.from('wa_sesiones').update({
                estado:               'esperando_encuesta',
                encuesta_enviada_at:  ahoraISO,
                inactividad_aviso_at: null,
                motivo_cierre_wa:     'inactividad'
              }).eq('telefono', s.telefono).is('encuesta_enviada_at', null);
              if (_errEnc2) { console.warn('encuesta ya enviada agente (race condition):', s.telefono); continue; }
              await logConv(s.telefono, s.agente_id, s.agente_nombre, 'encuesta_enviada', null, { motivo: 'inactividad_agente' });
            }
          }
        }

      } catch(eS) {
        console.error(`❌ cronInactividad [${s.telefono}]:`, eS.message);
      }
    }
  } catch(e) {
    console.error('❌ cronInactividad:', e.message);
  }
}
setInterval(cronInactividad, 60 * 1000);

// ── HEALTH CHECK ───────────────────────────────────────────────────────────
app.get('/', (req, res) =>
  res.json({ status: 'ok', service: 'webhook-meta-tododrogas', ts: new Date().toISOString() })
);

// ── VERIFICACIÓN WEBHOOK (GET) ─────────────────────────────────────────────
app.get('/webhook/meta', (req, res) => {
  const mode      = req.query['hub.mode'];
  const token     = req.query['hub.verify_token'];
  const challenge = req.query['hub.challenge'];
  if (mode === 'subscribe' && token === META_VERIFY_TOKEN) {
    console.log('✅ Webhook Meta verificado');
    return res.status(200).send(challenge);
  }
  res.sendStatus(403);
});

// ── RECIBIR MENSAJES (POST) ────────────────────────────────────────────────
app.post('/webhook/meta', async (req, res) => {
  if (!validarFirmaMeta(req)) return res.sendStatus(403);

  res.sendStatus(200);

  try {
    const entry    = req.body?.entry?.[0];
    const changes  = entry?.changes?.[0];
    const value    = changes?.value;
    const messages = value?.messages;

    if (!messages?.length) return;

    const msg      = messages[0];
    const telefono = '+' + msg.from;
    const tipo     = msg.type;
    const profile  = value?.contacts?.[0]?.profile?.name || '';

    if (rateLimit(telefono)) return;

    // ── Obtener o crear sesión ─────────────────────────────────────────────
    let { data: sesion } = await supabase
      .from('wa_sesiones').select('*').eq('telefono', telefono).single();

    const ahoraISO = new Date().toISOString();

    // ── Procesar según tipo de mensaje ─────────────────────────────────────
    let body     = msg.text?.body || '';
    let nuevoMsg = { role:'user', content: body || `[${tipo}]`, ts: ahoraISO };

    if (tipo === 'audio') {
      // Procesar audio: descargar → storage → transcribir con Whisper
      const audioData = await procesarAudio(msg, telefono);
      body     = audioData.content; // transcripción para Nova/agente
      nuevoMsg = { role:'user', ts: ahoraISO, ...audioData };

      // Confirmar recepción al usuario mientras se procesa
      if (sesion?.estado === 'nova') {
        await enviarMeta(telefono, '🎙️ _Audio recibido, procesando..._').catch(()=>{});
      }
    } else if (tipo === 'image' || tipo === 'document' || tipo === 'video') {
      // Descargar de Meta → subir a Supabase Storage → guardar URL
      const mediaData = await procesarMedia(msg, telefono, tipo);
      body     = mediaData.content;
      nuevoMsg = { role:'user', ts: ahoraISO, ...mediaData };
    } else if (tipo === 'sticker') {
      body     = '[Sticker 😄]';
      nuevoMsg = { role:'user', content: body, tipo, ts: ahoraISO };
    } else if (!body) {
      body     = `[${tipo}]`;
      nuevoMsg = { role:'user', content: body, tipo, ts: ahoraISO };
    }

    if (sesion) {

      // ── SESIÓN CERRADA: lógica de ventana 24h ────────────────────────────
      if (sesion.estado === 'cerrado') {
        const cerradoAt  = sesion.cerrado_at ? new Date(sesion.cerrado_at).getTime() : 0;
        const horasDesde = (Date.now() - cerradoAt) / 3600000;

        if (horasDesde <= 24) {
          // ── Dentro de 24h → MISMA sesión ─────────────────────────────────
          const hist = Array.isArray(sesion.history) ? sesion.history : [];
          hist.push(nuevoMsg);

          // ¿El agente ya registró la gestión?
          const gestionRegistrada = !!(sesion.tipificacion?.trim() || sesion.observacion_cierre?.trim());
          const tieneAgente       = !!sesion.agente_id;

          let nuevoEstado;
          let _msgReabierto;

          if (!gestionRegistrada && tieneAgente) {
            // Agente nunca registró → devolver al mismo agente para que complete
            nuevoEstado   = 'escalado';
            _msgReabierto =
              `Hemos recibido su mensaje, ${tratamiento(sesion.nombre)}. 😊\n\n` +
              `Su asesor continuará atendiéndole en breve.\n\n` +
              `*Tododrogas, siempre a su servicio.*`;
            console.log(`🔄 Sesión → escalado (agente sin gestión): ${telefono}`);
          } else if (gestionRegistrada && tieneAgente) {
            // Agente ya gestionó → nueva consulta, segunda gestión
            nuevoEstado   = 'pte_gestion';
            _msgReabierto =
              `Hemos recibido su mensaje, ${tratamiento(sesion.nombre)}. 😊\n\n` +
              `Un asesor revisará su caso a la brevedad.\n\n` +
              `*Tododrogas, siempre a su servicio.*`;
            console.log(`🔄 Sesión → pte_gestion (segunda gestión): ${telefono}`);
          } else {
            // Sin agente (cerrado por Nova sola) → Nova atiende de nuevo
            nuevoEstado   = 'nova';
            _msgReabierto = null; // Nova responderá normalmente
            console.log(`🔄 Sesión → nova (sin agente previo): ${telefono}`);
          }

          await supabase.from('wa_sesiones').update({
            estado:              nuevoEstado,
            history:             hist,
            unread_count:        (sesion.unread_count || 0) + 1,
            inactividad_aviso_at: null,
            cerrado_at:          null,  // ya no está cerrado
            updated_at:          ahoraISO
          }).eq('telefono', telefono);

          sesion.estado = nuevoEstado;
          sesion.history = hist;

          if (_msgReabierto) {
            await enviarMeta(telefono, _msgReabierto);
            await pushHistoryNova(telefono, _msgReabierto, 'nova');
            return;
          }
          // Si nuevoEstado = 'nova' → continúa al bloque Nova TD abajo
          if (nuevoEstado !== 'nova') return;

        } else {
          // ── Más de 24h → NUEVA sesión limpia, Nova TD ────────────────────
          // Primero archivar el historial anterior en wa_historico
          if (Array.isArray(sesion.history) && sesion.history.length > 0) {
            await supabase.from('wa_historico').insert({
              telefono,
              nombre:       sesion.nombre || '',
              eps:          sesion.eps    || '',
              cedula:       sesion.cedula || '',
              history:      sesion.history,
              agente_id:    sesion.agente_id    || null,
              agente_nombre: sesion.agente_nombre || null,
              calificacion: sesion.calificacion  || null,
              calificacion_texto: sesion.calificacion_texto || null,
              motivo_cierre_wa:   sesion.motivo_cierre_wa || null,
              cerrado_at:   sesion.cerrado_at  || sesion.updated_at,
              created_at:   ahoraISO
            }).catch(e => console.warn('⚠️ wa_historico insert:', e.message));
          }

          const nueva = {
            telefono,
            nombre:       sesion.nombre || profile || '',
            eps:          sesion.eps    || '',
            cedula:       sesion.cedula || '',
            history:      [nuevoMsg],
            estado:       'nova',
            unread_count: 1,
            updated_at:   ahoraISO,
            agente_id:           null,
            agente_nombre:       null,
            calificacion:        null,
            calificacion_texto:  null,
            encuesta_enviada:    null,
            encuesta_enviada_at: null,
            cerrado_at:          null,
            motivo_cierre_wa:    null,
            inactividad_aviso_at: null,
            asignado_at:         null,
            primera_respuesta_at: null,
            transferido_de:      null,
            transferido_at:      null,
            tipificacion:        null,
            observacion_cierre:  null,
            fase:                null,
          };
          await supabase.from('wa_sesiones').upsert(nueva);
          sesion = nueva;
          console.log(`🆕 Nueva sesión (>24h): ${telefono}`);
        }
      }

      // ── ENCUESTA: si está esperando respuesta de encuesta ────────────────
      if (sesion.estado === 'esperando_encuesta') {
        const procesado = await procesarEncuesta(telefono, body, sesion);
        if (procesado) return;
        // Si no procesó (no era 1/2/3) → recordar que solo aceptamos esa respuesta
        // y NO pasar a Nova ni a ningún otro handler
        const bodyTrim = (body||'').trim();
        if (bodyTrim && !['1','2','3'].includes(bodyTrim)) {
          await enviarMeta(telefono,
            `Por favor responda solo con *1*, *2* o *3* para calificarnos:\n\n` +
            `*1* → 😞 Mala\n*2* → 😐 Regular\n*3* → 😊 Buena`
          );
        }
        return; // SIEMPRE salir — no llegar a Nova ni a ningún otro handler
      }

      // ── SALIR / LISTO: cierre voluntario del usuario ─────────────────────
      const bodyUp = body.trim().toUpperCase();
      if (['SALIR', 'LISTO', 'ADIOS', 'ADIÓS', 'CHAO'].includes(bodyUp) &&
          ['nova', 'escalado', 'activo', 'esperando'].includes(sesion.estado)) {
        await enviarMeta(telefono,
          `Gracias por contactarnos, ${tratamiento(sesion.nombre)}.\n\nAntes de cerrar, le invitamos a calificar nuestra atención:\n\n*1* → 😞 Mala\n*2* → 😐 Regular\n*3* → 😊 Buena\n\n*Tododrogas, siempre a su servicio.*`
        );
        if (sesion.agente_id) {
          const { data: ag } = await supabase.from('agentes').select('carga_actual').eq('id', sesion.agente_id).single();
          if (ag) await supabase.from('agentes').update({
            carga_actual: Math.max(0, (ag.carga_actual || 1) - 1)
          }).eq('id', sesion.agente_id);
        }
        await supabase.from('wa_sesiones').update({
          estado:              'esperando_encuesta',
          encuesta_enviada_at: ahoraISO,
          inactividad_aviso_at: null,
          updated_at:          ahoraISO
        }).eq('telefono', telefono);
        return;
      }

      const history = Array.isArray(sesion.history) ? sesion.history : [];
      if (history.length >= 500) history.splice(0, history.length - 499);
      history.push(nuevoMsg);

      // Si hay agente y el usuario responde → volver a "activo" + reset inactividad
      const updateData = {
        history,
        unread_count:        (sesion.unread_count || 0) + 1,
        inactividad_aviso_at: null, // reset al recibir mensaje
        updated_at:           ahoraISO,
        ...(profile && !sesion.nombre ? { nombre: profile } : {})
      };

      if (['escalado', 'esperando'].includes(sesion.estado) && sesion.agente_id) {
        updateData.estado = 'activo';
        updateData.mensajes_usuario_ag = (sesion.mensajes_usuario_ag || 0) + 1;
        await logConv(telefono, sesion.agente_id, sesion.agente_nombre, 'mensaje_usuario');
      }

      // Si el usuario escribe cuando está en pte_gestion → guardar y confirmar
      if (sesion.estado === 'pte_gestion') {
        await supabase.from('wa_sesiones').update(updateData).eq('telefono', telefono);
        sesion.history = history;
        // Solo responder una vez cada 30 min para no saturar
        const ultimaAct = sesion.updated_at ? new Date(sesion.updated_at).getTime() : 0;
        const minsDesdeUlt = (Date.now() - ultimaAct) / 60000;
        if (minsDesdeUlt > 5) {
          const _msgPte = `Hemos recibido su mensaje, ${tratamiento(sesion.nombre)}. 📝

Un asesor revisará su caso en el próximo horario de atención:
🕐 *Lunes a viernes 7:00 a.m. - 5:30 p.m.* | *Sábados 8:00 a.m. - 12:00 m.*

*Tododrogas, siempre a su servicio.*`;
          await enviarMeta(telefono, _msgPte);
          await pushHistoryNova(telefono, _msgPte, 'nova');
        }
        return;
      }

      await supabase.from('wa_sesiones').update(updateData).eq('telefono', telefono);
      sesion.history = history;
    } else {
      // ── Nueva sesión ──────────────────────────────────────────────────────
      const nueva = {
        telefono,
        nombre:       profile || '',
        history:      [nuevoMsg],
        estado:       'nova',
        unread_count: 1,
        updated_at:   ahoraISO
      };
      await supabase.from('wa_sesiones').insert(nueva);
      sesion = nueva;
    }

    console.log(`📩 Mensaje de ${telefono}: ${body.substring(0, 60)}`);

    // ── Llamar a Nova TD si estado es nova ────────────────────────────────
    if ((sesion.estado || 'nova') === 'nova') {

      // Pre-detección de satisfacción ANTES de llamar a Nova
      const _bodyLowerPre = (body||'').toLowerCase().trim();
      const _frasesSat = ['gracias','muchas gracias','ok gracias','no gracias',
        'así está bien','no, así está bien','ya está bien','está bien así',
        'perfecto','listo','eso era todo','ya quedé','no necesito más',
        'fue todo','ya me ayudó','con eso es suficiente','excelente gracias',
        'bien gracias','gracias por tu'];
      const _histLen = Array.isArray(sesion?.history) ? sesion.history.length : 0;
      const _esSat = _frasesSat.some(f => _bodyLowerPre.includes(f)) && _histLen >= 2;

      if (_esSat) {
        const _nom = sesion.nombre ? `*${sesion.nombre.split(' ')[0]}*` : '';
        const _msgEnc =
          `¡Con mucho gusto${_nom ? ', '+_nom : ''}! Fue un placer ayudarle. 😊

` +
          `Antes de despedirnos, ¿nos regala un momento para calificarnos?

` +
          `*1* → 😞 Mala
*2* → 😐 Regular
*3* → 😊 Buena

` +
          `*Tododrogas, siempre a su servicio.* 🌟`;
        await enviarMeta(telefono, _msgEnc);
        await pushHistoryNova(telefono, _msgEnc, 'nova');
        await supabase.from('wa_sesiones').update({
          estado: 'esperando_encuesta',
          encuesta_enviada_at: ahoraISO,
          inactividad_aviso_at: null,
          motivo_cierre_wa: 'satisfaccion_usuario'
        }).eq('telefono', telefono).is('encuesta_enviada_at', null);
        console.log(`✅ Satisfacción detectada pre-Nova: ${telefono} "${_bodyLowerPre.substring(0,30)}"`);
        return res.sendStatus(200);
      }

      try {
        const novaRes = await fetch('https://tododrogas.online/nova-wa.php', {
          method: 'POST',
          headers: {
            'Content-Type':  'application/json',
            'X-Nova-Token':  NOVA_TOKEN
          },
          body: JSON.stringify({ telefono, mensaje: body, sesion })
        });

        if (novaRes.ok) {
          const novaData = await novaRes.json();
          const respuesta = novaData.respuesta;

          // Enviar y guardar respuesta de Nova solo si NO es escalado
          if (respuesta && novaData.accion !== 'ESCALADO') {
            await enviarMeta(telefono, respuesta);
            const { data: sesUp } = await supabase
              .from('wa_sesiones').select('history').eq('telefono', telefono).single();
            const hist = Array.isArray(sesUp?.history) ? sesUp.history : [];
            hist.push({ role: 'nova', content: respuesta, ts: ahoraISO });
            await supabase.from('wa_sesiones')
              .update({ history: hist, updated_at: ahoraISO })
              .eq('telefono', telefono);
          }

          // Detección de satisfacción en el BODY del mensaje como respaldo
          // Si Nova olvidó [ENCUESTA] pero el usuario claramente dijo gracias/listo
          if (!novaData.accion || novaData.accion === 'DEFAULT') {
            const _bodyLower = (body||'').toLowerCase().trim();
            const _frasesSatisfaccion = [
              'no, así está bien','así está bien','no gracias','ya está bien',
              'está bien así','gracias','muchas gracias','perfecto','listo',
              'ok gracias','eso era todo','ya quedé','no necesito más','fue todo',
              'ya me ayudó','con eso es suficiente','ya no necesito'
            ];
            if (_frasesSatisfaccion.some(f => _bodyLower.includes(f))) {
              novaData.accion = 'ENCUESTA';
              console.log(`💡 Satisfacción detectada en server: "${_bodyLower.substring(0,40)}"`);
            }
          }

          // Si Nova envía encuesta (usuario satisfecho) → cambiar estado
          if (novaData.accion === 'ENCUESTA') {
            const { error: _errEnc3 } = await supabase.from('wa_sesiones').update({
              estado:               'esperando_encuesta',
              encuesta_enviada_at:  ahoraISO,
              inactividad_aviso_at: null,
              motivo_cierre_wa:     'satisfaccion_usuario'
            }).eq('telefono', telefono).is('encuesta_enviada_at', null);
            if (_errEnc3) console.warn('encuesta PHP race condition:', telefono);
            await logConv(telefono, null, null, 'encuesta_enviada', null, { motivo: 'satisfaccion_usuario' });
            console.log(`📋 Encuesta enviada por satisfacción: ${telefono}`);
          }

          // Si Nova escala → NO enviar el respuesta de Nova (evitar duplicado)
          // El server enviará el mensaje de traspaso mejorado
          if (novaData.accion === 'ESCALADO') {
            const { data: sesActual } = await supabase
              .from('wa_sesiones').select('*').eq('telefono', telefono).single();
            const _nombre = sesActual?.nombre || sesion?.nombre || '';
            const _trat   = _nombre ? `*${_nombre.split(' ')[0]}*` : 'estimado usuario';

            if (estaEnHorario()) {
              // Dentro del horario → asignar agente normalmente
              const agente = await autoAsignarAgente(telefono, sesActual);
              if (agente) {
                // Mensaje cálido de Nova despidiéndose + presentando al asesor
                const _msgEscalado =
                  `Ha sido un gusto acompañarle, ${_trat}. 🤝

` +
                  `Su caso tiene nuestra máxima atención y está en las mejores manos.

` +
                  `En este momento le conectamos con un asesor especializado que revisará ` +
                  `su situación de manera personalizada.

` +
                  `Por favor espere un momento. 🙏

` +
                  `*Tododrogas, siempre a su servicio.*`;
                await enviarMeta(telefono, _msgEscalado);
                await pushHistoryNova(telefono, _msgEscalado, 'nova');
              } else {
                // No hay agentes disponibles ahora
                const _msgSinAgente = mensajeFueraHorario(sesActual?.nombre);
                await enviarMeta(telefono, _msgSinAgente);
                await pushHistoryNova(telefono, _msgSinAgente, 'nova');
                await supabase.from('wa_sesiones')
                  .update({ estado: 'pte_gestion', updated_at: ahoraISO })
                  .eq('telefono', telefono);
              }
            } else {
              // Fuera del horario → guardar como pte_gestion para mañana
              const _msgFuera = mensajeFueraHorario(sesActual?.nombre);
              await enviarMeta(telefono, _msgFuera);
              await pushHistoryNova(telefono, _msgFuera, 'nova');
              // Estado pte_gestion: el agente lo verá al llegar mañana
              await supabase.from('wa_sesiones')
                .update({ estado: 'pte_gestion', updated_at: ahoraISO })
                .eq('telefono', telefono);
              console.log(`🌙 Fuera de horario: ${telefono} → pte_gestion`);
            }
          }
        } else {
          console.error('❌ Error Nova HTTP:', novaRes.status);
        }
      } catch (err) {
        console.error('❌ Error llamando Nova:', err.message);
      }
    }

  } catch (err) {
    console.error('❌ Error webhook Meta:', err.message);
  }
});

// ── ENVIAR MENSAJE (agente → usuario) ─────────────────────────────────────
// ── ENVIAR AUDIO DEL AGENTE → USUARIO ────────────────────────────────────
// ── ENVIAR MEDIA DEL AGENTE → USUARIO ────────────────────────────────────
app.post('/send-media', upload.single('file'), async (req, res) => {
  const origin = req.headers.origin || req.headers.referer || '';
  if (!ALLOWED_ORIGINS.some(o => origin.startsWith(o)))
    return res.status(403).json({ error: 'Forbidden' });

  try {
    const { telefono, agente_nombre, agente_id, caption } = req.body;
    const file = req.file;
    if (!telefono || !file) return res.status(400).json({ error: 'telefono y file requeridos' });

    const ahoraISO  = new Date().toISOString();
    const mime      = file.mimetype;
    const isImage   = mime.startsWith('image/');
    const isVideo   = mime.startsWith('video/');
    const isAudio   = mime.startsWith('audio/');
    const isPdf     = mime === 'application/pdf';
    const folder    = isImage ? 'imagenes-agente' : isVideo ? 'videos-agente' : isAudio ? 'audios-agente' : 'documentos-agente';
    const safeName  = file.originalname?.replace(/[^a-zA-Z0-9._-]/g,'_') || `file_${Date.now()}`;
    const fileName  = `${folder}/${telefono.replace('+','')}/${Date.now()}_${safeName}`;

    // 1. Subir a Supabase Storage
    const media_url = await subirASupabase(file.buffer, fileName, mime);

    // 2. Enviar por Meta API según tipo
    let metaBody;
    if (isImage) {
      metaBody = { messaging_product:'whatsapp', to:telefono.replace('+',''), type:'image',
        image:{ link:media_url, caption: caption||'' }};
    } else if (isVideo) {
      metaBody = { messaging_product:'whatsapp', to:telefono.replace('+',''), type:'video',
        video:{ link:media_url, caption: caption||'' }};
    } else if (isAudio) {
      metaBody = { messaging_product:'whatsapp', to:telefono.replace('+',''), type:'audio',
        audio:{ link:media_url }};
    } else {
      // documento, pdf, etc
      metaBody = { messaging_product:'whatsapp', to:telefono.replace('+',''), type:'document',
        document:{ link:media_url, filename:safeName, caption: caption||'' }};
    }

    const metaRes = await fetch(`https://graph.facebook.com/v19.0/${META_PHONE_ID}/messages`,{
      method:'POST',
      headers:{'Content-Type':'application/json','Authorization':`Bearer ${META_TOKEN}`},
      body: JSON.stringify(metaBody)
    });
    if (!metaRes.ok) throw new Error(await metaRes.text());

    // 3. Guardar en history
    const { data: sesion } = await supabase.from('wa_sesiones').select('history').eq('telefono',telefono).single();
    const hist = Array.isArray(sesion?.history) ? sesion.history : [];
    hist.push({
      role:'assistant', sender:'agent',
      tipo:    isImage?'image':isVideo?'video':isAudio?'audio':'document',
      content: caption || `[${isImage?'Imagen':isVideo?'Video':isAudio?'Audio':'Documento'}: ${safeName}]`,
      media_url, file_name:safeName, mime_type:mime,
      agente_nombre: agente_nombre||'Agente',
      ts: ahoraISO
    });
    await supabase.from('wa_sesiones').update({ history:hist, updated_at:ahoraISO }).eq('telefono',telefono);
    await logConv(telefono, agente_id||null, agente_nombre||'Agente', 'mensaje_agente');

    res.json({ ok:true, media_url });
  } catch(err) {
    console.error('❌ send-media:', err.message);
    res.status(500).json({ error: err.message });
  }
});

app.post('/send-audio', upload.single('audio'), async (req, res) => {
  const origin = req.headers.origin || req.headers.referer || '';
  if (!ALLOWED_ORIGINS.some(o => origin.startsWith(o)))
    return res.status(403).json({ error: 'Forbidden' });

  try {
    const { telefono, agente_nombre, agente_id, duracion } = req.body;
    const file = req.file;

    if (!telefono || !file) return res.status(400).json({ error: 'telefono y audio requeridos' });

    const ahoraISO = new Date().toISOString();
    const ext      = file.mimetype.includes('webm') ? 'webm' : 'ogg';
    const fileName = `audios-agente/${telefono.replace('+','')}/${Date.now()}.${ext}`;

    // 1. Subir audio a Supabase Storage
    const audioUrl = await subirASupabase(file.buffer, fileName, file.mimetype);

    // 2. Enviar a WhatsApp via Meta API (audio message)
    const metaRes = await fetch(
      `https://graph.facebook.com/v19.0/${META_PHONE_ID}/messages`,
      {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'Authorization':`Bearer ${META_TOKEN}` },
        body: JSON.stringify({
          messaging_product: 'whatsapp',
          to:   telefono.replace('+',''),
          type: 'audio',
          audio: { link: audioUrl }
        })
      }
    );
    if (!metaRes.ok) throw new Error(await metaRes.text());

    // 3. Guardar en history del agente
    const { data: sesion } = await supabase.from('wa_sesiones').select('history').eq('telefono', telefono).single();
    const hist = Array.isArray(sesion?.history) ? sesion.history : [];
    hist.push({
      role:      'assistant',
      sender:    'agent',
      tipo:      'audio',
      content:   '[Audio de voz]',
      audio_url: audioUrl,
      duracion:  parseInt(duracion)||0,
      agente_nombre: agente_nombre || 'Agente',
      ts: ahoraISO
    });
    await supabase.from('wa_sesiones').update({
      history:    hist,
      updated_at: ahoraISO,
      mensajes_agente: supabase.rpc ? undefined : undefined // se actualiza aparte
    }).eq('telefono', telefono);

    // 4. Log
    await logConv(telefono, agente_id||null, agente_nombre||'Agente', 'mensaje_agente');

    res.json({ ok: true, audio_url: audioUrl });
  } catch(err) {
    console.error('❌ send-audio:', err.message);
    res.status(500).json({ error: err.message });
  }
});

// ── INICIAR CONVERSACIÓN CON NUEVO NÚMERO ───────────────────────────────
// Meta exige plantilla HSM para el primer mensaje saliente.
// Usamos el template "hello_world" (siempre aprobado) o uno personalizado.
app.post('/iniciar-conversacion', async (req, res) => {
  const origin = req.headers.origin || req.headers.referer || '';
  if (!ALLOWED_ORIGINS.some(o => origin.startsWith(o)))
    return res.status(403).json({ error: 'Forbidden' });

  try {
    const { telefono, agente_id, agente_nombre, template_name, template_params, mensaje_libre } = req.body;
    if (!telefono) return res.status(400).json({ error: 'telefono requerido' });

    const ahoraISO  = new Date().toISOString();
    const telClean  = telefono.replace(/\D/g,'');
    const telFull   = '+' + telClean;

    // Verificar si ya existe sesión activa
    const { data: sesExist } = await supabase
      .from('wa_sesiones').select('telefono,estado').eq('telefono', telFull).single();

    if (sesExist && !['cerrado'].includes(sesExist.estado)) {
      return res.status(409).json({
        error: 'Ya existe una conversación activa con este número',
        estado: sesExist.estado
      });
    }

    // ── Enviar por Meta API ──────────────────────────────────────────────
    let metaBody;

    if (template_name) {
      // Usar plantilla HSM aprobada
      const components = [];
      if (template_params?.length) {
        components.push({
          type: 'body',
          parameters: template_params.map(p => ({ type: 'text', text: p }))
        });
      }
      metaBody = {
        messaging_product: 'whatsapp',
        to:   telClean,
        type: 'template',
        template: {
          name:     template_name,
          language: { code: 'es' },
          ...(components.length ? { components } : {})
        }
      };
    } else if (mensaje_libre) {
      // Mensaje de texto libre (solo funciona dentro de ventana 24h existente)
      metaBody = {
        messaging_product: 'whatsapp',
        to:   telClean,
        type: 'text',
        text: { body: mensaje_libre }
      };
    } else {
      return res.status(400).json({ error: 'Se requiere template_name o mensaje_libre' });
    }

    const metaRes = await fetch(
      `https://graph.facebook.com/v19.0/${META_PHONE_ID}/messages`,
      {
        method:  'POST',
        headers: { 'Content-Type':'application/json', 'Authorization':`Bearer ${META_TOKEN}` },
        body:    JSON.stringify(metaBody)
      }
    );
    if (!metaRes.ok) {
      const err = await metaRes.text();
      console.error('Meta iniciar-conv error:', err);
      return res.status(502).json({ error: 'Error Meta API: ' + err.substring(0,200) });
    }
    const metaData = await metaRes.json();

    // ── Crear o reactivar sesión en Supabase ─────────────────────────────
    const contenido = mensaje_libre || `[Plantilla: ${template_name}]`;
    const nuevoMsg  = { role:'assistant', sender:'agent', content:contenido,
                        agente_nombre: agente_nombre||'Agente', ts: ahoraISO };

    const sesionData = {
      telefono:    telFull,
      estado:      'escalado',
      agente_id:   agente_id   || null,
      agente_nombre: agente_nombre || null,
      asignado_at: ahoraISO,
      history:     [nuevoMsg],
      unread_count: 0,
      updated_at:  ahoraISO,
      // Limpiar campos anteriores si reactivamos
      calificacion: null, calificacion_texto: null,
      encuesta_enviada_at: null, cerrado_at: null,
      inactividad_aviso_at: null, motivo_cierre_wa: null,
    };

    await supabase.from('wa_sesiones').upsert(sesionData);
    await logConv(telFull, agente_id||null, agente_nombre||'Agente', 'iniciado_agente', null, {
      template: template_name || 'mensaje_libre'
    });

    console.log(`📤 Conversación iniciada con ${telFull} por ${agente_nombre}`);
    res.json({ ok: true, telefono: telFull, message_id: metaData.messages?.[0]?.id });

  } catch(err) {
    console.error('❌ iniciar-conversacion:', err.message);
    res.status(500).json({ error: err.message });
  }
});

app.post('/send', async (req, res) => {
  const origin = req.headers.origin || req.headers.referer || '';
  if (!ALLOWED_ORIGINS.some(o => origin.startsWith(o)))
    return res.status(403).json({ error: 'Origen no permitido' });

  try {
    const { telefono, mensaje, agente_nombre, agente_id } = req.body;
    if (!telefono || !mensaje)
      return res.status(400).json({ error: 'telefono y mensaje requeridos' });

    const metaData = await enviarMeta(telefono, mensaje);
    const ahoraISO = new Date().toISOString();

    const { data: sesion } = await supabase
      .from('wa_sesiones').select('*').eq('telefono', telefono).single();

    const history = Array.isArray(sesion?.history) ? sesion.history : [];
    history.push({
      role:    'assistant',
      sender:  'agent',
      content: mensaje,
      agente:  agente_nombre || 'Agente',
      ts:      ahoraISO
    });

    const updateData = {
      history,
      unread_count:        0,
      updated_at:          ahoraISO,
      inactividad_aviso_at: null, // reset al enviar mensaje
      mensajes_agente:     (sesion?.mensajes_agente || 0) + 1
    };

    // Primera respuesta del agente → estado activo + registrar tiempo
    if (!sesion?.primera_respuesta_at) {
      updateData.primera_respuesta_at = ahoraISO;
      updateData.estado = 'activo';
      // Calcular duración desde asignación
      const durSeg = sesion?.asignado_at
        ? Math.round((new Date(ahoraISO) - new Date(sesion.asignado_at)) / 1000)
        : null;
      await logConv(telefono, agente_id || sesion?.agente_id, agente_nombre, 'primera_respuesta', durSeg, {
        es_saludo: mensaje === sesion?.saludo_sugerido
      });
    } else {
      // Respuesta posterior → asegurar estado activo
      updateData.estado = 'activo';
      await logConv(telefono, agente_id || sesion?.agente_id, agente_nombre, 'mensaje_agente');
    }

    await supabase.from('wa_sesiones').update(updateData).eq('telefono', telefono);

    console.log(`📤 Enviado a ${telefono} por ${agente_nombre || 'Agente'}`);
    res.json({ ok: true, message_id: metaData.messages?.[0]?.id });

  } catch (err) {
    console.error('❌ Error send:', err.message);
    res.status(500).json({ error: err.message });
  }
});

// ── TRANSFERIR CONVERSACIÓN ────────────────────────────────────────────────
app.post('/transferir', async (req, res) => {
  const origin = req.headers.origin || req.headers.referer || '';
  if (!ALLOWED_ORIGINS.some(o => origin.startsWith(o)))
    return res.status(403).json({ error: 'Origen no permitido' });

  try {
    const { telefono, nuevo_agente_id, nuevo_agente_nombre, agente_origen_id, agente_origen_nombre } = req.body;
    if (!telefono || !nuevo_agente_id)
      return res.status(400).json({ error: 'telefono y nuevo_agente_id requeridos' });

    const ahoraISO = new Date().toISOString();

    const { data: sesion } = await supabase
      .from('wa_sesiones').select('*').eq('telefono', telefono).single();

    // Reducir carga del agente origen
    if (agente_origen_id) {
      const { data: agOrig } = await supabase.from('agentes').select('carga_actual').eq('id', agente_origen_id).single();
      if (agOrig) await supabase.from('agentes').update({
        carga_actual: Math.max(0, (agOrig.carga_actual || 1) - 1)
      }).eq('id', agente_origen_id);
    }

    // Aumentar carga del nuevo agente
    const { data: agNuevo } = await supabase.from('agentes').select('carga_actual').eq('id', nuevo_agente_id).single();
    if (agNuevo) await supabase.from('agentes').update({
      carga_actual: (agNuevo.carga_actual || 0) + 1
    }).eq('id', nuevo_agente_id);

    // Agregar separador de transferencia al historial
    const history = Array.isArray(sesion?.history) ? sesion.history : [];
    history.push({
      role:    'system',
      content: `── Transferido de ${agente_origen_nombre || 'agente anterior'} a ${nuevo_agente_nombre} ──`,
      ts:      ahoraISO
    });

    // Generar nuevo saludo para el nuevo agente
    const nuevoSaludo = await generarSaludo(
      nuevo_agente_nombre,
      sesion?.resumen_nova || '',
      sesion?.nombre || '',
      sesion?.eps    || ''
    );

    await supabase.from('wa_sesiones').update({
      agente_id:        nuevo_agente_id,
      agente_nombre:    nuevo_agente_nombre,
      estado:           'escalado', // nuevo agente recibe como escalado
      transferido_de:   agente_origen_id || sesion?.agente_id,
      transferido_at:   ahoraISO,
      asignado_at:      ahoraISO,
      primera_respuesta_at: null, // reset para el nuevo agente
      saludo_sugerido:  nuevoSaludo || null,
      inactividad_aviso_at: null,
      history,
      updated_at:       ahoraISO
    }).eq('telefono', telefono);

    await logConv(telefono, nuevo_agente_id, nuevo_agente_nombre, 'transferido', null, {
      agente_origen_id,
      agente_origen_nombre
    });

    console.log(`🔄 Transferido ${telefono}: ${agente_origen_nombre} → ${nuevo_agente_nombre}`);
    res.json({ ok: true, saludo_sugerido: nuevoSaludo });

  } catch (err) {
    console.error('❌ Error transferir:', err.message);
    res.status(500).json({ error: err.message });
  }
});

app.listen(PORT, () =>
  console.log(`✅ webhook-meta-tododrogas corriendo en :${PORT}`)
);
