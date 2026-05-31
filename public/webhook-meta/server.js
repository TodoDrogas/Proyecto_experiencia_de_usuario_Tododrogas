// ══════════════════════════════════════════════════════════════════════════════
//  webhook-server.js — Tododrogas WhatsApp
//  v3.0 — Arquitectura limpia 3 contactos
//
//  TABLAS QUE ESCRIBE:
//  wa_sesiones       → estado vivo de cada conversación activa
//  wa_historico      → archivo de conversaciones cerradas
//  logs_conversacion → métricas de eventos por conversación
//  agentes           → carga_actual, ultima_actividad, en_linea
//
//  TABLAS QUE SOLO LEE:
//  sedes, tabla_usuarios, eps_catalogo, agentes (para asignación)
//
//  FLUJOS:
//  CONTACTO 1: Nova atiende → menú M/A → si A: busca asesor
//  CONTACTO 2: Asesor activo → sistema MUDO → panel: continúa/encuesta/cerrar
//              Sin asesor → SI/NO esperar
//  CONTACTO 3: Fuera horario → Nova atiende → menú M/A
//              A → acumula mensajes hasta # → confirmación prioritaria
// ══════════════════════════════════════════════════════════════════════════════

const express  = require('express');
const { createClient } = require('@supabase/supabase-js');
const ws       = require('ws');
const FormData = require('form-data');
const multer   = require('multer');
const upload   = multer({ storage: multer.memoryStorage(), limits: { fileSize: 16*1024*1024 } });
require('dotenv').config();

const app = express();

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

const supabase = createClient(
  process.env.SUPABASE_URL,
  process.env.SUPABASE_KEY,
  { realtime: { transport: ws } }
);

const META_TOKEN        = process.env.META_TOKEN;
const META_PHONE_ID     = process.env.META_PHONE_NUMBER_ID;
const META_VERIFY_TOKEN = process.env.META_WA_VERIFY_TOKEN;
const NOVA_TOKEN        = process.env.NOVA_TOKEN || '';
const OPENAI_KEY        = process.env.OPENAI_API_KEY || '';
const PORT              = process.env.PORT || 3000;

// ══════════════════════════════════════════════════════════════════════════════
//  UTILIDADES
// ══════════════════════════════════════════════════════════════════════════════

function nombreSeguro(nombre) {
  if (!nombre || nombre.trim().length < 3) return null;
  if (/^[\d\s+\-()]+$/.test(nombre.trim())) return null;
  return nombre.trim();
}
function trat(nombre) {
  const n = nombreSeguro(nombre);
  return n ? `*${n.split(' ')[0]}*` : 'estimado usuario';
}

const ratemap = new Map();
function rateLimit(telefono) {
  const now   = Date.now();
  const entry = ratemap.get(telefono) || { count: 0, ts: now };
  if (now - entry.ts > 60000) { entry.count = 0; entry.ts = now; }
  entry.count++;
  ratemap.set(telefono, entry);
  return entry.count > 30;
}

function estaEnHorario() {
  const now  = new Date(new Date().toLocaleString('en-US', { timeZone: 'America/Bogota' }));
  const dow  = now.getDay();
  const mins = now.getHours() * 60 + now.getMinutes();
  if (dow === 0) return false;
  if (dow >= 1 && dow <= 5) return mins >= 420 && mins < 1050;
  if (dow === 6) return mins >= 480 && mins < 720;
  return false;
}

function _mensajeMenuFueraHorario(nombre) {
  const now = new Date(new Date().toLocaleString('en-US', { timeZone: 'America/Bogota' }));
  const dow = now.getDay();
  const horario = dow === 6
    ? 'Los sábados atendemos de *8:00 a.m. a 12:00 m.*'
    : '*lunes a viernes de 7:00 a.m. a 5:30 p.m.* y *sábados de 8:00 a.m. a 12:00 m.*';
  return `Nuestro equipo de asesores está fuera de horario, ${trat(nombre)}. 🌙\n\n🕐 Atendemos de ${horario}\n\n¿Qué desea hacer?\n\n🏠 Marque *M* → Volver al menú principal (Nova TD le atiende)\n✍️ Marque *A* → Dejar un mensaje para un asesor`;
}

async function enviarMeta(telefono, mensaje) {
  const res = await fetch(`https://graph.facebook.com/v19.0/${META_PHONE_ID}/messages`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${META_TOKEN}` },
    body: JSON.stringify({
      messaging_product: 'whatsapp',
      to:   telefono.replace('+', ''),
      type: 'text',
      text: { body: mensaje }
    })
  });
  if (!res.ok) throw new Error(await res.text());
  return res.json();
}

async function pushHistory(telefono, contenido, role = 'nova') {
  try {
    const { data: ses } = await supabase.from('wa_sesiones').select('history').eq('telefono', telefono).single();
    const hist = Array.isArray(ses?.history) ? ses.history : [];
    hist.push({ role, content: contenido, ts: new Date().toISOString() });
    await supabase.from('wa_sesiones').update({ history: hist }).eq('telefono', telefono);
  } catch(e) { console.error('❌ pushHistory:', e.message); }
}

async function logConv(telefono, agenteId, agenteNombre, evento, duracion = null, meta = {}) {
  try {
    await supabase.from('logs_conversacion').insert({
      telefono,
      agente_id:     agenteId    || null,
      agente_nombre: agenteNombre || null,
      evento,
      duracion_seg:  duracion,
      metadata:      meta,
      created_at:    new Date().toISOString()
    });
  } catch(e) { console.error('❌ logConv:', e.message); }
}

async function archivarEnHistorico(sesion, cerradoAt) {
  try {
    if (!sesion?.telefono) return;
    const { data: s } = await supabase.from('wa_sesiones').select('*').eq('telefono', sesion.telefono).single();
    const src = s || sesion;
    if (!Array.isArray(src.history) || src.history.length === 0) return;
    await supabase.from('wa_historico').insert({
      telefono:           src.telefono,
      nombre:             src.nombre             || '',
      eps:                src.eps                || '',
      cedula:             src.cedula             || '',
      history:            src.history,
      agente_id:          src.agente_id          || null,
      agente_nombre:      src.agente_nombre      || null,
      calificacion:       src.calificacion       || null,
      calificacion_texto: src.calificacion_texto || null,
      motivo_cierre_wa:   src.motivo_cierre_wa   || null,
      cerrado_at:         cerradoAt              || new Date().toISOString(),
      created_at:         new Date().toISOString()
    });
  } catch(e) { console.error('❌ archivarEnHistorico:', e.message); }
}

async function generarSaludo(agenteNombre, resumenNova, nombreUsuario, eps) {
  if (!OPENAI_KEY) return null;
  try {
    const nombre = agenteNombre.split(' ')[0];
    const prompt = `Eres un asistente de Tododrogas. Genera UN saludo cálido y empático de máximo 3 líneas que el asesor ${nombre} enviará por WhatsApp al usuario ${nombreUsuario || 'el usuario'} (EPS: ${eps || 'no disponible'}). Contexto: ${resumenNova || 'solicita atención'}. Usa usted, no markdown, termina positivo.`;
    const res = await fetch('https://api.openai.com/v1/chat/completions', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${OPENAI_KEY}` },
      body: JSON.stringify({ model: 'gpt-4o-mini', max_tokens: 200, temperature: 0.7, messages: [{ role: 'user', content: prompt }] })
    });
    if (!res.ok) return null;
    const data = await res.json();
    return data.choices?.[0]?.message?.content?.trim() || null;
  } catch(e) { return null; }
}

async function autoAsignarAgente(telefono, sesion) {
  try {
    const { data: agentes } = await supabase
      .from('agentes')
      .select('id, nombre, carga_actual')
      .eq('activo', true).eq('en_linea', true).eq('pausado', false)
      .order('carga_actual', { ascending: true })
      .limit(1);
    if (!agentes?.length) return null;

    const agente   = agentes[0];
    const ahoraISO = new Date().toISOString();
    const saludo   = await generarSaludo(agente.nombre, sesion?.resumen_nova || '', sesion?.nombre || '', sesion?.eps || '');

    await supabase.from('wa_sesiones').update({
      agente_id:       agente.id,
      agente_nombre:   agente.nombre,
      estado:          'escalado',
      asignado_at:     ahoraISO,
      saludo_sugerido: saludo || null,
      updated_at:      ahoraISO
    }).eq('telefono', telefono);

    await supabase.from('agentes').update({
      carga_actual:     (agente.carga_actual || 0) + 1,
      ultima_actividad: ahoraISO
    }).eq('id', agente.id);

    await logConv(telefono, agente.id, agente.nombre, 'asignado', null, { nombre_usuario: sesion?.nombre || '' });
    console.log(`✅ Autoasignado ${telefono} → ${agente.nombre}`);
    return agente;
  } catch(err) { console.error('❌ autoAsignarAgente:', err.message); return null; }
}

