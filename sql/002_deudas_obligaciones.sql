-- ============================================================================
-- Migración 002: módulo de Deudas (tarjetas, préstamos, deudas informales)
--                y Obligaciones anuales (renta, SOAT, tecnomecánica, impuestos...)
-- ANTES DE EJECUTAR: haz una copia de seguridad (phpMyAdmin > Exportar).
-- Es idempotente: se puede ejecutar más de una vez. No modifica datos existentes.
-- Ejecutar con la base de datos u214407853_dbFinanzas seleccionada.
-- ============================================================================

CREATE TABLE IF NOT EXISTS deudas (
    idDeuda        INT AUTO_INCREMENT PRIMARY KEY,
    Nombre         VARCHAR(100) NOT NULL,
    Tipo           ENUM('Tarjeta','Prestamo','Informal') NOT NULL,
    Acreedor       VARCHAR(100) NULL,
    SaldoInicial   DECIMAL(12,2) NOT NULL DEFAULT 0,   -- deuda al momento de empezar a registrarla (puede ser aproximada)
    CupoTotal      DECIMAL(12,2) NULL,                 -- solo tarjetas
    DiaCorte       TINYINT NULL,                       -- solo tarjetas (1-31)
    DiaPago        TINYINT NULL,                       -- fecha límite de pago / día de la cuota (1-31)
    TasaAnual      DECIMAL(6,2) NULL,                  -- % efectivo anual, informativo
    CuotaMensual   DECIMAL(12,2) NULL,                 -- préstamos
    FechaFin       DATE NULL,                          -- préstamos: fecha estimada de terminación
    Activa         TINYINT(1) NOT NULL DEFAULT 1,
    Notas          VARCHAR(250) NULL,
    FechaCreacion  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Saldo actual = SaldoInicial + Cargos + Intereses - Abonos
CREATE TABLE IF NOT EXISTS movimientos_deuda (
    idMovDeuda   INT AUTO_INCREMENT PRIMARY KEY,
    idDeuda      INT NOT NULL,
    Tipo         ENUM('Cargo','Abono','Interes') NOT NULL,
    Valor        DECIMAL(12,2) NOT NULL,
    Fecha        DATE NOT NULL,
    Nota         VARCHAR(250) NULL,
    idMovimiento INT NULL,                              -- si nació de un movimiento del presupuesto (pago de un gasto vinculado)
    UNIQUE KEY uq_movdeuda_movimiento (idMovimiento),
    KEY ix_movdeuda_deuda (idDeuda),
    CONSTRAINT fk_movdeuda_deuda FOREIGN KEY (idDeuda) REFERENCES deudas (idDeuda) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_movdeuda_movimiento FOREIGN KEY (idMovimiento) REFERENCES movimientos (idMovimiento) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS obligaciones (
    idObligacion      INT AUTO_INCREMENT PRIMARY KEY,
    Nombre            VARCHAR(100) NOT NULL,
    IdCategoria       INT NOT NULL,
    ValorEstimado     DECIMAL(12,2) NOT NULL,
    FechaVencimiento  DATE NOT NULL,
    CicloInicio       DATE NOT NULL,                    -- desde cuándo cuenta lo provisionado en el ciclo actual
    Activa            TINYINT(1) NOT NULL DEFAULT 1,
    Notas             VARCHAR(250) NULL,
    KEY ix_obligaciones_categoria (IdCategoria),
    CONSTRAINT fk_obligaciones_categoria FOREIGN KEY (IdCategoria) REFERENCES categoriagastos (idCategoriaGastos) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Vínculos opcionales de un gasto del presupuesto con una deuda u obligación.
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'gastos' AND column_name = 'idDeuda');
SET @sql := IF(@existe = 0, 'ALTER TABLE gastos ADD COLUMN idDeuda INT NULL', 'SELECT ''gastos.idDeuda ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'gastos' AND column_name = 'idObligacion');
SET @sql := IF(@existe = 0, 'ALTER TABLE gastos ADD COLUMN idObligacion INT NULL', 'SELECT ''gastos.idObligacion ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.table_constraints
                WHERE constraint_schema = DATABASE() AND table_name = 'gastos' AND constraint_name = 'fk_gastos_deuda');
SET @sql := IF(@existe = 0,
    'ALTER TABLE gastos ADD CONSTRAINT fk_gastos_deuda FOREIGN KEY (idDeuda) REFERENCES deudas (idDeuda) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT ''fk_gastos_deuda ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.table_constraints
                WHERE constraint_schema = DATABASE() AND table_name = 'gastos' AND constraint_name = 'fk_gastos_obligacion');
SET @sql := IF(@existe = 0,
    'ALTER TABLE gastos ADD CONSTRAINT fk_gastos_obligacion FOREIGN KEY (idObligacion) REFERENCES obligaciones (idObligacion) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT ''fk_gastos_obligacion ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Verificación (debe devolver 3 tablas y 2 columnas):
SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('deudas','movimientos_deuda','obligaciones')) AS tablas_nuevas,
       (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'gastos' AND column_name IN ('idDeuda','idObligacion')) AS columnas_gastos;
