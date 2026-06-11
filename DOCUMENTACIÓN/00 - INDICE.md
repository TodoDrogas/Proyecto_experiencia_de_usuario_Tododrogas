# Documentacion SIGI

Sistema Integrado de Gestion Inteligente
Tododrogas — Procesos Digitales

Esta carpeta contiene la documentacion tecnica completa de SIGI, la plataforma omnicanal de gestion de PQRSFD y comunicaciones con pacientes.

Los documentos estan numerados en el orden de lectura aconsejado: van de lo general a lo especifico, agrupados por proposito. Aun asi, cada uno puede leerse por separado. Este indice indica que contiene cada documento y por donde empezar segun lo que se necesite.

---

## Orden de lectura aconsejado

Entender el sistema
- 01 README — que es SIGI y vision general
- 02 ARQUITECTURA — como esta construido

Detalle tecnico (para programar)
- 03 ESTRUCTURA BD — el modelo de datos
- 04 MAPA DE CONEXIONES — que se conecta con que
- 05 NOVA TD — el asistente en detalle

Operar el sistema (dia a dia)
- 06 DEPENDENCIAS SERVIDOR — que debe estar instalado
- 07 RUNBOOK — operacion normal
- 08 OPERACION INCIDENTES — resolver problemas

Seguridad y futuro
- 09 POLITICAS RLS — control de acceso
- 10 PLAN DE MIGRACION AZURE — migrar a Azure

---

## Atajos segun lo que necesite

| Si necesita... | Lea |
|----------------|-----|
| Entender que es SIGI y como esta montado | 01 y 02 |
| Operar el sistema o resolver una falla | 07 y 08 |
| Programar o modificar el sistema | 03, 04 y 05 |
| Migrar a Azure | 10 (y los que referencia) |
| Revisar la seguridad | 09 |

---

## Los documentos en detalle

### 01 README
Vision general de SIGI. Que hace el sistema, el stack tecnologico completo (PHP, Node, Supabase, OpenAI, Meta, Microsoft Graph) y la estructura de carpetas del proyecto. El punto de partida para quien llega nuevo.

### 02 ARQUITECTURA
Como esta construido el sistema de punta a punta. Diagrama de componentes, la infraestructura del servidor, el flujo de la informacion por cada canal (web, correo, WhatsApp), como se comunican las piezas y como se manejan las credenciales y el despliegue.

### 03 ESTRUCTURA BD
El modelo de datos completo. Las 21 tablas con sus columnas, las vistas, los triggers, las relaciones, los indices y el inventario de los buckets de almacenamiento de archivos. La referencia para cualquier trabajo sobre la base de datos.

### 04 MAPA DE CONEXIONES
Que se conecta con que. Para cada archivo: que servicios externos consume, que modelos de IA usa y que tablas toca. Incluye que proceso escribe y cual lee cada tabla, y que deja de funcionar si falla cada servicio.

### 05 NOVA TD
El asistente virtual en detalle. Los dos canales de Nova (web y WhatsApp), como comparten el mismo cerebro, la maquina de estados, el menu, la identificacion del paciente, la normalizacion de EPS, el escalamiento a agentes y las tareas automaticas. Termina con ideas para mejorarlo.

### 06 DEPENDENCIAS SERVIDOR
Todo lo que debe estar instalado para que SIGI funcione. Los paquetes de PHP y sus extensiones, Node y PM2, Nginx, la configuracion de produccion, los cron y las credenciales. Guia para montar el entorno desde cero.

### 07 RUNBOOK
El manual de operacion del dia a dia. Como controlar los servicios, donde estan los logs, como diagnosticar fallas, como renovar el token de Meta, como volver a una version anterior y el mantenimiento periodico.

### 08 OPERACION INCIDENTES
Los problemas que ya ocurrieron y como se resuelven. El orden de diagnostico cuando el sistema no carga (empezando por la capacidad de la base de datos), el incidente de Nginx, las politicas RLS que bloquean tablas y la restriccion de acceso por dominio.

### 09 POLITICAS RLS
El inventario literal de las politicas de seguridad de la base de datos, tal como existen hoy. El script completo para recrear el control de acceso y las notas sobre que cambia al migrar a Azure.

### 10 PLAN DE MIGRACION AZURE
El plan completo para migrar a Azure conservando toda la funcionalidad. Inventario de lo que hay que mover, equivalencias hacia Azure, los puntos criticos, las fases, la lista de verificacion funcional y el plan de rollback.

---

## Como mantener esta documentacion

Cada documento es independiente y se actualiza por separado cuando cambia la parte del sistema que describe. Al hacer un cambio importante, conviene revisar si algun documento queda desactualizado. Cuando se complete la migracion a Azure, varios documentos (02, 06, 07) deberan actualizarse para reflejar la nueva infraestructura.

---

Documentacion tecnica de SIGI — Tododrogas, Procesos Digitales.
