-- 009: avance previo de una obligación anual ("ya llevo ahorrado") al empezar a usar la app. Idempotente.
SET @e := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'obligaciones' AND column_name = 'AhorradoInicial');
SET @sql := IF(@e = 0, 'ALTER TABLE obligaciones ADD COLUMN AhorradoInicial DECIMAL(12,2) NOT NULL DEFAULT 0', 'SELECT ''obligaciones.AhorradoInicial ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
