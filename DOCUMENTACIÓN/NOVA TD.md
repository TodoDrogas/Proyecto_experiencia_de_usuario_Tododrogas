# Nova TD

Sistema Integrado de Gestion Inteligente — SIGI
Tododrogas — Procesos Digitales

Nova TD es el asistente virtual de Tododrogas. Atiende a los pacientes de forma automatica las 24 horas, resuelve las consultas mas comunes sin intervencion humana y escala a un agente cuando hace falta. Nova opera en dos canales: el chat web (Nova directo, en el sitio) y WhatsApp. Ambos comparten la misma logica y conocimiento, pero se ejecutan en componentes distintos. Este documento describe en detalle como funciona por dentro, que componentes lo forman y como se conectan entre si.

---

## Contenido

1. Los dos canales de Nova
2. Como se conectan los componentes
3. Ciclo de vida de una conversacion
4. Maquina de estados
5. El menu principal y sus ocho opciones
6. Identificacion del paciente
7. Normalizacion de EPS
8. Consulta de sedes
9. El cerebro de IA y los tags
10. Reglas dinamicas
11. Escalamiento a agente humano
12. Encuesta de cierre
13. Tareas automaticas (crons)
14. Tablas que usa Nova
15. Nova TD respuesta (correos)
16. Observaciones para mejorar Nova

---

## 1. Los dos canales de Nova

El nombre "Nova" se usa para tres cosas relacionadas. Conviene distinguirlas desde el principio:

| Nombre | Que es | Donde vive |
|--------|--------|-----------|
| Nova directo (web) | Chat web que el paciente usa desde el navegador | nova.html |
| Nova WhatsApp | El mismo asistente atendiendo por WhatsApp | webhook-meta/server.js + nova-wa.php |
| Nova TD respuesta | Generador de respuestas sugeridas para los agentes sobre tickets de correo (no es un chat) | nova-td-respuesta.php |

Las dos primeras son el asistente conversacional propiamente dicho (el foco de este documento). La tercera comparte el nombre pero es otra cosa: ayuda a los agentes a redactar respuestas a las PQRSFD que llegan por correo, y se explica al final.

### Nova directo (web) y Nova WhatsApp: mismo cerebro, distinto canal

Ambos canales hacen lo mismo de cara al paciente: piden aceptar la politica, identifican por cedula, muestran el mismo menu de 8 opciones, consultan las mismas sedes, aplican la misma normalizacion de EPS y escalan a un agente igual. La diferencia esta en donde corre la logica:

| Aspecto | Nova directo (web) | Nova WhatsApp |
|---------|--------------------|--------------|
| Interfaz | nova.html (navegador) | WhatsApp del paciente |
| Donde se arma el prompt | En JavaScript, dentro de nova.html (funcion ntdSys) | En PHP, dentro de nova-wa.php (funcion construirSistema) |
| Quien controla el flujo | El propio nova.html | webhook-meta/server.js (Node) |
| Llamada a IA | Directo a nova-proxy.php | nova-wa.php llama a nova-proxy.php |
| Estado de la sesion | nova_sesiones (via funciones RPC) | wa_sesiones (tabla de estado completa) |

En la practica, Nova web es autonomo: el mismo nova.html arma el prompt y llama al proxy de IA. Nova WhatsApp necesita la pareja Node + PHP porque el canal es asincrono (los mensajes entran por webhook) y requiere mantener el estado de la conversacion entre mensajes.

Un punto importante de mantenimiento: como las dos versiones arman el prompt por separado (una en JavaScript, otra en PHP), cualquier cambio en el comportamiento de Nova hay que hacerlo en los dos lugares para que ambos canales se mantengan iguales.

### Componentes completos

