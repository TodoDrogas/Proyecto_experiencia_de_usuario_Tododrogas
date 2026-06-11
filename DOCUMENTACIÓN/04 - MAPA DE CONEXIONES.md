# Mapa de Conexiones — SIGI

Sistema Integrado de Gestion Inteligente
Tododrogas — Procesos Digitales

Este documento detalla que componente se conecta con que: cada archivo del sistema, los servicios externos que consume, las tablas de base de datos que usa y los modelos de IA que invoca. Sirve para entender las dependencias reales antes de modificar cualquier pieza.

---

## Contenido

1. Servicios externos del sistema
2. Conexiones de los endpoints PHP
3. Conexiones del servicio Node
4. Conexiones de las vistas (frontend)
5. Quien escribe y quien lee cada tabla
6. Resumen de dependencias

---

## 1. Servicios externos del sistema

El sistema se conecta con cuatro servicios externos:

| Servicio | Para que se usa | Quien lo llama |
|----------|-----------------|----------------|
| OpenAI | Clasificacion, resumenes, transcripcion, voz, vision | Endpoints PHP y servicio Node |
| Microsoft Graph | Leer y enviar correo de Outlook | Endpoints PHP |
| Meta WhatsApp (graph.facebook.com) | Enviar y recibir mensajes de WhatsApp | Servicio Node y algunos PHP |
| Supabase | Base de datos y almacenamiento de archivos | Todos |

Modelos de OpenAI utilizados:

| Modelo | Tarea |
|--------|-------|
| gpt-4o-mini | Clasificacion de PQRSFD, sentimiento, resumenes, respuestas del bot |
| gpt-4o (Vision) | Lectura de imagenes y documentos adjuntos |
| whisper-1 | Transcripcion de audio a texto |
| tts-1 | Generacion de respuestas en audio (voz) |

---

## 2. Conexiones de los endpoints PHP

Cada endpoint con lo que consume. Todos usan la clave de servicio de Supabase (acceso de servidor).

### radicar-pqr.php
Radica una PQRSFD desde el formulario web.
- Externos: OpenAI (chat y transcripcion), Microsoft Graph
- Modelos: gpt-4o, gpt-4o-mini, whisper-1
- Tablas: correos, configuracion_sistema
- Storage: si (guarda adjuntos)

### sincronizar-correos.php
Trae correos nuevos de Outlook (ejecutado por cron cada minuto).
- Externos: Microsoft Graph, OpenAI (chat), Meta WhatsApp
- Modelos: gpt-4o-mini
- Storage: si

### transcribir-audio.php
Transcribe audios adjuntos a un ticket.
- Externos: OpenAI (transcripcion)
- Modelos: whisper-1
- Tablas: correos, adjuntos, historial_eventos
- Storage: si

### procesar-canvas.php
Procesa imagenes y documentos adjuntos.
- Externos: OpenAI (chat con vision)
- Modelos: gpt-4o
- Tablas: correos, adjuntos, historial_eventos
- Storage: si

### nova-consulta.php
Backend principal del bot Nova.
- Tablas: nova_sesiones, correos, agentes, sedes, knowledge_base, historial_eventos, configuracion_sistema

### nova-proxy.php
Proxy seguro del bot, incluye respuestas en audio.
- Externos: OpenAI (chat, transcripcion, voz)
- Modelos: gpt-4o-mini, whisper-1, tts-1

### nova-td-respuesta.php
Generacion de respuestas del bot.
- Externos: OpenAI (chat)
- Modelos: gpt-4o-mini
- Tablas: knowledge_base

### nova-wa.php
Logica del bot para WhatsApp.
- Tablas: nova_sesiones, sedes
- Storage: si

### whatsapp-webhook.php
Recepcion de eventos de WhatsApp del lado PHP.
- Externos: Meta WhatsApp, OpenAI (chat con vision)
- Modelos: gpt-4o
- Tablas: correos, sedes, tabla_usuarios

### chatbot.php
Logica conversacional.
- Externos: OpenAI (chat, transcripcion)
- Modelos: gpt-4o-mini, whisper-1
- Tablas: chatbot_sesiones

### radicar-encuesta.php
Registra una encuesta de satisfaccion.
- Externos: OpenAI (chat), Microsoft Graph
- Modelos: gpt-4o-mini
- Tablas: correos
- Storage: si

### encuesta.php
Recibe la calificacion embebida en un correo.
- Tablas: correos, historial_eventos

### login.php
Valida acceso de administrador y agentes.
- Tablas: agentes

### validar-paciente.php
Valida identidad del paciente contra el padron. (Las consultas se resuelven via funciones del servidor.)

### admin-buscar-paciente.php
Busqueda de pacientes.
- Tablas: via funcion RPC

### admin-buscar-medicamento.php
Busqueda de medicamentos.
- Tablas: medicamentos

### cargar-plantillas.php
Carga plantillas a la base de conocimiento.
- Tablas: knowledge_base

---

