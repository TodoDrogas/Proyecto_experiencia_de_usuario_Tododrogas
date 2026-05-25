const express = require('express');
const { createClient } = require('@supabase/supabase-js');
const ws      = require('ws');
const crypto  = require('crypto');
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
const PORT              = process.env.PORT || 3000;

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

// ── AUTOASIGNACIÓN ────────────────────────────────────────────────────────
async function autoAsignarAgente(telefono) {
  try {
    // Buscar agentes en línea, no pausados, activos
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

    const agente = agentes[0];

    // Asignar al agente
    await supabase.from('wa_sesiones').update({
      agente_id:     agente.id,
      agente_nombre: agente.nombre,
      estado:        'escalado',
      updated_at:    new Date().toISOString()
    }).eq('telefono', telefono);

    // Incrementar carga del agente
    await supabase.from('agentes').update({
      carga_actual:      (agente.carga_actual || 0) + 1,
      ultima_actividad:  new Date().toISOString()
    }).eq('id', agente.id);

    console.log(`✅ Autoasignado ${telefono} → ${agente.nombre}`);
    return agente;
  } catch (err) {
    console.error('❌ Error autoasignar:', err.message);
    return null;
  }
}

// ── PROCESAR ENCUESTA WA ─────────────────────────────────────────────────
async function procesarEncuesta(telefono, respuesta, sesion) {
  const cal = respuesta.trim();
  if (!['1', '2', '3'].includes(cal)) return false;

  const calNum  = parseInt(cal);
  const textos  = { 1: 'Mala', 2: 'Regular', 3: 'Buena' };
  const emojis  = { 1: '😞', 2: '😐', 3: '😊' };
  const texto   = textos[calNum];

  await supabase.from('wa_sesiones').update({
    calificacion:       calNum,
    calificacion_texto: texto,
    fecha_calificacion: new Date().toISOString(),
    estado:             'cerrado',
    updated_at:         new Date().toISOString()
  }).eq('telefono', telefono);

  // Agregar al historial
  const hist = Array.isArray(sesion.history) ? sesion.history : [];
  hist.push({ role: 'user', content: cal, ts: new Date().toISOString() });

  await supabase.from('wa_sesiones').update({
    history: hist
  }).eq('telefono', telefono);

  // Responder al usuario
  const msg = `${emojis[calNum]} ¡Gracias por tu calificación! Registramos tu opinión como *${texto}*.\n\nTu comentario nos ayuda a mejorar la atención en *Tododrogas*. ¡Hasta pronto! 🌟`;
  await enviarMeta(telefono, msg);

  console.log(`⭐ Encuesta respondida por ${telefono}: ${texto} (${calNum})`);
  return true;
}

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
  console.warn('⚠️  Verificación Meta fallida');
  res.sendStatus(403);
});

