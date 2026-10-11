-- ============================================================================
-- Migración 021: imágenes adjuntas a los reportes de «Sugerencias y errores»
-- ANTES DE EJECUTAR: haz una copia de seguridad. Es idempotente. Requiere la 019.
-- Máximo 3 imágenes por reporte (la app las reduce a ~1280 px, JPEG). Se borran con el reporte.
-- Para verlas como administrador: phpMyAdmin > comentarios_app_imagenes > Imagen > descargar.
-- ============================================================================
CREATE TABLE IF NOT EXISTS comentarios_app_imagenes (
    idImagen      INT AUTO_INCREMENT PRIMARY KEY,
    idComentario  INT NOT NULL,
    Mime          VARCHAR(30) NOT NULL,
    Bytes         INT NOT NULL,
    Imagen        MEDIUMBLOB NOT NULL,
    FechaCreacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_imagen_comentario (idComentario),
    CONSTRAINT fk_imagen_comentario FOREIGN KEY (idComentario) REFERENCES comentarios_app (idComentario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT COUNT(*) AS imagenes FROM comentarios_app_imagenes;
