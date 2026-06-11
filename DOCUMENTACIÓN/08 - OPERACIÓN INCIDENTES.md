# Operacion e Incidentes — SIGI

Sistema Integrado de Gestion Inteligente
Tododrogas CIA SAS — Area de Innovacion y Tecnologia

Registro de incidentes que ya ocurrieron en produccion, con su causa y su solucion, mas los procedimientos para resolverlos si se repiten. Este documento recoge aprendizajes reales del dia a dia: son los problemas que mas tiempo han costado.

---

## Contenido

1. Orden de diagnostico cuando "el sistema no carga"
2. Incidente: capacidad de la base de datos (Supabase)
3. Incidente: Nginx no levanta tras editar la configuracion
4. Incidente: no se guardan ni cargan datos por falta de politica RLS en Supabase
5. Restriccion de acceso: que todo responda solo desde el dominio
6. Checklist rapido de incidentes

---

## 1. Orden de diagnostico cuando "el sistema no carga"

Cuando el sistema deja de cargar, seguir SIEMPRE este orden. La experiencia muestra que la causa mas frecuente y la que mas tiempo ha costado es la capacidad de la base de datos, por eso va primero.

```
PASO 1 → Revisar capacidad de Supabase (ver seccion 2)
PASO 2 → Revisar que Nginx este activo (systemctl is-active nginx)
PASO 3 → Revisar que PHP-FPM este activo (systemctl is-active php8.3-fpm)
PASO 4 → Revisar que Nova (Node/PM2) este online (pm2 list)
PASO 5 → Revisar los logs (ver runbook)
```

La razon de empezar por la base de datos: el frontend, los endpoints PHP y Nova dependen todos de Supabase. Si Supabase esta al limite de su capacidad (conexiones, almacenamiento o computo), el sintoma es que "nada carga", aunque todos los servicios del servidor esten correctos. Es facil perder tiempo revisando Nginx o PHP cuando el problema esta en la base.

---

## 2. Incidente: capacidad de la base de datos (Supabase)

Sintoma: el sistema no carga, los paneles quedan en blanco o muy lentos, las consultas tardan o fallan, pero Nginx, PHP y Nova estan todos activos.

Causa: el proyecto de Supabase llego a un limite de su plan (numero de conexiones simultaneas, espacio de base de datos, o capacidad de computo). Cuando se satura, las consultas se encolan o se rechazan y el efecto se ve como si todo el sistema estuviera caido.

Que revisar, en orden:

1. Entrar al panel de Supabase del proyecto, seccion de uso (Usage / Reports).
2. Revisar tres metricas:
   - Espacio de base de datos usado contra el limite del plan.
   - Conexiones activas contra el maximo permitido.
   - Uso de computo (CPU del proyecto).
3. Si alguna esta cerca del 100 por ciento, esa es la causa.

Soluciones segun el caso:

- Si es espacio: revisar las tablas que mas crecen. En SIGI las candidatas son los logs (logs_conversacion) y el historial de conversaciones (wa_historico, correos con raw_payload). Archivar o limpiar datos antiguos, o subir el plan.
- Si son conexiones: revisar que el codigo no este abriendo conexiones sin cerrarlas. Subir el plan da aire inmediato.
- Si es computo: identificar consultas pesadas o subir el plan.

Nota para la migracion a Azure: este punto cambia al migrar. Si la base pasa a Azure Database for PostgreSQL, la capacidad se gestiona desde Azure (escalar el tier), no desde Supabase. Conviene dimensionar el tier de Azure mirando primero cuanto consume hoy el proyecto en Supabase.

Accion preventiva recomendada: revisar el uso de Supabase de forma periodica (por ejemplo, una vez por semana) para anticipar el momento de subir el plan antes de que sature.

---

## 3. Incidente: Nginx no levanta tras editar la configuracion

Sintoma: tras editar la configuracion de Nginx, el sitio deja de responder y `nginx -t` reporta error o el servicio no arranca.

Causa real (ya ocurrida): se incluyo en la configuracion una directiva mal formada. En concreto, una linea de `valid_referers` escrita como:

```
valid_referers none blocked server_names tododrogas.online www.tododrogas.online;
```

El termino correcto NO es `server_names` (en plural) seguido de los dominios. La forma valida usa la variable `$server_name` o los dominios directamente. La version que funciona:

```
valid_referers none blocked $server_name;
```

Procedimiento seguro para editar Nginx (evita quedarse sin sitio):

```bash
# 1. SIEMPRE respaldar antes de tocar
cp /etc/nginx/sites-enabled/pqr /etc/nginx/sites-enabled/pqr.bak.$(date +%Y%m%d_%H%M)

# 2. Editar
nano /etc/nginx/sites-enabled/pqr

# 3. PROBAR la sintaxis ANTES de aplicar (este paso es el que evita el incidente)
nginx -t

# 4. Solo si "test is successful", recargar
systemctl reload nginx
```

Si `nginx -t` falla, NO recargar. Corregir o restaurar el respaldo:

```bash
cp /etc/nginx/sites-enabled/pqr.bak.AAAAMMDD_HHMM /etc/nginx/sites-enabled/pqr
nginx -t && systemctl reload nginx
```

Regla de oro: nunca usar `systemctl restart nginx` antes de que `nginx -t` diga que la configuracion es valida. El `reload` (en lugar de `restart`) no corta las conexiones activas si la config es buena.

---

## 4. Incidente: no se guardan ni cargan datos por falta de politica RLS en Supabase

