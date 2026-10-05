-- ============================================================================
-- Migración 012: catálogo «lugares de guardado» (dónde tienes el dinero Guardado/Acumulado)
-- ANTES DE EJECUTAR: haz una copia de seguridad (phpMyAdmin > Exportar).
-- Es idempotente. No modifica datos existentes de gastos. Ejecutar con la base de datos seleccionada.
--   - Tabla lugares_guardado (por usuario), con 4 lugares de ejemplo que el usuario puede renombrar o eliminar.
--   - gastos.idLugar (opcional): lugar donde está guardado lo separado en ese gasto. Si se elimina el lugar, el gasto queda «sin lugar».
-- ============================================================================
CREATE TABLE IF NOT EXISTS lugares_guardado (
    idLugar       INT AUTO_INCREMENT PRIMARY KEY,
    IdUsuario     INT NOT NULL,
    Nombre        VARCHAR(60) NOT NULL,
    FechaRegistro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY ux_lugar_usuario_nombre (IdUsuario, Nombre),
    CONSTRAINT fk_lugar_usuario FOREIGN KEY (IdUsuario) REFERENCES usuarios (IdUsuario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Semilla: 4 lugares por usuario (solo los que falten)
INSERT INTO lugares_guardado (IdUsuario, Nombre)
SELECT u.IdUsuario, n.Nombre
FROM usuarios u
JOIN (SELECT 'Bolsillo físico' AS Nombre UNION ALL SELECT 'Bolsillo Nequi' UNION ALL SELECT 'Bolsillo Bancolombia' UNION ALL SELECT 'Meta Nequi') n
LEFT JOIN lugares_guardado l ON l.IdUsuario = u.IdUsuario AND l.Nombre = n.Nombre
WHERE l.idLugar IS NULL;

-- Columna gastos.idLugar
SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'gastos' AND column_name = 'idLugar');
SET @sql := IF(@col = 0, 'ALTER TABLE gastos ADD COLUMN idLugar INT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'gastos' AND constraint_name = 'fk_gastos_lugar');
SET @sql := IF(@fk = 0, 'ALTER TABLE gastos ADD CONSTRAINT fk_gastos_lugar FOREIGN KEY (idLugar) REFERENCES lugares_guardado (idLugar) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT COUNT(*) AS lugares FROM lugares_guardado;
