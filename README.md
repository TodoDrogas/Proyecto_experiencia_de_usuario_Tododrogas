# SIGI — Sistema Integrado de Gestion Inteligente

Plataforma omnicanal de gestion de PQRSFD (Peticiones, Quejas, Reclamos, Sugerencias, Felicitaciones y Denuncias) para Tododrogas. Centraliza en un solo lugar las comunicaciones de pacientes que llegan por correo, WhatsApp, llamada y formularios web, las clasifica con ayuda de inteligencia artificial y permite a los agentes gestionarlas con trazabilidad completa.

Desarrollado por el Area de Innovacion y Tecnologia.

---

## Que hace el sistema

Cuando un paciente se comunica con Tododrogas por cualquier canal, SIGI:

1. Recibe el mensaje (correo, WhatsApp, llamada o formulario web).
2. Lo registra como un ticket en una base de datos central.
3. Lo analiza con IA: clasifica el tipo de PQRSFD, detecta el sentimiento, evalua el nivel de riesgo y genera un resumen.
4. Lo asigna a un agente y controla los tiempos de respuesta (SLA).
5. Permite responder desde un panel unificado y, al cerrar, envia una encuesta de satisfaccion.

Sobre WhatsApp opera ademas un bot llamado Nova, que atiende de forma automatica las consultas mas comunes y escala a un agente humano cuando es necesario.

---

## Arquitectura general

El sistema se compone de cuatro capas:

Capa de presentacion. Vistas HTML que consume el usuario: panel de administracion, panel de agente, modulo PBX, chat de Nova y los formularios publicos de radicacion y encuesta.

Capa de logica de negocio. Endpoints en PHP que ejecutan las operaciones del lado del servidor: radicar PQRSFD, sincronizar correos, transcribir audios, clasificar documentos, responder consultas de Nova, validar pacientes, entre otros. Estos endpoints son los unicos que manejan las credenciales sensibles.

Capa de mensajeria en tiempo real. Servicios en Node.js que reciben los webhooks de WhatsApp (Meta y Twilio) y los procesan. Corren bajo PM2 como procesos persistentes.

Capa de datos. Base de datos PostgreSQL gestionada en Supabase, mas almacenamiento de archivos (Supabase Storage) para adjuntos y audios.

---

## Stack tecnologico

| Componente | Tecnologia |
|------------|------------|
| Servidor web | Nginx |
| Backend | PHP 8.3 (FPM) |
| Servicios de mensajeria | Node.js con PM2 |
| Base de datos | Supabase (PostgreSQL Pro) |
| Almacenamiento de archivos | Supabase Storage |
| Inteligencia artificial | OpenAI (GPT-4o-mini, GPT-4o Vision, Whisper, TTS) |
| Correo | Microsoft Graph API (Outlook) |
| WhatsApp | Meta WhatsApp Business API y Twilio |
| Hosting | VPS Hostinger (KVM) |
| Despliegue | GitHub Actions (CI/CD) |

---

## Canales de entrada

| Canal | Como ingresa | Procesamiento |
|-------|--------------|---------------|
| Correo electronico | Microsoft Graph API sincroniza la bandeja | Clasificacion automatica con IA |
| WhatsApp | Webhook de Meta o Twilio | Atendido por el bot Nova, con escalamiento a agente |
| Llamada telefonica | Modulo PBX | Registro manual del agente |
| Formulario web | Formulario publico de radicacion | Insercion directa del ticket |

Todos los canales convergen en la misma tabla central de tickets, identificada por la columna `canal_contacto`.

---

## Estructura del proyecto

