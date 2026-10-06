-- ============================================================================
-- Migración 017: más decimales en la tasa de interés de las inversiones
-- ANTES DE EJECUTAR: haz una copia de seguridad (phpMyAdmin > Exportar).
-- Es idempotente. Al convertir una tasa anual (E.A.) a mensual salen 4 decimales (p. ej. 12% E.A. = 0.9489% mensual);
-- con menos decimales la tasa se redondearía y los intereses saldrían un poco distintos.
-- ============================================================================
SET @sql := (SELECT IF(COUNT(*) = 0, 'SELECT 1',
    CONCAT('ALTER TABLE Inversiones MODIFY Interes DECIMAL(9,4) ', IF(MAX(is_nullable) = 'YES', 'NULL', 'NOT NULL'),
           IF(MAX(column_default) IS NULL, '', CONCAT(' DEFAULT ', MAX(column_default)))))
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'Inversiones' AND column_name = 'Interes'
    AND (data_type <> 'decimal' OR numeric_scale < 4));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'Inversiones' AND column_name = 'Interes';