| Componente | Tecnologia | Rol |
|------------|-----------|-----|
| nova.html | HTML + JavaScript | Nova directo: interfaz y logica del chat web |
| webhook-meta/server.js | Node.js (PM2, puerto 3000) | Nova WhatsApp: recibe mensajes, controla la maquina de estados, envia respuestas |
| nova-wa.php | PHP | Cerebro de Nova WhatsApp: decide que responder, aplica reglas, arma el prompt, interpreta tags |
| nova-proxy.php | PHP | Puente seguro hacia OpenAI (chat, transcripcion, voz). Lo usan ambos canales |
| nova-consulta.php | PHP | Consulta de radicados PQRSFD por cedula, ticket o correo. Lo usan ambos canales |
| validar-paciente.php | PHP | Valida la cedula del paciente contra el padron. Lo usan ambos canales |
| nova-td-respuesta.php | PHP | Genera respuestas sugeridas para agentes sobre tickets de correo (uso aparte) |

---

## 2. Como se conectan los componentes

Esta seccion describe el canal de WhatsApp, que es el que mas piezas involucra. El canal web (nova.html) es mas simple: el navegador arma el prompt y llama directo a nova-proxy.php, sin pasar por el Node.

El flujo de una respuesta automatica por WhatsApp recorre esta cadena:

```
WhatsApp del paciente
   |
   v
Meta WhatsApp API
   |  (envia el evento)
   v
Nginx  /wa/  ->  server.js (Node, puerto 3000)
   |
   |  el Node decide: ¿es navegacion de menu, una fase especial,
   |  o una pregunta libre?
   |
   |  si necesita pensar una respuesta, llama por HTTP a:
   v
nova-wa.php (PHP)  --->  validar-paciente.php   (identificar)
   |                --->  nova-consulta.php      (consultar radicado)
   |                --->  nova-proxy.php  --->  OpenAI   (respuesta IA)
   |
   |  nova-wa.php responde con un JSON: { respuesta, accion, fase }
   v
server.js recibe el JSON, envia el texto al paciente por Meta,
y guarda el estado en la tabla wa_sesiones (Supabase)
```

Puntos clave de la conexion:

- El server.js (Node) y nova-wa.php (PHP) se comunican por HTTP interno, protegido con una cabecera `X-Nova-Token`.
- Toda llamada a OpenAI pasa por nova-proxy.php; ni el Node ni nova-wa.php hablan directo con OpenAI para las respuestas del bot (nova-wa delega en el proxy).
- El estado de cada conversacion vive en la tabla `wa_sesiones`, identificada por el numero de telefono. Ambos componentes leen y escriben ahi.
- La respuesta de nova-wa.php siempre trae una `accion` (MENU, ESCALADO, ENCUESTA, CONTINUAR, etc.) que le dice al Node que hacer a continuacion.

---

## 3. Ciclo de vida de una conversacion

```
1. Paciente escribe por primera vez
   -> Nova pide aceptar la politica de privacidad

2. Acepta
   -> Nova pide el numero de documento

3. Ingresa la cedula
   -> validar-paciente.php la busca en el padron
   -> si existe: saludo personalizado + menu principal
   -> si es VIP: saludo especial guardado para ese paciente

4. El paciente navega el menu (opciones 1 a 8) o escribe libremente
   -> Nova responde con informacion, enlaces o respuestas de IA

5. Cierre
   -> si Nova resuelve: pregunta si quedo resuelto -> encuesta
   -> si el paciente pide asesor o hay urgencia: escala a un agente
   -> si hay 5 minutos de inactividad: la sesion se cierra sola
```

---

## 4. Maquina de estados

Cada conversacion tiene un `estado` y una `fase`. El estado dice en que situacion general esta; la fase, en que paso puntual del dialogo.

Estados principales (columna `estado` de wa_sesiones):

| Estado | Significado |
|--------|-------------|
| nova | Atendido por el bot |
| confirmando_solucion_nova | Nova pregunta si la consulta quedo resuelta (SI/NO) |
| esperando_encuesta | Esperando la calificacion del paciente |
| esperando | En cola para un agente, o tomando un mensaje fuera de horario |
| escalado | Asignado a un agente, pendiente de su primera respuesta |
| activo | Conversando con un agente humano |
| cerrado | Conversacion finalizada |

Fases (columna `fase`), pasos puntuales dentro del flujo:

| Fase | Que espera |
|------|------------|
| politica | Que acepte o rechace la politica de privacidad |
| ident_ced | Que ingrese su numero de documento |
| libre | Conversacion abierta, ya identificado |
| municipio_sedes | Que indique el municipio para buscar sedes |
| consulta_cedula | Que indique cedula/radicado para consultar PQRSFD |
| esperando_decision_espera | SI/NO para seguir esperando un agente |
| tomando_mensaje_fh | Acumulando el mensaje que dejara fuera de horario |
| esperando_menu_post_nova | M (menu) o A (asesor) despues de una respuesta |
| esperando_menu_fh | M o A en el mensaje de fuera de horario |
| ofrece_asesor_meds / ofrece_asesor_horario | SI/NO tras ofrecer un asesor |

Una regla importante de diseno: cuando Nova muestra el menu numerado, el Node intercepta el numero que responde el paciente ANTES de mandarlo a la IA. Si el numero llegara crudo al modelo, este lo interpretaria como texto libre y daria una respuesta incorrecta. Por eso el numero se traduce a una accion concreta en el server.js.

---

## 5. El menu principal y sus ocho opciones

El menu que ve el paciente:

```
1. Estado o entrega de medicamentos
2. Puntos de dispensacion
3. Requisitos para reclamar
4. Radicar PQRSFD
5. Estado de mi radicado
6. Horarios y canales
7. Encuesta de satisfaccion
8. Pregunta a Nova TD
```

Que hace cada opcion:

| Opcion | Accion interna | Comportamiento |
|--------|----------------|----------------|
| 1 Medicamentos | MEDICAMENTOS | Entrega el enlace a la App Solicitudes Web y ofrece que un asesor verifique |
| 2 Sedes | SEDES | Pregunta el municipio y luego lista los puntos de dispensacion |
| 3 Requisitos | REQUISITOS | Explica que documentos se necesitan para reclamar medicamentos |
| 4 Radicar | FORMULARIO | Entrega el enlace al formulario de PQRSFD (con la cedula precargada si se conoce) |
| 5 Estado radicado | CONSULTAR | Consulta los radicados del paciente por su cedula |
| 6 Horarios | HORARIOS | Muestra canales de contacto y horarios de atencion |
| 7 Encuesta | ENCUESTA | Entrega el enlace a la encuesta de satisfaccion |
| 8 Pregunta libre | DEFAULT | Abre el modo conversacion con IA |

Las opciones 1, 2, 3, 4, 6 y 7 estan escritas directamente en el codigo (respuestas fijas), por velocidad y para no gastar llamadas de IA en informacion que no cambia. Solo la opcion 8 (y las preguntas libres) usan el modelo de lenguaje.

---

## 6. Identificacion del paciente

Antes de atender, Nova identifica a quien escribe:

1. En el primer contacto muestra la politica de privacidad. Si el paciente la rechaza dos veces, la conversacion se cierra.
2. Tras aceptar, pide el numero de documento (entre 5 y 12 digitos).
3. Llama a `validar-paciente.php`, que busca la cedula en el padron de pacientes (`tabla_usuarios`).
4. Si la encuentra, recupera nombre, EPS y ciudad, y saluda de forma personalizada. Si el paciente esta marcado como VIP, usa un saludo especial guardado para el.
5. Si no la encuentra, permite reintentar e indica los canales alternativos de contacto.

La sesion identificada queda registrada en `nova_sesiones` para metricas (cedula, nombre, EPS, municipio, si es VIP, hora de inicio).

---

## 7. Normalizacion de EPS

Los pacientes nombran su EPS de muchas formas, y el padron a veces trae la razon social completa. Nova normaliza todos esos nombres a un valor estandar para que coincidan con el catalogo de sedes. El mapa de equivalencias:

| El paciente o el padron dice | Nova lo convierte en |
|------------------------------|----------------------|
| PREVENTIVA, PREVENTIVA SALUD | COOSALUD |
| CEM, COMITE DE ESTUDIOS MEDICOS | SAVIA |
| ANGIOSUR, ANGIOSUR S.A.S | SAVIA |
| SAVIA SALUD, ALIANZA MEDELLIN | SAVIA |
| NUEVA EMPRESA PROMOTORA DE SALUD | NUEVA EPS |
| COOSALUD | COOSALUD |

