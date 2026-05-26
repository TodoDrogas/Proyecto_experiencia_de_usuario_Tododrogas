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

// ── PROCESAR ENCUESTA WA ─────────────────────────────────────────────────
async function procesarEncuesta(telefono, respuesta, sesion) {
  const cal = respuesta.trim();
  if (!['1', '2', '3'].includes(cal)) return false;

  const calNum = parseInt(cal);
  const textos = { 1: 'Mala', 2: 'Regular', 3: 'Buena' };
  const emojis = { 1: '😞', 2: '😐', 3: '😊' };

  const ahoraISO = new Date().toISOString();

  await supabase.from('wa_sesiones').update({
    calificacion:       calNum,
    calificacion_texto: textos[calNum],
    fecha_calificacion: ahoraISO,
    estado:             'cerrado',
    cerrado_at:         ahoraISO,
    motivo_cierre_wa:   'encuesta',
    updated_at:         ahoraISO
  }).eq('telefono', telefono);

  // Agregar al historial
  const hist = Array.isArray(sesion.history) ? sesion.history : [];
  hist.push({ role: 'user', content: cal, ts: ahoraISO });
  await supabase.from('wa_sesiones').update({ history: hist }).eq('telefono', telefono);

  // Responder al usuario
  const msg = `${emojis[calNum]} Gracias por calificarnos. Hemos registrado su atención como *${textos[calNum]}*.\n\nGracias por contactarnos. *Tododrogas, siempre a su servicio.*`;
  await enviarMeta(telefono, msg);

  await logConv(telefono, sesion.agente_id, sesion.agente_nombre, 'cerrado', null, {
    motivo: 'encuesta', calificacion: calNum
  });

  console.log(`⭐ Encuesta WA respondida por ${telefono}: ${textos[calNum]}`);
  return true;
}

// ── CRON: INACTIVIDAD Y ESTADOS ──────────────────────────────────────────
async function cronInactividad() {
  try {
    const ahora = Date.now();
    const ahoraISO = new Date(ahora).toISOString();

    // Traer sesiones activas (nova, escalado, activo, esperando, esperando_encuesta)
    const { data: sesiones } = await supabase
      .from('wa_sesiones')
      .select('telefono, estado, nombre, agente_id, agente_nombre, updated_at, inactividad_aviso_at, asignado_at, encuesta_enviada_at, history, eps, resumen_nova')
      .in('estado', ['nova', 'escalado', 'activo', 'esperando', 'esperando_encuesta'])
      .not('updated_at', 'is', null);

    if (!sesiones?.length) return;

    for (const s of sesiones) {
      const ultimaAct  = new Date(s.updated_at).getTime();
      const minsSinAct = (ahora - ultimaAct) / 60000;

      // ── NOVA: inactividad ────────────────────────────────────────────────
      if (s.estado === 'nova') {
        // 2 min sin respuesta → preguntar si continúa
        if (minsSinAct >= 2 && !s.inactividad_aviso_at) {
          await enviarMeta(s.telefono,
            `¿${tratamiento(s.nombre)}, continúa en línea?\n\nEstamos disponibles para atenderle. Si tiene alguna consulta, con gusto le ayudamos.\n\nSi ya no necesita asistencia, escriba *SALIR* para cerrar la conversación.`
          );
          await supabase.from('wa_sesiones').update({
            inactividad_aviso_at: ahoraISO,
            updated_at: ahoraISO
          }).eq('telefono', s.telefono);
          await logConv(s.telefono, null, null, 'inactividad_aviso', null, { estado: 'nova' });
          continue;
        }
        // 30 min sin respuesta total → enviar encuesta Nova y cerrar
        if (minsSinAct >= 30 && s.inactividad_aviso_at) {
          const minsDesdeAviso = (ahora - new Date(s.inactividad_aviso_at).getTime()) / 60000;
          if (minsDesdeAviso >= 28) {
            await enviarMeta(s.telefono,
              `Cerramos la conversación por inactividad. ¡Fue un gusto poder acompañarle! 😊\n\nAntes de despedirnos, ¿nos regala un momento para calificarnos?\n\nResponda con:\n*1* → 😞 Mala\n*2* → 😐 Regular\n*3* → 😊 Buena\n\n¡Tododrogas siempre a su servicio! 🌟`
            );
            await supabase.from('wa_sesiones').update({
              estado:             'esperando_encuesta',
              encuesta_enviada_at: ahoraISO,
              motivo_cierre_wa:   'inactividad',
              updated_at:         ahoraISO
            }).eq('telefono', s.telefono);
            await logConv(s.telefono, null, null, 'encuesta_enviada', null, { motivo: 'inactividad_nova' });
          }
        }
        continue;
      }

      // ── CON AGENTE: escalado/activo/esperando ───────────────────────────
      if (['escalado', 'activo', 'esperando'].includes(s.estado)) {
        // 1 min sin respuesta del usuario con agente activo → pasar a "esperando"
        if (s.estado === 'activo' && minsSinAct >= 1) {
          await supabase.from('wa_sesiones').update({
            estado:     'esperando',
            updated_at: ahoraISO
          }).eq('telefono', s.telefono);
          await logConv(s.telefono, s.agente_id, s.agente_nombre, 'estado_cambio', null, {
            estado_anterior: 'activo', estado_nuevo: 'esperando'
          });
          continue;
        }

        // 2 min en "esperando" sin aviso previo → preguntar si continúa
        if (s.estado === 'esperando' && minsSinAct >= 2 && !s.inactividad_aviso_at) {
          await enviarMeta(s.telefono,
            `¿${tratamiento(s.nombre)}, continúa en línea?\n\n*${s.agente_nombre || 'Su asesor'}* continúa disponible para atenderle.\n\nSi ya resolvió su consulta, puede escribir *LISTO* para cerrar la atención.`
          );
          await supabase.from('wa_sesiones').update({
            inactividad_aviso_at: ahoraISO,
            updated_at: ahoraISO
          }).eq('telefono', s.telefono);
          await logConv(s.telefono, s.agente_id, s.agente_nombre, 'inactividad_aviso', null, { estado: 'esperando' });
          continue;
        }

        // 30 min sin respuesta total → encuesta y cierre
        if (minsSinAct >= 30 && s.inactividad_aviso_at) {
          const minsDesdeAviso = (ahora - new Date(s.inactividad_aviso_at).getTime()) / 60000;
          if (minsDesdeAviso >= 28) {
            await enviarMeta(s.telefono,
              `Cerramos la conversación por inactividad.\n\nAntes de finalizar, le invitamos a calificar nuestra atención:\n\n*1* → 😞 Mala\n*2* → 😐 Regular\n*3* → 😊 Buena\n\nGracias por contactarnos. *Tododrogas, siempre a su servicio.*`
            );
            // Reducir carga del agente
            if (s.agente_id) {
              const { data: ag } = await supabase.from('agentes').select('carga_actual').eq('id', s.agente_id).single();
              if (ag) await supabase.from('agentes').update({
                carga_actual: Math.max(0, (ag.carga_actual || 1) - 1)
              }).eq('id', s.agente_id);
            }
            await supabase.from('wa_sesiones').update({
              estado:              'esperando_encuesta',
              encuesta_enviada_at: ahoraISO,
              motivo_cierre_wa:    'inactividad',
              updated_at:          ahoraISO
            }).eq('telefono', s.telefono);
            await logConv(s.telefono, s.agente_id, s.agente_nombre, 'encuesta_enviada', null, { motivo: 'inactividad_agente' });
          }
        }
        continue;
      }

      // ── ESPERANDO_ENCUESTA: 30 min sin responder → cerrar sin calificación
      if (s.estado === 'esperando_encuesta') {
        const minsEnc = s.encuesta_enviada_at
          ? (ahora - new Date(s.encuesta_enviada_at).getTime()) / 60000
          : minsSinAct;
        if (minsEnc >= 30) {
          await supabase.from('wa_sesiones').update({
            estado:             'cerrado',
            calificacion:       null,
            calificacion_texto: 'Sin calificación',
            cerrado_at:         ahoraISO,
            motivo_cierre_wa:   'inactividad',
            updated_at:         ahoraISO
          }).eq('telefono', s.telefono);
          await logConv(s.telefono, s.agente_id, s.agente_nombre, 'cerrado', null, {
            motivo: 'sin_calificacion_inactividad'
          });
        }
      }
    }
  } catch (e) {
    console.error('❌ cronInactividad:', e.message);
  }
}

