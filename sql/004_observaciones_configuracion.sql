-- ============================================================================
-- Migración 004: observaciones por cuota y tabla de configuración (parámetros)
-- ANTES DE EJECUTAR: haz una copia de seguridad (phpMyAdmin > Exportar).
-- Es idempotente: se puede ejecutar más de una vez. No borra ningún registro.
-- Ejecutar con la base de datos u214407853_dbFinanzas seleccionada.
-- ============================================================================

-- 1. Observaciones por cuota del plan de pagos (inversiones).
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'PlanPagos' AND column_name = 'Observaciones');
SET @sql := IF(@existe = 0, 'ALTER TABLE PlanPagos ADD COLUMN Observaciones VARCHAR(500) NULL', 'SELECT ''Observaciones ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Parámetros de la app (clave / valor).
CREATE TABLE IF NOT EXISTS configuracion (
    Clave VARCHAR(60) NOT NULL PRIMARY KEY,
    Valor VARCHAR(255) NOT NULL,
    Actualizado TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO configuracion (Clave, Valor) VALUES
    ('dias_aviso_pagos', '3'),
    ('dias_aviso_obligaciones', '30'),
    ('dia_inicio_mes', '1'),
    ('porcentaje_alerta_presupuesto', '80');

-- 3. Plantilla de gastos frecuentes: gastos que el usuario marca con el check "Repetir cada mes".
--    Guarda solo la identidad (nombre + categoría); el valor y el día salen de su aparición más reciente.
CREATE TABLE IF NOT EXISTS plantilla_gastos (
    Nombre VARCHAR(255) NOT NULL,
    IdCategoria INT NOT NULL,
    PRIMARY KEY (Nombre, IdCategoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Verificación: debe devolver 1 y 4.
SELECT (SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'PlanPagos' AND column_name = 'Observaciones') AS columna_observaciones,
       (SELECT COUNT(*) FROM configuracion) AS parametros;