// ══════════════════════════════════════════════════════════════════════════════
//  AUDIO Y MEDIA
// ══════════════════════════════════════════════════════════════════════════════

async function descargarMediaMeta(mediaId) {
  const infoRes = await fetch(`https://graph.facebook.com/v19.0/${mediaId}`, { headers: { 'Authorization': `Bearer ${META_TOKEN}` } });
  if (!infoRes.ok) throw new Error(`Meta media info: ${infoRes.status}`);
  const info = await infoRes.json();
  if (!info.url) throw new Error('Meta no devolvió URL del media');
  const fileRes = await fetch(info.url, { headers: { 'Authorization': `Bearer ${META_TOKEN}` } });
  if (!fileRes.ok) throw new Error(`Meta download: ${fileRes.status}`);
  return { buffer: Buffer.from(await fileRes.arrayBuffer()), mimeType: info.mime_type || fileRes.headers.get('content-type') || 'audio/ogg' };
}

async function subirASupabase(buffer, fileName, mimeType) {
  const { error } = await supabase.storage.from('wa-media').upload(fileName, buffer, { contentType: mimeType, upsert: true });
  if (error) throw new Error(`Storage: ${error.message}`);
  const { data: pub } = supabase.storage.from('wa-media').getPublicUrl(fileName);
  return pub.publicUrl;
}

