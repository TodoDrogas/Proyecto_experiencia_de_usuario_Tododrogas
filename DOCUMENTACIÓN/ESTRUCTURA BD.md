# Estructura de Base de Datos — SIGI

Sistema Integrado de Gestion Inteligente
Tododrogas — Procesos Digitales 

Motor: Supabase PostgreSQL Pro
Esquema: public
Ultima actualizacion: junio de 2026

---

## Contenido

1. Vision general
2. Inventario de objetos
3. Organizacion por dominio
4. Diccionario de datos
5. Relaciones (Foreign Keys)
6. Triggers y funciones
7. Indices
8. Vistas
9. Extensiones
10. Convenciones de diseno

---

## 1. Vision general

La base de datos de SIGI corre sobre PostgreSQL gestionado en Supabase Pro. El modelo gira en torno a una tabla central de tickets, `correos`, que recibe las comunicaciones de pacientes desde los distintos canales. El resto de tablas dan soporte a agentes, sesiones de WhatsApp, catalogos, historicos y configuracion.

El esquema `public` contiene 21 tablas y 7 vistas. Row Level Security esta habilitado en todas las tablas. Hay triggers que se encargan de la normalizacion de datos y del mantenimiento de columnas de auditoria, y un conjunto de indices que incluye indices parciales y de busqueda de texto.

---

## 2. Inventario de objetos

Tablas:

| Tabla | Dominio | Llave primaria |
|-------|---------|----------------|
| correos | Tickets / PQRSFD | id (uuid) |
| agentes | Agentes | id (uuid) |
| respuestas | Tickets / PQRSFD | id (uuid) |
| adjuntos | Tickets / PQRSFD | id (uuid) |
| historial_eventos | Auditoria | id (uuid) |
| gestiones_medicamentos | Tickets / PQRSFD | id (uuid) |
| encuestas_satisfaccion | Satisfaccion | id (uuid) |
| knowledge_base | Conocimiento | id (uuid) |
| wa_sesiones | WhatsApp | telefono (text) |
| wa_historico | WhatsApp | id (uuid) |
| nova_sesiones | Bot Nova | id (uuid) |
| nova_reglas | Bot Nova | id (uuid) |
| logs_conversacion | Logs | id (uuid) |
| logs_agentes | Logs | id (uuid) |
| contactos_frecuentes | Contactos | id (uuid) |
| usuarios_vip | Contactos | cedula (text) |
| tabla_usuarios | Padron pacientes | id (int4) |
| medicamentos | Catalogo | id (int8) |
| eps_catalogo | Catalogo | id (uuid) |
| sedes | Catalogo | id (uuid) |
| configuracion_sistema | Configuracion | id (text) |

Vistas:

| Vista | Proposito |
|-------|-----------|
| v_carga_agentes | Carga de trabajo por agente |
| v_encuestas_mes | Resumen mensual de encuestas |
| v_informe_sigi | Informe consolidado |
| v_kpi_correos | Indicadores de tickets |
| v_resumen_sedes | Metricas por sede |
| metricas_agentes_wa | Metricas de agentes en WhatsApp |
| resumen_diario_wa | Resumen diario de WhatsApp |

---

## 3. Organizacion por dominio

Tickets / PQRSFD: la tabla `correos` es el centro del modelo. A ella se vinculan `respuestas`, `adjuntos`, `gestiones_medicamentos`, `historial_eventos` y `encuestas_satisfaccion`.

Agentes: `agentes` guarda los usuarios internos; `logs_agentes` registra su actividad.

WhatsApp y bot Nova: `wa_sesiones` mantiene las conversaciones activas y se archiva en `wa_historico` al cerrarse. `nova_sesiones` y `nova_reglas` corresponden al bot. `logs_conversacion` registra los eventos del canal.

Contactos y padron: `contactos_frecuentes`, `usuarios_vip` y `tabla_usuarios`.

Catalogos: `medicamentos`, `eps_catalogo`, `sedes` y `knowledge_base`.

