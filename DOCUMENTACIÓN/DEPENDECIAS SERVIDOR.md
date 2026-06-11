# Dependencias del Servidor — SIGI

Sistema Integrado de Gestion Inteligente
Tododrogas CIA SAS — Area de Innovacion y Tecnologia

Inventario de todo lo que debe estar instalado y configurado en el servidor para que SIGI funcione. Pensado como guia para montar un entorno desde cero, especialmente de cara a la migracion a Azure.

---

## Contenido

1. Resumen del stack
2. PHP y sus extensiones
3. Node.js y el servicio de WhatsApp
4. Nginx
5. Configuracion de PHP para produccion
6. Tareas programadas (cron)
7. Servicios externos y credenciales
8. Checklist de migracion a Azure

---

## 1. Resumen del stack

| Componente | Version actual | Rol |
|------------|----------------|-----|
| Sistema operativo | Ubuntu 24.04 | Base |
| Nginx | 1.24 | Servidor web y proxy |
| PHP | 8.3.6 (FPM) | Backend / endpoints |
| Node.js | 20.20.2 | Servicio de WhatsApp (Nova) |
| PM2 | — | Gestor del proceso Node |
| PostgreSQL | (gestionado en Supabase hoy) | Base de datos |

Un dato importante para la migracion: el backend PHP NO usa Composer ni librerias de terceros. Solo usa extensiones estandar de PHP. Esto simplifica mucho el montaje en un entorno nuevo.

---

## 2. PHP y sus extensiones

### Paquetes que se deben instalar

En el servidor actual estan instalados estos paquetes de PHP 8.3:

```
php8.3-cli
php8.3-common
php8.3-curl
php8.3-fpm
php8.3-mbstring
php8.3-opcache
php8.3-readline
```

Comando de instalacion en un servidor Ubuntu nuevo:

```bash
apt-get update
apt-get install -y \
  php8.3-fpm \
  php8.3-cli \
  php8.3-common \
  php8.3-curl \
  php8.3-mbstring \
  php8.3-opcache \
  php8.3-readline
```

### Extensiones que el codigo realmente usa

Verificado sobre el codigo fuente. El backend depende de estas extensiones:

| Extension | Para que la usa SIGI |
|-----------|----------------------|
| curl | Todas las llamadas a servicios externos (OpenAI, Microsoft Graph, Meta, Supabase). Uso intensivo |
| json | Construir y leer las respuestas JSON de todas las APIs. Uso intensivo |
| pcre | Expresiones regulares (validaciones, normalizacion de cedulas, EPS, etc.) |
| mbstring | Manejo de texto con tildes y mayusculas en espanol |
| fileinfo | Deteccion del tipo de archivos adjuntos |
| openssl | Conexiones seguras y firmas |
| date | Manejo de fechas y zona horaria de Colombia |

Todas estas vienen incluidas o se cubren con los paquetes listados arriba. No hay extensiones exoticas.

### Extensiones presentes pero no criticas

El servidor tiene cargadas otras extensiones estandar (calendar, exif, ftp, gettext, sockets, sodium, etc.) que vienen por defecto con la instalacion de PHP y no requieren accion especial.

---

## 3. Node.js y el servicio de WhatsApp

El servicio de Nova en WhatsApp corre sobre Node.js. Requisitos:

```bash
# Node.js 20.x (la version actual es 20.20.2)
# Instalar via nodesource o el metodo que prefiera Azure

# PM2 global para gestionar el proceso
npm install -g pm2
```

Dependencias del servicio (se instalan dentro de /opt/webhook-meta con npm install):

El servicio usa, entre otras: el cliente de Supabase, Express, y librerias para manejar multipart (subida de archivos) y websockets. Estas se instalan automaticamente con:

```bash
cd /opt/webhook-meta
npm install --omit=dev
```

PM2 debe configurarse para arrancar al inicio del sistema:

```bash
pm2 start server.js --name webhook-meta
pm2 save
pm2 startup    # genera el comando para habilitar el arranque automatico
```

---

## 4. Nginx

Nginx sirve el sitio y enruta hacia PHP y hacia el servicio Node. Instalacion:

```bash
apt-get install -y nginx
```

Necesita ademas certificado TLS. En el servidor actual se usa Let's Encrypt (certbot). En Azure puede usarse el certificado gestionado por la plataforma o seguir con Let's Encrypt.

La configuracion del sitio define: el redireccionamiento de HTTP a HTTPS, la raiz en /var/www/pqr, el manejo de archivos PHP por socket FPM, el proxy hacia el servicio Node en el puerto 3000 (ruta /wa/), y la proteccion de config.js por referer.

