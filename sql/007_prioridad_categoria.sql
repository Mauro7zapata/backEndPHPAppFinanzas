-- ============================================================================
-- Migración 007: prioridad por categoría de gastos (para ordenar y agrupar el presupuesto)
-- ANTES DE EJECUTAR: haz una copia de seguridad (phpMyAdmin > Exportar).
-- Es idempotente. No modifica datos existentes. Ejecutar con la base de datos seleccionada.
-- Prioridad: 0 = sin prioridad, 1 = alta, 2 = media, 3 = baja.
-- IMPORTANTE: ejecútala ANTES de subir el nuevo CategoriaGastos.php.
-- ============================================================================
SET @e := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'categoriagastos' AND column_name = 'Prioridad');
SET @sql := IF(@e = 0, 'ALTER TABLE categoriagastos ADD COLUMN Prioridad TINYINT NOT NULL DEFAULT 0', 'SELECT ''Prioridad ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT COUNT(*) AS categorias, SUM(Prioridad > 0) AS con_prioridad FROM categoriagastos;