Configuracion: `configuracion_sistema`.

---

## 4. Diccionario de datos

### correos

Tabla central de tickets. Almacena todas las PQRSFD sin importar el canal de origen.

| Columna | Tipo | Descripcion |
|---------|------|-------------|
| id | uuid | Llave primaria |
| ticket_id | text | Identificador legible por canal. Unico |
| message_id | text | Identificador del mensaje en el proveedor. Unico |
| internet_message_id | text | Cabecera Message-ID para trazabilidad de hilos |
| conversation_id | text | Identificador de hilo del proveedor |
| from_email | text | Correo del remitente |
| from_name | text | Nombre del remitente |
| nombre | text | Nombre del paciente o solicitante |
| correo | text | Correo del paciente |
| telefono_contacto | text | Telefono de contacto |
| to_recipients | jsonb | Destinatarios principales |
| cc_recipients | jsonb | Destinatarios en copia |
| bcc_recipients | jsonb | Destinatarios en copia oculta |
| reply_to | jsonb | Direccion de respuesta |
| subject | text | Asunto o titulo del ticket |
| descripcion | text | Descripcion del caso |
| body_preview | text | Vista previa del cuerpo |
| body_content | text | Cuerpo completo |
| body_type | text | Tipo de cuerpo: html o text |
| transcripcion | text | Transcripcion de audio |
| has_attachments | bool | Indica presencia de adjuntos |
| audio_url | text | URL del audio en Storage |
| canvas_url | text | URL de imagen o documento visual |
| tipo_pqr | text | Peticion, Queja, Reclamo, Sugerencia, Felicitacion, Denuncia |
| categoria_ia | text | Categoria asignada por IA |
| sentimiento | text | Analisis de sentimiento |
| nivel_riesgo | text | Nivel de riesgo detectado |
| resumen_corto | text | Resumen breve |
| resumen_ia | text | Resumen detallado |
| ley_aplicable | text | Normativa aplicable identificada |
| datos_legales | jsonb | Datos legales estructurados |
| origen | text | automatico, manual, bot, web |
| canal_contacto | text | EMAIL, WHATSAPP, PBX, CNX |
| estado | text | nuevo, en_proceso, resuelto, cerrado, escalado |
| prioridad | text | baja, media, alta, critica |
| es_urgente | bool | Marca de urgencia |
| agente_id | uuid | Agente asignado |
| asignado_a | text | Nombre del agente asignado |
| horas_sla | int4 | Horas de SLA aplicables |
| fecha_limite_sla | timestamptz | Fecha limite de respuesta |
| fecha_resolucion | timestamptz | Fecha de resolucion |
| sla_vencido | bool | Indica SLA vencido |
| acuse_enviado | bool | Acuse de recibo enviado |
| notificacion_agente | bool | Notificacion al agente enviada |
| is_read | bool | Estado de lectura |
| is_draft | bool | Indica borrador |
| importance | text | Importancia del proveedor |
| categories | jsonb | Categorias del proveedor |
| flag_status | text | Estado de bandera |
| raw_payload | jsonb | Payload original del proveedor |
| received_at | timestamptz | Fecha de recepcion |
| sent_at | timestamptz | Fecha de envio |
| created_at | timestamptz | Fecha de creacion en SIGI |
| updated_at | timestamptz | Ultima modificacion |
| token_consulta | text | Token para consulta publica del ticket. Unico |
| cedula | text | Cedula del gestor o solicitante |
| tipo_documento | text | CC, TI, CE, PA |
| cedula_paciente | text | Cedula del paciente |
| direccion_paciente | text | Direccion del paciente |
| nombre_solicitud | text | Nombre del campo de solicitud |
| detalle_solicitud | text | Detalle de la solicitud |
| departamento_paciente | text | Departamento de residencia |
| ciudad_paciente | text | Ciudad de residencia |
| tiene_tutela | bool | Indica accion de tutela |
| categoria_sigi | text | Categoria interna de SIGI |
| asignacion_automatica | timestamptz | Fecha de asignacion automatica |
| num_gestiones | int4 | Contador de gestiones |
| gestion_compra | text | Gestion de compra |
| usuario_gestion_compra | text | Usuario de gestion de compra |
| gestion_usuarios_integ | text | Gestion de usuarios integrales |
| usuarios_integrales | text | Usuarios integrales vinculados |
| eps_id | uuid | EPS del paciente |
| adjuntos_pendientes | bool | Adjuntos pendientes de procesar |
| tiene_respuesta | bool | Indica si tiene respuesta |
| calificacion | int4 | Calificacion de satisfaccion (1 a 5) |
| calificacion_texto | text | Comentario de satisfaccion |
| encuesta_enviada_at | timestamptz | Fecha de envio de encuesta |
| fecha_calificacion | timestamptz | Fecha de calificacion |

