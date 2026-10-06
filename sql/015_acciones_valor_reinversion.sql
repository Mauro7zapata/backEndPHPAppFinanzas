-- ============================================================================
-- Migración 015: acciones — valor actual (saldo de la plataforma) y reinversión de dividendos
-- ANTES DE EJECUTAR: haz una copia de seguridad (phpMyAdmin > Exportar).
-- Es idempotente. No modifica datos existentes.
--   - Inversiones.ValorActual / FechaValorActual: lo que dice la plataforma hoy (para ver ganancia o pérdida).
--   - valoraciones_inversion: historial de esos saldos (para ver la evolución).
--   - PlanPagos.Reinvertido: el dividendo cobrado se reinvirtió (suma al capital).
--   - aportes_inversion.idPlan: aporte que nació de la reinversión de un dividendo (se mantiene solo).
-- Requiere 006 (aportes_inversion). Ejecutar con la base de datos seleccionada.
-- ============================================================================

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'Inversiones' AND column_name = 'ValorActual');
SET @sql := IF(@c = 0, 'ALTER TABLE Inversiones ADD COLUMN ValorActual DECIMAL(15,2) NULL, ADD COLUMN FechaValorActual DATE NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS valoraciones_inversion (
    idValoracion INT AUTO_INCREMENT PRIMARY KEY,
    idInversion  INT NOT NULL,
    IdUsuario    INT NOT NULL,
    Fecha        DATE NOT NULL,
    Valor        DECIMAL(15,2) NOT NULL,
    FechaRegistro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_valoracion_inversion (idInversion, Fecha),
    KEY ix_valoracion_usuario (IdUsuario),
    CONSTRAINT fk_valoracion_usuario FOREIGN KEY (IdUsuario) REFERENCES usuarios (IdUsuario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'PlanPagos' AND column_name = 'Reinvertido');
SET @sql := IF(@c = 0, 'ALTER TABLE PlanPagos ADD COLUMN Reinvertido TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'aportes_inversion' AND column_name = 'idPlan');
SET @sql := IF(@c = 0, 'ALTER TABLE aportes_inversion ADD COLUMN idPlan INT NULL, ADD KEY ix_aporte_plan (idPlan)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'Inversiones' AND column_name = 'ValorActual') AS valor_actual,
       (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'PlanPagos' AND column_name = 'Reinvertido') AS reinvertido,
       (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'aportes_inversion' AND column_name = 'idPlan') AS aporte_plan,
       (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'valoraciones_inversion') AS tabla_valoraciones;
