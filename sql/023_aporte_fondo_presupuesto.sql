-- ============================================================================
-- Migración 023: aporte mensual al fondo de inversión desde el presupuesto
-- ANTES DE EJECUTAR: haz una copia de seguridad. Es idempotente.
--
-- · gastos.AporteFondo: 1 = el gasto es el «Aporte al fondo de inversión» que se crea solo en cada presupuesto.
--   Lo que se pague (movimientos) de ese gasto cuenta como INGRESO al fondo (en COP). Si borras el gasto, desaparece ese ingreso.
-- · El valor del aporte mensual se guarda en configuracion (clave aporte_fondo_mensual; 0 = desactivado). No necesita cambios aquí.
--
-- Opcional (corrige presupuestos ya creados): las provisiones de obligaciones que quedaron «Acumulado» sin ningún movimiento
-- pasan a «Pendiente» (lo correcto: aún no has apartado la plata). Revisa el SELECT antes de ejecutar el UPDATE.
-- ============================================================================
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'gastos' AND column_name = 'AporteFondo');
SET @sql := IF(@existe = 0, 'ALTER TABLE gastos ADD COLUMN AporteFondo TINYINT(1) NOT NULL DEFAULT 0', 'SELECT ''AporteFondo ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Opcional · 1) mira cuáles cambiarían:
-- SELECT g.idGastos, g.NombreGasto, p.Mes, p.Anho FROM gastos g
--   INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
--   INNER JOIN estados e ON e.idEstado = g.IdEstado
--  WHERE g.idObligacion IS NOT NULL AND e.NombreEstado = 'Acumulado' AND e.TipoEstado = 'Gastos' AND g.valorGastosMovimiento = 0;
-- Opcional · 2) aplícalos:
-- UPDATE gastos g
--   INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
--   INNER JOIN estados e ON e.idEstado = g.IdEstado
--   INNER JOIN estados pe ON pe.IdUsuario = e.IdUsuario AND pe.TipoEstado = 'Gastos' AND pe.NombreEstado = 'Pendiente'
--    SET g.IdEstado = pe.idEstado
--  WHERE g.idObligacion IS NOT NULL AND e.NombreEstado = 'Acumulado' AND e.TipoEstado = 'Gastos' AND g.valorGastosMovimiento = 0;