### agentes

Usuarios internos que atienden tickets.

| Columna | Tipo | Descripcion |
|---------|------|-------------|
| id | uuid | Llave primaria |
| nombre | text | Nombre completo |
| email | text | Correo corporativo |
| cedula | text | Cedula. Unico |
| pin | text | PIN de acceso al modulo PBX |
| rol | text | agente, supervisor, admin |
| sede | text | Sede asignada |
| telefono | text | Telefono de contacto |
| avatar_url | text | URL del avatar |
| activo | bool | Disponible para recibir tickets |
| pausado | bool | En pausa |
| pausado_at | timestamptz | Inicio de la pausa |
| en_linea | bool | Estado de conexion |
| ultima_actividad | timestamptz | Ultima accion registrada |
| casos_activos | int4 | Tickets activos asignados |
| carga_actual | int4 | Metrica de carga |
| created_at | timestamptz | Fecha de creacion |
| updated_at | timestamptz | Ultima modificacion |

### respuestas

Respuestas enviadas o redactadas sobre tickets.

| Columna | Tipo | Descripcion |
|---------|------|-------------|
| id | uuid | Llave primaria |
| correo_id | uuid | Ticket relacionado |
| agente_id | uuid | Agente que respondio |
| contenido | text | Texto plano |
| html | text | Version HTML |
| enviado | bool | Indica envio |
| canal | text | Canal de envio |
| generado_por | text | agente, ia, plantilla |
| created_at | timestamptz | Fecha de creacion |

### adjuntos

Metadatos de archivos adjuntos. Los binarios se guardan en Supabase Storage.

| Columna | Tipo | Descripcion |
|---------|------|-------------|
| id | uuid | Llave primaria |
| correo_id | uuid | Ticket relacionado |
| message_id | text | Identificador del mensaje en proveedor |
| attachment_id | text | Identificador del adjunto en proveedor |
| nombre | text | Nombre del archivo |
| tipo_contenido | text | Tipo MIME |
| tamano_bytes | int4 | Tamano en bytes |
| es_inline | bool | Adjunto en linea |
| storage_url | text | URL en Storage |
| storage_path | text | Ruta en el bucket |
| direccion | text | entrante o saliente |
| enviado_por | text | Agente o sistema emisor |
| created_at | timestamptz | Fecha de registro |

### historial_eventos

Bitacora de acciones sobre tickets.

| Columna | Tipo | Descripcion |
|---------|------|-------------|
| id | uuid | Llave primaria |
| correo_id | uuid | Ticket afectado |
| agente_id | uuid | Agente del evento |
| evento | text | Tipo de evento |
| descripcion | text | Descripcion del evento |
| subject | text | Asunto al momento del evento |
| from_email | text | Correo al momento del evento |
| datos_extra | jsonb | Datos adicionales |
| created_at | timestamptz | Fecha del evento |

### gestiones_medicamentos

Medicamentos gestionados en el contexto de un ticket.