// Ejecutar cron cada 60 segundos
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
    const body     = msg.text?.body || `[${tipo}]`;
    const profile  = value?.contacts?.[0]?.profile?.name || '';

    if (rateLimit(telefono)) return;

    // ── Obtener o crear sesión ─────────────────────────────────────────────
    let { data: sesion } = await supabase
      .from('wa_sesiones').select('*').eq('telefono', telefono).single();

    const ahoraISO = new Date().toISOString();
    const nuevoMsg = {
      role:    'user',
      content: body,
      ts:      ahoraISO,
      tipo:    tipo !== 'text' ? tipo : undefined
    };

    if (sesion) {
      // ── ENCUESTA: si está esperando respuesta de encuesta ────────────────
      if (sesion.estado === 'esperando_encuesta') {
        const procesado = await procesarEncuesta(telefono, body, sesion);
        if (procesado) return;
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

          if (respuesta) {
            await enviarMeta(telefono, respuesta);
            const { data: sesUp } = await supabase
              .from('wa_sesiones').select('history').eq('telefono', telefono).single();
            const hist = Array.isArray(sesUp?.history) ? sesUp.history : [];
            hist.push({ role: 'nova', content: respuesta, ts: ahoraISO });
            await supabase.from('wa_sesiones')
              .update({ history: hist, updated_at: ahoraISO })
              .eq('telefono', telefono);
          }

          // Si Nova escaló → autoasignar agente
          if (novaData.accion === 'ESCALADO') {
            const { data: sesActual } = await supabase
              .from('wa_sesiones').select('*').eq('telefono', telefono).single();
            const agente = await autoAsignarAgente(telefono, sesActual);
            if (agente) {
              await enviarMeta(telefono,
                `En este momento le estamos conectando con un asesor especializado que revisará su caso. Por favor espere un momento.\n\n*Tododrogas, siempre a su servicio.*`
              );
            } else {
              await supabase.from('wa_sesiones')
                .update({ estado: 'escalado', updated_at: ahoraISO })
                .eq('telefono', telefono);
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