async function procesarAudio(msg, telefono) {
  const mediaId = msg.audio?.id;
  if (!mediaId) return { content: '[Audio recibido]', tipo: 'audio', audio_url: null };
  try {
    const { buffer, mimeType } = await descargarMediaMeta(mediaId);
    const extMap = { 'audio/ogg':'ogg','audio/mpeg':'mp3','audio/mp4':'m4a','audio/wav':'wav','audio/webm':'webm','audio/aac':'aac' };
    const ext      = extMap[mimeType] || 'ogg';
    const fileName = `audios/${telefono.replace('+','')}/${Date.now()}.${ext}`;
    const audio_url = await subirASupabase(buffer, fileName, mimeType);
    let transcripcion = '[Audio recibido]';
    if (OPENAI_KEY) {
      const form = new FormData();
      form.append('file', buffer, { filename: `audio.${ext}`, contentType: mimeType });
      form.append('model', 'whisper-1');
      form.append('language', 'es');
      form.append('response_format', 'text');
      const res = await fetch('https://api.openai.com/v1/audio/transcriptions', {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${OPENAI_KEY}`, ...form.getHeaders() },
        body: form
      });
      if (res.ok) transcripcion = (await res.text()).trim() || '[Audio sin voz]';
    }
    return { content: transcripcion, tipo: 'audio', audio_url, duracion: msg.audio?.duration || null, mime_type: mimeType };
  } catch(err) {
    console.error('❌ procesarAudio:', err.message);
    return { content: '[Audio recibido — error al procesar]', tipo: 'audio', audio_url: null };
  }
}

async function procesarMedia(msg, telefono, tipo) {
  const mediaObj = msg[tipo] || {};
  const mediaId  = mediaObj.id;
  const caption  = mediaObj.caption || '';
  if (!mediaId) return { content: caption || `[${tipo}]`, tipo, media_url: null };
  try {
    const { buffer, mimeType: mime } = await descargarMediaMeta(mediaId);
    const extMap = { 'image/jpeg':'jpg','image/png':'png','image/webp':'webp','video/mp4':'mp4','application/pdf':'pdf','text/plain':'txt' };
    const ext      = extMap[mime] || mime.split('/')[1] || 'bin';
    const folder   = tipo === 'image' ? 'imagenes' : tipo === 'video' ? 'videos' : 'documentos';
    const safeName = (mediaObj.filename || `${Date.now()}.${ext}`).replace(/[^a-zA-Z0-9._-]/g,'_');
    const media_url = await subirASupabase(buffer, `${folder}/${telefono.replace('+','')}/${safeName}`, mime);
    return { content: caption || (tipo === 'image' ? '[Imagen]' : tipo === 'video' ? '[Video]' : `[Documento: ${safeName}]`), tipo, media_url, caption, mime_type: mime, file_name: safeName };
  } catch(err) { return { content: caption || `[${tipo}]`, tipo, media_url: null }; }
}

// ══════════════════════════════════════════════════════════════════════════════
//  ENCUESTA — DOS PASOS: confirmación SI/NO → calificación → cierre
// ══════════════════════════════════════════════════════════════════════════════

// PASO 1 (solo Nova): ¿se resolvió? SI → encuesta  |  NO → Nova retoma
async function procesarConfirmacionNova(telefono, respuesta, sesion) {
  const r    = respuesta.trim().toUpperCase();
  const esSI = ['SI','SÍ','YES','S','1','CLARO','OK','CORRECTO','RESUELTO','SOLUCIONADO'].some(p => r.startsWith(p) || r === p);
  const esNO = ['NO','N','2','TODAVIA','AÚN','FALTA','SIGUE'].some(p => r.startsWith(p) || r === p);
  const ahoraISO = new Date().toISOString();

  if (esSI) {
    const msgEnc = `¡Nos alegra mucho haberle ayudado! 😊\n\n¿Nos regala un momento para calificarnos?\n\n*MALA* → 😞\n*REGULAR* → 😐\n*BUENA* → 😊\n\n*Tododrogas, siempre a su servicio.* 🌟`;
    const { error } = await supabase.from('wa_sesiones').update({
      estado: 'esperando_encuesta', encuesta_enviada_at: ahoraISO,
      motivo_cierre_wa: 'satisfaccion_nova', inactividad_aviso_at: null, updated_at: ahoraISO
    }).eq('telefono', telefono).eq('estado', 'confirmando_solucion_nova');
    if (!error) { await enviarMeta(telefono, msgEnc); await pushHistory(telefono, msgEnc, 'nova'); }
    return true;
  }
  if (esNO) {
    const msgRetoma = `Entendemos, ${trat(sesion.nombre)}. Continuamos para ayudarle. 🤝\n\n¿En qué más le puedo asistir?\n\n🏠 Marque *M* → Menú principal\n👤 Marque *A* → Hablar con un asesor`;
    await supabase.from('wa_sesiones').update({ estado: 'nova', fase: 'esperando_menu_post_nova', updated_at: ahoraISO }).eq('telefono', telefono);
    await enviarMeta(telefono, msgRetoma); await pushHistory(telefono, msgRetoma, 'nova');
    return true;
  }
  await enviarMeta(telefono, `Por favor responda *SI* si su consulta fue resuelta o *NO* si necesita más ayuda.`);
  return true;
}

// PASO 2: calificación MALA/REGULAR/BUENA → despedida personalizada → cerrar
async function procesarEncuesta(telefono, respuesta, sesion) {
  const calRaw = respuesta.trim().toUpperCase();
  const mapa   = { 'MALA':1,'MAL':1,'1':1,'REGULAR':2,'REG':2,'2':2,'BUENA':3,'BUEN':3,'BUENO':3,'3':3 };
  if (!mapa[calRaw]) return false;

  const calNum = mapa[calRaw];
  const textos = { 1:'Mala', 2:'Regular', 3:'Buena' };
  const despedida = {
    1: `Lamentamos que su experiencia no haya sido la mejor, ${trat(sesion.nombre)}. 😔\n\nHemos registrado su calificación y trabajaremos para mejorar. Si tiene alguna observación adicional, puede escribirnos cuando lo desee.\n\n*Tododrogas, siempre a su servicio.*`,
    2: `Gracias por su calificación, ${trat(sesion.nombre)}. 😐\n\nNos comprometemos a mejorar su experiencia en la próxima atención.\n\n*¡Hasta pronto! Tododrogas, siempre a su servicio.*`,
    3: `¡Nos alegra mucho haberle ayudado, ${trat(sesion.nombre)}! 😊\n\nFue un placer atenderle. Recuerde que estamos disponibles cuando nos necesite.\n\n*¡Hasta pronto! Tododrogas, siempre a su servicio.* 🌟`
  };
  const ahoraISO = new Date().toISOString();

  const { data: sesFresh } = await supabase.from('wa_sesiones').select('*').eq('telefono', telefono).single();
  const hist = Array.isArray(sesFresh?.history) ? sesFresh.history : [];
  hist.push({ role: 'user', content: calRaw, ts: ahoraISO });
  hist.push({ role: 'nova', content: despedida[calNum], ts: ahoraISO });

  await supabase.from('wa_sesiones').update({
    calificacion: calNum, calificacion_texto: textos[calNum], fecha_calificacion: ahoraISO,
    estado: 'cerrado', cerrado_at: ahoraISO,
    motivo_cierre_wa: sesFresh?.motivo_cierre_wa || 'encuesta',
    encuesta_enviada_at: sesFresh?.encuesta_enviada_at || ahoraISO,
    history: hist, inactividad_aviso_at: null, fase: null, updated_at: ahoraISO
  }).eq('telefono', telefono);

  await enviarMeta(telefono, despedida[calNum]);

  if (sesion.agente_id) {
    const { data: ag } = await supabase.from('agentes').select('carga_actual').eq('id', sesion.agente_id).single();
    if (ag) await supabase.from('agentes').update({ carga_actual: Math.max(0, (ag.carga_actual || 1) - 1) }).eq('id', sesion.agente_id);
  }
  await archivarEnHistorico({ ...sesion, history: hist, calificacion: calNum, calificacion_texto: textos[calNum], motivo_cierre_wa: sesFresh?.motivo_cierre_wa || 'encuesta', cerrado_at: ahoraISO }, ahoraISO);
  await logConv(telefono, sesion.agente_id, sesion.agente_nombre || 'Nova TD', 'cerrado', null, { motivo: 'encuesta', calificacion: calNum, origen_nova: !sesion.agente_id });
  console.log(`✅ Sesión cerrada ${telefono}: ${textos[calNum]}`);
  return true;
}

// ══════════════════════════════════════════════════════════════════════════════
//  WEBHOOK GET — verificación Meta
// ══════════════════════════════════════════════════════════════════════════════
app.get('/', (req, res) => res.json({ status: 'ok', service: 'webhook-tododrogas', ts: new Date().toISOString() }));
app.get('/webhook/meta', (req, res) => {
  const { 'hub.mode': mode, 'hub.verify_token': token, 'hub.challenge': challenge } = req.query;
  if (mode === 'subscribe' && token === META_VERIFY_TOKEN) return res.status(200).send(challenge);
  res.sendStatus(403);
});

// ══════════════════════════════════════════════════════════════════════════════
//  WEBHOOK POST — lógica principal
// ══════════════════════════════════════════════════════════════════════════════
app.post('/webhook/meta', async (req, res) => {
  res.sendStatus(200);
  try {
    const msg      = req.body?.entry?.[0]?.changes?.[0]?.value?.messages?.[0];
    if (!msg) return;
    const telefono = '+' + msg.from;
    const tipo     = msg.type;
    const profile  = req.body?.entry?.[0]?.changes?.[0]?.value?.contacts?.[0]?.profile?.name || '';
    if (rateLimit(telefono)) return;

    let { data: sesion } = await supabase.from('wa_sesiones').select('*').eq('telefono', telefono).single();
    const ahoraISO = new Date().toISOString();

    // Procesar tipo de mensaje
    let body     = msg.text?.body || '';
    let nuevoMsg = { role: 'user', content: body || `[${tipo}]`, ts: ahoraISO };
    if (tipo === 'audio') {
      const d = await procesarAudio(msg, telefono); body = d.content; nuevoMsg = { role:'user', ts:ahoraISO, ...d };
    } else if (['image','document','video'].includes(tipo)) {
      const d = await procesarMedia(msg, telefono, tipo); body = d.content; nuevoMsg = { role:'user', ts:ahoraISO, ...d };
    } else if (!body) { body = `[${tipo}]`; nuevoMsg = { role:'user', content:body, tipo, ts:ahoraISO }; }

    // ── NUEVA SESIÓN ──────────────────────────────────────────────────────────
    if (!sesion) {
      await supabase.from('wa_sesiones').upsert({
        telefono, history:[nuevoMsg], estado:'nova', nombre:profile||null, unread_count:1, updated_at:ahoraISO
      });
      sesion = { telefono, history:[nuevoMsg], estado:'nova', nombre:profile||null };

    } else {

      // ── SESIÓN CERRADA ──────────────────────────────────────────────────────
      if (sesion.estado === 'cerrado') {
        const horasDesde = sesion.cerrado_at ? (Date.now() - new Date(sesion.cerrado_at).getTime()) / 3600000 : 999;
        const hist = Array.isArray(sesion.history) ? sesion.history : [];
        hist.push(nuevoMsg);
        if (horasDesde <= 24) {
          await supabase.from('wa_sesiones').update({
            estado:'nova', history:hist, unread_count:(sesion.unread_count||0)+1,
            inactividad_aviso_at:null, cerrado_at:null, fase:null, updated_at:ahoraISO
          }).eq('telefono', telefono);
          sesion = { ...sesion, estado:'nova', history:hist, fase:null };
        } else {
          const fechaSep = new Date(ahoraISO).toLocaleDateString('es-CO',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
          hist.push({ role:'system', content:`── Nueva conversación · ${fechaSep} ──`, ts:ahoraISO });
          if (hist.length > 2000) hist.splice(0, hist.length - 2000);
          await supabase.from('wa_sesiones').update({
            history:hist, estado:'nova', unread_count:1, updated_at:ahoraISO,
            agente_id:null, agente_nombre:null, calificacion:null, calificacion_texto:null,
            encuesta_enviada_at:null, cerrado_at:null, motivo_cierre_wa:null, inactividad_aviso_at:null,
            asignado_at:null, primera_respuesta_at:null, tipificacion:null, observacion_cierre:null, fase:null
          }).eq('telefono', telefono);
          sesion = { ...sesion, history:hist, estado:'nova', fase:null };
        }
      }

      // ── CONFIRMACIÓN SI/NO (solo Nova) ──────────────────────────────────────
      if (sesion.estado === 'confirmando_solucion_nova') {
        await procesarConfirmacionNova(telefono, body, sesion); return;
      }

      // ── ENCUESTA DE CALIFICACIÓN ────────────────────────────────────────────
      if (sesion.estado === 'esperando_encuesta') {
        const ok = await procesarEncuesta(telefono, body, sesion);
        if (!ok) await enviarMeta(telefono, 'Por favor responda con *MALA*, *REGULAR* o *BUENA*:\n\n*MALA* → 😞\n*REGULAR* → 😐\n*BUENA* → 😊');
        return;
      }

      // ── ASESOR ACTIVO — SISTEMA MUDO ────────────────────────────────────────
      if (sesion.agente_id && sesion.primera_respuesta_at && ['escalado','activo','esperando'].includes(sesion.estado)) {
        const hist = Array.isArray(sesion.history) ? sesion.history : [];
        hist.push(nuevoMsg);
        await supabase.from('wa_sesiones').update({
          history:hist, estado:'activo', unread_count:(sesion.unread_count||0)+1,
          updated_at:ahoraISO, mensajes_usuario_ag:(sesion.mensajes_usuario_ag||0)+1,
          ...(profile && !sesion.nombre ? { nombre:profile } : {})
        }).eq('telefono', telefono);
        await logConv(telefono, sesion.agente_id, sesion.agente_nombre, 'mensaje_usuario');
        return;
      }

      // ── DECISIÓN SI/NO ESPERA ───────────────────────────────────────────────
      if (sesion.fase === 'esperando_decision_espera') {
        const resp = body.trim().toUpperCase().replace(/[^A-ZÁÉÍÓÚN0-9]/g,'');
        const esSI = ['SI','SÍ','YES','1','CLARO','OK','OKEY','ESPERO','ESPERANDO'].some(p => resp.includes(p));
        const esNO = ['NO','2','NOPE','MENU','M'].some(p => resp.includes(p));
        if (esSI) {
          const m = `Perfecto, ${trat(sesion.nombre)}. Le avisamos en cuanto un asesor esté disponible. 🙏\n\n*Tododrogas, siempre a su servicio.*`;
          await supabase.from('wa_sesiones').update({ fase:null, inactividad_aviso_at:ahoraISO, updated_at:ahoraISO }).eq('telefono', telefono);
          await enviarMeta(telefono, m); await pushHistory(telefono, m, 'nova'); return;
        }
        if (esNO) {
          if (sesion.agente_id && !sesion.primera_respuesta_at) {
            const { data: ag } = await supabase.from('agentes').select('carga_actual').eq('id', sesion.agente_id).single();
            if (ag) await supabase.from('agentes').update({ carga_actual: Math.max(0,(ag.carga_actual||1)-1) }).eq('id', sesion.agente_id);
          }
          await supabase.from('wa_sesiones').update({
            estado:'nova', fase:null, agente_id:null, agente_nombre:null,
            asignado_at:null, inactividad_aviso_at:null, primera_respuesta_at:null, updated_at:ahoraISO
          }).eq('telefono', telefono);
          sesion = { ...sesion, estado:'nova', fase:null, agente_id:null, agente_nombre:null };
          const hist = Array.isArray(sesion.history) ? sesion.history : [];
          hist.push(nuevoMsg); sesion.history = hist;
        } else {
          await enviarMeta(telefono, `Por favor responda *SI* para seguir esperando o *NO* para volver al menú principal.`); return;
        }
      }

      // ── TOMANDO MENSAJE FUERA DE HORARIO ────────────────────────────────────
      // BUG FIX: el # puede venir solo, con espacios, o PEGADO al texto
      // ("Por favor me ayudan con mi medicamento#") — body.trim()==='#' fallaba en ese caso
      if (sesion.fase === 'tomando_mensaje_fh') {
        const bodyTrimmed  = body.trim();
        const terminaHash  = bodyTrimmed === '#' || bodyTrimmed.endsWith('#');
        // Si el # viene pegado al texto, guardar el texto limpio sin el símbolo
        const textoLimpio  = terminaHash && bodyTrimmed !== '#'
          ? bodyTrimmed.slice(0, -1).trim()
          : bodyTrimmed;
        // Guardar el mensaje con el contenido real (sin el # final)
        const msgParaHist  = textoLimpio ? { ...nuevoMsg, content: textoLimpio } : nuevoMsg;
        const hist = Array.isArray(sesion.history) ? sesion.history : [];
        hist.push(msgParaHist);

        if (terminaHash) {
          // Usuario terminó — guardar y confirmar
          // Intentar asignar agente — si no hay disponible queda en cola para el primero que entre
          const agAsignado = await autoAsignarAgente(telefono, sesion);

          const msgGuardado =
            `✅ *Su caso ha quedado registrado con atención prioritaria*, ${trat(sesion.nombre)}.\n\n` +
            `Uno de nuestros asesores especializados tomará su caso en el próximo horario hábil y se comunicará con usted directamente.\n\n` +
            `🕐 *Lunes a viernes 7:00 a.m. – 5:30 p.m.* | *Sábados 8:00 a.m. – 12:00 m.*\n\n` +
            `*¡Hasta pronto! Tododrogas, siempre a su servicio.*`;

          await supabase.from('wa_sesiones').update({
            history:          hist,
            estado:           'esperando',
            fase:             null,
            motivo_cierre_wa: 'guardado_fuera_horario',
            agente_id:        agAsignado?.id     || null,
            agente_nombre:    agAsignado?.nombre || null,
            updated_at:       ahoraISO
          }).eq('telefono', telefono);

          await enviarMeta(telefono, msgGuardado);
          await pushHistory(telefono, msgGuardado, 'nova');
          await logConv(telefono, agAsignado?.id||null, agAsignado?.nombre||null, 'guardado_fuera_horario');
          console.log(`📩 Mensaje FH guardado: ${telefono} — "${textoLimpio.substring(0,60)}..."`);
        } else {
          // Acumula silenciosamente — no responde nada
          await supabase.from('wa_sesiones').update({ history:hist, updated_at:ahoraISO }).eq('telefono', telefono);
        }
        return;
      }

      // ── MENÚ POST-NOVA (M/A) ────────────────────────────────────────────────
      if (sesion.fase === 'esperando_menu_post_nova') {
        const resp = body.trim().toUpperCase();
        const esM  = ['M','MENU','MENÚ','1'].includes(resp) || resp.startsWith('M');
        const esA  = ['A','ASESOR','2'].includes(resp) || resp.startsWith('A');
        if (esM) {
          await supabase.from('wa_sesiones').update({ estado:'nova', fase:null, updated_at:ahoraISO }).eq('telefono', telefono);
          sesion = { ...sesion, estado:'nova', fase:null };
          const hist = Array.isArray(sesion.history)?sesion.history:[];
          hist.push(nuevoMsg); sesion.history = hist;
        } else if (esA) {
          await supabase.from('wa_sesiones').update({ fase:null, updated_at:ahoraISO }).eq('telefono', telefono);
          if (estaEnHorario()) {
            const sesActual = (await supabase.from('wa_sesiones').select('*').eq('telefono', telefono).single()).data;
            const agente = await autoAsignarAgente(telefono, sesActual || sesion);
            let msgEsc;
            if (agente) {
              msgEsc = `Ha sido un gusto acompañarle, ${trat(sesion.nombre)}. 🤝\n\nLe conectamos con un asesor especializado que revisará su caso.\n\nPor favor espere un momento. 🙏\n\n*Tododrogas, siempre a su servicio.*`;
            } else {
              msgEsc = `${trat(sesion.nombre)}, en este momento todos nuestros asesores están ocupados. ⏳\n\n¿Desea seguir esperando?\n\nResponda *SI* para continuar en espera\nResponda *NO* para volver al menú principal`;
              await supabase.from('wa_sesiones').update({ estado:'esperando', fase:'esperando_decision_espera', inactividad_aviso_at:ahoraISO, updated_at:ahoraISO }).eq('telefono', telefono);
            }
            await enviarMeta(telefono, msgEsc); await pushHistory(telefono, msgEsc, 'nova');
          } else {
            const msgFH = _mensajeMenuFueraHorario(sesion.nombre);
            await supabase.from('wa_sesiones').update({ fase:'esperando_menu_fh', updated_at:ahoraISO }).eq('telefono', telefono);
            await enviarMeta(telefono, msgFH); await pushHistory(telefono, msgFH, 'nova');
          }
          return;
        } else {
          await enviarMeta(telefono, `Por favor marque *M* para volver al menú principal o *A* para hablar con un asesor.`); return;
        }
      }

      // ── MENÚ FUERA DE HORARIO (M/A) ─────────────────────────────────────────
      if (sesion.fase === 'esperando_menu_fh') {
        const resp = body.trim().toUpperCase();
        const esM  = ['M','MENU','MENÚ','1'].includes(resp) || resp.startsWith('M');
        const esA  = ['A','ASESOR','2'].includes(resp) || resp.startsWith('A');
        if (esM) {
          await supabase.from('wa_sesiones').update({ estado:'nova', fase:null, updated_at:ahoraISO }).eq('telefono', telefono);
          sesion = { ...sesion, estado:'nova', fase:null };
          const hist = Array.isArray(sesion.history)?sesion.history:[];
          hist.push(nuevoMsg); sesion.history = hist;
        } else if (esA) {
          const msgDejarMensaje = `Entendido, ${trat(sesion.nombre)}. ✍️\n\nEscriba su mensaje a continuación. Puede enviar varios mensajes.\n\nCuando termine, escriba *#* para guardarlo y un asesor le contactará en el próximo horario hábil.`;
          await supabase.from('wa_sesiones').update({ estado:'esperando', fase:'tomando_mensaje_fh', updated_at:ahoraISO }).eq('telefono', telefono);
          await enviarMeta(telefono, msgDejarMensaje); await pushHistory(telefono, msgDejarMensaje, 'nova'); return;
        } else {
          await enviarMeta(telefono, `Por favor marque *M* para volver al menú principal o *A* para dejar un mensaje.`); return;
        }
      }

      // ── Agente asignado sin responder aún — acumula ─────────────────────────
      if (['escalado','esperando'].includes(sesion.estado) && sesion.agente_id) {
        const hist = Array.isArray(sesion.history) ? sesion.history : [];
        hist.push(nuevoMsg);
        await supabase.from('wa_sesiones').update({
          history:hist, unread_count:(sesion.unread_count||0)+1, updated_at:ahoraISO,
          ...(profile && !sesion.nombre ? { nombre:profile } : {})
        }).eq('telefono', telefono);
        return;
      }
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  NOVA TD
    // ══════════════════════════════════════════════════════════════════════════
    if ((sesion?.estado || 'nova') !== 'nova') return;

    // ── INTERCEPTAR MENÚ NUMERADO 1-8 ────────────────────────────────────────
    // BUG FIX: cuando Nova emite el menú 1-8 (case MENU del PHP), el usuario
    // responde con un número. Si ese número llega crudo a GPT, GPT no sabe
    // que es una navegación de menú — lo interpreta como mensaje libre y
    // repite la respuesta anterior o genera *1*/*2* propios.
    // Solución: interceptar aquí y convertir el número en la acción correcta
    // ANTES de llamar a Nova PHP.
    {
      const bodyNum = body.trim();
      const ultimoNova = (() => {
        const hist = Array.isArray(sesion?.history) ? sesion.history : [];
        for (let i = hist.length - 1; i >= 0; i--) {
          if (hist[i].role === 'nova') return hist[i].content || '';
        }
        return '';
      })();
      // Detectar si el último mensaje de Nova era un menú numerado (contiene 1️⃣ o *1*)
      const eraMenúNumerado = /1️⃣|2️⃣|\*1\*|\*2\*/.test(ultimoNova);

      if (eraMenúNumerado && /^[1-8]$/.test(bodyNum)) {
        const mapMenuNova = {
          '1': 'MEDICAMENTOS',  // Estado/entrega medicamentos
          '2': 'SEDES',         // Puntos de dispensación
          '3': 'REQUISITOS',    // Requisitos para reclamar
          '4': 'FORMULARIO',    // Radicar PQRSFD
          '5': 'CONSULTAR',     // Estado radicado
          '6': 'HORARIOS',      // Horarios y canales
          '7': 'ENCUESTA',      // Encuesta de satisfacción
          '8': 'DEFAULT',       // Pregunta libre a Nova
        };
        const accionMenu = mapMenuNova[bodyNum];
        console.log(`🎯 Menú numerado interceptado: opción ${bodyNum} → ${accionMenu}`);

        // Actualizar historial con la selección del usuario
        const histMenu = Array.isArray(sesion?.history) ? sesion.history : [];
        if (!histMenu.find(m => m.content === nuevoMsg.content && m.ts === nuevoMsg.ts)) {
          histMenu.push(nuevoMsg);
        }
        await supabase.from('wa_sesiones').update({ history: histMenu, updated_at: ahoraISO }).eq('telefono', telefono);

        if (accionMenu === 'MEDICAMENTOS') {
          // Dar info + menú M/A — NO generar *1*/*2* propios
          const msgMed = `*${sesion?.nombre?.split(' ')[0] || 'estimado usuario'}*, para consultar el estado de sus medicamentos, entregas pendientes o historial de dispensación, ingrese a nuestra plataforma:

🌐 *App Solicitudes Web:*
https://dispensacion.tododrogas.com.co:8443/AppSolicitudesWebJavaSQLServer/com.appsolicitudesweb.appsolicitudweb

Si necesita que un asesor verifique directamente en el sistema, marque *A* en el menú de abajo.`;
          await enviarMeta(telefono, msgMed);
          await pushHistory(telefono, msgMed, 'nova');
          await supabase.from('wa_sesiones').update({ fase: 'esperando_menu_post_nova', updated_at: ahoraISO }).eq('telefono', telefono);
          const msgM = `¿En qué más le puedo ayudar?

🏠 Marque *M* → Menú principal
👤 Marque *A* → Hablar con un asesor`;
          await enviarMeta(telefono, msgM);
          await pushHistory(telefono, msgM, 'nova');
          return;
        }
        if (accionMenu === 'SEDES') {
          const ciudad = sesion?.ciudad || '';
          if (ciudad) {
            // Llamar a Nova PHP con una pregunta de sedes explícita
            body = `¿Dónde puedo recoger mi medicamento en ${ciudad}? [SEDES:${ciudad}]`;
          } else {
            const msgSedes = `¿En qué municipio se encuentra para mostrarle las sedes más cercanas?`;
            await enviarMeta(telefono, msgSedes);
            await pushHistory(telefono, msgSedes, 'nova');
            await supabase.from('wa_sesiones').update({ fase: 'esperando_menu_post_nova', updated_at: ahoraISO }).eq('telefono', telefono);
            const msgM = `¿En qué más le puedo ayudar?

🏠 Marque *M* → Menú principal
👤 Marque *A* → Hablar con un asesor`;
            await enviarMeta(telefono, msgM); await pushHistory(telefono, msgM, 'nova');
            return;
          }
        }
        if (accionMenu === 'REQUISITOS') {
          const msgReq = `📋 *Requisitos para reclamar medicamentos:*

• Fórmula médica vigente
• Documento de identidad
• Carné de afiliación a la EPS
• En caso de representante: autorización escrita + copia del documento del paciente

¿Necesita más información? 📞 604 322 2432`;
          await enviarMeta(telefono, msgReq); await pushHistory(telefono, msgReq, 'nova');
          await supabase.from('wa_sesiones').update({ fase: 'esperando_menu_post_nova', updated_at: ahoraISO }).eq('telefono', telefono);
          const msgM = `¿En qué más le puedo ayudar?

🏠 Marque *M* → Menú principal
👤 Marque *A* → Hablar con un asesor`;
          await enviarMeta(telefono, msgM); await pushHistory(telefono, msgM, 'nova');
          return;
        }
        if (accionMenu === 'FORMULARIO') {
          const cedula = sesion?.cedula || '';
          const url = cedula ? `https://tododrogas.online/pqr_form.html?cedula=${cedula}` : 'https://tododrogas.online/pqr_form.html';
          const msgForm = `📋 *Radicar PQRSFD:*

${url}`;
          await enviarMeta(telefono, msgForm); await pushHistory(telefono, msgForm, 'nova');
          await supabase.from('wa_sesiones').update({ fase: 'esperando_menu_post_nova', updated_at: ahoraISO }).eq('telefono', telefono);
          const msgM = `¿En qué más le puedo ayudar?

🏠 Marque *M* → Menú principal
👤 Marque *A* → Hablar con un asesor`;
          await enviarMeta(telefono, msgM); await pushHistory(telefono, msgM, 'nova');
          return;
        }
        if (accionMenu === 'CONSULTAR') {
          const msgCon = `Por favor indíqueme el número de radicado (formato TD-xxxxx) o su correo electrónico para consultar el estado de su PQRSFD.`;
          await enviarMeta(telefono, msgCon); await pushHistory(telefono, msgCon, 'nova');
          await supabase.from('wa_sesiones').update({ fase: 'esperando_menu_post_nova', updated_at: ahoraISO }).eq('telefono', telefono);
          const msgM = `¿En qué más le puedo ayudar?

🏠 Marque *M* → Menú principal
👤 Marque *A* → Hablar con un asesor`;
          await enviarMeta(telefono, msgM); await pushHistory(telefono, msgM, 'nova');
          return;
        }
        if (accionMenu === 'HORARIOS') {
          const msgHor = `🕐 *Horarios de atención:*

📅 Lunes a Viernes: 7:00 a.m. – 5:30 p.m.
📅 Sábados: 8:00 a.m. – 12:00 m.

📞 PBX: 604 322 2432
💬 WhatsApp: 304 341 2431
📧 pqrsfd@tododrogas.com.co`;
          await enviarMeta(telefono, msgHor); await pushHistory(telefono, msgHor, 'nova');
          await supabase.from('wa_sesiones').update({ fase: 'esperando_menu_post_nova', updated_at: ahoraISO }).eq('telefono', telefono);
          const msgM = `¿En qué más le puedo ayudar?

🏠 Marque *M* → Menú principal
👤 Marque *A* → Hablar con un asesor`;
          await enviarMeta(telefono, msgM); await pushHistory(telefono, msgM, 'nova');
          return;
        }
        if (accionMenu === 'ENCUESTA') {
          // Opción 7: disparar flujo de satisfacción
          const msgEnc = `¿La información que le brindamos resolvió su consulta hoy?

Responda *SI* o *NO*`;
          await supabase.from('wa_sesiones').update({ estado: 'confirmando_solucion_nova', inactividad_aviso_at: null, updated_at: ahoraISO }).eq('telefono', telefono).not('estado','eq','confirmando_solucion_nova');
          await enviarMeta(telefono, msgEnc); await pushHistory(telefono, msgEnc, 'nova');
          return;
        }
        // accionMenu === 'DEFAULT' (opción 8 = pregunta libre): cae a Nova PHP normalmente
        // body ya tiene el número original — Nova lo entenderá como pregunta libre
      }
    }

    const history = Array.isArray(sesion?.history) ? sesion.history : [];
    if (!history.find(m => m.content === nuevoMsg.content && m.ts === nuevoMsg.ts)) history.push(nuevoMsg);
    if (history.length >= 500) history.splice(0, history.length - 499);

    await supabase.from('wa_sesiones').update({
      history, unread_count:(sesion?.unread_count||0)+1, updated_at:ahoraISO,
      ...(profile && !sesion?.nombre ? { nombre:profile } : {})
    }).eq('telefono', telefono);

    try {
      const sesionActual = (await supabase.from('wa_sesiones').select('*').eq('telefono', telefono).single()).data;
      const novaRes = await fetch('https://tododrogas.online/nova-wa.php', {
        method:'POST', headers:{'Content-Type':'application/json','X-Nova-Token':NOVA_TOKEN},
        body: JSON.stringify({ telefono, mensaje:body, sesion:sesionActual })
      });
      if (!novaRes.ok) { console.error('❌ Nova HTTP:', novaRes.status); return; }

      const novaData = await novaRes.json();
      const respuesta = novaData.respuesta;
      const accion    = novaData.accion || 'DEFAULT';

      if (respuesta && accion !== 'ESCALADO') {
        await enviarMeta(telefono, respuesta);
        const { data: sf } = await supabase.from('wa_sesiones').select('history').eq('telefono', telefono).single();
        const h = Array.isArray(sf?.history)?sf.history:[];
        h.push({ role:'nova', content:respuesta, ts:ahoraISO });
        await supabase.from('wa_sesiones').update({ history:h }).eq('telefono', telefono);
      }

      // ENCUESTA: Nova detectó satisfacción → confirmar primero SI/NO
      if (accion === 'ENCUESTA') {
        const msgConfirm = `¿La información que le brindamos resolvió su consulta hoy?\n\nResponda *SI* o *NO*`;
        const { error } = await supabase.from('wa_sesiones').update({
          estado:'confirmando_solucion_nova', inactividad_aviso_at:null, updated_at:ahoraISO
        }).eq('telefono', telefono).not('estado','eq','confirmando_solucion_nova');
        if (!error) { await enviarMeta(telefono, msgConfirm); await pushHistory(telefono, msgConfirm, 'nova'); }
        return;
      }

      // ESCALADO
      if (accion === 'ESCALADO') {
        if (estaEnHorario()) {
          const sesActual = (await supabase.from('wa_sesiones').select('*').eq('telefono', telefono).single()).data;
          const agente = await autoAsignarAgente(telefono, sesActual);
          if (agente) {
            const m = `Ha sido un gusto acompañarle, ${trat(sesActual?.nombre)}. 🤝\n\nLe conectamos con un asesor especializado.\n\nPor favor espere un momento. 🙏\n\n*Tododrogas, siempre a su servicio.*`;
            await enviarMeta(telefono, m); await pushHistory(telefono, m, 'nova');
          } else {
            const m = `${trat(sesActual?.nombre)}, en este momento todos nuestros asesores están ocupados. ⏳\n\n¿Desea seguir esperando?\n\nResponda *SI* para continuar en espera\nResponda *NO* para volver al menú principal`;
            await supabase.from('wa_sesiones').update({ estado:'esperando', fase:'esperando_decision_espera', inactividad_aviso_at:ahoraISO, updated_at:ahoraISO }).eq('telefono', telefono);
            await enviarMeta(telefono, m); await pushHistory(telefono, m, 'nova');
          }
        } else {
          const sesActual = (await supabase.from('wa_sesiones').select('*').eq('telefono', telefono).single()).data;
          const msgFH = _mensajeMenuFueraHorario(sesActual?.nombre);
          await supabase.from('wa_sesiones').update({ fase:'esperando_menu_fh', updated_at:ahoraISO }).eq('telefono', telefono);
          await enviarMeta(telefono, msgFH); await pushHistory(telefono, msgFH, 'nova');
        }
        return;
      }

      // DEFAULT: agregar menú M/A
      if (accion === 'DEFAULT' || !accion) {
        await supabase.from('wa_sesiones').update({ fase:'esperando_menu_post_nova', updated_at:ahoraISO }).eq('telefono', telefono);
        if (respuesta) {
          const msgMenu = `¿En qué más le puedo ayudar?\n\n🏠 Marque *M* → Menú principal\n👤 Marque *A* → Hablar con un asesor`;
          await enviarMeta(telefono, msgMenu); await pushHistory(telefono, msgMenu, 'nova');
        }
      }
    } catch(err) { console.error('❌ Error Nova TD:', err.message); }
  } catch(err) { console.error('❌ Error webhook:', err.message); }
});