| Columna | Tipo | Descripcion |
|---------|------|-------------|
| id | uuid | Llave primaria |
| correo_id | uuid | Ticket relacionado |
| codigo | text | Codigo del medicamento |
| descripcion | text | Descripcion de catalogo |
| descripcion_escrita | text | Descripcion escrita manualmente |
| cantidad | numeric | Cantidad solicitada |
| pbs | text | PBS o NO PBS |
| created_at | timestamptz | Fecha de registro |

### encuestas_satisfaccion

Respuestas detalladas de encuestas de satisfaccion.

| Columna | Tipo | Descripcion |
|---------|------|-------------|
| id | uuid | Llave primaria |
| correo_id | uuid | Ticket relacionado |
| ticket_id | text | Identificador legible del ticket |
| nombre | text | Nombre del encuestado |
| cedula | text | Cedula del encuestado |
| eps | text | EPS del encuestado |
| canal | text | Canal de respuesta |
| origen | text | email, whatsapp, link |
| ip_origen | text | IP de origen |
| sede_id | uuid | Sede evaluada |
| sede_nombre | text | Nombre de la sede |
| sede_ciudad | text | Ciudad de la sede |
| calificacion | int4 | Calificacion general (1 a 5) |
| comentario | text | Comentario libre |
| instalaciones | int4 | Puntuacion de instalaciones |
| atencion | int4 | Puntuacion de atencion |
| tiempos | int4 | Puntuacion de tiempos |
| medicamentos | int4 | Puntuacion de medicamentos |
| recomendacion | int4 | Recomendacion tipo NPS |
| promedio | numeric | Promedio calculado |
| created_at | timestamptz | Fecha de respuesta |

### knowledge_base

Respuestas modelo para asistencia a agentes y generacion de respuestas.

| Columna | Tipo | Descripcion |
|---------|------|-------------|
| id | uuid | Llave primaria |
| tipo_pqr | text | Tipo de PQRSFD aplicable |
| categoria | text | Categoria |
| situacion | text | Situacion que activa la respuesta |
| respuesta_modelo | text | Texto de respuesta aprobado |
| tags | jsonb | Etiquetas de busqueda |
| activo | bool | Disponible para uso |
| veces_usado | int4 | Contador de usos |
| calificacion | numeric | Efectividad promedio |
| creado_por | text | Creador |
| aprobado_por | text | Aprobador |
| created_at | timestamptz | Fecha de creacion |
| updated_at | timestamptz | Ultima modificacion |

### wa_sesiones

Estado de las conversaciones activas de WhatsApp.

| Columna | Tipo | Descripcion |
|---------|------|-------------|
| telefono | text | Llave primaria. Numero en formato E.164 |
| history | jsonb | Mensajes de la conversacion |
| nombre | text | Nombre del paciente |
| cedula | text | Cedula |
| eps | text | EPS normalizada |
| ciudad | text | Ciudad |
| estado | text | bot, agente, cerrado |
| fase | text | identificacion, menu, gestion, cierre |
| agente_id | uuid | Agente asignado |
| agente_nombre | text | Nombre del agente |
| asignado_at | timestamptz | Fecha de asignacion |
| primera_respuesta_at | timestamptz | Primera respuesta del agente |
| tipificacion | text | Clasificacion al cierre |
| observacion_cierre | text | Observaciones de cierre |
| motivo_cierre_wa | text | Motivo de cierre |
| cerrado_at | timestamptz | Fecha de cierre |
| unread_count | int4 | Mensajes no leidos |
| resumen_nova | text | Resumen de Nova al transferir |
| intentos_nova | int4 | Intentos del bot antes de escalar |
| no_acepto_count | int4 | Rechazos de menu |
| transferido_de | uuid | Agente que transfirio |
| transferido_at | timestamptz | Fecha de transferencia |
| calificacion_asesor | text | Calificacion del asesor |
| encuesta_enviada | bool | Encuesta enviada |
| encuesta_enviada_at | timestamptz | Fecha de envio de encuesta |
| calificacion | int4 | Puntuacion (1 a 5) |
| calificacion_texto | text | Comentario |
| fecha_calificacion | timestamptz | Fecha de calificacion |
| recordatorio_enviado | timestamptz | Ultimo recordatorio |
| saludo_sugerido | text | Saludo sugerido por Nova |
| origen_canal | text | Sub-origen del canal |
| mensajes_agente | int4 | Mensajes del agente |
| mensajes_usuario_ag | int4 | Mensajes del usuario con agente |
| inactividad_aviso_at | timestamptz | Ultimo aviso de inactividad |
| updated_at | timestamptz | Ultima actualizacion |

