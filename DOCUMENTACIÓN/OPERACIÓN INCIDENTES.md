# Operacion e Incidentes — SIGI

Tododrogas — Procesos Digitales

Registro de incidentes que ya ocurrieron en produccion, con su causa y su solucion, mas los procedimientos para resolverlos si se repiten. Este documento recoge aprendizajes reales del dia a dia: son los problemas que mas tiempo han costado.

---

## Contenido

1. Orden de diagnostico cuando "el sistema no carga"
2. Incidente: capacidad de la base de datos (Supabase)
3. Incidente: Nginx no levanta tras editar la configuracion
4. Incidente: no se guardan datos cuando no se acepta la politica
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

## 4. Incidente: no se guardan datos cuando no se acepta la politica

Sintoma: una conversacion o radicacion no queda registrada en la base de datos. Ocurre cuando el flujo de aceptacion de la politica de tratamiento de datos no se completo o no esta creado.

Causa: el sistema esta disenado para no almacenar los datos personales del paciente hasta que acepta la politica de tratamiento de datos (cumplimiento de Habeas Data). Si el registro de la politica no existe o el paciente no la acepta, el sistema, por diseno, no persiste la informacion.

Que verificar:

1. Confirmar que el paciente efectivamente acepto la politica en el flujo (en Nova, responder 1 / Acepto).
2. Confirmar que existe la configuracion o el registro de la politica en la base de datos. En SIGI el documento de politica se referencia desde la configuracion del sistema y desde Nova; si ese registro falta, el flujo de aceptacion no puede completarse y los datos no se guardan.
3. Revisar la tabla configuracion_sistema y el flujo de politica en nova-wa.php (fase "politica").

Importante: este comportamiento es intencional y correcto desde el punto de vista legal. La accion no es "forzar el guardado", sino asegurarse de que el registro de la politica este creado y disponible para que el paciente pueda aceptarla. Una vez aceptada, los datos se guardan con normalidad.

Punto a documentar para el futuro: dejar claro en la configuracion cual es el registro de politica requerido, para que al desplegar un entorno nuevo (por ejemplo, Azure) no se olvide crearlo y el sistema "no guarde datos" sin razon aparente.

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
| Una PQRSFD o chat no se guarda | Aceptacion y registro de la politica | 4 |
| Nova no responde WhatsApp | Token de Meta y estado de PM2 | Runbook |
| No entran correos | Log de sincronizacion y cron | Runbook |

---

Documentacion tecnica de SIGI — Tododrogas.