Sintoma: una tabla no devuelve datos (consulta vacia) o no acepta una insercion o actualizacion, aunque el dato exista y la consulta sea correcta. Tipicamente aparece al crear una tabla nueva o al usar una tabla recien creada desde el frontend o un endpoint.

Causa: en Supabase, todas las tablas tienen Row Level Security (RLS) habilitado. Con RLS activo, una tabla sin politicas RECHAZA por defecto todas las operaciones de los roles anon y authenticated. Es decir: si una tabla no tiene una politica que permita explicitamente la operacion (SELECT, INSERT, UPDATE), Supabase no deja pasar la consulta y el resultado se ve como "no carga" o "no guarda", sin un error obvio.

Esto explica el caso vivido: al crear una tabla o consultar una que no tenia la politica correspondiente, los datos no aparecian aunque estuvieran ahi (o no se insertaban), porque faltaba la politica que autoriza esa consulta.

Que verificar:

1. Identificar la tabla afectada.
2. Revisar si tiene RLS habilitado y que politicas tiene. En el SQL Editor de Supabase:

```sql
-- Estado de RLS de la tabla
SELECT tablename, rowsecurity
FROM pg_tables
WHERE schemaname = 'public' AND tablename = 'NOMBRE_TABLA';

-- Politicas existentes en la tabla
SELECT policyname, roles, cmd, qual, with_check
FROM pg_policies
WHERE schemaname = 'public' AND tablename = 'NOMBRE_TABLA';
```

3. Si la tabla tiene RLS habilitado pero NO tiene politica para la operacion que falla, esa es la causa.

Solucion: crear la politica que autorice la operacion para el rol que la necesita. Ejemplos:

```sql
-- Permitir lectura publica (rol anon) de una tabla
CREATE POLICY "lectura_anon" ON NOMBRE_TABLA
  FOR SELECT TO anon USING (true);

-- Permitir insercion (rol anon)
CREATE POLICY "insert_anon" ON NOMBRE_TABLA
  FOR INSERT TO anon WITH CHECK (true);

-- Acceso completo para el backend (rol de servicio)
CREATE POLICY "service_all" ON NOMBRE_TABLA
  FOR ALL TO service_role USING (true) WITH CHECK (true);
```

Importante: la politica debe coincidir con el rol que hace la consulta. El frontend usa el rol anon (clave publica); los endpoints PHP y el servicio Node usan el rol de servicio. Si el frontend lee una tabla directamente, esa tabla necesita una politica para anon; si solo la toca el backend, basta una politica para service_role.

Regla practica para tablas nuevas: cada vez que se crea una tabla en Supabase, hay que crear de inmediato sus politicas RLS, o la tabla quedara inaccesible desde la aplicacion. Crear la tabla no es suficiente: sin politica, RLS la bloquea.

Nota para la migracion a Azure: si la base pasa a Azure Database for PostgreSQL, el modelo de RLS de PostgreSQL sigue existiendo (RLS es una caracteristica nativa de PostgreSQL, no de Supabase), pero el manejo de roles anon / authenticated / service_role es propio de Supabase. Al migrar habra que replantear como se controla el acceso, ya que esos roles automaticos no existiran igual en Azure. Conviene documentar todas las politicas actuales antes de migrar.

---

## 5. Restriccion de acceso: que todo responda solo desde el dominio

Objetivo: que los recursos del sistema solo se puedan invocar desde tododrogas.online, no directamente ni desde otros origenes. Esto reduce el uso indebido de los endpoints y la exposicion de archivos.

Medidas aplicadas y recomendadas:

Proteccion de config.js por referer. Ya implementada en Nginx. Solo sirve config.js si la peticion viene del propio dominio:

```
location = /config.js {
    valid_referers none blocked $server_name;
    if ($invalid_referer) { return 403; }
    add_header Cache-Control "no-store, no-cache";
}
```

Validacion de origen en los endpoints PHP. Varios endpoints (login.php, radicar-pqr.php, entre otros) ya validan la cabecera Origin contra una lista blanca de dominios permitidos, y responden 403 si el origen no esta autorizado. Conviene aplicar el mismo patron a todos los endpoints sensibles:

```php
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://tododrogas.online', 'https://www.tododrogas.online'];
if ($origin && !in_array($origin, $allowed)) {
    http_response_code(403);
    echo json_encode(['error' => 'Origen no permitido']);
    exit;
}
```

Token interno entre Node y PHP. La comunicacion entre el servicio Node de Nova y nova-wa.php usa la cabecera X-Nova-Token. Mantener ese token configurado garantiza que solo el Node autorizado pueda invocar la logica de Nova.

Bloqueo de archivos sensibles. Conviene que Nginx no sirva archivos de codigo o configuracion como estaticos (por ejemplo, el server.js del servicio Node, archivos .env, package.json). Esto se logra con reglas de bloqueo por ruta o extension en la configuracion de Nginx.

Recomendacion para Azure: al migrar, replicar estas tres capas (referer en el servidor web, validacion de Origin en los endpoints, y token interno) desde el inicio, para no exponer recursos durante la transicion.

---

## 6. Checklist rapido de incidentes

| Sintoma | Primero revisar | Seccion |
|---------|-----------------|---------|
| El sistema no carga / paneles en blanco | Capacidad de Supabase | 2 |
| El sitio no responde tras tocar Nginx | nginx -t y restaurar respaldo | 3 |
| Una tabla no carga o no guarda datos | Politicas RLS de esa tabla en Supabase | 4 |
| Nova no responde WhatsApp | Token de Meta y estado de PM2 | Runbook |
| No entran correos | Log de sincronizacion y cron | Runbook |

---

Documentacion tecnica de SIGI — Tododrogas CIA SAS.
