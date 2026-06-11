# Runbook Operativo — SIGI

Sistema Integrado de Gestion Inteligente
Tododrogas — Procesos Digitales

Manual de operacion y solucion de problemas del sistema en produccion. Describe como esta montado todo, como operarlo en el dia a dia y que hacer cuando algo falla.

---

## Contenido

1. Mapa rapido del sistema
2. Servicios y como controlarlos
3. Logs: donde mirar
4. Operacion normal
5. Diagnostico de problemas
6. Renovacion del token de Meta
7. Rollback (volver a una version anterior)
8. Mantenimiento periodico
9. Contactos y accesos

---

## 1. Mapa rapido del sistema

Todo corre en un unico VPS (Hostinger).

| Recurso | Valor |
|---------|-------|
| Dominio | tododrogas.online |
| Servidor web | Nginx 1.24 (puerto 443) |
| Backend | PHP 8.3 FPM |
| Servicio Nova WhatsApp | Node.js bajo PM2, puerto 3000, nombre webhook-meta |
| Base de datos | Supabase (PostgreSQL) |
| Memoria del servidor | 15 GB (amplio margen) |
| Disco | 193 GB (uso bajo) |

Ubicaciones clave:
- Sitio web y endpoints PHP: /var/www/pqr/
- Servicio Node de Nova: /opt/webhook-meta/

---

## 2. Servicios y como controlarlos

### Nova WhatsApp (servicio Node, PM2)

```bash
# Ver estado
pm2 list

# Ver detalle del servicio
pm2 show webhook-meta

# Reiniciar (la accion mas comun cuando Nova no responde)
pm2 restart webhook-meta

# Reiniciar recargando el .env (tras cambiar variables o token)
pm2 restart webhook-meta --update-env

# Detener / arrancar
pm2 stop webhook-meta
pm2 start webhook-meta
```

PM2 esta configurado para arrancar solo si se reinicia el servidor (startup habilitado), asi que tras un reinicio del VPS, Nova vuelve sola.

### Nginx y PHP

```bash
# Estado
systemctl status nginx
systemctl status php8.3-fpm

# Recargar Nginx tras cambiar su configuracion (sin cortar conexiones)
nginx -t && systemctl reload nginx

# Reiniciar PHP si los endpoints dejan de responder
systemctl restart php8.3-fpm
```

---

## 3. Logs: donde mirar

| Que | Archivo / comando |
|-----|-------------------|
| Nova — salida normal | ~/.pm2/logs/webhook-meta-out.log |
| Nova — errores | ~/.pm2/logs/webhook-meta-error.log |
| Nova — en vivo | pm2 logs webhook-meta |
| Sincronizacion de correos | /var/log/sync-correos.log |
| Renovacion del token Meta | /var/log/renovar-token.log |
| Nginx — errores | /var/log/nginx/error.log |

Comandos utiles:

```bash
# Ver los ultimos errores de Nova
tail -50 ~/.pm2/logs/webhook-meta-error.log

# Seguir los logs de Nova en vivo
pm2 logs webhook-meta

# Ver la ultima sincronizacion de correos
tail -30 /var/log/sync-correos.log
```

---

## 4. Operacion normal

### Verificar que todo esta arriba

```bash
pm2 list                                  # Nova online
systemctl is-active nginx php8.3-fpm      # ambos active
curl -s -o /dev/null -w "%{http_code}\n" https://tododrogas.online/   # 200
curl -s http://localhost:3000/ | head -1  # responde status ok
```

### Verificar que entran los correos

El cron corre cada minuto. Para confirmar que esta trabajando:

```bash
tail -20 /var/log/sync-correos.log
crontab -l | grep sincronizar    # debe existir la linea
```

### Verificar el cron de renovacion del token

```bash
crontab -l | grep renovar
# Debe mostrar: 0 3 1,15 * * /opt/webhook-meta/renovar-token.sh ...
```

---

## 5. Diagnostico de problemas

### Nova no responde por WhatsApp

Orden de revision:

1. ¿El servicio esta arriba?
   ```bash
   pm2 list
   ```
   Si esta detenido o en error: `pm2 restart webhook-meta`

2. ¿Que dicen los errores?
   ```bash
   tail -50 ~/.pm2/logs/webhook-meta-error.log
   ```

3. ¿El token de Meta es valido? (ver seccion 6). Si el token expiro, Nova recibe mensajes pero no puede enviar respuestas.

4. ¿Responde el servicio localmente?
   ```bash
   curl -s http://localhost:3000/
   ```

5. ¿Nginx esta enrutando bien la ruta /wa/?
   ```bash
   curl -s -o /dev/null -w "%{http_code}\n" https://tododrogas.online/wa/
   ```

### No entran correos nuevos como tickets

1. Revisar el log de sincronizacion:
   ```bash
   tail -50 /var/log/sync-correos.log
   ```
2. Confirmar que el cron existe:
   ```bash
   crontab -l | grep sincronizar
   ```
3. Probar el endpoint manualmente:
   ```bash
   php /var/www/pqr/sincronizar-correos.php
   ```
   Si falla aqui, el error es de credenciales de Microsoft Graph o de conexion.

