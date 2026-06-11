# Plan de Migracion a Azure — SIGI

Sistema Integrado de Gestion Inteligente
Tododrogas — Procesos Digitales

Plan completo para migrar SIGI desde la infraestructura actual (VPS Hostinger + Supabase) a Azure, asegurando que toda la funcionalidad se conserve. El plan se basa en tres pilares: inventario exacto de lo que hay que mover, verificacion de cada funcion despues de migrar, y un plan de rollback por si algo falla.

> Principio rector: la migracion no se considera exitosa cuando "esta todo movido", sino cuando cada funcion del sistema pasa su prueba de verificacion. Mientras una prueba no pase, esa parte no esta migrada.

---

## Contenido

1. Filosofia de la migracion (como asegurar el 100%)
2. Inventario completo de componentes a migrar
3. Equivalencias: de la infraestructura actual a Azure
4. Puntos criticos que suelen romper una migracion
5. Plan de migracion por fases
6. Lista de verificacion funcional (la prueba del 100%)
7. Plan de rollback
8. Despues de migrar

---

## 1. Filosofia de la migracion (como asegurar el 100%)

Ningun plan garantiza por si solo que nada falle. Lo que garantiza la funcionalidad es la combinacion de tres cosas:

1. Migracion en paralelo, no en reemplazo. Se monta SIGI en Azure mientras el sistema actual sigue corriendo. No se apaga nada hasta que Azure pase todas las pruebas.
2. Verificacion funcion por funcion. Cada capacidad del sistema (radicar, sincronizar correos, Nova web, Nova WhatsApp, paneles) tiene una prueba concreta. Se migra y se prueba, una por una.
3. Rollback siempre disponible. Mientras dura la transicion, se puede volver al sistema actual en minutos cambiando el DNS. El sistema viejo no se toca hasta el final.

Esto convierte "migrar y rezar" en "migrar, verificar y confirmar". El riesgo baja a casi cero porque en ningun momento se depende de que Azure funcione a la primera.

---

## 2. Inventario completo de componentes a migrar

### Aplicacion

| Componente | Cantidad | Detalle |
|------------|----------|---------|
| Endpoints PHP | 19 | Logica de negocio |
| Vistas HTML | 25 | Interfaz |
| JS de soporte | 1 | geo-sede.js |
| Servicio Node | 1 | webhook-meta (Nova WhatsApp) |

### Base de datos

| Elemento | Cantidad | Documento de referencia |
|----------|----------|-------------------------|
| Tablas | 21 | estructura-base-de-datos.md |
| Vistas | 7 | estructura-base-de-datos.md |
| Triggers | 12 | estructura-base-de-datos.md |
| Funciones de trigger | 5 | estructura-base-de-datos.md |
| Politicas RLS | 45 | politicas-rls-actuales.md |
| Foreign keys | 9 | estructura-base-de-datos.md |

### Funciones RPC (criticas — se invocan desde el codigo)

Estas funciones se llaman directamente desde la aplicacion. Si no se recrean en Azure, las partes que las usan fallan:

| Funcion RPC | La usan | Para que |
|-------------|---------|----------|
| buscar_paciente | admin.html, agente.html, agente-wa.html, admin-buscar-paciente.php | Busqueda de pacientes |
| registrar_sesion_nova | nova.html, pqr_bienvenida.html | Registrar sesion de Nova web |
| cerrar_sesion_nova | nova.html | Cerrar sesion de Nova web |

### Almacenamiento de archivos (Supabase Storage → Azure Blob)

Ocho buckets con contenido que hay que migrar (el codigo referencia algunos directamente, pero en Storage existen los ocho):

| Bucket | Limite | Contenido |
|--------|--------|-----------|
| adjuntos-pqr | 50 MB | Adjuntos de las PQRSFD (bucket principal) |
| wa-media | 20 MB | Archivos de WhatsApp (audio, imagen, video, documentos) |
| audios | 10 MB | Mensajes de voz para transcribir |
| canvas-images | 5 MB | Imagenes y documentos procesados con vision |
| logos-config | 5 MB | Logos e imagenes de configuracion |
| POLITICAS TRATAMIENTO DE DATOS | 50 MB | PDF de la politica de privacidad |
| TURNEROS | 50 MB | Videos de turnero |
| MEDIOS H | 50 MB | Medios varios |

