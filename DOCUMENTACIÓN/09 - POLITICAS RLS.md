# Inventario de Politicas RLS — SIGI

Sistema Integrado de Gestion Inteligente
Tododrogas — Procesos Digitales

Inventario literal de todas las politicas Row Level Security (RLS) existentes en la base de datos, tal como estan hoy. Este documento es el respaldo del control de acceso: sirve para reconstruirlo en otro entorno (por ejemplo, la migracion a Azure) o para restaurarlo si se pierde.

Generado desde Supabase. Total: 45 politicas sobre 18 tablas.

> Importante: este es el estado ACTUAL, no el recomendado. El analisis de seguridad de estas politicas (cuales conviene ajustar) esta en seguridad.md. Aqui solo se registra lo que existe.

---

## Como se genera este inventario

Para regenerarlo en cualquier momento, ejecutar en el SQL Editor de Supabase:

```sql
SELECT
  'CREATE POLICY "' || policyname || '" ON ' || tablename ||
  ' FOR ' || cmd ||
  ' TO ' || array_to_string(roles, ', ') ||
  CASE WHEN qual IS NOT NULL THEN ' USING (' || qual || ')' ELSE '' END ||
  CASE WHEN with_check IS NOT NULL THEN ' WITH CHECK (' || with_check || ')' ELSE '' END ||
  ';' AS sql_politica
FROM pg_policies
WHERE schemaname = 'public'
ORDER BY tablename, policyname;
```

---

## Resumen por tabla

| Tabla | Politicas | Roles que acceden |
|-------|-----------|-------------------|
| adjuntos | 6 | anon, public, authenticated |
| agentes | 2 | anon |
| configuracion_sistema | 4 | anon, service_role |
| contactos_frecuentes | 3 | anon |
| correos | 6 | anon, public |
| encuestas_satisfaccion | 2 | anon |
| eps_catalogo | 2 | public |
| gestiones_medicamentos | 3 | anon, authenticated |
| historial_eventos | 3 | anon, authenticated |
| knowledge_base | 3 | anon |
| logs_agentes | 2 | public |
| logs_conversacion | 1 | public |
| medicamentos | 1 | anon, authenticated |
| nova_reglas | 1 | public |
| nova_sesiones | 3 | public |
| respuestas | 1 | anon |
| sedes | 2 | anon, service_role |
| wa_sesiones | 1 | public |

Tablas con RLS habilitado pero SIN politicas (acceso denegado por defecto a anon/authenticated): tabla_usuarios, usuarios_vip, wa_historico. Estas solo son accesibles por el rol de servicio.

---

## Script completo de recreacion

Este bloque recrea todas las politicas tal como existen hoy. Para usarlo en un entorno nuevo, ejecutar despues de crear las tablas y habilitar RLS en cada una.