## 3. Conexiones del servicio Node (webhook-meta)

Servicio que atiende WhatsApp, en el puerto 3000. Usa la clave de servicio de Supabase.

- Externos: Meta WhatsApp (graph.facebook.com v19.0), OpenAI (chat y transcripcion)
- Modelos: gpt-4o-mini, whisper-1
- Tablas: wa_sesiones, wa_historico, logs_conversacion, agentes

Flujo: recibe el evento de Meta en `/webhook`, consulta o actualiza la sesion en `wa_sesiones`, usa OpenAI para las respuestas del bot, y al cerrar mueve la sesion a `wa_historico` y registra en `logs_conversacion`.

---

## 4. Conexiones de las vistas (frontend)

Las vistas leen datos de Supabase con la clave publica y llaman a los endpoints PHP o al servicio Node para las operaciones del servidor.

### admin.html
- Lee directo: correos, contactos_frecuentes, historial_eventos, sedes, eps_catalogo, tabla_usuarios, usuarios_vip
- Llama: admin-buscar-paciente.php

### agente.html
- Lee directo: correos, agentes, wa_sesiones
- Llama: admin-buscar-paciente.php

### agente-wa.html
- Lee directo: via funciones RPC
- Llama al servicio Node (via /wa/): send, send-audio, send-media, transferir, archivar, iniciar-conversacion, iniciar-encuesta-cierre, continua-en-linea

### pbx.html
- Lee directo: agentes
- Llama: validar-paciente.php

### nova.html
- Lee directo: correos, adjuntos, nova_sesiones, nova_reglas, sedes, via RPC

### consulta.html
- Lee directo: correos, adjuntos, configuracion_sistema
- Es la consulta publica del estado de un ticket por parte del paciente.

### panel_config.html
- Lee directo: configuracion_sistema, sedes

### pqr_form.html
- Lee directo: configuracion_sistema, sedes
- Llama: radicar-pqr.php (al enviar el formulario)

### pqr_encuesta.html
- Lee directo: sedes
- Llama: radicar-encuesta.php

---

## 5. Quien escribe y quien lee cada tabla

| Tabla | Escriben | Leen |
|-------|----------|------|
| correos | radicar-pqr, sincronizar-correos, transcribir-audio, procesar-canvas, whatsapp-webhook, encuesta, radicar-encuesta | admin.html, agente.html, nova.html, consulta.html, nova-consulta |
| agentes | login, servicio Node | agente.html, pbx.html, login, nova-consulta |
| respuestas | endpoints de respuesta | paneles de agente |
| adjuntos | transcribir-audio, procesar-canvas | nova.html, consulta.html |
| historial_eventos | transcribir-audio, procesar-canvas, encuesta, nova-consulta | paneles |
| gestiones_medicamentos | flujo de gestion de medicamentos | paneles |
| encuestas_satisfaccion | radicar-encuesta | reportes y vistas |
| knowledge_base | cargar-plantillas | nova-consulta, nova-td-respuesta |
| wa_sesiones | servicio Node, nova-wa | agente.html, servicio Node |
| wa_historico | servicio Node (al cerrar) | reportes |
| nova_sesiones | nova-consulta, nova-wa | nova.html |
| nova_reglas | configuracion | nova.html, bot Nova |
| logs_conversacion | servicio Node | reportes |
| tabla_usuarios | importacion del padron | validar-paciente, whatsapp-webhook, admin.html |
| usuarios_vip | administracion | admin.html, bot Nova |
| medicamentos | importacion de catalogo | admin-buscar-medicamento |
| eps_catalogo | administracion | admin.html, formularios |
| sedes | administracion | casi todos los flujos (geolocalizacion) |
| configuracion_sistema | panel_config | radicar-pqr, consulta, nova, formularios |

---

## 6. Resumen de dependencias

Quien depende de OpenAI: radicar-pqr, sincronizar-correos, transcribir-audio, procesar-canvas, nova-proxy, nova-td-respuesta, whatsapp-webhook, chatbot, radicar-encuesta y el servicio Node. Si OpenAI falla, se afecta la clasificacion, la transcripcion y las respuestas del bot, pero no la radicacion basica.

Quien depende de Microsoft Graph: radicar-pqr, sincronizar-correos, radicar-encuesta. Si Graph falla, se afecta la sincronizacion de correo y el envio de acuses.

Quien depende de Meta WhatsApp: el servicio Node, whatsapp-webhook y sincronizar-correos. Si Meta falla, se afecta todo el canal de WhatsApp.

Quien depende de Supabase: todos. Es el componente central; si Supabase falla, se detiene el sistema completo.

La tabla mas conectada es `correos`: la escriben siete procesos distintos y la leen casi todas las vistas. Es el centro del modelo y cualquier cambio en su estructura impacta muchos componentes.

La tabla `sedes` es la segunda mas usada, porque casi todos los flujos la consultan para la geolocalizacion de la sede mas cercana.

---

Documentacion tecnica de SIGI — Tododrogas.
