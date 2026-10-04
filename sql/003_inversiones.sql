-- ============================================================================
-- Migración 003: limpieza de datos del módulo de Inversiones / préstamos otorgados
-- ANTES DE EJECUTAR: haz una copia de seguridad (phpMyAdmin > Exportar).
-- Es idempotente: se puede ejecutar más de una vez. No borra ningún registro.
-- Ejecutar con la base de datos u214407853_dbFinanzas seleccionada.
-- ============================================================================

-- 1. Fechas '0000-00-00' (fecha inválida) -> NULL. Significan "sin fecha": cuota aún no cobrada o inversión sin fecha final.
SET SESSION sql_mode = REPLACE(REPLACE(@@sql_mode, 'NO_ZERO_DATE', ''), 'NO_ZERO_IN_DATE', '');
UPDATE PlanPagos   SET FechaRealPago = NULL WHERE FechaRealPago = '0000-00-00';
UPDATE Inversiones SET FechaFin      = NULL WHERE FechaFin      = '0000-00-00';

-- 2. Cuotas con un estado que no corresponde a un cobro (estado de gastos "Pendiente", id 3) -> Pagos "Pendiente".
UPDATE PlanPagos
   SET IdEstado = (SELECT idEstado FROM estados WHERE TipoEstado = 'Pagos' AND NombreEstado = 'Pendiente' LIMIT 1)
 WHERE IdEstado IN (SELECT idEstado FROM estados WHERE TipoEstado <> 'Pagos')
   AND EXISTS (SELECT 1 FROM estados WHERE TipoEstado = 'Pagos' AND NombreEstado = 'Pendiente');

-- 3. Índice para las consultas de cobros (por estado y fecha prevista).
SET @existe := (SELECT COUNT(*) FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = 'PlanPagos' AND index_name = 'ix_plan_estado_fecha');
SET @sql := IF(@existe = 0, 'CREATE INDEX ix_plan_estado_fecha ON PlanPagos (IdEstado, FechaPrevistaPago)', 'SELECT ''ix_plan_estado_fecha ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Verificación: las dos primeras columnas deben ser 0; la tercera, 1.
SELECT (SELECT COUNT(*) FROM PlanPagos WHERE FechaRealPago = '0000-00-00') AS fechas_invalidas_plan,
       (SELECT COUNT(*) FROM Inversiones WHERE FechaFin = '0000-00-00')    AS fechas_invalidas_inversiones,
       (SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'PlanPagos' AND index_name = 'ix_plan_estado_fecha') > 0 AS indice_creado;