El detalle completo (tipos de archivo permitidos por bucket) esta en estructura-base-de-datos.md, seccion 8.1.

### Servicios externos (se reconfiguran, no se migran)

| Servicio | Referencias en el codigo | Accion |
|----------|--------------------------|--------|
| OpenAI | 19 | Misma clave de API, sigue funcionando |
| Supabase Storage | 16 | Migrar a Azure Blob Storage |
| Microsoft Graph | 12 | Mismas credenciales Azure (ya es de Microsoft) |
| Meta WhatsApp | 7 | Mismo token, actualizar URL del webhook |

### Tareas programadas

| Cron | Funcion |
|------|---------|
| sincronizar-correos.php (cada minuto) | Traer correos de Outlook |
| renovar-token.sh (dia 1 y 15) | Renovar token de Meta |

---

## 3. Equivalencias: de la infraestructura actual a Azure

| Hoy | En Azure | Notas |
|-----|----------|-------|
| VPS Hostinger | Azure VM o App Service | Una VM con Ubuntu replica el entorno tal cual; App Service requiere mas adaptacion |
| Nginx | Nginx (en VM) o el balanceador de App Service | En VM, se copia la configuracion casi igual |
| PHP 8.3 FPM | PHP 8.3 (mismas extensiones) | Ver dependencias-servidor.md |
| Node.js + PM2 | Node.js + PM2 (en VM) | Igual en una VM |
| Supabase PostgreSQL | Azure Database for PostgreSQL | El motor es el mismo; cambian los roles (ver punto critico) |
| Supabase Storage | Azure Blob Storage | Cambia la API de subida/descarga de archivos |
| Supabase RPC | Funciones de PostgreSQL en Azure | Se recrean tal cual (son funciones SQL estandar) |
| Secretos en GitHub | Azure Key Vault | Mejora: las credenciales no van en archivos |
| Let's Encrypt | Certificado gestionado de Azure o Let's Encrypt | — |

Recomendacion de modelo: la opcion mas segura para conservar la funcionalidad al 100 por ciento es usar una Azure VM con Ubuntu, porque replica el entorno actual casi identico (Nginx, PHP-FPM, PM2). App Service es mas moderno pero obliga a adaptar mas cosas, lo que aumenta el riesgo en esta primera migracion. Se puede modernizar despues, una vez estable.

---

## 4. Puntos criticos que suelen romper una migracion

Estos son los puntos que, si se pasan por alto, hacen que algo falle en silencio. Cada uno tiene su mitigacion.

### 4.1 Roles de base de datos (el mas importante)

Supabase crea automaticamente los roles anon, authenticated y service_role. En Azure NO existen. Las 45 politicas RLS apuntan a esos roles, y dos politicas usan la funcion auth.role() que tambien es de Supabase.

Mitigacion: decidir el modelo de acceso en Azure antes de migrar. Dos caminos (detallados en politicas-rls-actuales.md):
- Recrear roles equivalentes en Azure y ajustar las politicas.
- O cambiar el modelo: que toda la aplicacion pase por el backend con un unico rol, y controlar el acceso en los endpoints PHP. Este camino es mas simple y mas seguro, y aprovecha que el backend ya centraliza la mayoria de operaciones.

### 4.2 Almacenamiento de archivos

El codigo sube y descarga archivos de Supabase Storage con una API especifica (rutas storage/v1/object/...). Azure Blob Storage usa otra API. Los 16 puntos del codigo que tocan Storage hay que adaptarlos.

Mitigacion: identificar los archivos que manejan Storage (transcribir-audio.php, procesar-canvas.php, radicar-pqr.php, entre otros) y adaptar las llamadas de subida/descarga a Blob Storage. Migrar tambien el contenido existente de los cuatro buckets.

### 4.3 URLs hardcodeadas en el codigo

El servicio Node de Nova llama a endpoints con la URL completa escrita en el codigo:
- https://tododrogas.online/nova-consulta.php
- https://tododrogas.online/nova-proxy.php
- https://tododrogas.online/nova-wa.php