### wa_historico

Archivo de sesiones de WhatsApp cerradas.

| Columna | Tipo | Descripcion |
|---------|------|-------------|
| id | uuid | Llave primaria |
| telefono | text | Numero del paciente |
| nombre | text | Nombre |
| eps | text | EPS |
| cedula | text | Cedula |
| history | jsonb | Historial completo |
| agente_id | uuid | Agente que atendio |
| agente_nombre | text | Nombre del agente |
| calificacion | int4 | Puntuacion final |
| calificacion_texto | text | Comentario |
| motivo_cierre_wa | text | Motivo de cierre |
| cerrado_at | timestamptz | Fecha de cierre |
| created_at | timestamptz | Fecha de archivo |

### nova_sesiones

Metricas y resumenes de las sesiones del bot Nova.

| Columna | Tipo | Descripcion |
|---------|------|-------------|
| id | uuid | Llave primaria |
| sesion_id | text | Identificador correlacionable con wa_sesiones |
| cedula | text | Cedula del paciente |
| nombre | text | Nombre |
| eps | text | EPS declarada |
| eps_paciente | text | EPS segun padron |
| municipio | text | Municipio |
| menu_elegido | text | Opcion de menu (1 a 8) |
| turnos | int4 | Intercambios de mensajes |
| consultas_count | int4 | Consultas a IA |
| preguntas_libres | int4 | Preguntas en lenguaje libre |
| resumen | text | Resumen de la sesion |
| motivo_cierre | text | Motivo de cierre |
| duracion_seg | int4 | Duracion en segundos |
| hora_inicio | timestamptz | Inicio de la sesion |
| es_vip | bool | Paciente VIP |
| calificacion | text | Calificacion del bot |
| origen | text | Origen de la sesion |
| created_at | timestamptz | Fecha de creacion |
| updated_at | timestamptz | Ultima actualizacion |

### nova_reglas

Reglas de comportamiento del bot Nova.

| Columna | Tipo | Descripcion |
|---------|------|-------------|
| id | uuid | Llave primaria |
| triggers | _text | Palabras que activan la regla |
| instruccion | text | Instruccion para Nova |
| prioridad | int4 | Orden de evaluacion |
| activo | bool | Regla habilitada |
| created_at | timestamptz | Fecha de creacion |
| updated_at | timestamptz | Ultima modificacion |

### logs_conversacion

Registro de eventos de conversacion de WhatsApp.

| Columna | Tipo | Descripcion |
|---------|------|-------------|
| id | uuid | Llave primaria |
| created_at | timestamptz | Fecha del evento |
| telefono | text | Telefono involucrado |
| agente_id | uuid | Agente involucrado |
| agente_nombre | text | Nombre del agente |
| evento | text | Tipo de evento |
| duracion_seg | int4 | Duracion asociada |
| metadata | jsonb | Datos adicionales |

### logs_agentes

Registro de actividad de los agentes.

| Columna | Tipo | Descripcion |
|---------|------|-------------|
| id | uuid | Llave primaria |
| agente_id | uuid | Agente |
| agente_nombre | text | Nombre del agente |
| evento | text | login, logout, pausa, entre otros |
| created_at | timestamptz | Fecha del evento |

### contactos_frecuentes

Directorio de contactos externos que han interactuado con SIGI.