// ══════════════════════════════════════════════════════════════════════════════
//  CRONS
// ══════════════════════════════════════════════════════════════════════════════

// CRON 1: Agentes offline (sin heartbeat 6+ min)
async function cronAgentesOffline() {
  try {
    const hace6min = new Date(Date.now() - 6*60*1000).toISOString();
    const { data: viejos } = await supabase.from('agentes').select('id,nombre').eq('en_linea',true).eq('activo',true).lt('ultima_actividad',hace6min);
    if (viejos?.length) for (const ag of viejos) {
      await supabase.from('agentes').update({ en_linea:false, pausado:false, carga_actual:0 }).eq('id',ag.id);
      console.log(`🔴 Agente offline: ${ag.nombre}`);
    }
  } catch(e) { console.error('❌ cronAgentesOffline:', e.message); }
}
setInterval(cronAgentesOffline, 3*60*1000);
setTimeout(cronAgentesOffline, 10000);

// CRON 2: Reasignar sesiones esperando cuando hay agentes libres
// Recoge: sesiones sin agente + sesiones guardadas fuera de horario sin agente
async function cronReasignar() {
  try {
    if (!estaEnHorario()) return;
    const { data: agentes } = await supabase.from('agentes').select('id').eq('activo',true).eq('en_linea',true).eq('pausado',false).limit(1);
    if (!agentes?.length) return;

    // Buscar todas las sesiones esperando sin agente asignado, ordenadas por antigüedad
    const { data: pendientes } = await supabase.from('wa_sesiones')
      .select('telefono,nombre,eps,cedula,resumen_nova,motivo_cierre_wa')
      .eq('estado','esperando')
      .is('agente_id',null)
      .order('updated_at',{ascending:true})
      .limit(5);

    if (!pendientes?.length) return;

    for (const ses of pendientes) {
      const agente = await autoAsignarAgente(ses.telefono, ses);
      if (!agente) break; // sin más agentes disponibles

      // Mensaje diferenciado según origen
      let m;
      if (ses.motivo_cierre_wa === 'guardado_fuera_horario') {
        m = `Buenos días, ${trat(ses.nombre)}. 🤝\n\nUn asesor especializado ha tomado su caso y le atenderá ahora.\n\n*Tododrogas, siempre a su servicio.*`;
      } else {
        m = `Gracias por su paciencia, ${trat(ses.nombre)}. 🤝\n\nUn asesor especializado está listo para atenderle ahora.\n\n*Tododrogas, siempre a su servicio.*`;
      }
      await enviarMeta(ses.telefono, m);
      await pushHistory(ses.telefono, m, 'nova');
      console.log(`✅ Reasignado: ${ses.telefono} → ${agente.nombre} (${ses.motivo_cierre_wa||'espera'})`);
    }
  } catch(e) { console.error('❌ cronReasignar:', e.message); }
}
setInterval(cronReasignar, 2*60*1000);