Si el dominio o la estructura cambian en Azure y estas URLs no se actualizan, Nova deja de funcionar sin dar un error obvio.

Mitigacion: revisar y actualizar estas URLs (idealmente moverlas a una variable de entorno en lugar de tenerlas fijas en el codigo). Buscar en todo el codigo cualquier referencia a tododrogas.online antes de migrar.

### 4.4 Funciones RPC

Las tres funciones RPC (buscar_paciente, registrar_sesion_nova, cerrar_sesion_nova) viven en la base de datos. Si se migra solo el esquema de tablas pero no las funciones, la busqueda de pacientes y el registro de sesiones de Nova fallan.

Mitigacion: exportar la definicion de las tres funciones desde Supabase y recrearlas en Azure. Verificar que existan antes de dar por migrada la base.

### 4.5 La politica de privacidad y el flujo de datos

El sistema no guarda datos del paciente hasta que acepta la politica. El PDF de la politica esta en un bucket de Storage y se referencia desde la configuracion. Si en Azure ese archivo o ese registro de configuracion no existe, el flujo de aceptacion se rompe y el sistema no guarda datos.

Mitigacion: migrar el PDF de la politica y verificar el registro de configuracion que lo referencia.

### 4.6 Cron y zona horaria

Los crons dependen de la hora del servidor. Nova calcula el horario de atencion en zona horaria de Colombia. Si la VM de Azure queda en UTC u otra zona, los horarios y los crons se desfasan.

Mitigacion: configurar la zona horaria del servidor o verificar que el codigo siempre convierta a America/Bogota (Nova ya lo hace internamente, pero conviene confirmarlo).

---

## 5. Plan de migracion por fases

### Fase 0 — Preparacion (sin tocar produccion)

- Documentar y exportar todo: esquema, datos, funciones RPC, politicas RLS (ya esta en los documentos de esta carpeta).
- Generar el inventario literal de politicas (politicas-rls-actuales.md, ya hecho).
- Exportar las definiciones de las 3 funciones RPC y los 12 triggers desde Supabase.
- Listar todas las credenciales necesarias (dependencias-servidor.md, seccion 7).
- Decidir el modelo de acceso en Azure (punto 4.1).

### Fase 1 — Infraestructura base en Azure

- Provisionar la Azure VM (Ubuntu) o el App Service.
- Instalar PHP 8.3 con extensiones, Node.js 20, PM2, Nginx (dependencias-servidor.md).
- Crear Azure Database for PostgreSQL.
- Crear los contenedores de Azure Blob Storage equivalentes a los ocho buckets.
- Configurar Azure Key Vault con las credenciales.

### Fase 2 — Migrar la base de datos

- Crear las 21 tablas con su esquema (estructura-base-de-datos.md).
- Recrear los 12 triggers y sus 5 funciones.
- Recrear las 3 funciones RPC.
- Habilitar RLS y aplicar las politicas segun el modelo elegido (politicas-rls-actuales.md).
- Recrear las 9 foreign keys y los indices.
- Migrar los datos.
- Verificar conteos: que el numero de filas de cada tabla coincida con el origen.

### Fase 3 — Migrar el almacenamiento

- Copiar el contenido de los ocho buckets a Azure Blob.
- Adaptar el codigo que sube/descarga archivos (punto 4.2).

### Fase 4 — Desplegar la aplicacion

- Desplegar los endpoints PHP y las vistas.
- Adaptar las URLs hardcodeadas (punto 4.3).
- Desplegar el servicio Node y configurarlo con PM2.
- Configurar las credenciales desde Key Vault.
- Configurar Nginx (incluida la proteccion por dominio).
- Crear los dos crons.

### Fase 5 — Pruebas en paralelo (sin cambiar el DNS todavia)

- Apuntar a Azure con un dominio temporal o el archivo hosts.
- Ejecutar la lista de verificacion completa (seccion 6).
- Actualizar el webhook de Meta a la URL de Azure SOLO para pruebas, o usar un numero de prueba.

### Fase 6 — Corte (cuando todo pasa las pruebas)