```sql
-- ════════════════════════════════════════════════════════════
-- adjuntos
-- ════════════════════════════════════════════════════════════
CREATE POLICY "adjuntos_anon_insert" ON adjuntos FOR INSERT TO anon WITH CHECK (true);
CREATE POLICY "adjuntos_anon_select" ON adjuntos FOR SELECT TO anon USING (true);
CREATE POLICY "admin_delete_adjuntos_informacion" ON adjuntos FOR DELETE TO anon, authenticated USING ((correo_id IN ( SELECT correos.id FROM correos WHERE (correos.estado = 'informacion'::text))));
CREATE POLICY "anon_all_adjuntos" ON adjuntos FOR ALL TO anon USING (true) WITH CHECK (true);
CREATE POLICY "leer adjuntos" ON adjuntos FOR SELECT TO public USING (true);
CREATE POLICY "leer_adjuntos_publico" ON adjuntos FOR SELECT TO public USING (true);

-- ════════════════════════════════════════════════════════════
-- agentes
-- ════════════════════════════════════════════════════════════
CREATE POLICY "agentes_anon_select" ON agentes FOR SELECT TO anon USING (true);
CREATE POLICY "agentes_anon_update" ON agentes FOR UPDATE TO anon USING (true) WITH CHECK (true);

-- ════════════════════════════════════════════════════════════
-- configuracion_sistema
-- ════════════════════════════════════════════════════════════
CREATE POLICY "config_anon_select" ON configuracion_sistema FOR SELECT TO anon USING (true);
CREATE POLICY "config_anon_update" ON configuracion_sistema FOR UPDATE TO anon USING (true) WITH CHECK (true);
CREATE POLICY "config_anon_write" ON configuracion_sistema FOR INSERT TO anon WITH CHECK (true);
CREATE POLICY "config_service_all" ON configuracion_sistema FOR ALL TO service_role USING (true) WITH CHECK (true);

-- ════════════════════════════════════════════════════════════
-- contactos_frecuentes
-- ════════════════════════════════════════════════════════════
CREATE POLICY "contactos_anon_insert" ON contactos_frecuentes FOR INSERT TO anon WITH CHECK (true);
CREATE POLICY "contactos_anon_select" ON contactos_frecuentes FOR SELECT TO anon USING (true);
CREATE POLICY "contactos_anon_update" ON contactos_frecuentes FOR UPDATE TO anon USING (true) WITH CHECK (true);

-- ════════════════════════════════════════════════════════════
-- correos
-- ════════════════════════════════════════════════════════════
CREATE POLICY "admin_delete_informacion" ON correos FOR DELETE TO anon, authenticated USING ((estado = 'informacion'::text));
CREATE POLICY "correos_anon_insert" ON correos FOR INSERT TO anon WITH CHECK (true);
CREATE POLICY "correos_anon_select" ON correos FOR SELECT TO anon USING (true);
CREATE POLICY "correos_anon_update" ON correos FOR UPDATE TO anon USING (true) WITH CHECK (true);
CREATE POLICY "insert_solo_formulario" ON correos FOR INSERT TO public WITH CHECK (true);
CREATE POLICY "select_solo_autenticado" ON correos FOR SELECT TO public USING ((auth.role() = 'authenticated'::text));

-- ════════════════════════════════════════════════════════════
-- encuestas_satisfaccion
-- ════════════════════════════════════════════════════════════
CREATE POLICY "encuestas_anon_insert" ON encuestas_satisfaccion FOR INSERT TO anon WITH CHECK (true);
CREATE POLICY "encuestas_anon_select" ON encuestas_satisfaccion FOR SELECT TO anon USING (true);

-- ════════════════════════════════════════════════════════════
-- eps_catalogo
-- ════════════════════════════════════════════════════════════
CREATE POLICY "eps_catalogo_escritura_admin" ON eps_catalogo FOR ALL TO public USING ((auth.role() = 'authenticated'::text)) WITH CHECK ((auth.role() = 'authenticated'::text));
CREATE POLICY "eps_catalogo_lectura_publica" ON eps_catalogo FOR SELECT TO public USING (true);

-- ════════════════════════════════════════════════════════════
-- gestiones_medicamentos
-- ════════════════════════════════════════════════════════════
CREATE POLICY "admin_delete_gestiones_informacion" ON gestiones_medicamentos FOR DELETE TO anon, authenticated USING ((correo_id IN ( SELECT correos.id FROM correos WHERE (correos.estado = 'informacion'::text))));
CREATE POLICY "allow_insert_gestiones_medicamentos" ON gestiones_medicamentos FOR INSERT TO anon, authenticated WITH CHECK (true);
CREATE POLICY "allow_select_gestiones_medicamentos" ON gestiones_medicamentos FOR SELECT TO anon, authenticated USING (true);

-- ════════════════════════════════════════════════════════════
-- historial_eventos
-- ════════════════════════════════════════════════════════════
CREATE POLICY "admin_delete_historial_informacion" ON historial_eventos FOR DELETE TO anon, authenticated USING ((correo_id IN ( SELECT correos.id FROM correos WHERE (correos.estado = 'informacion'::text))));
CREATE POLICY "historial_eventos_anon_insert" ON historial_eventos FOR INSERT TO anon WITH CHECK (true);
CREATE POLICY "historial_eventos_anon_select" ON historial_eventos FOR SELECT TO anon USING (true);

-- ════════════════════════════════════════════════════════════
-- knowledge_base
-- ════════════════════════════════════════════════════════════
CREATE POLICY "kb_anon_insert" ON knowledge_base FOR INSERT TO anon WITH CHECK (true);
CREATE POLICY "kb_anon_select" ON knowledge_base FOR SELECT TO anon USING ((activo = true));
CREATE POLICY "kb_anon_update" ON knowledge_base FOR UPDATE TO anon USING (true) WITH CHECK (true);

-- ════════════════════════════════════════════════════════════
-- logs_agentes
-- ════════════════════════════════════════════════════════════
CREATE POLICY "allow_insert_logs_agentes" ON logs_agentes FOR INSERT TO public WITH CHECK (true);
CREATE POLICY "allow_select_logs_agentes" ON logs_agentes FOR SELECT TO public USING (true);

-- ════════════════════════════════════════════════════════════
-- logs_conversacion
-- ════════════════════════════════════════════════════════════
CREATE POLICY "allow_all_logs_conversacion" ON logs_conversacion FOR ALL TO public USING (true) WITH CHECK (true);

-- ════════════════════════════════════════════════════════════
-- medicamentos
-- ════════════════════════════════════════════════════════════
CREATE POLICY "lectura_publica_medicamentos" ON medicamentos FOR SELECT TO anon, authenticated USING (true);

-- ════════════════════════════════════════════════════════════
-- nova_reglas
-- ════════════════════════════════════════════════════════════
CREATE POLICY "Lectura publica nova_reglas" ON nova_reglas FOR SELECT TO public USING (true);

-- ════════════════════════════════════════════════════════════
-- nova_sesiones
-- ════════════════════════════════════════════════════════════
CREATE POLICY "Permitir insert desde nova" ON nova_sesiones FOR INSERT TO public WITH CHECK (true);
CREATE POLICY "Permitir select desde admin" ON nova_sesiones FOR SELECT TO public USING (true);
CREATE POLICY "Permitir update desde nova" ON nova_sesiones FOR UPDATE TO public USING (true);

-- ════════════════════════════════════════════════════════════
-- respuestas
-- ════════════════════════════════════════════════════════════
CREATE POLICY "respuestas_anon_all" ON respuestas FOR ALL TO anon USING (true) WITH CHECK (true);

-- ════════════════════════════════════════════════════════════
-- sedes
-- ════════════════════════════════════════════════════════════
CREATE POLICY "sedes_anon_select" ON sedes FOR SELECT TO anon USING ((activa = true));
CREATE POLICY "sedes_service_all" ON sedes FOR ALL TO service_role USING (true) WITH CHECK (true);

-- ════════════════════════════════════════════════════════════
-- wa_sesiones
-- ════════════════════════════════════════════════════════════
CREATE POLICY "allow_all_service" ON wa_sesiones FOR ALL TO public USING (true) WITH CHECK (true);
```

