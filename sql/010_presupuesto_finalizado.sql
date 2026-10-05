-- ============================================================================
-- Migración 010: estado «Finalizado» del presupuesto y cierre de mes
-- ANTES DE EJECUTAR: haz una copia de seguridad (phpMyAdmin > Exportar).
-- Es idempotente: se puede ejecutar más de una vez. Ejecutar con la base de datos seleccionada.
--
--  * presupuestos.IdEstado          -> estado del presupuesto (tipo «Presupuestos»: En curso / Finalizado)
--  * presupuestos.FechaFinalizado   -> cuándo se finalizó
--  * presupuestos.CierreManual      -> 1 si el usuario lo finalizó a mano con gastos aún abiertos (no se reabre solo)
--  * Estados «En curso» y «Finalizado» para cada usuario (se editan en Configuraciones > Estados; solo cambia el color).
--  * Los presupuestos anteriores al MES PASADO se marcan Finalizados (historia): así no te piden cerrar meses viejos.
--    El mes pasado y el actual quedan «En curso»: ciérralos tú o se finalizan solos al pagar/acumular todo.
-- ============================================================================

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'presupuestos' AND column_name = 'IdEstado');
SET @sql := IF(@existe = 0, 'ALTER TABLE presupuestos ADD COLUMN IdEstado INT NULL', 'SELECT ''presupuestos.IdEstado ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'presupuestos' AND column_name = 'FechaFinalizado');
SET @sql := IF(@existe = 0, 'ALTER TABLE presupuestos ADD COLUMN FechaFinalizado DATE NULL', 'SELECT ''presupuestos.FechaFinalizado ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'presupuestos' AND column_name = 'CierreManual');
SET @sql := IF(@existe = 0, 'ALTER TABLE presupuestos ADD COLUMN CierreManual TINYINT(1) NOT NULL DEFAULT 0', 'SELECT ''presupuestos.CierreManual ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Estados del presupuesto para cada usuario (si faltan).
INSERT INTO estados (TipoEstado, NombreEstado, ColorEstado, IdUsuario)
SELECT 'Presupuestos', 'En curso', -14575885, u.IdUsuario FROM usuarios u
WHERE NOT EXISTS (SELECT 1 FROM estados e WHERE e.IdUsuario = u.IdUsuario AND e.TipoEstado = 'Presupuestos' AND e.NombreEstado = 'En curso');

INSERT INTO estados (TipoEstado, NombreEstado, ColorEstado, IdUsuario)
SELECT 'Presupuestos', 'Finalizado', -11751600, u.IdUsuario FROM usuarios u
WHERE NOT EXISTS (SELECT 1 FROM estados e WHERE e.IdUsuario = u.IdUsuario AND e.TipoEstado = 'Presupuestos' AND e.NombreEstado = 'Finalizado');

-- Historia: anteriores al mes pasado -> Finalizado (cierre manual, para que no se reabran solos).
UPDATE presupuestos p
JOIN estados e ON e.IdUsuario = p.IdUsuario AND e.TipoEstado = 'Presupuestos' AND e.NombreEstado = 'Finalizado'
SET p.IdEstado = e.idEstado, p.CierreManual = 1, p.FechaFinalizado = CURDATE()
WHERE p.IdEstado IS NULL AND p.Anho * 12 + p.Mes < YEAR(CURDATE()) * 12 + MONTH(CURDATE()) - 1;

-- El resto, «En curso».
UPDATE presupuestos p
JOIN estados e ON e.IdUsuario = p.IdUsuario AND e.TipoEstado = 'Presupuestos' AND e.NombreEstado = 'En curso'
SET p.IdEstado = e.idEstado
WHERE p.IdEstado IS NULL;

-- Verificación: sin_estado debe ser 0.
SELECT (SELECT COUNT(*) FROM presupuestos WHERE IdEstado IS NULL) AS sin_estado,
       (SELECT COUNT(*) FROM presupuestos p JOIN estados e ON e.idEstado = p.IdEstado WHERE e.NombreEstado = 'Finalizado') AS finalizados,
       (SELECT COUNT(*) FROM presupuestos p JOIN estados e ON e.idEstado = p.IdEstado WHERE e.NombreEstado = 'En curso') AS en_curso;
