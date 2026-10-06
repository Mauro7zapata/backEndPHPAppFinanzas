-- ============================================================================
-- Migración 018: tarjetas de crédito con compras en pesos (COP) y dólares (USD)
-- ANTES DE EJECUTAR: haz una copia de seguridad (phpMyAdmin > Exportar).
-- Es idempotente. Todo lo existente queda en COP.
--   - movimientos_deuda.Moneda: moneda de cada movimiento (cargo, interés o abono).
--   - deudas.SaldoInicialUsd: lo que ya debías en dólares al empezar a registrar la tarjeta.
-- El saldo en pesos y el saldo en dólares se llevan por separado (no se convierten ni se suman).
-- Ejecutar con la base de datos seleccionada.
-- ============================================================================

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'movimientos_deuda' AND column_name = 'Moneda');
SET @sql := IF(@c = 0, 'ALTER TABLE movimientos_deuda ADD COLUMN Moneda CHAR(3) NOT NULL DEFAULT ''COP''', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'deudas' AND column_name = 'SaldoInicialUsd');
SET @sql := IF(@c = 0, 'ALTER TABLE deudas ADD COLUMN SaldoInicialUsd DECIMAL(12,2) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'movimientos_deuda' AND column_name = 'Moneda') AS moneda_movimientos,
       (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'deudas' AND column_name = 'SaldoInicialUsd') AS saldo_inicial_usd;