// CRON 3: Timeout encuesta sin responder
// 30 min → recordatorio único | 24h → cierre silencioso
async function cronEncuestaTimeout() {
  try {
    const ahora    = Date.now();
    const ahoraISO = new Date(ahora).toISOString();
    const { data: sesiones } = await supabase.from('wa_sesiones')
      .select('telefono,nombre,agente_id,agente_nombre,encuesta_enviada_at,motivo_cierre_wa,history,calificacion')
      .eq('estado','esperando_encuesta').not('encuesta_enviada_at','is',null);
    if (!sesiones?.length) return;

    for (const s of sesiones) {
      try {
        const mins = (ahora - new Date(s.encuesta_enviada_at).getTime()) / 60000;

        // 30 min: recordatorio único
        if (mins >= 30 && mins < 60) {
          const { data: check } = await supabase.from('wa_sesiones').select('inactividad_aviso_at').eq('telefono',s.telefono).single();
          if (check?.inactividad_aviso_at) continue;
          const msgRec = `¿Nos regala un momento para calificarnos, ${trat(s.nombre)}? 😊\n\n*MALA* → 😞\n*REGULAR* → 😐\n*BUENA* → 😊`;
          const { error } = await supabase.from('wa_sesiones').update({ inactividad_aviso_at:ahoraISO }).eq('telefono',s.telefono).is('inactividad_aviso_at',null);
          if (!error) { await enviarMeta(s.telefono, msgRec); await pushHistory(s.telefono, msgRec, 'nova'); }
          continue;
        }

        // 24h: cierre silencioso
        if (mins >= 24*60) {
          const { data: sf } = await supabase.from('wa_sesiones').select('history').eq('telefono',s.telefono).single();
          const hist = Array.isArray(sf?.history) ? sf.history : [];
          hist.push({ role:'system', content:'── Encuesta cerrada automáticamente (24h sin respuesta) ──', ts:ahoraISO });
          const { error } = await supabase.from('wa_sesiones').update({
            estado:'cerrado', cerrado_at:ahoraISO, calificacion:null, calificacion_texto:'Sin calificación',
            motivo_cierre_wa:s.motivo_cierre_wa||'encuesta_timeout', inactividad_aviso_at:null, fase:null,
            history:hist, updated_at:ahoraISO
          }).eq('telefono',s.telefono).eq('estado','esperando_encuesta');
          if (error) continue;
          if (s.agente_id) {
            const { data: ag } = await supabase.from('agentes').select('carga_actual').eq('id',s.agente_id).single();
            if (ag) await supabase.from('agentes').update({ carga_actual:Math.max(0,(ag.carga_actual||1)-1) }).eq('id',s.agente_id);
          }
          await archivarEnHistorico({ ...s, history:hist, calificacion:null, calificacion_texto:'Sin calificación', motivo_cierre_wa:s.motivo_cierre_wa||'encuesta_timeout', cerrado_at:ahoraISO }, ahoraISO);
          await logConv(s.telefono, s.agente_id, s.agente_nombre||'Nova TD', 'cerrado', null, { motivo:'encuesta_timeout', origen_nova:!s.agente_id });
          console.log(`🔒 Timeout encuesta: ${s.telefono}`);
        }
      } catch(eS) { console.error(`❌ cronEncuestaTimeout [${s.telefono}]:`, eS.message); }
    }
  } catch(e) { console.error('❌ cronEncuestaTimeout:', e.message); }
}
setInterval(cronEncuestaTimeout, 15*60*1000);
setTimeout(cronEncuestaTimeout, 15000);