- Cambiar el DNS de tododrogas.online para que apunte a Azure.
- Actualizar el webhook de Meta a la URL definitiva de Azure.
- Vigilar el sistema de cerca las primeras horas.
- Mantener el sistema viejo encendido unos dias por si hay que volver.

---

## 6. Lista de verificacion funcional (la prueba del 100%)

Cada item debe pasar antes de considerar la migracion exitosa. Si uno falla, esa funcion no esta migrada.

Radicacion y correos:
- [ ] Radicar una PQRSFD desde el formulario web crea el ticket.
- [ ] El ticket recibe clasificacion de IA (tipo, sentimiento, resumen).
- [ ] Subir un adjunto en la radicacion lo guarda y lo asocia al ticket.
- [ ] El cron de sincronizacion trae un correo de prueba de Outlook como ticket.
- [ ] Se envia el acuse de recibo.

Nova web (nova.html):
- [ ] Carga la pagina y muestra la politica.
- [ ] Identifica un paciente por cedula (funcion buscar_paciente).
- [ ] Registra la sesion (funcion registrar_sesion_nova).
- [ ] Responde una pregunta libre (IA via proxy).
- [ ] Consulta de sedes por municipio funciona.
- [ ] Cierra la sesion (funcion cerrar_sesion_nova).

Nova WhatsApp:
- [ ] Un mensaje de WhatsApp llega al webhook y Nova responde.
- [ ] El menu numerado 1-8 funciona.
- [ ] La identificacion por cedula funciona.
- [ ] La transcripcion de un audio funciona (Whisper + Storage).
- [ ] El escalamiento a un agente asigna correctamente.
- [ ] La renovacion del token de Meta funciona en el entorno nuevo.

Paneles:
- [ ] Login de administrador y de agente.
- [ ] El panel de agente carga los tickets.
- [ ] El panel de admin carga datos (correos, contactos, sedes).
- [ ] Buscar un paciente desde el panel funciona.
- [ ] Generar una respuesta sugerida a un correo funciona (nova-td-respuesta).

Encuestas:
- [ ] Responder una encuesta de satisfaccion la registra.

Almacenamiento:
- [ ] Subir y descargar un archivo de cada bucket funciona.
- [ ] El PDF de la politica se ve correctamente.

Seguridad:
- [ ] config.js solo carga desde el dominio (no desde otro origen).
- [ ] Los endpoints sensibles rechazan origenes no autorizados.
- [ ] Las tablas responden segun las politicas definidas.

---

## 7. Plan de rollback

Mientras dura la transicion, el sistema viejo permanece encendido e intacto. Si algo falla en Azure tras el corte:

1. Revertir el DNS de tododrogas.online al servidor viejo (propagacion en minutos si el TTL es bajo).
2. Revertir el webhook de Meta a la URL del servidor viejo.
3. El sistema viejo retoma la operacion como si nada hubiera pasado.

Preparacion para que el rollback sea rapido:
- Bajar el TTL del DNS a 300 segundos unos dias ANTES del corte, para que el cambio propague rapido.
- No apagar ni modificar el servidor viejo hasta varios dias despues de confirmar que Azure es estable.
- Tener anotada la URL del webhook viejo de Meta para revertirla al instante.

Consideracion sobre los datos: si el sistema opera en Azure durante un tiempo y luego se hace rollback, los datos generados en Azure (tickets, sesiones) habria que traerlos de vuelta al sistema viejo. Por eso el periodo de prueba en paralelo (Fase 5) es clave: cuanto mas se verifique antes del corte, menos probable es necesitar rollback con datos ya en Azure.

---

## 8. Despues de migrar

- Mantener el sistema viejo encendido al menos una semana.
- Vigilar los logs de Azure de cerca los primeros dias (errores, rendimiento).
- Revisar la capacidad de la base de datos en Azure bajo carga real (escalar el tier si hace falta).
- Una vez estable, considerar las mejoras que la migracion habilita: usar Key Vault para todo, mover el control de acceso al backend, modernizar a App Service si se desea.
- Actualizar los documentos de esta carpeta para reflejar la infraestructura de Azure (rutas, servicios, comandos del runbook).

---

Documentacion tecnica de SIGI — Tododrogas.
