-- ============================================================================
-- Migración 001: integridad del módulo de presupuesto
-- ANTES DE EJECUTAR: haz una copia de seguridad (phpMyAdmin > Exportar).
-- Es idempotente: se puede ejecutar más de una vez.
-- ============================================================================

-- 1) Un solo presupuesto por mes/año (el PHP ya lo valida; esto lo garantiza en la BD).
--    Verificado: no hay duplicados actuales.
SET @existe := (SELECT COUNT(*) FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = 'presupuestos'
                  AND index_name = 'uq_presupuestos_anho_mes');
SET @sql := IF(@existe = 0,
    'ALTER TABLE presupuestos ADD UNIQUE KEY uq_presupuestos_anho_mes (Anho, Mes)',
    'SELECT ''uq_presupuestos_anho_mes ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) Un gasto no puede quedar con una categoría o estado inexistente (RESTRICT = no permite borrar la categoría/estado en uso).
--    Verificado: no hay gastos huérfanos actuales.
SET @existe := (SELECT COUNT(*) FROM information_schema.table_constraints
                WHERE constraint_schema = DATABASE() AND table_name = 'gastos'
                  AND constraint_name = 'fk_gastos_categoria');
SET @sql := IF(@existe = 0,
    'ALTER TABLE gastos ADD CONSTRAINT fk_gastos_categoria FOREIGN KEY (IdCategoria) REFERENCES categoriagastos (idCategoriaGastos) ON DELETE RESTRICT ON UPDATE CASCADE',
    'SELECT ''fk_gastos_categoria ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.table_constraints
                WHERE constraint_schema = DATABASE() AND table_name = 'gastos'
                  AND constraint_name = 'fk_gastos_estado');
SET @sql := IF(@existe = 0,
    'ALTER TABLE gastos ADD CONSTRAINT fk_gastos_estado FOREIGN KEY (IdEstado) REFERENCES estados (idEstado) ON DELETE RESTRICT ON UPDATE CASCADE',
    'SELECT ''fk_gastos_estado ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3) Triggers de movimientos: nunca dejan NULL (0 si no quedan movimientos) y,
--    si un movimiento cambia de gasto, recalculan también el gasto anterior.
DROP TRIGGER IF EXISTS actualizar_valor_gasto_insert;
DROP TRIGGER IF EXISTS actualizar_valor_gasto_update;
DROP TRIGGER IF EXISTS actualizar_valor_gasto_delete;

DELIMITER $$
CREATE TRIGGER actualizar_valor_gasto_insert AFTER INSERT ON movimientos FOR EACH ROW
BEGIN
    UPDATE gastos
    SET valorGastosMovimiento = (SELECT COALESCE(SUM(valorMovimiento), 0) FROM movimientos WHERE idGasto = NEW.idGasto)
    WHERE idGastos = NEW.idGasto;
END$$

CREATE TRIGGER actualizar_valor_gasto_update AFTER UPDATE ON movimientos FOR EACH ROW
BEGIN
    UPDATE gastos
    SET valorGastosMovimiento = (SELECT COALESCE(SUM(valorMovimiento), 0) FROM movimientos WHERE idGasto = NEW.idGasto)
    WHERE idGastos = NEW.idGasto;
    IF OLD.idGasto <> NEW.idGasto THEN
        UPDATE gastos
        SET valorGastosMovimiento = (SELECT COALESCE(SUM(valorMovimiento), 0) FROM movimientos WHERE idGasto = OLD.idGasto)
        WHERE idGastos = OLD.idGasto;
    END IF;
END$$

CREATE TRIGGER actualizar_valor_gasto_delete AFTER DELETE ON movimientos FOR EACH ROW
BEGIN
    UPDATE gastos
    SET valorGastosMovimiento = (SELECT COALESCE(SUM(valorMovimiento), 0) FROM movimientos WHERE idGasto = OLD.idGasto)
    WHERE idGastos = OLD.idGasto;
END$$
DELIMITER ;

-- 4) Recalcula el total de movimientos de todos los gastos.
--    Corrige los 2 gastos descuadrados detectados en la auditoría (idGastos 120 y 187).
UPDATE gastos g
SET g.valorGastosMovimiento = COALESCE((SELECT SUM(m.valorMovimiento) FROM movimientos m WHERE m.idGasto = g.idGastos), 0);