// ══════════════════════════════════════════════════════════════════════════════
//  ENDPOINTS PANEL AGENTE
// ══════════════════════════════════════════════════════════════════════════════

function verificarOrigen(req, res) {
  const origin = req.headers.origin || req.headers.referer || '';
  if (!ALLOWED_ORIGINS.some(o => origin.startsWith(o))) { res.status(403).json({ error:'Forbidden' }); return false; }
  return true;
}

// Enviar mensaje (agente → usuario)
app.post('/send', async (req, res) => {
  if (!verificarOrigen(req, res)) return;
  try {
    const { telefono, mensaje, agente_nombre, agente_id } = req.body;
    if (!telefono || !mensaje) return res.status(400).json({ error:'telefono y mensaje requeridos' });
    const metaData = await enviarMeta(telefono, mensaje);
    const ahoraISO = new Date().toISOString();
    const { data: sesion } = await supabase.from('wa_sesiones').select('*').eq('telefono', telefono).single();
    const history = Array.isArray(sesion?.history) ? sesion.history : [];
    history.push({ role:'assistant', sender:'agent', content:mensaje, agente:agente_nombre||'Agente', ts:ahoraISO });
    const upd = { history, unread_count:0, updated_at:ahoraISO, inactividad_aviso_at:null, mensajes_agente:(sesion?.mensajes_agente||0)+1, fase:null };
    if (!sesion?.primera_respuesta_at) {
      upd.primera_respuesta_at = ahoraISO; upd.estado = 'activo';
      const dur = sesion?.asignado_at ? Math.round((new Date(ahoraISO)-new Date(sesion.asignado_at))/1000) : null;
      await logConv(telefono, agente_id||sesion?.agente_id, agente_nombre, 'primera_respuesta', dur);
    } else { upd.estado = 'activo'; await logConv(telefono, agente_id||sesion?.agente_id, agente_nombre, 'mensaje_agente'); }
    await supabase.from('wa_sesiones').update(upd).eq('telefono', telefono);
    res.json({ ok:true, message_id:metaData.messages?.[0]?.id });
  } catch(err) { console.error('❌ /send:', err.message); res.status(500).json({ error:err.message }); }
});