| Columna | Tipo | Descripcion |
|---------|------|-------------|
| id | uuid | Llave primaria |
| email | text | Correo. Unico |
| telefono_wa | text | WhatsApp en formato E.164. Unico |
| nombre | text | Nombre |
| telefono | text | Telefono general |
| total_pqrs | int4 | Total de PQRSFD |
| ultima_pqr | timestamptz | Ultima PQRSFD |
| vip | bool | Contacto VIP |
| bloqueado | bool | Contacto bloqueado |
| notas | text | Notas internas |
| created_at | timestamptz | Primera interaccion |
| updated_at | timestamptz | Ultima actualizacion |

### usuarios_vip

Pacientes con atencion prioritaria.

| Columna | Tipo | Descripcion |
|---------|------|-------------|
| cedula | text | Llave primaria |
| nombre | text | Nombre |
| saludo | text | Saludo personalizado |
| eps | varchar | EPS |
| ciudad | varchar | Ciudad |
| created_at | timestamptz | Fecha de registro |

### tabla_usuarios

Padron de pacientes importado del sistema de informacion. Los nombres de columna conservan espacios y mayusculas del origen; en SQL deben ir entre comillas dobles.

| Columna | Tipo | Descripcion |
|---------|------|-------------|
| id | int4 | Llave primaria |
| Tipo De Documento | varchar | Tipo de documento |
| Cedula Pacientes | varchar | Documento del paciente |
| Nombre Paciente | varchar | Nombre completo |
| Sexo | varchar | Sexo |
| Fecha de Nacimiento | date | Fecha de nacimiento |
| Direccion | varchar | Direccion |
| Telefono | varchar | Telefono |
| Departamento | varchar | Departamento |
| Ciudad | varchar | Ciudad |
| EPS | varchar | EPS |
| Correo | varchar | Correo |

### medicamentos

Catalogo de medicamentos con clasificacion PBS.

| Columna | Tipo | Descripcion |
|---------|------|-------------|
| id | int8 | Llave primaria |
| Codigo | text | Codigo interno |
| Nombre | text | Nombre del medicamento |
| Articulo_PBS | text | PBS o NO PBS |

### eps_catalogo

Catalogo de EPS habilitadas.

| Columna | Tipo | Descripcion |
|---------|------|-------------|
| id | uuid | Llave primaria |
| nit | text | NIT de la EPS. Unico |
| nombre | text | Nombre oficial |
| activa | bool | EPS activa |
| created_at | timestamptz | Fecha de registro |

### sedes

Catalogo de sedes con coordenadas para geolocalizacion.

| Columna | Tipo | Descripcion |
|---------|------|-------------|
| id | uuid | Llave primaria |
| nombre | text | Nombre de la sede |
| codigo | text | Codigo interno. Unico |
| ciudad | text | Ciudad |
| municipio_norm | text | Municipio normalizado |
| direccion | text | Direccion fisica |
| telefono | text | Telefono |
| encargado | text | Responsable |
| lat | numeric | Latitud |
| lng | numeric | Longitud |
| radio_m | int4 | Radio para geofencing |
| eps | _text | EPS que atiende (arreglo) |
| modelo | text | Modelo de atencion |
| horario | text | Horario de atencion |
| activa | bool | Sede operativa |
| created_at | timestamptz | Fecha de registro |

### configuracion_sistema

Parametros globales del sistema en formato clave-valor.

| Columna | Tipo | Descripcion |
|---------|------|-------------|
| id | text | Llave primaria. Nombre de la configuracion |
| data | jsonb | Valor en formato JSON |
| updated_at | timestamptz | Ultima modificacion |

---

## 5. Relaciones (Foreign Keys)

| Tabla origen | Columna | Tabla destino | Columna | ON DELETE |
|--------------|---------|---------------|---------|-----------|
| adjuntos | correo_id | correos | id | CASCADE |
| correos | eps_id | eps_catalogo | id | SET NULL |
| encuestas_satisfaccion | correo_id | correos | id | SET NULL |
| gestiones_medicamentos | correo_id | correos | id | CASCADE |
| historial_eventos | correo_id | correos | id | CASCADE |
| historial_eventos | agente_id | agentes | id | SET NULL |
| logs_agentes | agente_id | agentes | id | NO ACTION |
| respuestas | correo_id | correos | id | CASCADE |
| respuestas | agente_id | agentes | id | SET NULL |

