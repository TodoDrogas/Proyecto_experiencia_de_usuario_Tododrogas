# Arquitectura — SIGI

Sistema Integrado de Gestion Inteligente
Tododrogas — Procesos Digitales

Este documento describe como esta construido el sistema por dentro: los componentes, como se comunican entre si y como fluye la informacion de punta a punta. Complementa al README, que da la vision general.

---

## Contenido

1. Diagrama de componentes
2. Infraestructura del servidor
3. Componentes en detalle
4. Flujo por canal
5. Comunicacion entre componentes
6. Manejo de credenciales y despliegue
7. Tareas programadas
8. Configuracion heredada por revisar

---

## 1. Diagrama de componentes

```
                            Internet
                               |
                               v
        ┌──────────────────────────────────────────────┐
        |              Nginx (puerto 443)               |
        |   Sirve los HTML, enruta PHP y proxys Node    |
        └──────────────────────────────────────────────┘
              |              |                |
              v              v                v
     ┌─────────────┐  ┌─────────────┐  ┌──────────────────┐
     |  Vistas HTML |  | PHP 8.3-FPM |  | Node webhook-meta|
     | (navegador)  |  | (endpoints) |  |  PM2 :3000       |
     └─────────────┘  └─────────────┘  └──────────────────┘
              |              |                |
              |              v                |
              |        ┌──────────┐           |
              └───────>| Supabase |<──────────┘
                       | Postgres |
                       | + Storage|
                       └──────────┘
                            ^
              servicios externos consumidos por PHP/Node:
        OpenAI · Microsoft Graph (Outlook) · Meta WhatsApp API
```

---

## 2. Infraestructura del servidor

El sistema corre en un unico VPS (Hostinger).

| Elemento | Version / detalle |
|----------|-------------------|
| Sistema operativo | Ubuntu |
| Servidor web | Nginx 1.24.0 |
| Backend | PHP 8.3.6 (FPM) |
| Runtime de servicios | Node.js v20.20.2 |
| Gestor de procesos Node | PM2 |
| Dominio | tododrogas.online |
| TLS | Let's Encrypt |

Distribucion en disco:

- `/var/www/pqr/` — raiz del sitio servida por Nginx: vistas HTML, endpoints PHP, `config.js`.
- `/opt/webhook-meta/` — servicio Node que atiende WhatsApp, fuera de la raiz web.

---

## 3. Componentes en detalle

### Nginx

Es la puerta de entrada. Cumple tres funciones:

1. Sirve los archivos estaticos (las vistas HTML y `config.js`).
2. Pasa las peticiones a archivos `.php` al interprete PHP-FPM por socket Unix.
3. Actua como proxy inverso hacia el servicio Node para las rutas de WhatsApp.

Ruta de WhatsApp activa:

```
/wa/  ->  http://localhost:3000/
```

### PHP-FPM (logica de negocio)

Los endpoints PHP ejecutan toda la logica del lado del servidor y son los unicos que manejan las credenciales sensibles (clave de servicio de Supabase, OpenAI, Microsoft Graph). El navegador nunca habla directo con esos servicios; pasa por estos endpoints.

Agrupados por funcion:

PQRSFD y correos:
- `radicar-pqr.php` — radica una nueva PQRSFD desde el formulario web.
- `sincronizar-correos.php` — trae los correos nuevos de Outlook y los registra como tickets (ejecutado por cron).
- `transcribir-audio.php` — sube el audio a Storage y lo transcribe con Whisper.
- `procesar-canvas.php` — sube imagenes y documentos adjuntos a Storage y los registra.

Bot Nova y WhatsApp:
- `nova-consulta.php` — backend principal del bot.
- `nova-proxy.php` — proxy seguro del bot, incluye respuestas en audio (texto a voz).
- `nova-td-respuesta.php` — generacion de respuestas del bot.
- `nova-wa.php` — logica del bot para WhatsApp.
- `whatsapp-webhook.php` — recepcion de eventos de WhatsApp del lado PHP.
- `chatbot.php` — logica conversacional.

Encuestas:
- `radicar-encuesta.php` — registra una encuesta de satisfaccion.
- `encuesta.php` — recibe la calificacion embebida en un correo.

Autenticacion:
- `login.php` — valida el acceso de administrador y agentes. Las credenciales se verifican en el servidor.

Busquedas y validacion:
- `validar-paciente.php` — valida la identidad del paciente contra el padron.
- `admin-buscar-paciente.php` — busqueda de pacientes.
- `admin-buscar-medicamento.php` — busqueda de medicamentos.
- `cargar-plantillas.php` — carga plantillas a la base de conocimiento.

### Servicio Node (webhook-meta)

Servicio Express que corre bajo PM2 en el puerto 3000, con el nombre `webhook-meta`. Recibe los eventos de WhatsApp de Meta y gestiona el envio de mensajes salientes. Expone estas rutas:

| Ruta | Funcion |
|------|---------|
| `/webhook` | Recibe los eventos entrantes de Meta (mensajes del paciente) |
| `/send` | Envia un mensaje de texto |
| `/send-audio` | Envia un mensaje de audio |
| `/send-media` | Envia un archivo o imagen |
| `/iniciar-conversacion` | Inicia una conversacion |
| `/transferir` | Transfiere la sesion a un agente humano |
| `/archivar` | Cierra y archiva la sesion |
| `/heartbeat` | Senal de actividad |
| `/continua-en-linea` | Verifica si la sesion sigue activa |
| `/iniciar-encuesta-cierre` | Lanza la encuesta al cerrar |

El servicio se accede desde el exterior a traves del proxy `/wa/` de Nginx.

### Supabase (datos)

Base de datos PostgreSQL y almacenamiento de archivos. Guarda los tickets, las sesiones, los catalogos y los adjuntos. La estructura completa esta en `estructura-base-de-datos.md`.

Hay dos formas de acceso:
- Los endpoints PHP y el servicio Node usan la clave de servicio (acceso completo, solo en el servidor).
- El frontend usa la clave publica para las consultas que hace directamente desde el navegador.

---

## 4. Flujo por canal

### Correo electronico

```
Outlook
  -> cron cada minuto ejecuta sincronizar-correos.php
  -> Microsoft Graph API trae los correos nuevos
  -> IA clasifica (tipo, sentimiento, riesgo, resumen)
  -> se inserta el ticket en la tabla correos
  -> aparece en el panel del agente
```

### WhatsApp

```
Paciente escribe por WhatsApp
  -> Meta envia el evento a /webhook (servicio Node, via proxy /wa/)
  -> el bot Nova procesa el mensaje
  -> si Nova resuelve: responde automaticamente
  -> si no: transfiere a un agente (/transferir) y se registra la sesion
  -> al cerrar: se envia encuesta (/iniciar-encuesta-cierre) y se archiva
```

### Formulario web

```
Paciente llena pqr_form.html
  -> radicar-pqr.php valida y procesa (incluye lectura de adjuntos con IA)
  -> se inserta el ticket en la tabla correos
  -> se envia acuse de recibo
```

### Llamada telefonica

```
Llamada entra al modulo PBX
  -> el agente registra manualmente desde pbx.html
  -> se crea el ticket asociado al canal PBX
```

---

## 5. Comunicacion entre componentes

El navegador del usuario nunca se comunica con los servicios externos (OpenAI, Graph, Meta) directamente. Toda llamada sensible pasa por el servidor:

```
Navegador  ->  Nginx  ->  PHP-FPM  ->  Servicio externo
                                   ->  Supabase
```

Para WhatsApp el flujo es inverso (el evento llega de afuera):

```
Meta  ->  Nginx (/wa/)  ->  Node :3000  ->  Supabase
                                        ->  OpenAI (Nova)
```

Las vistas del panel (admin, agente) tambien leen datos directamente de Supabase usando la clave publica, para refrescar listados en tiempo real.

---

## 6. Manejo de credenciales y despliegue

Las credenciales no estan en el codigo. Se guardan como secretos en GitHub y se inyectan durante el despliegue, que es automatico al hacer push a la rama `main`.

Pasos del despliegue (definidos en `.github/workflows/deploy.yml`):

1. Genera `config.js` con los valores del frontend (incluida la clave publica de Supabase).
2. Inyecta los secretos en los endpoints PHP, reemplazando marcadores `__NOMBRE__`.
3. Minifica el codigo de las vistas HTML.
4. Copia los archivos al VPS por SSH.
5. Genera el `.env` del servicio Node y lo copia a `/opt/webhook-meta/`.
6. Reinicia el servicio con PM2.
7. Aplica migraciones pendientes en la base de datos.
8. Valida que no queden marcadores sin reemplazar.

En cada despliegue se crea una copia de respaldo del `server.js` anterior con marca de tiempo.

---

## 7. Tareas programadas

| Cron | Frecuencia | Que hace |
|------|------------|----------|
| `sincronizar-correos.php` | Cada minuto | Trae correos nuevos de Outlook y los registra como tickets |
| `renovar-token.sh` | Ver nota | Renueva el token de acceso |

Nota sobre `renovar-token.sh`: la expresion de cron actual (`0 3 5 7 *`) lo ejecuta solo una vez al ano, el 5 de julio a las 3:00 AM. Conviene revisar si la frecuencia es la correcta, dado que los tokens de acceso suelen requerir renovacion periodica.

---

## 8. Configuracion heredada por revisar

Durante la documentacion se identificaron elementos que parecen restos de etapas anteriores y que conviene revisar:

- Proxy `/webhook/` en Nginx apuntando a un servicio externo en el puerto 5678. Si ya no se usa automatizacion externa, este bloque puede retirarse.
- Proxy `/twilio/` en Nginx hacia el puerto 3000. Si WhatsApp opera solo por Meta, este proxy puede retirarse.
- Acumulacion de respaldos `server.js.bak.*` en `/opt/webhook-meta/`. Conviene conservar solo los mas recientes y limpiar los antiguos.

Estos puntos no afectan la operacion actual, pero mantenerlos limpios facilita el mantenimiento.

---

Documentacion tecnica de SIGI — Tododrogas CIA SAS.