// Continúa en línea (respuesta rápida del asesor)
app.post('/continua-en-linea', async (req, res) => {
  if (!verificarOrigen(req, res)) return;
  try {
    const { telefono, agente_nombre, agente_id } = req.body;
    if (!telefono) return res.status(400).json({ error:'telefono requerido' });
    const ahoraISO = new Date().toISOString();
    const { data: sesion } = await supabase.from('wa_sesiones').select('history,nombre').eq('telefono', telefono).single();
    const mensaje = `Sigo disponible para atenderle, ${trat(sesion?.nombre)}. Estoy aquí para lo que necesite. 😊`;
    await enviarMeta(telefono, mensaje);
    const history = Array.isArray(sesion?.history) ? sesion.history : [];
    history.push({ role:'assistant', sender:'agent', content:mensaje, agente:agente_nombre||'Agente', ts:ahoraISO });
    await supabase.from('wa_sesiones').update({ history, estado:'activo', inactividad_aviso_at:null, updated_at:ahoraISO }).eq('telefono', telefono);
    await logConv(telefono, agente_id||null, agente_nombre||'Agente', 'continua_en_linea');
    res.json({ ok:true });
  } catch(err) { console.error('❌ /continua-en-linea:', err.message); res.status(500).json({ error:err.message }); }
});

// Encuesta de cierre (asesor envía encuesta y abre modal SIGI)
app.post('/iniciar-encuesta-cierre', async (req, res) => {
  if (!verificarOrigen(req, res)) return;
  try {
    const { telefono, agente_nombre, agente_id } = req.body;
    if (!telefono) return res.status(400).json({ error:'telefono requerido' });
    const ahoraISO = new Date().toISOString();
    const { data: sesion } = await supabase.from('wa_sesiones').select('*').eq('telefono', telefono).single();
    if (sesion?.encuesta_enviada_at) return res.json({ ok:false, msg:'Encuesta ya enviada' });
    const msgEnc = `Fue un placer atenderle, ${trat(sesion?.nombre)}. 😊\n\nAntes de cerrar, ¿nos regala un momento para calificarnos?\n\n*MALA* → 😞\n*REGULAR* → 😐\n*BUENA* → 😊\n\n*Tododrogas, siempre a su servicio.* 🌟`;
    if (agente_id) {
      const { data: ag } = await supabase.from('agentes').select('carga_actual').eq('id', agente_id).single();
      if (ag) await supabase.from('agentes').update({ carga_actual:Math.max(0,(ag.carga_actual||1)-1) }).eq('id', agente_id);
    }
    const { error } = await supabase.from('wa_sesiones').update({
      estado:'esperando_encuesta', encuesta_enviada_at:ahoraISO,
      inactividad_aviso_at:null, motivo_cierre_wa:'cierre_asesor', updated_at:ahoraISO
    }).eq('telefono', telefono).is('encuesta_enviada_at', null);
    if (error) return res.json({ ok:false, msg:'Race condition' });
    await enviarMeta(telefono, msgEnc); await pushHistory(telefono, msgEnc, 'nova');
    await logConv(telefono, agente_id||null, agente_nombre||'Agente', 'encuesta_enviada', null, { motivo:'cierre_asesor' });
    res.json({ ok:true });
  } catch(err) { console.error('❌ /iniciar-encuesta-cierre:', err.message); res.status(500).json({ error:err.message }); }
});

// Enviar media (agente → usuario)
app.post('/send-media', upload.single('file'), async (req, res) => {
  if (!verificarOrigen(req, res)) return;
  try {
    const { telefono, agente_nombre, agente_id, caption } = req.body;
    const file = req.file;
    if (!telefono || !file) return res.status(400).json({ error:'telefono y file requeridos' });
    const ahoraISO = new Date().toISOString();
    const mime = file.mimetype;
    const isImage = mime.startsWith('image/'); const isVideo = mime.startsWith('video/'); const isAudio = mime.startsWith('audio/');
    const folder = isImage?'imagenes-agente':isVideo?'videos-agente':isAudio?'audios-agente':'documentos-agente';
    const safeName = (file.originalname||`file_${Date.now()}`).replace(/[^a-zA-Z0-9._-]/g,'_');
    const media_url = await subirASupabase(file.buffer, `${folder}/${telefono.replace('+','')}/${Date.now()}_${safeName}`, mime);
    let metaBody;
    if (isImage)      metaBody = { messaging_product:'whatsapp',to:telefono.replace('+',''),type:'image',   image:   {link:media_url,caption:caption||''} };
    else if (isVideo) metaBody = { messaging_product:'whatsapp',to:telefono.replace('+',''),type:'video',   video:   {link:media_url,caption:caption||''} };
    else if (isAudio) metaBody = { messaging_product:'whatsapp',to:telefono.replace('+',''),type:'audio',   audio:   {link:media_url} };
    else              metaBody = { messaging_product:'whatsapp',to:telefono.replace('+',''),type:'document',document:{link:media_url,filename:safeName,caption:caption||''} };
    const mr = await fetch(`https://graph.facebook.com/v19.0/${META_PHONE_ID}/messages`,{method:'POST',headers:{'Content-Type':'application/json','Authorization':`Bearer ${META_TOKEN}`},body:JSON.stringify(metaBody)});
    if (!mr.ok) throw new Error(await mr.text());
    const { data: ses } = await supabase.from('wa_sesiones').select('history').eq('telefono',telefono).single();
    const hist = Array.isArray(ses?.history)?ses.history:[];
    hist.push({ role:'assistant',sender:'agent',tipo:isImage?'image':isVideo?'video':isAudio?'audio':'document',content:caption||`[${safeName}]`,media_url,file_name:safeName,mime_type:mime,agente_nombre:agente_nombre||'Agente',ts:ahoraISO });
    await supabase.from('wa_sesiones').update({history:hist,updated_at:ahoraISO}).eq('telefono',telefono);
    await logConv(telefono,agente_id||null,agente_nombre||'Agente','mensaje_agente');
    res.json({ ok:true, media_url });
  } catch(err) { console.error('❌ /send-media:', err.message); res.status(500).json({ error:err.message }); }
});