---

## 5. Configuracion de PHP para produccion

Valores actuales relevantes (en php.ini de FPM):

| Parametro | Valor actual | Por que importa |
|-----------|--------------|-----------------|
| upload_max_filesize | 32M | Tamano maximo de adjuntos que sube un paciente |
| post_max_size | 32M | Debe ser igual o mayor que upload_max_filesize |
| max_file_uploads | 20 | Numero de archivos por peticion |
| max_execution_time | 0 (sin limite en CLI) | Para procesos largos como la sincronizacion de correos |
| memory_limit | sin limite en CLI | — |

Al montar el entorno nuevo, conviene replicar al menos upload_max_filesize y post_max_size en 32M, porque el manejo de adjuntos depende de ello. Si quedan en el valor por defecto (2M), las subidas de documentos fallaran.

Nota: el client_max_body_size de Nginx tambien debe estar en 32M (ya lo esta), porque limita el tamano de subida antes incluso de llegar a PHP.

---

## 6. Tareas programadas (cron)

Dos tareas cron deben existir en el servidor:

```
# Sincronizar correos de Outlook cada minuto
* * * * * php /var/www/pqr/sincronizar-correos.php >> /var/log/sync-correos.log 2>&1

# Renovar el token de Meta el dia 1 y 15 de cada mes
0 3 1,15 * * /opt/webhook-meta/renovar-token.sh >> /var/log/renovar-token.log 2>&1
```

Al migrar, recrear ambas. En Azure, segun el modelo de hosting, podrian implementarse como cron del sistema (si es una VM) o como tareas programadas / funciones (si es un servicio gestionado).

---

## 7. Servicios externos y credenciales

SIGI depende de estos servicios externos. En el entorno nuevo hay que configurar las credenciales de cada uno:

| Servicio | Credencial necesaria | Donde se usa |
|----------|----------------------|--------------|
| Supabase / PostgreSQL | URL del proyecto, clave de servicio, clave publica | Toda la persistencia |
| OpenAI | Clave de API | Clasificacion, resumenes, transcripcion, voz, Nova |
| Microsoft Graph | Tenant ID, Client ID, Client Secret | Lectura y envio de correo de Outlook |
| Meta WhatsApp | Token de acceso, Phone Number ID, App Secret, Verify Token | Canal de WhatsApp |

Las credenciales hoy se gestionan como secretos en GitHub y se inyectan en el despliegue. En Azure se recomienda usar Azure Key Vault para almacenarlas, en lugar de inyectarlas en archivos.

---

## 8. Checklist de migracion a Azure

Pasos sugeridos para levantar SIGI en Azure, en orden:

Base de datos:
- Crear una instancia de Azure Database for PostgreSQL.
- Dimensionar el tier mirando el consumo actual en Supabase (espacio, conexiones, computo).
- Migrar el esquema y los datos (ver estructura-base-de-datos.md para el esquema completo).
- Replicar las funciones, triggers y politicas RLS (documentar todas las politicas actuales ANTES de migrar; los roles anon/service_role de Supabase no existen igual en Azure).
- Revisar que el almacenamiento de archivos (hoy Supabase Storage) tenga equivalente: Azure Blob Storage.

Servidor de aplicacion:
- Provisionar el computo (VM o App Service) con Ubuntu o equivalente.
- Instalar PHP 8.3 con las extensiones de la seccion 2.
- Instalar Node.js 20 y PM2.
- Instalar y configurar Nginx con TLS.
- Replicar la configuracion de PHP (seccion 5).

Codigo y configuracion:
- Desplegar el codigo (los endpoints PHP y el servicio Node).
- Configurar las credenciales (idealmente en Azure Key Vault).
- Crear las politicas RLS de todas las tablas (ver operacion-incidentes.md, seccion 4). Sin politicas, RLS bloquea el acceso y las tablas no cargan ni guardan datos.
- Recrear las dos tareas cron (seccion 6).
- Aplicar las restricciones de acceso por dominio (ver operacion-incidentes.md, seccion 5).

Verificacion:
- Probar el flujo de radicacion web.
- Probar la sincronizacion de correos.
- Probar Nova en WhatsApp (incluida la renovacion del token).
- Probar los paneles de agente y administrador.
- Revisar la capacidad de la base de datos bajo carga.

Ventaja de partida: como el backend PHP no usa Composer ni dependencias externas, y el servicio Node tiene dependencias estandar, la migracion del codigo es directa. El mayor cuidado esta en la base de datos (esquema, funciones, RLS, storage) y en reconfigurar las credenciales y los crons.

---

Documentacion tecnica de SIGI — Tododrogas CIA SAS.
