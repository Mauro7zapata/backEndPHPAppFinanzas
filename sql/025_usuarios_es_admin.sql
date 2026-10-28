-- 025 · Rol de administrador (reemplaza el «código de administrador»).
-- Idempotente. Todos los usuarios quedan en 0; el rol se asigna SOLO por SQL, nunca desde la app.
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'EsAdmin');
SET @sql := IF(@existe = 0, 'ALTER TABLE usuarios ADD COLUMN EsAdmin TINYINT(1) NOT NULL DEFAULT 0 AFTER Activo', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Asignar el rol (ejecútalo aparte, después de la columna):
UPDATE usuarios SET EsAdmin = 1 WHERE Correo = 'mauro7developer@gmail.com';