Esta normalizacion ocurre en dos lugares: al identificar al paciente (sobre el dato del padron) y dentro del prompt que se le da al modelo. Asi, cuando el paciente pregunta por sedes, la EPS ya esta en el formato que el catalogo entiende.

---

## 8. Consulta de sedes

Tododrogas tiene puntos de dispensacion en muchos municipios de Antioquia, cada uno con las EPS que atiende. Nova maneja esto asi:

- Mantiene un catalogo de sedes con nombre, municipio, direccion, coordenadas y EPS atendidas. El catalogo se carga desde la tabla `sedes` de Supabase; si esa carga falla, usa una copia local incluida en el codigo como respaldo.
- Cuando el paciente elige la opcion de sedes, Nova siempre le pregunta el municipio (puede ser distinto al de su registro).
- Filtra las sedes de ese municipio segun la EPS del paciente, o segun una EPS que el paciente mencione explicitamente.
- Caso especial documentado: en Medellin hay dos sedes en la misma direccion que atienden EPS diferentes; Nova lo aclara al responder.

El modelo de IA recibe instrucciones precisas (los tags `[SEDES:municipio]` y `[SEDES:municipio:EPS]`) para decidir como consultar segun lo que pida el paciente.

---

## 9. El cerebro de IA y los tags

Cuando el paciente escribe libremente (opcion 8 o cualquier texto que no sea navegacion), nova-wa.php construye un prompt de sistema y se lo envia al modelo a traves de nova-proxy.php.

El prompt de sistema incluye:
- La identidad de Nova y la fecha actual.
- El nombre, la EPS y la ciudad del paciente.
- La cobertura de EPS por municipio, derivada del catalogo de sedes.
- El catalogo completo de sedes.
- Reglas de comportamiento (trato de usted, maximo 120 palabras, formato de WhatsApp, prioridad a la seguridad en temas de medicamentos).
- Las reglas dinamicas activas (ver siguiente seccion).

El modelo no responde solo con texto: incluye tags entre corchetes que nova-wa.php interpreta para ejecutar acciones. Los tags y su efecto:

| Tag | Efecto |
|-----|--------|
| [MENU] | Vuelve a mostrar el menu principal |
| [FORMULARIO] | Agrega el enlace para radicar PQRSFD |
| [ESCALAR] | Escala la conversacion a un agente humano |
| [ENCUESTA] | Agrega el enlace de la encuesta de satisfaccion |
| [MEDICAMENTOS] | Informacion sobre estado y entrega de medicamentos |
| [REQUISITOS] | Requisitos para reclamar |
| [CONSULTAR:valor] | Consulta un radicado por numero, cedula o correo |
| [SEDES:municipio] | Lista sedes del municipio para la EPS del paciente |
| [SEDES:municipio:EPS] | Lista sedes del municipio para una EPS especifica |
| [CAMBIAR_EPS] | Flujo de cambio de EPS |

Un detalle de seguridad relevante en el prompt: ante cualquier mencion de un medicamento vencido, en mal estado o deteriorado, Nova tiene la instruccion de responder siempre primero "No lo consuma. Por su seguridad NO utilice ese medicamento" antes de cualquier otra cosa.

---

## 10. Reglas dinamicas

Nova puede ajustar su comportamiento sin tocar el codigo, gracias a la tabla `nova_reglas`. Cada regla tiene unas palabras disparadoras (`triggers`) y una instruccion. Cuando el mensaje del paciente contiene alguna palabra disparadora, la instruccion correspondiente se inyecta en el prompt con prioridad maxima.

Esto permite, por ejemplo, crear una respuesta especial para una campana puntual o un cambio temporal de proceso, solo agregando una fila en la base de datos.

---

## 11. Escalamiento a agente humano

Nova escala a un agente en varios casos:

- El paciente pide explicitamente un asesor (detecta palabras como "asesor", "agente", "hablar con").
- Urgencia medica: si menciona un medicamento vencido, una reaccion, una intoxicacion o una emergencia, se escala de inmediato.
- Paciente molesto: hay tres niveles segun la insistencia. Primero Nova reconoce la molestia y ofrece un asesor; si persiste, lo ofrece con mas enfasis; al tercer intento, escala automaticamente marcando el caso como urgente.
- El modelo decide escalar (tag [ESCALAR]).

El proceso de asignacion (`autoAsignarAgente`):
1. Busca el agente activo, en linea y no pausado con menor carga de trabajo.
2. Le asigna la sesion y le suma uno a su carga.
3. Genera con IA un saludo sugerido y empatico para que el agente lo use al iniciar.
4. Registra el evento en `logs_conversacion`.

Si no hay agentes disponibles, Nova le pregunta al paciente si desea seguir esperando. Fuera del horario de atencion, le ofrece dejar un mensaje que un agente atendera en el proximo horario habil.

Horario de atencion de agentes: lunes a viernes de 7:00 a.m. a 5:30 p.m., sabados de 8:00 a.m. a 12:00 m. Nova como bot funciona 24/7; lo que respeta el horario es el escalamiento a humanos.

---

## 12. Encuesta de cierre

Cuando Nova resuelve una consulta, pregunta si quedo resuelta (SI/NO). Si el paciente confirma, se le envia la encuesta de satisfaccion, que se responde con MALA, REGULAR o BUENA. La calificacion se guarda en la sesion y, al cerrar, se archiva en `wa_historico`.

---

## 13. Tareas automaticas (crons)

El server.js corre tres tareas periodicas en segundo plano:

| Tarea | Frecuencia | Que hace |
|-------|-----------|----------|
| cronAgentesOffline | Cada 3 minutos | Marca como desconectados a los agentes sin actividad en 6 minutos y libera su carga |
| cronReasignar | Cada 2 minutos | Asigna las sesiones en espera (incluidas las dejadas fuera de horario) cuando hay un agente libre |
| cronEncuestaTimeout | Cada 15 minutos | A los 30 minutos sin responder la encuesta envia un recordatorio; a las 24 horas cierra la sesion en silencio |

---

## 14. Tablas que usa Nova

| Tabla | Uso de Nova |
|-------|-------------|
| wa_sesiones | Estado de cada conversacion activa (la tabla central del bot) |
| wa_historico | Archivo de las conversaciones cerradas |
| nova_sesiones | Metricas de cada sesion del bot (duracion, consultas, motivo de cierre) |
| nova_reglas | Reglas de comportamiento dinamico |
| logs_conversacion | Registro de eventos (asignaciones, cierres, mensajes) |
| agentes | Buscar agente disponible y actualizar su carga |
| sedes | Catalogo de puntos de dispensacion |
| tabla_usuarios | Padron para validar la identidad del paciente |
| correos | Consulta de radicados PQRSFD del paciente |

---

## 15. Nova TD respuesta (correos)

Ademas del asistente conversacional, existe nova-td-respuesta.php, que comparte el nombre "Nova" pero cumple una funcion distinta: no chatea con pacientes, sino que ayuda a los agentes a responder las PQRSFD que llegan por correo.

Como funciona:
- Lo invocan los paneles admin.html y agente.html cuando un agente abre un ticket de correo.
- Recibe los datos del ticket (asunto, contenido, tipo de PQRSFD, prioridad, ley aplicable, SLA, etc.).
- Busca en la base de conocimiento (knowledge_base) situaciones similares.
- Con GPT-4o-mini genera una respuesta sugerida que el agente puede usar o ajustar.

Segun su propia documentacion interna, reemplazo a un flujo de automatizacion externo anterior, manteniendo el mismo formato de respuesta. Es decir, antes esta tarea la hacia una herramienta externa y ahora la resuelve este endpoint PHP.

Aunque lleva "Nova" en el nombre, conviene tenerlo claro como una pieza separada: el asistente conversacional (web y WhatsApp) atiende pacientes; Nova TD respuesta asiste a los agentes con los correos.

---
Documentacion tecnica de SIGI — Tododrogas.
