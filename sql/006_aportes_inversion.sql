-- ============================================================================
-- Migración 006: aportes de capital a una inversión (capital adicional con fecha y observación)
-- ANTES DE EJECUTAR: haz una copia de seguridad (phpMyAdmin > Exportar).
-- Es idempotente. No modifica datos existentes. Ejecutar con la base de datos seleccionada.
-- El capital total de la inversión (Inversiones.CapitalInvertido) lo mantiene el servidor:
-- cada aporte lo suma y al borrarlo lo resta.
-- ============================================================================
CREATE TABLE IF NOT EXISTS aportes_inversion (
    idAporte      INT AUTO_INCREMENT PRIMARY KEY,
    idInversion   INT NOT NULL,
    IdUsuario     INT NOT NULL,
    Fecha         DATE NOT NULL,
    Valor         DECIMAL(15,2) NOT NULL,
    Observaciones VARCHAR(500) NULL,
    FechaRegistro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_aporte_inversion (idInversion, Fecha),
    KEY ix_aporte_usuario (IdUsuario),
    CONSTRAINT fk_aporte_usuario FOREIGN KEY (IdUsuario) REFERENCES usuarios (IdUsuario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT COUNT(*) AS aportes_registrados FROM aportes_inversion;