Relaciones gestionadas a nivel de aplicacion (sin Foreign Key formal): correos.agente_id hacia agentes.id; wa_sesiones.agente_id hacia agentes.id; logs_conversacion.agente_id hacia agentes.id; gestiones_medicamentos.codigo hacia medicamentos.Codigo; encuestas_satisfaccion.sede_id hacia sedes.id.

---

## 6. Triggers y funciones

| Trigger | Tabla | Evento | Funcion |
|---------|-------|--------|---------|
| trg_agentes_updated_at | agentes | UPDATE | update_updated_at |
| trg_correos_updated_at | correos | UPDATE | update_updated_at |
| trg_kb_updated_at | knowledge_base | UPDATE | update_updated_at |
| trg_nova_reglas_updated | nova_reglas | UPDATE | update_nova_reglas_timestamp |
| trg_check_pendiente_firma | correos | INSERT, UPDATE | check_pendiente_firma |
| trg_tipo_pqr_norm | correos | INSERT, UPDATE | trg_set_tipo_pqr_norm |
| trg_ciudad_correos | correos | INSERT, UPDATE | trg_normalizar_ciudad |
| trg_ciudad_nova_sesiones | nova_sesiones | INSERT, UPDATE | trg_normalizar_ciudad |
| trg_ciudad_tabla_usuarios | tabla_usuarios | INSERT, UPDATE | trg_normalizar_ciudad |
| trg_ciudad_usuarios_vip | usuarios_vip | INSERT, UPDATE | trg_normalizar_ciudad |
| trg_ciudad_wa_sesiones | wa_sesiones | INSERT, UPDATE | trg_normalizar_ciudad |
| trg_proteger_sesion_fh | wa_sesiones | UPDATE | proteger_sesion_fh |

Funciones de soporte:

- update_updated_at: actualiza la columna updated_at en cada modificacion.
- trg_normalizar_ciudad: estandariza nombres de ciudad.
- trg_set_tipo_pqr_norm: estandariza el valor de tipo_pqr.
- check_pendiente_firma: gestiona el estado de firma pendiente, base de la integracion con el bot PQRSFD FIRMAS en UiPath.
- proteger_sesion_fh: evita la sobreescritura de campos en sesiones concurrentes.

---

## 7. Indices

### correos

| Indice | Tipo | Columnas |
|--------|------|----------|
| correos_pkey | unico | id |
| correos_ticket_id_key | unico | ticket_id |
| correos_message_id_key | unico | message_id |
| correos_token_consulta_key | unico | token_consulta |
| idx_correos_estado_fecha | btree | estado, received_at DESC |
| idx_correos_estado_agente | btree | estado, agente_id |
| idx_correos_estado | btree | estado |
| idx_correos_agente | btree | agente_id |
| idx_correos_ticket | btree | ticket_id |
| idx_correos_message_id | btree | message_id |
| idx_correos_received_at | btree | received_at DESC |
| idx_correos_tipo_pqr | btree | tipo_pqr |
| idx_correos_origen | btree | origen |
| idx_correos_from_email | btree | from_email |
| idx_correos_prioridad | btree | prioridad |
| idx_correos_cedula | btree | cedula |
| idx_correos_eps_id | btree | eps_id |
| idx_correos_categoria_sigi | btree | categoria_sigi |
| idx_correos_nombre_sol | btree | nombre_solicitud |
| idx_correos_departamento | btree | departamento_paciente |
| idx_correos_sla | btree | fecha_limite_sla |
| idx_correos_es_urgente | btree parcial | es_urgente donde es true |
| idx_correos_sla_vencido | btree parcial | sla_vencido donde es true |
| idx_correos_tutela | btree parcial | tiene_tutela donde es true |
| idx_correos_num_gestiones | btree parcial | num_gestiones mayor a cero |
| idx_correos_archivado | btree parcial | estado igual a archivado |
| idx_correos_resolucion | btree parcial | fecha_resolucion no nula |
| idx_correos_conversation | btree parcial | conversation_id, received_at DESC |
| idx_correos_internet_message_id | btree parcial | internet_message_id no nulo |

