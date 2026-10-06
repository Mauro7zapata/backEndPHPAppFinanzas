-- ============================================================================
-- Migración 013: moneda de las inversiones (COP o USD) y del fondo de inversión
-- ANTES DE EJECUTAR: haz una copia de seguridad (phpMyAdmin > Exportar).
-- Es idempotente. Todo lo existente queda en COP.
--   - Inversiones.Moneda y fondo_inversion.Moneda ('COP' | 'USD'), por defecto 'COP'.
--   - Los importes de inversiones pasan a DECIMAL(15,2) (si no lo eran) para poder guardar centavos en USD.
-- Requiere haber ejecutado antes la 011 (fondo_inversion). Ejecutar con la base de datos seleccionada.
-- ============================================================================

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'Inversiones' AND column_name = 'Moneda');
SET @sql := IF(@c = 0, 'ALTER TABLE Inversiones ADD COLUMN Moneda CHAR(3) NOT NULL DEFAULT ''COP''', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'fondo_inversion' AND column_name = 'Moneda');
SET @sql := IF(@c = 0, 'ALTER TABLE fondo_inversion ADD COLUMN Moneda CHAR(3) NOT NULL DEFAULT ''COP''', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Importes con 2 decimales (solo si la columna existe y aún no tiene 2 decimales)
SET @sql := (SELECT IF(COUNT(*) = 0, 'SELECT 1',
    CONCAT('ALTER TABLE Inversiones MODIFY CapitalInvertido DECIMAL(15,2) ', IF(MAX(is_nullable) = 'YES', 'NULL', 'NOT NULL'),
           IF(MAX(column_default) IS NULL, '', CONCAT(' DEFAULT ', MAX(column_default)))))
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'Inversiones' AND column_name = 'CapitalInvertido'
    AND (numeric_scale IS NULL OR numeric_scale < 2 OR data_type <> 'decimal'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := (SELECT IF(COUNT(*) = 0, 'SELECT 1',
    CONCAT('ALTER TABLE Inversiones MODIFY CuotaPactada DECIMAL(15,2) ', IF(MAX(is_nullable) = 'YES', 'NULL', 'NOT NULL'),
           IF(MAX(column_default) IS NULL, '', CONCAT(' DEFAULT ', MAX(column_default)))))
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'Inversiones' AND column_name = 'CuotaPactada'
    AND (numeric_scale IS NULL OR numeric_scale < 2 OR data_type <> 'decimal'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := (SELECT IF(COUNT(*) = 0, 'SELECT 1',
    CONCAT('ALTER TABLE PlanPagos MODIFY InteresPagado DECIMAL(15,2) ', IF(MAX(is_nullable) = 'YES', 'NULL', 'NOT NULL'),
           IF(MAX(column_default) IS NULL, '', CONCAT(' DEFAULT ', MAX(column_default)))))
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'PlanPagos' AND column_name = 'InteresPagado'
    AND (numeric_scale IS NULL OR numeric_scale < 2 OR data_type <> 'decimal'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := (SELECT IF(COUNT(*) = 0, 'SELECT 1',
    CONCAT('ALTER TABLE PlanPagos MODIFY CapitalPagado DECIMAL(15,2) ', IF(MAX(is_nullable) = 'YES', 'NULL', 'NOT NULL'),
           IF(MAX(column_default) IS NULL, '', CONCAT(' DEFAULT ', MAX(column_default)))))
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'PlanPagos' AND column_name = 'CapitalPagado'
    AND (numeric_scale IS NULL OR numeric_scale < 2 OR data_type <> 'decimal'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := (SELECT IF(COUNT(*) = 0, 'SELECT 1',
    CONCAT('ALTER TABLE PlanPagos MODIFY DividendoPagado DECIMAL(15,2) ', IF(MAX(is_nullable) = 'YES', 'NULL', 'NOT NULL'),
           IF(MAX(column_default) IS NULL, '', CONCAT(' DEFAULT ', MAX(column_default)))))
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'PlanPagos' AND column_name = 'DividendoPagado'
    AND (numeric_scale IS NULL OR numeric_scale < 2 OR data_type <> 'decimal'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := (SELECT IF(COUNT(*) = 0, 'SELECT 1',
    CONCAT('ALTER TABLE aportes_inversion MODIFY Valor DECIMAL(15,2) ', IF(MAX(is_nullable) = 'YES', 'NULL', 'NOT NULL'),
           IF(MAX(column_default) IS NULL, '', CONCAT(' DEFAULT ', MAX(column_default)))))
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'aportes_inversion' AND column_name = 'Valor'
    AND (numeric_scale IS NULL OR numeric_scale < 2 OR data_type <> 'decimal'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT (SELECT COUNT(*) FROM Inversiones WHERE Moneda = 'COP') AS inversiones_cop,
       (SELECT COUNT(*) FROM Inversiones WHERE Moneda = 'USD') AS inversiones_usd;