---

## Notas para la migracion a Azure

Los roles no son universales. Las politicas usan los roles anon, authenticated, public y service_role. Estos son roles que Supabase crea automaticamente:
- anon: peticiones con la clave publica (el frontend).
- authenticated: usuarios autenticados con Supabase Auth.
- service_role: el backend con la clave de servicio (acceso completo).
- public: en PostgreSQL, public agrupa a todos los roles.

En Azure Database for PostgreSQL estos roles no existen de forma automatica. Si se migra, hay dos caminos:
- Recrear roles equivalentes manualmente y ajustar las politicas para que apunten a ellos.
- O cambiar el modelo de acceso: que toda la aplicacion pase por el backend con un unico rol, y controlar el acceso en la capa de aplicacion en vez de en RLS.

La funcion auth.role() es de Supabase. Dos politicas (select_solo_autenticado en correos y eps_catalogo_escritura_admin) usan auth.role(), una funcion propia de Supabase Auth. En Azure habria que reemplazarla por la logica de roles equivalente.

Recomendacion: antes de migrar, decidir el modelo de acceso en Azure. Si el backend ya centraliza la mayoria de operaciones con el rol de servicio, lo mas simple es que en Azure toda la aplicacion use un rol de servidor y el control de acceso se haga en los endpoints, conservando RLS solo como segunda barrera.

---

Documentacion tecnica de SIGI — Tododrogas.