```
public/
  Vistas (HTML)
    admin.html              Panel de administracion principal
    admin-wa.html           Administracion de WhatsApp
    admin-pbx.html          Administracion del modulo PBX
    agente.html             Panel del agente
    agente-wa.html          Panel del agente para WhatsApp
    pbx.html                Modulo de llamadas
    nova.html               Interfaz del bot Nova
    nova-td-chat.html       Chat de Nova
    pqr_form.html           Formulario publico de radicacion
    pqr_encuesta.html       Formulario de encuesta
    pqr_bienvenida.html     Pagina de bienvenida
    consulta.html           Consulta publica del estado de un ticket
    clasificador.html       Clasificador de documentos
    gestor_pdf.html         Gestor de documentos PDF
    panel_config.html       Configuracion del sistema
    login.html              Acceso al sistema

  Endpoints de logica (PHP)
    PQRSFD y correos
      radicar-pqr.php          Radica una nueva PQRSFD
      sincronizar-correos.php  Sincroniza la bandeja de Outlook (cron cada minuto)
      transcribir-audio.php    Transcribe audios con Whisper
      procesar-canvas.php      Procesa imagenes y documentos adjuntos
      clasificador-proxy.php   Clasifica documentos con GPT-4o Vision

    Bot Nova y WhatsApp
      nova-consulta.php        Backend principal de Nova
      nova-proxy.php           Proxy seguro de Nova (incluye texto a voz)
      nova-td-respuesta.php    Generacion de respuestas de Nova
      nova-wa.php              Logica de Nova para WhatsApp
      whatsapp-webhook.php     Recepcion de webhooks de WhatsApp
      chatbot.php              Logica conversacional

    Encuestas
      radicar-encuesta.php     Registra una encuesta de satisfaccion
      encuesta.php             Recibe la calificacion embebida en correo

    Autenticacion
      login.php                Validacion de acceso de administrador y agentes
      login_gestor.php         Acceso del gestor de documentos

    Busquedas y validacion
      validar-paciente.php          Valida la identidad del paciente contra el padron
      admin-buscar-paciente.php     Busqueda de pacientes
      admin-buscar-medicamento.php  Busqueda de medicamentos
      cargar-plantillas.php         Carga plantillas a la base de conocimiento

  config.js                 Configuracion del frontend (generada en el despliegue)

  webhook-meta/
    server.js               Servicio Node que recibe los webhooks de Meta WhatsApp
```

Los servicios Node de produccion corren fuera del directorio web, en `/opt/webhook-meta` y `/opt/webhook-twilio`, gestionados por PM2.

---

## Inteligencia artificial

El sistema usa los modelos de OpenAI para distintas tareas:

| Modelo | Uso |
|--------|-----|
| GPT-4o-mini | Clasificacion de PQRSFD, analisis de sentimiento, generacion de resumenes y respuestas de Nova |
| GPT-4o (Vision) | Lectura y clasificacion de documentos e imagenes adjuntas |
| Whisper | Transcripcion de mensajes de voz a texto |
| TTS (texto a voz) | Respuestas en audio de Nova |

---

## Base de datos

La estructura completa de la base de datos esta documentada en `estructura-base-de-datos.md`. En resumen, el modelo se organiza en torno a la tabla `correos` (tickets), con tablas de soporte para agentes, sesiones de WhatsApp, adjuntos, respuestas, encuestas, catalogos (medicamentos, EPS, sedes) y registros de auditoria.

---

## Manejo de credenciales

Las credenciales (claves de API, tokens, accesos a base de datos) no estan en el codigo fuente. Se almacenan como secretos en GitHub y se inyectan en los archivos durante el despliegue, reemplazando marcadores de posicion del tipo `__NOMBRE__`.

El frontend recibe unicamente la clave publica de Supabase a traves de `config.js`, que se genera en cada despliegue. Las claves sensibles (clave de servicio de Supabase, OpenAI, Microsoft Graph, Meta) viven solo en el servidor, dentro de los endpoints PHP y los servicios Node.

---

## Despliegue

El despliegue es automatico mediante GitHub Actions. Al hacer push a la rama `main`:

1. Se genera `config.js` con los valores del frontend.
2. Se inyectan los secretos en los endpoints PHP.
3. Se minifica el codigo de las vistas HTML.
4. Se copian los archivos al VPS por SSH.
5. Se reinician los servicios Node con PM2.
6. Se aplican migraciones pendientes en la base de datos.
7. Se valida que no queden marcadores de posicion sin reemplazar.

El flujo completo esta definido en `.github/workflows/deploy.yml`.

---

## Tareas programadas

Un cron en el servidor ejecuta `sincronizar-correos.php` cada minuto para traer los correos nuevos de la bandeja de Outlook y registrarlos como tickets.

---

## Convenciones

- Identificador de ticket: formato `CANAL-identificador-fecha-secuencia`, legible para soporte.
- Estados de un ticket: nuevo, en proceso, resuelto, cerrado, escalado.
- Tipos de PQRSFD: Peticion, Queja, Reclamo, Sugerencia, Felicitacion, Denuncia.
- Las fechas se almacenan con zona horaria (timestamptz), referidas a la hora de Colombia.

---

Documentacion tecnica de SIGI — Tododrogas CIA SAS.
