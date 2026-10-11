-- ============================================================================
-- Migración 022: «Sugerencias y errores» como tablero de la comunidad
-- ANTES DE EJECUTAR: haz una copia de seguridad. Es idempotente. Requiere la 019 (y la 021 si usas imágenes).
--
-- · comentarios_app.Publico: 1 = el reporte lo ven todos los usuarios (sin nombre ni datos del autor); 0 = solo su dueño.
--   Los reportes que ya existían quedan PRIVADOS (Publico = 0); su dueño puede hacerlos públicos desde la app.
-- · comentarios_apoyos: «a mí también me pasa / lo quiero» (Tipo = Tambien) y reaperturas de reportes cerrados (Tipo = Reabrir),
--   con una nota opcional. Una fila por usuario, reporte y tipo.
-- Las imágenes adjuntas (021) siguen siendo visibles solo para el dueño del reporte.
-- ============================================================================
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'comentarios_app' AND column_name = 'Publico');
SET @sql := IF(@existe = 0,
    'ALTER TABLE comentarios_app ADD COLUMN Publico TINYINT(1) NOT NULL DEFAULT 0 AFTER Estado',
    'SELECT ''Publico ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS comentarios_apoyos (
    idComentario INT NOT NULL,
    IdUsuario    INT NOT NULL,
    Tipo         ENUM('Tambien','Reabrir') NOT NULL,
    Nota         VARCHAR(300) NULL,
    Fecha        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (idComentario, IdUsuario, Tipo),
    KEY ix_apoyo_usuario (IdUsuario),
    CONSTRAINT fk_apoyo_comentario FOREIGN KEY (idComentario) REFERENCES comentarios_app (idComentario) ON DELETE CASCADE,
    CONSTRAINT fk_apoyo_usuario FOREIGN KEY (IdUsuario) REFERENCES usuarios (IdUsuario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT (SELECT COUNT(*) FROM comentarios_app) AS reportes, (SELECT COUNT(*) FROM comentarios_apoyos) AS apoyos;