### Indices de busqueda

| Indice | Tabla | Tipo | Uso |
|--------|-------|------|-----|
| idx_medicamentos_nombre | medicamentos | GIN tsvector | Busqueda de texto en espanol |
| idx_medicamentos_codigo | medicamentos | btree | Busqueda por codigo |
| idx_sedes_ciudad_trgm | sedes | GIN trigram | Busqueda tolerante a errores |
| idx_sedes_activa_municipio_norm | sedes | btree | Filtro por municipio activo |

### Indices de otras tablas

| Tabla | Indices |
|-------|---------|
| adjuntos | correo_id; (correo_id, direccion); message_id; direccion |
| historial_eventos | correo_id; evento; (evento, created_at); agente_id; created_at |
| respuestas | correo_id; agente_id |
| encuestas_satisfaccion | correo_id; sede_id; created_at |
| knowledge_base | tipo_pqr; veces_usado; activo (parcial) |
| logs_conversacion | evento; agente_id; telefono; created_at |
| wa_sesiones | estado; agente_id; updated_at |
| wa_historico | cedula; telefono; cerrado_at |
| tabla_usuarios | Cedula Pacientes; Nombre Paciente; Telefono |
| contactos_frecuentes | email; telefono_wa; vip (parcial) |
| usuarios_vip | cedula |

---

## 8. Vistas

| Vista | Proposito | Fuente principal |
|-------|-----------|------------------|
| v_carga_agentes | Carga de trabajo por agente | agentes, correos |
| v_encuestas_mes | Resumen mensual de satisfaccion | encuestas_satisfaccion |
| v_informe_sigi | Informe consolidado | varias tablas |
| v_kpi_correos | Indicadores de tickets | correos |
| v_resumen_sedes | Metricas por sede | sedes, encuestas_satisfaccion |
| metricas_agentes_wa | Metricas de agentes en WhatsApp | wa_sesiones, wa_historico |
| resumen_diario_wa | Resumen diario de WhatsApp | wa_sesiones, logs_conversacion |

---

## 9. Extensiones

| Extension | Uso |
|-----------|-----|
| pg_trgm | Busqueda por similitud de texto en sedes.ciudad |
| Full-text search | Busqueda en espanol en medicamentos.Nombre |
| Generacion de UUID | Llaves primarias de tipo uuid |

---

## 10. Convenciones de diseño

Llaves primarias. Las entidades operativas usan uuid, los catalogos importados usan enteros autoincrementales por compatibilidad con el sistema de origen, y algunas tablas usan clave natural de tipo text, como wa_sesiones.telefono y usuarios_vip.cedula.

Identificadores de ticket. Siguen el formato CANAL-identificador-fecha-secuencia, lo que facilita la referencia en soporte telefonico y en correos.

Desnormalizacion. Algunos campos, como agente_nombre o sede_nombre, se guardan junto a su llave foranea para mejorar el rendimiento de las consultas del tablero y para conservar el valor que tenian al momento del evento.

Columnas de auditoria. Las tablas operativas incluyen created_at y updated_at, esta ultima mantenida por triggers.

Normalizacion automatica. Los triggers estandarizan ciudad y tipo de PQRSFD en el momento de la insercion o actualizacion, sin depender de la capa de aplicacion.

Marcas temporales. Todas las fechas usan timestamptz para manejar correctamente la zona horaria de Colombia.

---

Documento de la documentacion técnica de SIGI. Debe actualizarse junto con cualquier cambio de esquema en Supabase.
