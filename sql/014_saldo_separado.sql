-- ============================================================================
-- Migración 014: saldo separado (lo Guardado/Acumulado que sigue guardado) y lo que se usa de él
-- ANTES DE EJECUTAR: haz una copia de seguridad (phpMyAdmin > Exportar).
-- Es idempotente. No modifica datos existentes. Requiere la 012 (lugares_guardado). Ejecutar con la base de datos seleccionada.
--
-- Saldo separado (por lugar) = Aportes + Ajustes - Usos
--   Aportes = movimientos de los gastos en estado Guardado/Acumulado (ya existían; se cuentan solos, de todos los meses)
--   Usos    = dinero que sacas de lo guardado (para pagar algo, retirarlo...). Registrado aquí.
--   Ajustes = correcciones cuando declaras cuánto tienes de verdad en un lugar (con signo)
-- ============================================================================
CREATE TABLE IF NOT EXISTS separado_usos (
    idUso         INT AUTO_INCREMENT PRIMARY KEY,
    IdUsuario     INT NOT NULL,
    idLugar       INT NULL,                          -- NULL = «sin lugar asignado»
    Fecha         DATE NOT NULL,
    Tipo          ENUM('Uso','Ajuste') NOT NULL,
    Valor         DECIMAL(15,2) NOT NULL,            -- Uso: siempre positivo. Ajuste: con signo
    Nota          VARCHAR(250) NULL,
    FechaRegistro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_uso_usuario (IdUsuario, Fecha),
    KEY ix_uso_lugar (idLugar),
    CONSTRAINT fk_uso_usuario FOREIGN KEY (IdUsuario) REFERENCES usuarios (IdUsuario) ON DELETE CASCADE,
    CONSTRAINT fk_uso_lugar FOREIGN KEY (idLugar) REFERENCES lugares_guardado (idLugar) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT COUNT(*) AS movimientos_separado FROM separado_usos;
