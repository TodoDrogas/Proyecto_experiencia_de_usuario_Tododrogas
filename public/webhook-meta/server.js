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
// CRÍTICO: saltarse multipart/form-data — multer necesita el stream intacto
app.use((req, res, next) => {
  const ct = req.headers['content-type'] || '';
  if (ct.includes('multipart/form-data')) return next();
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

// ── PUSH MENSAJE AL HISTORIAL (sin tocar updated_at) ────────────────────
async function pushHistoryNova(telefono, contenido, role = 'nova') {
  try {
    const { data: ses } = await supabase
      .from('wa_sesiones').select('history').eq('telefono', telefono).single();
    const hist = Array.isArray(ses?.history) ? ses.history : [];
    hist.push({ role, content: contenido, ts: new Date().toISOString() });
    // NO actualizar updated_at — solo el historial
    await supabase.from('wa_sesiones')
      .update({ history: hist })
      .eq('telefono', telefono);
  } catch(e) {
    console.error('❌ pushHistoryNova:', e.message);
  }
}

// ── ARCHIVAR SESIÓN EN wa_historico ──────────────────────────────────────
async function archivarEnHistorico(sesion, cerradoAt) {
  try {
    if (!sesion?.telefono) return;
    const { data: s } = await supabase.from('wa_sesiones').select('*').eq('telefono', sesion.telefono).single();
    const src = s || sesion;
    if (!Array.isArray(src.history) || src.history.length === 0) return;
    await supabase.from('wa_historico').insert({
      telefono:          src.telefono,
      nombre:            src.nombre            || '',
      eps:               src.eps               || '',
      cedula:            src.cedula            || '',
      history:           src.history,
      agente_id:         src.agente_id         || null,
      agente_nombre:     src.agente_nombre     || null,
      calificacion:      src.calificacion      || null,
      calificacion_texto: src.calificacion_texto || null,
      motivo_cierre_wa:  src.motivo_cierre_wa  || null,
      cerrado_at:        cerradoAt             || src.cerrado_at || new Date().toISOString(),
      created_at:        new Date().toISOString()
    });
    console.log(`📦 Archivado en wa_historico: ${src.telefono}`);
  } catch(e) { console.error('❌ archivarEnHistorico:', e.message); }
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
  const calRaw = respuesta.trim().toUpperCase();
  const mapaRespuesta = {'MALA':1,'MAL':1,'1':1,'REGULAR':2,'REG':2,'2':2,'BUENA':3,'BUEN':3,'BUENO':3,'3':3};
  if (!mapaRespuesta[calRaw]) return false;
  const calNum = mapaRespuesta[calRaw];
  const cal    = String(calNum);
  const textos = { 1: 'Mala', 2: 'Regular', 3: 'Buena' };
  const emojis = { 1: '😞', 2: '😐', 3: '😊' };

  const ahoraISO = new Date().toISOString();

  // Si no hay agente asignado, fue Nova quien gestionó → registrar como Nova TD
  const _agenteGestion = sesion.agente_nombre || 'Nova TD';
  const _agenteId      = sesion.agente_id || null;

  // Siempre pte_gestion para que el agente registre la gestión y cierre
  // Si no hay agente → asignar uno automáticamente
  let _agenteAsigPte = null;
  if (!sesion.agente_id) {
    _agenteAsigPte = await autoAsignarAgente(telefono, sesion);
  }

  // Fetch historial fresco antes de agregar (evitar pisar mensajes)
  const { data: _sesFresh } = await supabase
    .from('wa_sesiones').select('history').eq('telefono', telefono).single();
  const hist = Array.isArray(_sesFresh?.history) ? _sesFresh.history : (Array.isArray(sesion.history) ? sesion.history : []);
  hist.push({ role: 'user', content: cal, ts: ahoraISO });

  await supabase.from('wa_sesiones').update({
    calificacion:       calNum,
    calificacion_texto: textos[calNum],
    fecha_calificacion: ahoraISO,
    estado:             'esperando',  // agente verá al llegar y registra gestión
    cerrado_at:         null,
    motivo_cierre_wa:   'encuesta',
    agente_nombre:      _agenteAsigPte ? _agenteAsigPte.nombre : _agenteGestion,
    agente_id:          _agenteAsigPte ? _agenteAsigPte.id : (_agenteId || null),
    history:            hist,
    updated_at:         ahoraISO
  }).eq('telefono', telefono);

  // Responder al usuario
  const msg = `${emojis[calNum]} Gracias por calificarnos. Hemos registrado su atención como *${textos[calNum]}*.\n\nGracias por contactarnos. *Tododrogas, siempre a su servicio.*`;
  await enviarMeta(telefono, msg);

  // No archivar aquí — el agente registra la gestión y cierra desde el panel
  // El archivo a wa_historico ocurre en confirmarCerrar() del agente-wa.html

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
      .lt('ultima_actividad', hace6min);

    if (agentesViejos?.length) {
      for (const ag of agentesViejos) {
        await supabase.from('agentes').update({
          en_linea:         false,
          pausado:          false,
          pausado_at:       null,
          carga_actual:     0,
          ultima_actividad: ag.ultima_actividad
        }).eq('id', ag.id);
        console.log(`🔴 Agente offline (sin heartbeat): ${ag.nombre}`);
      }
    }

    // ── REGLA 2: Reasignar si agente pausado/offline 30+ min sin primera respuesta ──
    const hace30min = new Date(Date.now() - 30 * 60 * 1000).toISOString();
    const { data: sesionesSinResp } = await supabase
      .from('wa_sesiones')
      .select('telefono, nombre, agente_id, agente_nombre, asignado_at, resumen_nova, eps')
      .eq('estado', 'escalado')
      .is('primera_respuesta_at', null)
      .not('agente_id', 'is', null)
      .lt('asignado_at', hace30min);

    if (sesionesSinResp?.length) {
      for (const ses of sesionesSinResp) {
        // Verificar si el agente está offline o pausado
        const { data: agente } = await supabase.from('agentes')
          .select('en_linea, pausado').eq('id', ses.agente_id).single();
        if (!agente || (agente.en_linea && !agente.pausado)) continue; // agente activo, no tocar

        // Agente offline/pausado → reasignar
        const { data: agOld } = await supabase.from('agentes').select('carga_actual').eq('id', ses.agente_id).single();
        if (agOld) await supabase.from('agentes').update({ carga_actual: Math.max(0,(agOld.carga_actual||1)-1) }).eq('id', ses.agente_id);

        await supabase.from('wa_sesiones').update({
          agente_id: null, agente_nombre: null,
          asignado_at: null, inactividad_aviso_at: null,
          primera_respuesta_at: null, resumen_nova: null
        }).eq('telefono', ses.telefono);

        const nuevoAg = await autoAsignarAgente(ses.telefono, ses);
        const _trat = ses.nombre ? `*${ses.nombre.split(' ')[0]}*` : 'estimado usuario';
        const _m = nuevoAg
          ? `Le pedimos disculpas por la espera, ${_trat}. Hemos asignado un nuevo asesor para atenderle. Estará con usted en un momento. 🤝

*Tododrogas, siempre a su servicio.*`
          : `Le pedimos disculpas por la espera, ${_trat}. Su caso queda registrado como prioridad y le atenderemos a la brevedad.

*Tododrogas, siempre a su servicio.*`;
        await enviarMeta(ses.telefono, _m);
        await pushHistoryNova(ses.telefono, _m, 'nova');
        console.log(`🔄 Reasignado por agente pausado/offline 30min: ${ses.telefono} → ${nuevoAg?.nombre || 'sin agente'}`);
      }
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
      .in('estado', ['nova','escalado','activo','esperando','esperando_encuesta','confirmando_solucion','pte_gestion'])
      .not('updated_at','is',null);

    if (!sesiones?.length) return;

    for (const s of sesiones) {
      try {
        const minsDesdeUser = (ahora - new Date(s.updated_at).getTime()) / 60000;

        // ════════════════════════════════════════════════════════════════════
        // ESTADO: esperando_encuesta → solo verificar timeout de 10 min
        // ════════════════════════════════════════════════════════════════════
        if (s.estado === 'esperando_encuesta') {
          if (!s.encuesta_enviada_at) continue;
          const minsEnc = (ahora - new Date(s.encuesta_enviada_at).getTime()) / 60000;
          if (minsEnc >= 10) {
            const _agNombre = s.agente_nombre || 'Nova TD';
            const _sinAgente2 = !s.agente_id;
            let _agenteAsigInact = null;
            if (_sinAgente2) { _agenteAsigInact = await autoAsignarAgente(s.telefono, s); }
            // FIX: cambiar estado PRIMERO para evitar reenvío
            const { error: _errCierre } = await supabase.from('wa_sesiones').update({
              estado:             'esperando',  // agente verá al llegar y registra gestión
              calificacion:       null,
              calificacion_texto: 'Sin calificación',
              cerrado_at:         null,
              motivo_cierre_wa:   'inactividad',
              encuesta_enviada_at: null,
              agente_nombre:      _agenteAsigInact ? _agenteAsigInact.nombre : _agNombre,
              agente_id:          _agenteAsigInact ? _agenteAsigInact.id : (s.agente_id || null),
              updated_at:         ahoraISO
            }).eq('telefono', s.telefono).eq('estado', 'esperando_encuesta');
            if (_errCierre) { console.warn('⚠️ despedida ya procesada:', s.telefono); continue; }
            const _msgDespedida =
              `Cerramos su consulta, ${tratamiento(s.nombre)}. 😊

` +
              `Si necesita ayuda nuevamente, con gusto le atendemos.

` +
              `*¡Hasta pronto! Tododrogas, siempre a su servicio.*`;
            await enviarMeta(s.telefono, _msgDespedida);
            if (!_sinAgente2) {
              await archivarEnHistorico({ ...s, calificacion_texto: 'Sin calificación', motivo_cierre_wa: 'inactividad' }, ahoraISO);
            }
            await logConv(s.telefono, s.agente_id, _agNombre, 'cerrado', null,
              { motivo: 'sin_calificacion_inactividad', origen_nova: !s.agente_id });
          }
          continue; // no hacer nada más con esperando_encuesta
        }

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
              // FIX: update atómico PRIMERO — guard anti-loop
              const { error: _errAvisoNova } = await supabase.from('wa_sesiones')
                .update({ inactividad_aviso_at: ahoraISO })
                .eq('telefono', s.telefono)
                .is('inactividad_aviso_at', null);
              if (!_errAvisoNova) {
                await enviarMeta(s.telefono, _msgAviso);
                await pushHistoryNova(s.telefono, _msgAviso, 'nova');
              }
            }
          } else {
            // Ya se mandó aviso — esperar 5 min más desde el aviso
            const minsDesdeAviso = (ahora - new Date(s.inactividad_aviso_at).getTime()) / 60000;
            if (minsDesdeAviso >= 5) {
              const _msgEnc =
                `Ha sido un placer acompañarle, ${tratamiento(s.nombre)}. 😊

` +
                `Esperamos haber resuelto su consulta satisfactoriamente.

` +
                `Antes de despedirnos, ¿nos regala un momento para calificarnos?

` +
                `*MALA* → 😞
*REGULAR* → 😐
*BUENA* → 😊

` +
                `*Tododrogas, siempre a su servicio.* 🌟`;
              const { error: _errEnc1 } = await supabase.from('wa_sesiones').update({
                estado:               'esperando_encuesta',
                encuesta_enviada_at:  ahoraISO,
                inactividad_aviso_at: null,
                motivo_cierre_wa:     'inactividad'
              }).eq('telefono', s.telefono).is('encuesta_enviada_at', null);
              if (_errEnc1) { console.warn('encuesta ya enviada nova (race):', s.telefono); continue; }
              await enviarMeta(s.telefono, _msgEnc);
              await pushHistoryNova(s.telefono, _msgEnc, 'nova');
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
          // 1 aviso a los 5 min, reasignación a los 12 min
          if (s.estado === 'escalado' && s.asignado_at && !s.primera_respuesta_at) {
            const minsAsig  = (ahora - new Date(s.asignado_at).getTime()) / 60000;
            const _trat     = s.nombre ? `*${s.nombre.split(' ')[0]}*` : 'estimado usuario';
            // FIX: usar inactividad_aviso_at como flag en vez de resumen_nova
            // resumen_nova se reserva para el resumen real de la conversación
            const yaAvisado = !!s.inactividad_aviso_at;

            // Único aviso: 5+ min, sin aviso previo
            if (minsAsig >= 5 && !yaAvisado) {
              const _m = `Gracias por su paciencia, ${_trat}. 🙏\n\nSu caso es nuestra prioridad y uno de nuestros asesores estará con usted en breve.\n\n*Tododrogas, siempre a su servicio.*`;
              await enviarMeta(s.telefono, _m);
              await pushHistoryNova(s.telefono, _m, 'nova');
              await supabase.from('wa_sesiones')
                .update({ inactividad_aviso_at: ahoraISO })
                .eq('telefono', s.telefono);
              continue;
            }
            // Reasignación: 12+ min sin primera respuesta, ya se mandó aviso
            if (yaAvisado && minsAsig >= 12) {
              if (s.agente_id) {
                const { data: ag } = await supabase.from('agentes').select('carga_actual').eq('id',s.agente_id).single();
                if (ag) await supabase.from('agentes').update({ carga_actual: Math.max(0,(ag.carga_actual||1)-1) }).eq('id',s.agente_id);
              }
              await supabase.from('wa_sesiones').update({
                agente_id: null, agente_nombre: null,
                asignado_at: null, inactividad_aviso_at: null,
                primera_respuesta_at: null
                // resumen_nova NO se limpia — contiene el resumen real de la conversación
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
              const _lineaAgente = s.agente_nombre
                ? `*${s.agente_nombre}* continúa disponible para atenderle.\n\n`
                : '';
              const _msgAviso =
                `¿${tratamiento(s.nombre)}, continúa en línea?\n\n` +
                _lineaAgente +
                `Si ya resolvió su consulta, escriba *LISTO* para cerrar.`;
              // FIX: update atómico PRIMERO — si ya fue seteado por otro proceso, no reenviar
              const { error: _errAviso } = await supabase.from('wa_sesiones')
                .update({ inactividad_aviso_at: ahoraISO })
                .eq('telefono', s.telefono)
                .is('inactividad_aviso_at', null); // guard anti-loop
              if (!_errAviso) {
                await enviarMeta(s.telefono, _msgAviso);
                await pushHistoryNova(s.telefono, _msgAviso, 'nova');
              }
            }
          } else {
            const minsDesdeAviso = (ahora - new Date(s.inactividad_aviso_at).getTime()) / 60000;
            if (minsDesdeAviso >= 5) {
              const _msgEnc =
                `Ha sido un placer acompañarle, ${tratamiento(s.nombre)}. 😊

` +
                `Esperamos haber resuelto su consulta satisfactoriamente.

` +
                `Antes de despedirnos, ¿nos regala un momento para calificarnos?

` +
                `*MALA* → 😞
*REGULAR* → 😐
*BUENA* → 😊

` +
                `*Tododrogas, siempre a su servicio.* 🌟`;
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
              if (_errEnc2) { console.warn('encuesta ya enviada agente (race):', s.telefono); continue; }
              await enviarMeta(s.telefono, _msgEnc);
              await pushHistoryNova(s.telefono, _msgEnc, 'nova');
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
          // EXCEPCIÓN: casos guardados fuera de horario → mantener sesión hasta que agente responda
          if (sesion.motivo_cierre_wa === 'guardado_fuera_horario') {
            // Reabrir como 'esperando' sin resetear, Nova responde normalmente
            await supabase.from('wa_sesiones').update({
              estado:      'esperando',
              cerrado_at:  null,
              updated_at:  ahoraISO,
              unread_count: (sesion.unread_count || 0) + 1
            }).eq('telefono', telefono);
            sesion.estado = 'esperando';
            sesion._mantenerEsperando = true;
            // Continuar el flujo — Nova responderá normalmente
          } else {
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

          // FIX: igual que WhatsApp — acumular historial, nunca borrar
          // Solo limpiar campos operacionales, mantener history completo
          const histExistente = Array.isArray(sesion.history) ? sesion.history : [];

          // Agregar separador visual de nueva conversación
          const _fechaSep = new Date(ahoraISO).toLocaleDateString('es-CO',{
            weekday:'long', day:'numeric', month:'long', year:'numeric'
          });
          histExistente.push({
            role:    'system',
            content: `── Nueva conversación · ${_fechaSep} ──`,
            ts:      ahoraISO
          });
          histExistente.push(nuevoMsg);

          // Limitar a 2000 mensajes para no exceder límites de BD
          if (histExistente.length > 2000) histExistente.splice(0, histExistente.length - 2000);

          await supabase.from('wa_sesiones').update({
            history:              histExistente,
            estado:               'nova',
            unread_count:         1,
            updated_at:           ahoraISO,
            agente_id:            null,
            agente_nombre:        null,
            calificacion:         null,
            calificacion_texto:   null,
            encuesta_enviada:     null,
            encuesta_enviada_at:  null,
            cerrado_at:           null,
            motivo_cierre_wa:     null,
            inactividad_aviso_at: null,
            asignado_at:          null,
            primera_respuesta_at: null,
            transferido_de:       null,
            transferido_at:       null,
            tipificacion:         null,
            observacion_cierre:   null,
            fase:                 null,
          }).eq('telefono', telefono);

          sesion = { ...sesion, history: histExistente, estado: 'nova' };
          console.log(`🔄 Conversación nueva (>24h) acumulada: ${telefono} — ${histExistente.length} msgs totales`);
          } // end else guardado_fuera_horario
        }
      }

      // ── CONFIRMANDO SOLUCIÓN: usuario responde SÍ o NO ─────────────────
      if (sesion.estado === 'confirmando_solucion') {
        const _respConf = (body||'').toLowerCase().trim();
        const _siResp   = ['si','sí','yes','claro','correcto','exacto','efectivo',
                           'solucionado','resuelto','ok','okk','okey','perfecto',
                           'gracias si','si gracias','así es','eso es','de una'].some(p => _respConf.includes(p));
        const _noResp   = ['no','nop','nope','todavía','aún','falta','sigue',
                           'no solucionó','no resolvió','no está','no funciona'].some(p => _respConf.includes(p));

        if (_siResp) {
          // Usuario confirma → encuesta
          const ahoraConf = new Date().toISOString();
          const _msgEncConf =
            `¡Nos alegra mucho haberle ayudado, ${tratamiento(sesion.nombre)}! 😊

` +
            `Antes de despedirnos, ¿nos regala un momento para calificarnos?

` +
            `*MALA* → 😞
*REGULAR* → 😐
*BUENA* → 😊

` +
            `*Tododrogas, siempre a su servicio.* 🌟`;
          if (sesion.agente_id) {
            const { data: _agC } = await supabase.from('agentes').select('carga_actual').eq('id', sesion.agente_id).single();
            if (_agC) await supabase.from('agentes').update({ carga_actual: Math.max(0,(_agC.carga_actual||1)-1) }).eq('id', sesion.agente_id);
          }
          const { error: _eConf } = await supabase.from('wa_sesiones').update({
            estado:               'esperando_encuesta',
            encuesta_enviada_at:  ahoraConf,
            inactividad_aviso_at: null,
            motivo_cierre_wa:     'satisfaccion_usuario'
          }).eq('telefono', telefono).eq('estado', 'confirmando_solucion');
          if (!_eConf) {
            await enviarMeta(telefono, _msgEncConf);
            await pushHistoryNova(telefono, _msgEncConf, 'nova');
            await logConv(telefono, sesion.agente_id, sesion.agente_nombre||'Nova TD', 'encuesta_enviada', null, { motivo: 'confirmacion_si' });
          }
          return;
        }

        if (_noResp) {
          // Usuario dice NO → retomar conversación
          const _msgNoConf =
            `Entendemos, ${tratamiento(sesion.nombre)}. Queremos asegurarnos de resolver su caso completamente. 🤝

` +
            `¿En qué más le puedo ayudar hoy? Su caso es nuestra prioridad.`;
          // Devolver al estado anterior según si hay agente
          const _estadoRetoma = sesion.agente_id ? 'activo' : 'nova';
          await supabase.from('wa_sesiones').update({
            estado:               _estadoRetoma,
            inactividad_aviso_at: null,
            updated_at:           new Date().toISOString()
          }).eq('telefono', telefono);
          await enviarMeta(telefono, _msgNoConf);
          await pushHistoryNova(telefono, _msgNoConf, 'nova');
          return;
        }

        // Respuesta ambigua → recordar la pregunta
        await enviarMeta(telefono,
          `Por favor responda *SÍ* si su consulta fue resuelta, o *NO* si necesita más ayuda. 😊`
        );
        return;
      }

      // ── ENCUESTA: si está esperando respuesta de encuesta ────────────────
      if (sesion.estado === 'esperando_encuesta') {
        const procesado = await procesarEncuesta(telefono, body, sesion);
        if (procesado) return;
        const bodyTrim = (body||'').trim().toUpperCase();
        const mapaValido = ['MALA','MAL','REGULAR','REG','BUENA','BUEN','BUENO','1','2','3'];
        if (bodyTrim && !mapaValido.includes(bodyTrim)) {
          await enviarMeta(telefono,
            `Por favor responda con *MALA*, *REGULAR* o *BUENA* para calificarnos:

` +
            `*MALA* → 😞
*REGULAR* → 😐
*BUENA* → 😊`
          );
        }
        return;
      }

      // ── USUARIO ESCRIBE MIENTRAS ESTÁ EN ESPERA GUARDADA ───────────────
      // Si el usuario escribe cualquier cosa en estado 'esperando' con motivo
      // 'guardado_fuera_horario' → Nova responde pero el estado NO cambia,
      // el historial se acumula en el mismo registro (sin nueva sesión, sin 24h)
      if (sesion.estado === 'esperando' &&
          sesion.motivo_cierre_wa === 'guardado_fuera_horario' &&
          body.trim() !== '0') {
        // Solo dejar que el flujo llegue a Nova — pero forzar estado 'esperando'
        // para que no lo reabra como escalado/nova/pte_gestion
        // El updateData ya tiene updated_at; solo asegurar que el estado no cambie
        // Lo hacemos sobreescribiendo el estado en updateData más abajo — aquí solo marcamos flag
        sesion._mantenerEsperando = true;
      }

      // ── GUARDAR CASO FUERA DE HORARIO (usuario escribe 0) ──────────────
      if (body.trim() === '0' && sesion.estado === 'esperando' &&
          sesion.motivo_cierre_wa === 'fuera_horario_pausados') {
        const ahoraGuard = new Date().toISOString();
        // Asignar a cualquier agente para pte_gestion
        const _agGuard = await autoAsignarAgente(telefono, sesion);
        const _msgGuard =
          `✅ Su caso ha quedado registrado con *prioridad*, ${tratamiento(sesion.nombre)}.

` +
          `Uno de nuestros asesores especializados tomará su caso en el próximo horario hábil y le contactará.

` +
          `🕐 *Lunes a viernes 7:00 a.m. - 5:30 p.m.* | *Sábados 8:00 a.m. - 12:00 m.*

` +
          `*¡Hasta pronto! Tododrogas, siempre a su servicio.*`;
        await supabase.from('wa_sesiones').update({
          estado:              'esperando',
          encuesta_enviada_at: null,
          motivo_cierre_wa:    'guardado_fuera_horario',
          agente_id:           _agGuard?.id   || sesion.agente_id,
          agente_nombre:       _agGuard?.nombre || sesion.agente_nombre,
          updated_at:          ahoraGuard
        }).eq('telefono', telefono);
        await enviarMeta(telefono, _msgGuard);
        await pushHistoryNova(telefono, _msgGuard, 'nova');
        await logConv(telefono, sesion.agente_id, sesion.agente_nombre, 'guardado_fuera_horario');
        return;
      }

      // ── SALIR / LISTO: cierre voluntario del usuario ─────────────────────
      const bodyUp = body.trim().toUpperCase();
      if (['SALIR', 'LISTO', 'ADIOS', 'ADIÓS', 'CHAO'].includes(bodyUp) &&
          ['nova', 'escalado', 'activo', 'esperando'].includes(sesion.estado)) {
        await enviarMeta(telefono,
          `Gracias por contactarnos, ${tratamiento(sesion.nombre)}.\n\nAntes de cerrar, le invitamos a calificar nuestra atención:\n\n*MALA* → 😞\n*REGULAR* → 😐\n*BUENA* → 😊\n\n*Tododrogas, siempre a su servicio.*`
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

      // ── Si usuario responde al aviso de inactividad SIN agente → Nova retoma ──
      // Detectar: inactividad_aviso_at seteado + sin agente + estados nova/esperando
      if (sesion.inactividad_aviso_at && !sesion.agente_id &&
          ['nova','escalado','esperando','activo'].includes(sesion.estado)) {
        const _bodyRespAviso = (body||'').toLowerCase().trim();
        const _sigueActivo = ['si','sí','yes','aquí','ahi','acá','sigo','continúo',
          'aqui','presente','claro','ok','listo'].some(p => _bodyRespAviso.includes(p));
        if (_sigueActivo) {
          // Reset timer + Nova responde ofreciéndose a ayudar
          await supabase.from('wa_sesiones').update({
            inactividad_aviso_at: null,
            updated_at: ahoraISO
          }).eq('telefono', telefono);
          const _msgRetoma =
            `Con gusto, ${tratamiento(sesion.nombre)}. 😊

` +
            `¿En qué más le puedo ayudar hoy? Estoy aquí para lo que necesite.

` +
            `🏠 Escriba *M* → Menú principal
💬 Escriba *P* → Tengo otra pregunta`;
          await enviarMeta(telefono, _msgRetoma);
          await pushHistoryNova(telefono, _msgRetoma, 'nova');
          return;
        }
      }

      const history = Array.isArray(sesion.history) ? sesion.history : [];
      if (history.length >= 500) history.splice(0, history.length - 499);
      history.push(nuevoMsg);

      // Si hay agente y el usuario responde → volver a "activo"
      // IMPORTANTE: NO resetear inactividad_aviso_at aquí — solo el agente
      // al responder debe resetearlo. Si el usuario responde al aviso y el
      // cron reseteara el timer, volvería a enviar el aviso infinitamente.
      const updateData = {
        history,
        unread_count:        (sesion.unread_count || 0) + 1,
        updated_at:           ahoraISO,
        ...(profile && !sesion.nombre ? { nombre: profile } : {})
      };

      if (['escalado', 'esperando'].includes(sesion.estado) && sesion.agente_id &&
          !sesion._mantenerEsperando) {
        updateData.estado = 'activo';
        updateData.mensajes_usuario_ag = (sesion.mensajes_usuario_ag || 0) + 1;
        await logConv(telefono, sesion.agente_id, sesion.agente_nombre, 'mensaje_usuario');
      }
      // Mantener estado esperando para casos guardados fuera de horario
      if (sesion._mantenerEsperando) {
        updateData.estado = 'esperando';
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

      // FIX: en esperando_encuesta y confirmando_solucion NO tocar updated_at
      if (sesion.estado === 'esperando_encuesta' || sesion.estado === 'confirmando_solucion') {
        await supabase.from('wa_sesiones')
          .update({ history, unread_count: (sesion.unread_count || 0) + 1 })
          .eq('telefono', telefono);
      } else {
        await supabase.from('wa_sesiones').update(updateData).eq('telefono', telefono);
      }
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

    // ── Detectar satisfacción si hay AGENTE activo → encuesta directa ──────
    if (['escalado','activo','esperando'].includes(sesion.estado) && sesion.agente_id) {
      const _bodyLowerAg = (body||'').toLowerCase().trim();
      const _frasesSatAg = ['gracias','muchas gracias','ok gracias','no gracias',
        'así está bien','no, así está bien','ya está bien','está bien así',
        'perfecto','listo','eso era todo','ya quedé','no necesito más',
        'fue todo','ya me ayudó','con eso es suficiente','excelente gracias',
        'bien gracias','gracias por tu'];
      const _histLenAg = Array.isArray(sesion?.history) ? sesion.history.length : 0;
      if (_frasesSatAg.some(f => _bodyLowerAg.includes(f)) && _histLenAg >= 2) {
        const _nomAg = sesion.nombre ? `*${sesion.nombre.split(' ')[0]}*` : '';
        const _msgConfAg =
          `¡Con mucho gusto${_nomAg ? ', '+_nomAg : ''}! 😊

` +
          `¿La información que le brindamos resolvió su consulta el día de hoy?`;
        await supabase.from('wa_sesiones').update({
          estado:     'confirmando_solucion',
          updated_at: new Date().toISOString()
        }).eq('telefono', telefono);
        await enviarMeta(telefono, _msgConfAg);
        await pushHistoryNova(telefono, _msgConfAg, 'nova');
        return;
      }
    }

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
        const _msgConfPre =
          `¡Con mucho gusto${_nom ? ', '+_nom : ''}! 😊

` +
          `¿La información que le brindamos resolvió su consulta el día de hoy?`;
        await supabase.from('wa_sesiones').update({
          estado:     'confirmando_solucion',
          updated_at: ahoraISO
        }).eq('telefono', telefono);
        await enviarMeta(telefono, _msgConfPre);
        await pushHistoryNova(telefono, _msgConfPre, 'nova');
        console.log(`✅ Satisfacción pre-Nova → confirmando_solucion: ${telefono}`);
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
            // Nova detectó satisfacción → ir a confirmando_solucion primero
            const _nom = sesion?.nombre ? `*${sesion.nombre.split(' ')[0]}*` : '';
            const _msgConfNova2 =
              `¡Con mucho gusto${_nom ? ', '+_nom : ''}! 😊

` +
              `¿La información que le brindamos resolvió su consulta el día de hoy?`;
            await supabase.from('wa_sesiones').update({
              estado:     'confirmando_solucion',
              updated_at: ahoraISO
            }).eq('telefono', telefono);
            await enviarMeta(telefono, _msgConfNova2);
            await pushHistoryNova(telefono, _msgConfNova2, 'nova');
            console.log(`📋 Nova ENCUESTA → confirmando_solucion: ${telefono}`);
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
                // No hay agentes disponibles ahora → esperando para que el panel lo muestre
                const _trat2 = sesActual?.nombre ? `*${sesActual.nombre.split(' ')[0]}*` : 'estimado usuario';
                const _msgSinAgente =
                  `Agradecemos su paciencia, ${_trat2}. 🙏

` +
                  `En este momento todos nuestros asesores se encuentran atendiendo otras solicitudes. ` +
                  `Su caso queda registrado con prioridad y le atenderemos en breve.

` +
                  `*Tododrogas, siempre a su servicio.*`;
                await enviarMeta(telefono, _msgSinAgente);
                await pushHistoryNova(telefono, _msgSinAgente, 'nova');
                await supabase.from('wa_sesiones')
                  .update({ estado: 'esperando', updated_at: ahoraISO })
                  .eq('telefono', telefono);
              }
            } else {
              // Fuera del horario → guardar como esperando para mañana
              const _msgFuera = mensajeFueraHorario(sesActual?.nombre);
              await enviarMeta(telefono, _msgFuera);
              await pushHistoryNova(telefono, _msgFuera, 'nova');
              // Estado pte_gestion: el agente lo verá al llegar mañana
              await supabase.from('wa_sesiones')
                .update({ estado: 'esperando', updated_at: ahoraISO })
                .eq('telefono', telefono);
              console.log(`🌙 Fuera de horario: ${telefono} → esperando`);
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
      inactividad_aviso_at: null, // agente respondió → reset timer inactividad
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
    res.json({ ok: true, saludo_sugerido: nuevoSaludo, history });

  } catch (err) {
    console.error('❌ Error transferir:', err.message);
    res.status(500).json({ error: err.message });
  }
});

// ── CRON: REASIGNAR pte_gestion CUANDO HAY AGENTES DISPONIBLES ──────────
// Cada 2 min busca chats sin agente (pte_gestion) dentro de horario
// y los asigna si hay alguien disponible — garantiza que ningún chat se pierda
async function cronReasignarPteGestion() {
  try {
    if (!estaEnHorario()) return; // fuera de horario, esperar

    const { data: agentes } = await supabase
      .from('agentes').select('id').eq('activo', true).eq('en_linea', true).eq('pausado', false).limit(1);
    if (!agentes?.length) return; // sin agentes disponibles

    // Buscar sesiones pte_gestion sin agente asignado, ordenadas por antigüedad
    const { data: pendientes } = await supabase
      .from('wa_sesiones')
      .select('telefono, nombre, eps, cedula, resumen_nova')
      .eq('estado', 'esperando')
      .is('agente_id', null)
      .order('updated_at', { ascending: true })
      .limit(5);

    if (!pendientes?.length) return;

    for (const ses of pendientes) {
      const agente = await autoAsignarAgente(ses.telefono, ses);
      if (!agente) break; // sin más agentes disponibles
      const _trat = ses.nombre ? `*${ses.nombre.split(' ')[0]}*` : 'estimado usuario';
      const _m =
        `Gracias por su paciencia, ${_trat}. 🤝

` +
        `Un asesor especializado está listo para atenderle ahora.

` +
        `*Tododrogas, siempre a su servicio.*`;
      await enviarMeta(ses.telefono, _m);
      await pushHistoryNova(ses.telefono, _m, 'nova');
      console.log(`✅ pte_gestion reasignado: ${ses.telefono} → ${agente.nombre}`);
    }
  } catch(e) { console.error('❌ cronReasignarPteGestion:', e.message); }
}
setInterval(cronReasignarPteGestion, 2 * 60 * 1000);

// ── CRON: TODOS LOS AGENTES PAUSADOS ────────────────────────────────────
// Si todos están pausados dentro de horario → avisar al usuario cada 10 min
// Si se extiende fuera de horario → pedir mensaje y dejar en pte_gestion
async function cronAgentesOcupados() {
  try {
    // Solo correr si estamos en horario de atención (o justo saliendo)
    const { data: agentes } = await supabase
      .from('agentes').select('id, en_linea, pausado, activo').eq('activo', true);
    if (!agentes?.length) return;

    const hayDisponible = agentes.some(a => a.en_linea && !a.pausado);
    if (hayDisponible) return; // hay agentes activos, no hacer nada

    const todosOffline   = agentes.every(a => !a.en_linea);
    const todosPausados  = !todosOffline && agentes.every(a => !a.en_linea || a.pausado);
    if (!todosPausados && !todosOffline) return;

    const ahora   = Date.now();
    const enHora  = estaEnHorario();

    // Sesiones escaladas sin primera respuesta o activas sin agente respondiendo
    const { data: sesiones } = await supabase
      .from('wa_sesiones')
      .select('telefono, nombre, estado, agente_id, inactividad_aviso_at, asignado_at, encuesta_enviada_at')
      .in('estado', ['escalado', 'esperando'])
      .is('primera_respuesta_at', null);

    if (!sesiones?.length) return;

    for (const s of sesiones) {
      try {
        const _trat = s.nombre ? `*${s.nombre.split(' ')[0]}*` : 'estimado usuario';

        if (enHora) {
          // Dentro de horario: aviso cada 10 min
          const minsDesdeAviso = s.inactividad_aviso_at
            ? (ahora - new Date(s.inactividad_aviso_at).getTime()) / 60000
            : (ahora - new Date(s.asignado_at || ahora).getTime()) / 60000;

          if (minsDesdeAviso >= 10) {
            const _m =
              `Agradecemos su paciencia, ${_trat}. 🙏

` +
              `En este momento todos nuestros asesores se encuentran atendiendo otras solicitudes. ` +
              `Su caso es muy importante para nosotros y le atenderemos tan pronto como sea posible.

` +
              `*Tododrogas, siempre a su servicio.*`;
            await enviarMeta(s.telefono, _m);
            await pushHistoryNova(s.telefono, _m, 'nova');
            await supabase.from('wa_sesiones')
              .update({ inactividad_aviso_at: new Date(ahora).toISOString() })
              .eq('telefono', s.telefono);
          }
        } else {
          // Fuera de horario: pedir que deje su mensaje
          // Solo si no se ha enviado ya el aviso de fuera de horario
          if (!s.encuesta_enviada_at) {
            const _msgFuera =
              `${_trat}, lamentablemente nuestro horario de atención ha finalizado. 🌙

` +
              `Por favor deje su mensaje o consulta a continuación y cuando termine escriba el número *0* para guardar su caso.

` +
              `Un asesor especializado lo revisará y tomará su caso como prioridad en el próximo horario hábil.

` +
              `🕐 *Lunes a viernes 7:00 a.m. - 5:30 p.m.* | *Sábados 8:00 a.m. - 12:00 m.*`;
            await enviarMeta(s.telefono, _msgFuera);
            await pushHistoryNova(s.telefono, _msgFuera, 'nova');
            // Usar encuesta_enviada_at como flag para no reenviar
            await supabase.from('wa_sesiones').update({
              estado:              'esperando',
              encuesta_enviada_at: new Date(ahora).toISOString(),
              motivo_cierre_wa:    'fuera_horario_pausados'
            }).eq('telefono', s.telefono).is('encuesta_enviada_at', null);
          }
        }
      } catch(eS) { console.error(`❌ cronOcupados [${s.telefono}]:`, eS.message); }
    }
  } catch(e) { console.error('❌ cronAgentesOcupados:', e.message); }
}
setInterval(cronAgentesOcupados, 5 * 60 * 1000); // cada 5 min (chequea y actúa si pasaron 10)

// ── ARCHIVAR SESIÓN (llamado desde panel agente al cerrar) ──────────────
app.post('/archivar', async (req, res) => {
  const origin = req.headers.origin || req.headers.referer || '';
  if (!ALLOWED_ORIGINS.some(o => origin.startsWith(o)))
    return res.status(403).json({ error: 'Forbidden' });
  try {
    const { telefono } = req.body;
    if (!telefono) return res.status(400).json({ error: 'telefono requerido' });
    await archivarEnHistorico({ telefono }, new Date().toISOString());
    res.json({ ok: true });
  } catch(err) {
    console.error('❌ /archivar:', err.message);
    res.status(500).json({ error: err.message });
  }
});

app.listen(PORT, () =>
  console.log(`✅ webhook-meta-tododrogas corriendo en :${PORT}`)
);
