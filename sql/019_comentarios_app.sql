-- ============================================================================
-- Migración 019: sugerencias y errores que reportan los usuarios desde la app
-- ANTES DE EJECUTAR: haz una copia de seguridad (phpMyAdmin > Exportar).
-- Es idempotente y no modifica datos existentes. Ejecutar con la base de datos seleccionada.
-- Requiere la 005 (usuarios). Al eliminar la cuenta se borran sus reportes.
--
-- Quien administra la app cambia Estado y escribe la Respuesta desde phpMyAdmin; el usuario las ve en la app.
-- ============================================================================
CREATE TABLE IF NOT EXISTS comentarios_app (
    idComentario       INT AUTO_INCREMENT PRIMARY KEY,
    IdUsuario          INT NOT NULL,
    Tipo               ENUM('Mejora','Error') NOT NULL,
    Titulo             VARCHAR(120) NOT NULL,
    Descripcion        VARCHAR(2000) NOT NULL,
    Estado             ENUM('Nuevo','En revision','Planeado','Resuelto','Descartado') NOT NULL DEFAULT 'Nuevo',
    Respuesta          VARCHAR(1000) NULL,           -- la escribe el administrador
    VersionApp         VARCHAR(30) NULL,             -- ayuda a reproducir errores
    Dispositivo        VARCHAR(120) NULL,
    Ambiente           VARCHAR(10) NULL,
    FechaCreacion      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FechaActualizacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY ix_comentario_usuario (IdUsuario, FechaCreacion),
    KEY ix_comentario_estado (Estado, Tipo),
    CONSTRAINT fk_comentario_usuario FOREIGN KEY (IdUsuario) REFERENCES usuarios (IdUsuario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT COUNT(*) AS comentarios FROM comentarios_app;