### El panel (admin/agente) no carga datos

1. Confirmar que PHP responde:
   ```bash
   systemctl status php8.3-fpm
   curl -s -o /dev/null -w "%{http_code}\n" https://tododrogas.online/login.php
   ```
2. Si el panel carga pero no muestra datos, suele ser un problema de Supabase (ver abajo).

### Fallas de servicios externos

| Sintoma | Causa probable | Que revisar |
|---------|----------------|-------------|
| Nova no clasifica, no resume, no responde preguntas libres | OpenAI caido o sin cupo | Estado de OpenAI; saldo de la cuenta |
| No entran ni salen correos | Microsoft Graph o token Azure | Log de sincronizacion; credenciales Azure |
| Nova recibe pero no envia WhatsApp | Token de Meta expirado | Seccion 6 |
| Nada carga, ni paneles ni Nova | Supabase caido | Estado de Supabase |

Supabase es el punto central: si falla, se detiene casi todo. Los demas servicios afectan solo su area.

---

## 6. Renovacion del token de Meta

El token que permite a Nova enviar mensajes por WhatsApp expira aproximadamente cada 60 dias. La renovacion es automatica, pero conviene saber operarla a mano.

### Renovacion automatica

Un cron renueva el token el dia 1 y el dia 15 de cada mes:

```
0 3 1,15 * * /opt/webhook-meta/renovar-token.sh >> /var/log/renovar-token.log 2>&1
```

Esto da margen de sobra: aunque una ejecucion falle, la siguiente (a los ~15 dias) lo cubre antes de los 60.

### Renovacion manual

Si se necesita renovar de inmediato:

```bash
/opt/webhook-meta/renovar-token.sh
```

Respuesta esperada: `✅ Token renovado exitosamente`. El script renueva el token, lo guarda en el .env y reinicia Nova automaticamente.

### Si la renovacion falla

Si el script responde con error, normalmente significa que el token actual ya expiro y no se puede intercambiar por uno nuevo. En ese caso hay que generar un token nuevo desde el panel de desarrolladores de Meta y colocarlo en /opt/webhook-meta/.env (variable META_TOKEN), luego:

```bash
pm2 restart webhook-meta --update-env
```

### Verificar cuando se renovo por ultima vez

```bash
tail -10 /var/log/renovar-token.log
```

---

## 7. Rollback (volver a una version anterior)

El despliegue crea automaticamente una copia de respaldo del server.js de Nova antes de cada cambio, con marca de fecha y hora.

```bash
# Ver los respaldos disponibles
ls -lht /opt/webhook-meta/server.js.bak.* | head

# Restaurar uno (reemplazar la fecha por la deseada)
cp /opt/webhook-meta/server.js.bak.AAAAMMDD_HHMM /opt/webhook-meta/server.js
pm2 restart webhook-meta
```

Para el sitio y los endpoints PHP, el deploy tambien crea respaldos de archivos criticos en /var/www/pqr/ con extension .bak.fecha. La forma mas limpia de revertir, sin embargo, es volver al commit anterior en GitHub y dejar que el despliegue automatico publique esa version.

---

## 8. Mantenimiento periodico

### Limpiar respaldos antiguos de Nova

El directorio /opt/webhook-meta/ acumula muchas copias server.js.bak.*. Conviene conservar solo las mas recientes:

```bash
# Ver cuantas hay
ls /opt/webhook-meta/server.js.bak.* | wc -l

# Conservar las 10 mas recientes y borrar el resto
ls -t /opt/webhook-meta/server.js.bak.* | tail -n +11 | xargs rm -f
```

### Rotar el log de sincronizacion de correos

El archivo /var/log/sync-correos.log crece de forma continua (el cron escribe cada minuto). Para vaciarlo conservando lo reciente:

```bash
# Ver su tamano
ls -lh /var/log/sync-correos.log

# Conservar las ultimas 1000 lineas y vaciar el resto
tail -1000 /var/log/sync-correos.log > /tmp/sync.tmp && mv /tmp/sync.tmp /var/log/sync-correos.log
```

Para una solucion permanente, configurar logrotate sobre ese archivo.

### Revisar la estabilidad de Nova

Conviene vigilar el numero de reinicios del servicio:

```bash
pm2 show webhook-meta | grep -i restart
```

Un numero de reinicios que sube rapido indica que Nova se esta cayendo y PM2 la revive una y otra vez. En ese caso, revisar el log de errores (~/.pm2/logs/webhook-meta-error.log) para identificar la causa.

---

## 9. Contactos y accesos

| Recurso | Detalle |
|---------|---------|
| Repositorio | GitHub (despliegue automatico a la rama main) |
| Servidor | VPS Hostinger, acceso por SSH |
| Base de datos | Supabase (panel web del proyecto) |
| Correo PQRSFD | pqrsfd@tododrogas.com.co |
| PBX | 604 322 2432 |
| WhatsApp | 304 341 2431 |

Las credenciales del sistema (claves de API, tokens, accesos) se gestionan como secretos en GitHub y se inyectan en el despliegue. No estan en el codigo ni deben compartirse por canales inseguros.

---

Documentacion tecnica de SIGI — Tododrogas.
