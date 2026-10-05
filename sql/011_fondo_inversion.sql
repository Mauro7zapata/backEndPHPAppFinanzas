-- ============================================================================
-- Migración 011: fondo de inversión (dinero disponible para invertir vs. invertido) y su descuadre
-- ANTES DE EJECUTAR: haz una copia de seguridad (phpMyAdmin > Exportar).
-- Es idempotente. No modifica datos existentes. Ejecutar con la base de datos seleccionada.
--
-- Disponible = Ingresos - Retiros + Ajustes + Cobrado (capital, intereses y dividendos) - Capital desembolsado
-- Un «Ajuste» es el descuadre: la diferencia entre lo que la app calcula y lo que el usuario dice tener realmente.
-- ============================================================================
CREATE TABLE IF NOT EXISTS fondo_inversion (
    idMovFondo    INT AUTO_INCREMENT PRIMARY KEY,
    IdUsuario     INT NOT NULL,
    Fecha         DATE NOT NULL,
    Tipo          ENUM('Ingreso','Retiro','Ajuste') NOT NULL,
    Valor         DECIMAL(15,2) NOT NULL,          -- Ingreso/Retiro: siempre positivo. Ajuste: con signo (puede ser negativo)
    Nota          VARCHAR(250) NULL,
    FechaRegistro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_fondo_usuario (IdUsuario, Fecha),
    CONSTRAINT fk_fondo_usuario FOREIGN KEY (IdUsuario) REFERENCES usuarios (IdUsuario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT COUNT(*) AS movimientos_fondo FROM fondo_inversion;
