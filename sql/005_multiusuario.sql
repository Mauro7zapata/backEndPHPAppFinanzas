-- ============================================================================
-- Migración 005: multiusuario (inicio de sesión por usuario)
-- ANTES DE EJECUTAR: haz una copia de seguridad (phpMyAdmin > Exportar).
-- Es idempotente. No borra registros: todos los datos actuales pasan a ser del usuario 1
-- (la "cuenta inicial"), que reclamas después registrándote con el mismo correo.
-- Ejecutar con la base de datos u214407853_dbFinanzas seleccionada.
-- ============================================================================

-- >>> CAMBIA ESTE CORREO por el tuyo (el de la cuenta que reclamará los datos actuales).
SET @correo_inicial := 'mauro7developer@gmail.com';

-- 1. Usuarios
CREATE TABLE IF NOT EXISTS usuarios (
    IdUsuario    INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Correo       VARCHAR(190) NOT NULL,
    ClaveHash    VARCHAR(255) NULL,           -- NULL = cuenta solo con Google o aún sin reclamar
    GoogleSub    VARCHAR(64)  NULL,           -- identificador estable de Google
    Nombre       VARCHAR(100) NULL,
    Activo       TINYINT(1)   NOT NULL DEFAULT 1,
    Creado       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UltimoAcceso DATETIME     NULL,
    UNIQUE KEY uq_usuarios_correo (Correo),
    UNIQUE KEY uq_usuarios_google (GoogleSub)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Sesiones (tokens guardados solo como hash SHA-256; se pueden revocar)
CREATE TABLE IF NOT EXISTS sesiones (
    IdSesion       INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    IdUsuario      INT NOT NULL,
    TokenHash      CHAR(64) NOT NULL,
    RefreshHash    CHAR(64) NOT NULL,
    ExpiraToken    DATETIME NOT NULL,
    ExpiraRefresh  DATETIME NOT NULL,
    Dispositivo    VARCHAR(100) NULL,
    Creada         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sesiones_token (TokenHash),
    UNIQUE KEY uq_sesiones_refresh (RefreshHash),
    KEY ix_sesiones_usuario (IdUsuario),
    CONSTRAINT fk_sesiones_usuario FOREIGN KEY (IdUsuario) REFERENCES usuarios (IdUsuario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Intentos de acceso (límite de intentos por correo e IP)
CREATE TABLE IF NOT EXISTS intentos_acceso (
    Id     INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Clave  VARCHAR(190) NOT NULL,
    Tipo   VARCHAR(12)  NOT NULL,
    Fecha  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_intentos (Clave, Tipo, Fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Cuenta inicial (dueña de los datos existentes). Sin clave hasta que el dueño la reclame.
INSERT INTO usuarios (IdUsuario, Correo, Nombre)
SELECT 1, @correo_inicial, 'Cuenta inicial'
WHERE NOT EXISTS (SELECT 1 FROM usuarios WHERE IdUsuario = 1);

-- 5. IdUsuario en las tablas raíz (los hijos heredan por su padre: gastos, movimientos, PlanPagos, movimientos_deuda)
SET @e := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'presupuestos' AND column_name = 'IdUsuario');
SET @sql := IF(@e = 0, 'ALTER TABLE presupuestos ADD COLUMN IdUsuario INT NOT NULL DEFAULT 1', 'SELECT ''presupuestos.IdUsuario ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @e := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'categoriagastos' AND column_name = 'IdUsuario');
SET @sql := IF(@e = 0, 'ALTER TABLE categoriagastos ADD COLUMN IdUsuario INT NOT NULL DEFAULT 1', 'SELECT ''categoriagastos.IdUsuario ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @e := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'estados' AND column_name = 'IdUsuario');
SET @sql := IF(@e = 0, 'ALTER TABLE estados ADD COLUMN IdUsuario INT NOT NULL DEFAULT 1', 'SELECT ''estados.IdUsuario ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @e := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'deudas' AND column_name = 'IdUsuario');
SET @sql := IF(@e = 0, 'ALTER TABLE deudas ADD COLUMN IdUsuario INT NOT NULL DEFAULT 1', 'SELECT ''deudas.IdUsuario ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @e := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'obligaciones' AND column_name = 'IdUsuario');
SET @sql := IF(@e = 0, 'ALTER TABLE obligaciones ADD COLUMN IdUsuario INT NOT NULL DEFAULT 1', 'SELECT ''obligaciones.IdUsuario ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @e := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'Inversiones' AND column_name = 'IdUsuario');
SET @sql := IF(@e = 0, 'ALTER TABLE Inversiones ADD COLUMN IdUsuario INT NOT NULL DEFAULT 1', 'SELECT ''Inversiones.IdUsuario ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @e := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'plantilla_gastos' AND column_name = 'IdUsuario');
SET @sql := IF(@e = 0, 'ALTER TABLE plantilla_gastos ADD COLUMN IdUsuario INT NOT NULL DEFAULT 1', 'SELECT ''plantilla_gastos.IdUsuario ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @e := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'configuracion' AND column_name = 'IdUsuario');
SET @sql := IF(@e = 0, 'ALTER TABLE configuracion ADD COLUMN IdUsuario INT NOT NULL DEFAULT 1', 'SELECT ''configuracion.IdUsuario ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
-- Quitar el DEFAULT 1 (los endpoints deben indicar siempre el dueño) y crear índice + clave foránea
ALTER TABLE presupuestos ALTER COLUMN IdUsuario DROP DEFAULT;
SET @e := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'presupuestos' AND index_name = 'ix_presupuestos_usuario');
SET @sql := IF(@e = 0, 'ALTER TABLE presupuestos ADD KEY ix_presupuestos_usuario (IdUsuario)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @e := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'presupuestos' AND constraint_name = 'fk_presupuestos_usuario');
SET @sql := IF(@e = 0, 'ALTER TABLE presupuestos ADD CONSTRAINT fk_presupuestos_usuario FOREIGN KEY (IdUsuario) REFERENCES usuarios (IdUsuario) ON DELETE CASCADE', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
ALTER TABLE categoriagastos ALTER COLUMN IdUsuario DROP DEFAULT;
SET @e := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'categoriagastos' AND index_name = 'ix_categoriagastos_usuario');
SET @sql := IF(@e = 0, 'ALTER TABLE categoriagastos ADD KEY ix_categoriagastos_usuario (IdUsuario)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @e := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'categoriagastos' AND constraint_name = 'fk_categoriagastos_usuario');
SET @sql := IF(@e = 0, 'ALTER TABLE categoriagastos ADD CONSTRAINT fk_categoriagastos_usuario FOREIGN KEY (IdUsuario) REFERENCES usuarios (IdUsuario) ON DELETE CASCADE', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
ALTER TABLE estados ALTER COLUMN IdUsuario DROP DEFAULT;
SET @e := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'estados' AND index_name = 'ix_estados_usuario');
SET @sql := IF(@e = 0, 'ALTER TABLE estados ADD KEY ix_estados_usuario (IdUsuario)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @e := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'estados' AND constraint_name = 'fk_estados_usuario');
SET @sql := IF(@e = 0, 'ALTER TABLE estados ADD CONSTRAINT fk_estados_usuario FOREIGN KEY (IdUsuario) REFERENCES usuarios (IdUsuario) ON DELETE CASCADE', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
ALTER TABLE deudas ALTER COLUMN IdUsuario DROP DEFAULT;
SET @e := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'deudas' AND index_name = 'ix_deudas_usuario');
SET @sql := IF(@e = 0, 'ALTER TABLE deudas ADD KEY ix_deudas_usuario (IdUsuario)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @e := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'deudas' AND constraint_name = 'fk_deudas_usuario');
SET @sql := IF(@e = 0, 'ALTER TABLE deudas ADD CONSTRAINT fk_deudas_usuario FOREIGN KEY (IdUsuario) REFERENCES usuarios (IdUsuario) ON DELETE CASCADE', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
ALTER TABLE obligaciones ALTER COLUMN IdUsuario DROP DEFAULT;
SET @e := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'obligaciones' AND index_name = 'ix_obligaciones_usuario');
SET @sql := IF(@e = 0, 'ALTER TABLE obligaciones ADD KEY ix_obligaciones_usuario (IdUsuario)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @e := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'obligaciones' AND constraint_name = 'fk_obligaciones_usuario');
SET @sql := IF(@e = 0, 'ALTER TABLE obligaciones ADD CONSTRAINT fk_obligaciones_usuario FOREIGN KEY (IdUsuario) REFERENCES usuarios (IdUsuario) ON DELETE CASCADE', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
ALTER TABLE Inversiones ALTER COLUMN IdUsuario DROP DEFAULT;
SET @e := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'Inversiones' AND index_name = 'ix_Inversiones_usuario');
SET @sql := IF(@e = 0, 'ALTER TABLE Inversiones ADD KEY ix_Inversiones_usuario (IdUsuario)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @e := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'Inversiones' AND constraint_name = 'fk_Inversiones_usuario');
SET @sql := IF(@e = 0, 'ALTER TABLE Inversiones ADD CONSTRAINT fk_Inversiones_usuario FOREIGN KEY (IdUsuario) REFERENCES usuarios (IdUsuario) ON DELETE CASCADE', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
ALTER TABLE plantilla_gastos ALTER COLUMN IdUsuario DROP DEFAULT;
SET @e := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'plantilla_gastos' AND index_name = 'ix_plantilla_gastos_usuario');
SET @sql := IF(@e = 0, 'ALTER TABLE plantilla_gastos ADD KEY ix_plantilla_gastos_usuario (IdUsuario)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @e := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'plantilla_gastos' AND constraint_name = 'fk_plantilla_gastos_usuario');
SET @sql := IF(@e = 0, 'ALTER TABLE plantilla_gastos ADD CONSTRAINT fk_plantilla_gastos_usuario FOREIGN KEY (IdUsuario) REFERENCES usuarios (IdUsuario) ON DELETE CASCADE', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
ALTER TABLE configuracion ALTER COLUMN IdUsuario DROP DEFAULT;
SET @e := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'configuracion' AND index_name = 'ix_configuracion_usuario');
SET @sql := IF(@e = 0, 'ALTER TABLE configuracion ADD KEY ix_configuracion_usuario (IdUsuario)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @e := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'configuracion' AND constraint_name = 'fk_configuracion_usuario');
SET @sql := IF(@e = 0, 'ALTER TABLE configuracion ADD CONSTRAINT fk_configuracion_usuario FOREIGN KEY (IdUsuario) REFERENCES usuarios (IdUsuario) ON DELETE CASCADE', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 6. Claves únicas / primarias que ahora deben incluir al usuario
-- presupuestos: un presupuesto por (usuario, año, mes)
SET @e := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'presupuestos' AND index_name = 'uq_presupuestos_anho_mes');
SET @sql := IF(@e > 0, 'ALTER TABLE presupuestos DROP INDEX uq_presupuestos_anho_mes', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @e := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'presupuestos' AND index_name = 'uq_presupuestos_usuario_anho_mes');
SET @sql := IF(@e = 0, 'ALTER TABLE presupuestos ADD UNIQUE KEY uq_presupuestos_usuario_anho_mes (IdUsuario, Anho, Mes)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- configuracion: (usuario, clave)
SET @e := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'configuracion' AND index_name = 'PRIMARY' AND column_name = 'IdUsuario');
SET @sql := IF(@e = 0, 'ALTER TABLE configuracion DROP PRIMARY KEY, ADD PRIMARY KEY (IdUsuario, Clave)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- plantilla_gastos: (usuario, nombre, categoría)
SET @e := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'plantilla_gastos' AND index_name = 'PRIMARY' AND column_name = 'IdUsuario');
SET @sql := IF(@e = 0, 'ALTER TABLE plantilla_gastos DROP PRIMARY KEY, ADD PRIMARY KEY (IdUsuario, Nombre, IdCategoria)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Verificación: usuarios = 1 y ninguna tabla raíz con IdUsuario sin dueño.
SELECT (SELECT COUNT(*) FROM usuarios) AS usuarios,
       (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'IdUsuario'
          AND table_name IN ('presupuestos','categoriagastos','estados','deudas','obligaciones','Inversiones','plantilla_gastos','configuracion')) AS tablas_con_duenho_debe_ser_8;