// ── RECIBIR MENSAJES (POST) ────────────────────────────────────────────────
app.post('/webhook/meta', async (req, res) => {
  if (!validarFirmaMeta(req)) {
    console.warn('⚠️  Firma Meta inválida');
    return res.sendStatus(403);
  }

  res.sendStatus(200); // Meta exige 200 inmediato

  try {
    const entry    = req.body?.entry?.[0];
    const changes  = entry?.changes?.[0];
    const value    = changes?.value;
    const messages = value?.messages;

    if (!messages?.length) return;

    const msg      = messages[0];
    const telefono = '+' + msg.from;
    const tipo     = msg.type;
    const body     = msg.text?.body || `[${tipo}]`;
    const profile  = value?.contacts?.[0]?.profile?.name || '';

    if (rateLimit(telefono)) {
      console.warn('⚠️  Rate limit:', telefono);
      return;
    }

    // ── Obtener o crear sesión ─────────────────────────────────────────────
    let { data: sesion } = await supabase
      .from('wa_sesiones').select('*').eq('telefono', telefono).single();

    const nuevoMsg = {
      role:    'user',
      content: body,
      ts:      new Date().toISOString(),
      tipo:    tipo !== 'text' ? tipo : undefined
    };

    if (sesion) {
      // ── ENCUESTA: si está esperando respuesta de encuesta ────────────────
      if (sesion.estado === 'esperando_encuesta') {
        const procesado = await procesarEncuesta(telefono, body, sesion);
        if (procesado) return;
        // Si no es 1/2/3, ignorar y seguir
      }

      const history = Array.isArray(sesion.history) ? sesion.history : [];
      if (history.length >= 500) history.splice(0, history.length - 499);
      history.push(nuevoMsg);
      await supabase.from('wa_sesiones').update({
        history,
        unread_count: (sesion.unread_count || 0) + 1,
        updated_at:   new Date().toISOString(),
        ...(profile && !sesion.nombre ? { nombre: profile } : {})
      }).eq('telefono', telefono);
      sesion.history = history;
    } else {
      const nueva = {
        telefono,
        nombre:       profile || '',
        history:      [nuevoMsg],
        estado:       'nova',
        unread_count: 1,
        updated_at:   new Date().toISOString()
      };
      await supabase.from('wa_sesiones').insert(nueva);
      sesion = nueva;
    }

    console.log(`📩 Mensaje de ${telefono}: ${body.substring(0, 60)}`);

    // ── Llamar a Nova TD si estado es nova ────────────────────────────────
    const estado = sesion.estado || 'nova';

    if (estado === 'nova') {
      try {
        const novaRes = await fetch('https://tododrogas.online/nova-wa.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Nova-Token': NOVA_TOKEN
          },
          body: JSON.stringify({ telefono, mensaje: body, sesion })
        });

        if (novaRes.ok) {
          const novaData = await novaRes.json();
          const respuesta = novaData.respuesta;

          if (respuesta) {
            await enviarMeta(telefono, respuesta);
            const { data: sesUp } = await supabase
              .from('wa_sesiones').select('history').eq('telefono', telefono).single();
            const hist = Array.isArray(sesUp?.history) ? sesUp.history : [];
            hist.push({ role: 'nova', content: respuesta, ts: new Date().toISOString() });
            await supabase.from('wa_sesiones')
              .update({ history: hist, updated_at: new Date().toISOString() })
              .eq('telefono', telefono);
            console.log(`🤖 Nova respondió a ${telefono}: ${respuesta.substring(0, 60)}`);
          }

          // Si Nova escaló → autoasignar agente
          if (novaData.accion === 'ESCALADO') {
            const agente = await autoAsignarAgente(telefono);
            if (agente) {
              // Notificar al usuario que fue asignado
              await enviarMeta(telefono,
                `Un momento por favor, *${agente.nombre}* estará contigo en breve. 👤`
              );
            } else {
              // Sin agentes disponibles — dejar en escalado sin asignar
              await supabase.from('wa_sesiones')
                .update({ estado: 'escalado', updated_at: new Date().toISOString() })
                .eq('telefono', telefono);
            }
            console.log(`📢 Escalado a agente: ${telefono}`);
          }

        } else {
          console.error('❌ Error Nova HTTP:', novaRes.status, await novaRes.text());
        }
      } catch (err) {
        console.error('❌ Error llamando Nova:', err.message);
      }
    }

    // ── Si el agente está atendiendo, marcar como sin leer ────────────────
    if (estado === 'escalado' || estado === 'esperando') {
      await supabase.from('wa_sesiones')
        .update({ unread_count: (sesion.unread_count || 0) + 1, updated_at: new Date().toISOString() })
        .eq('telefono', telefono);
    }

  } catch (err) {
    console.error('❌ Error webhook Meta:', err.message);
  }
});

// ── ENVIAR MENSAJE (agente → usuario) ─────────────────────────────────────
app.post('/send', async (req, res) => {
  const origin = req.headers.origin || req.headers.referer || '';
  if (!ALLOWED_ORIGINS.some(o => origin.startsWith(o))) {
    return res.status(403).json({ error: 'Origen no permitido' });
  }

  try {
    const { telefono, mensaje, agente_nombre } = req.body;
    if (!telefono || !mensaje)
      return res.status(400).json({ error: 'telefono y mensaje requeridos' });

    const metaData = await enviarMeta(telefono, mensaje);

    const { data: sesion } = await supabase
      .from('wa_sesiones').select('history').eq('telefono', telefono).single();

    const history = Array.isArray(sesion?.history) ? sesion.history : [];
    history.push({
      role:    'assistant',
      sender:  'agent',
      content: mensaje,
      agente:  agente_nombre || 'Agente',
      ts:      new Date().toISOString()
    });

    await supabase.from('wa_sesiones')
      .update({ history, unread_count: 0, updated_at: new Date().toISOString() })
      .eq('telefono', telefono);

    console.log(`📤 Enviado a ${telefono} por ${agente_nombre || 'Agente'}`);
    res.json({ ok: true, message_id: metaData.messages?.[0]?.id });

  } catch (err) {
    console.error('❌ Error send:', err.message);
    res.status(500).json({ error: err.message });
  }
});

app.listen(PORT, () =>
  console.log(`✅ webhook-meta-tododrogas corriendo en :${PORT}`)
);
