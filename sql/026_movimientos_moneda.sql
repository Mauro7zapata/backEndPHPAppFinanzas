-- ============================================================================
-- Migración 026: movimientos del presupuesto en dólares (con su equivalente en pesos)
--
--   - movimientos.valorMovimiento SIGUE siendo el valor en PESOS (lo que cuenta el presupuesto; no cambia ningún cálculo).
--   - movimientos.Moneda: 'COP' (por defecto) o 'USD' (la compra/pago se hizo en dólares).
--   - movimientos.ValorOriginal: el valor en dólares (solo si Moneda = 'USD').
--   - movimientos.TasaCambio: pesos por dólar realmente aplicados (valorMovimiento / ValorOriginal), informativa.
--
-- Es idempotente (se puede ejecutar varias veces). Los movimientos existentes quedan en COP. Haz respaldo antes.
-- ============================================================================

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'movimientos' AND column_name = 'Moneda');
SET @sql := IF(@c = 0, 'ALTER TABLE movimientos ADD COLUMN Moneda CHAR(3) NOT NULL DEFAULT ''COP''', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'movimientos' AND column_name = 'ValorOriginal');
SET @sql := IF(@c = 0, 'ALTER TABLE movimientos ADD COLUMN ValorOriginal DECIMAL(15,2) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'movimientos' AND column_name = 'TasaCambio');
SET @sql := IF(@c = 0, 'ALTER TABLE movimientos ADD COLUMN TasaCambio DECIMAL(14,4) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Verificación: deben salir 3 columnas.
SELECT COUNT(*) AS columnas_moneda FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'movimientos' AND column_name IN ('Moneda','ValorOriginal','TasaCambio');