// Enviar audio del agente
app.post('/send-audio', upload.single('audio'), async (req, res) => {
  if (!verificarOrigen(req, res)) return;
  try {
    const { telefono, agente_nombre, agente_id, duracion } = req.body;
    const file = req.file;
    if (!telefono || !file) return res.status(400).json({ error:'telefono y audio requeridos' });
    const ahoraISO = new Date().toISOString();
    const ext = file.mimetype.includes('webm')?'webm':'ogg';
    const audioUrl = await subirASupabase(file.buffer, `audios-agente/${telefono.replace('+','')}/${Date.now()}.${ext}`, file.mimetype);
    const mr = await fetch(`https://graph.facebook.com/v19.0/${META_PHONE_ID}/messages`,{method:'POST',headers:{'Content-Type':'application/json','Authorization':`Bearer ${META_TOKEN}`},body:JSON.stringify({messaging_product:'whatsapp',to:telefono.replace('+',''),type:'audio',audio:{link:audioUrl}})});
    if (!mr.ok) throw new Error(await mr.text());
    const { data: ses } = await supabase.from('wa_sesiones').select('history').eq('telefono',telefono).single();
    const hist = Array.isArray(ses?.history)?ses.history:[];
    hist.push({ role:'assistant',sender:'agent',tipo:'audio',content:'[Audio de voz]',audio_url:audioUrl,duracion:parseInt(duracion)||0,agente_nombre:agente_nombre||'Agente',ts:ahoraISO });
    await supabase.from('wa_sesiones').update({history:hist,updated_at:ahoraISO}).eq('telefono',telefono);
    await logConv(telefono,agente_id||null,agente_nombre||'Agente','mensaje_agente');
    res.json({ ok:true, audio_url:audioUrl });
  } catch(err) { console.error('❌ /send-audio:', err.message); res.status(500).json({ error:err.message }); }
});

// Transferir conversación
app.post('/transferir', async (req, res) => {
  if (!verificarOrigen(req, res)) return;
  try {
    const { telefono, nuevo_agente_id, nuevo_agente_nombre, agente_origen_id, agente_origen_nombre } = req.body;
    if (!telefono || !nuevo_agente_id) return res.status(400).json({ error:'telefono y nuevo_agente_id requeridos' });
    const ahoraISO = new Date().toISOString();
    const { data: sesion } = await supabase.from('wa_sesiones').select('*').eq('telefono', telefono).single();
    if (agente_origen_id) {
      const { data: ao } = await supabase.from('agentes').select('carga_actual').eq('id',agente_origen_id).single();
      if (ao) await supabase.from('agentes').update({ carga_actual:Math.max(0,(ao.carga_actual||1)-1) }).eq('id',agente_origen_id);
    }
    const { data: an } = await supabase.from('agentes').select('carga_actual').eq('id',nuevo_agente_id).single();
    if (an) await supabase.from('agentes').update({ carga_actual:(an.carga_actual||0)+1 }).eq('id',nuevo_agente_id);
    const history = Array.isArray(sesion?.history)?sesion.history:[];
    history.push({ role:'system', content:`── Transferido de ${agente_origen_nombre||'agente anterior'} a ${nuevo_agente_nombre} ──`, ts:ahoraISO });
    const nuevoSaludo = await generarSaludo(nuevo_agente_nombre, sesion?.resumen_nova||'', sesion?.nombre||'', sesion?.eps||'');
    await supabase.from('wa_sesiones').update({
      agente_id:nuevo_agente_id, agente_nombre:nuevo_agente_nombre, estado:'escalado',
      transferido_de:agente_origen_id||sesion?.agente_id, transferido_at:ahoraISO, asignado_at:ahoraISO,
      primera_respuesta_at:null, saludo_sugerido:nuevoSaludo||null, inactividad_aviso_at:null, fase:null, history, updated_at:ahoraISO
    }).eq('telefono', telefono);
    await logConv(telefono, nuevo_agente_id, nuevo_agente_nombre, 'transferido', null, { agente_origen_id, agente_origen_nombre });
    res.json({ ok:true, saludo_sugerido:nuevoSaludo, history });
  } catch(err) { console.error('❌ /transferir:', err.message); res.status(500).json({ error:err.message }); }
});

// Iniciar conversación (agente → usuario nuevo)
app.post('/iniciar-conversacion', async (req, res) => {
  if (!verificarOrigen(req, res)) return;
  try {
    const { telefono, agente_id, agente_nombre, template_name, template_params, mensaje_libre } = req.body;
    if (!telefono) return res.status(400).json({ error:'telefono requerido' });
    const ahoraISO = new Date().toISOString();
    const telClean = telefono.replace(/\D/g,'');
    const telFull  = '+' + telClean;
    const { data: sesExist } = await supabase.from('wa_sesiones').select('telefono,estado').eq('telefono',telFull).single();
    if (sesExist && sesExist.estado !== 'cerrado') return res.status(409).json({ error:'Ya existe conversación activa', estado:sesExist.estado });
    let metaBody;
    if (template_name) {
      const components = template_params?.length ? [{type:'body',parameters:template_params.map(p=>({type:'text',text:p}))}] : [];
      metaBody = { messaging_product:'whatsapp',to:telClean,type:'template',template:{name:template_name,language:{code:'es'},...(components.length?{components}:{})} };
    } else if (mensaje_libre) {
      metaBody = { messaging_product:'whatsapp',to:telClean,type:'text',text:{body:mensaje_libre} };
    } else { return res.status(400).json({ error:'Se requiere template_name o mensaje_libre' }); }
    const mr = await fetch(`https://graph.facebook.com/v19.0/${META_PHONE_ID}/messages`,{method:'POST',headers:{'Content-Type':'application/json','Authorization':`Bearer ${META_TOKEN}`},body:JSON.stringify(metaBody)});
    if (!mr.ok) return res.status(502).json({ error:'Error Meta API: '+(await mr.text()).substring(0,200) });
    const metaData = await mr.json();
    const contenido = mensaje_libre||`[Plantilla: ${template_name}]`;
    await supabase.from('wa_sesiones').upsert({
      telefono:telFull, estado:'escalado', agente_id:agente_id||null, agente_nombre:agente_nombre||null,
      asignado_at:ahoraISO, history:[{role:'assistant',sender:'agent',content:contenido,agente_nombre:agente_nombre||'Agente',ts:ahoraISO}],
      unread_count:0, updated_at:ahoraISO, calificacion:null, calificacion_texto:null,
      encuesta_enviada_at:null, cerrado_at:null, inactividad_aviso_at:null, motivo_cierre_wa:null, fase:null
    });
    await logConv(telFull, agente_id||null, agente_nombre||'Agente', 'iniciado_agente', null, { template:template_name||'mensaje_libre' });
    res.json({ ok:true, telefono:telFull, message_id:metaData.messages?.[0]?.id });
  } catch(err) { console.error('❌ /iniciar-conversacion:', err.message); res.status(500).json({ error:err.message }); }
});

// Archivar sesión
app.post('/archivar', async (req, res) => {
  if (!verificarOrigen(req, res)) return;
  try {
    const { telefono } = req.body;
    if (!telefono) return res.status(400).json({ error:'telefono requerido' });
    await archivarEnHistorico({ telefono }, new Date().toISOString());
    res.json({ ok:true });
  } catch(err) { res.status(500).json({ error:err.message }); }
});

// Heartbeat agente
app.post('/heartbeat', async (req, res) => {
  if (!verificarOrigen(req, res)) return;
  try {
    const { agente_id } = req.body;
    if (!agente_id) return res.status(400).json({ error:'agente_id requerido' });
    await supabase.from('agentes').update({ ultima_actividad:new Date().toISOString() }).eq('id', agente_id);
    res.json({ ok:true });
  } catch(err) { res.status(500).json({ error:err.message }); }
});

app.listen(PORT, () => console.log(`✅ webhook-tododrogas en :${PORT}`));
