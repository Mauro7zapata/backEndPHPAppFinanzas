-- ============================================================================
-- Migración 024: clasificar los lugares de guardado por BANCO y por TIPO
-- ANTES DE EJECUTAR: haz una copia de seguridad. Es idempotente. Requiere la 012.
--
-- · lugares_guardado.Banco: dónde está la plata (Nequi, Bancolombia, Físico…). NULL = sin clasificar. «Físico» = efectivo en mano.
-- · lugares_guardado.Tipo: Bolsillo | Meta | Otro (por defecto Bolsillo).
-- Con eso el formulario del gasto elige primero el banco, luego el tipo y al final el lugar (menos opciones cuando hay muchos lugares).
--
-- Clasificación inicial (solo donde aún no hay banco; luego se puede cambiar desde la app):
--   Tipo  = Meta si el nombre empieza por «Meta»; si no, Bolsillo.
--   Banco = Nequi si contiene «nequi» o termina en « NQ»; Bancolombia si contiene «bancolombia» o termina en « BC»;
--           Físico si contiene «físico»/«fisico».
-- ============================================================================
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'lugares_guardado' AND column_name = 'Banco');
SET @sql := IF(@c = 0, 'ALTER TABLE lugares_guardado ADD COLUMN Banco VARCHAR(40) NULL', 'SELECT ''Banco ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'lugares_guardado' AND column_name = 'Tipo');
SET @sql := IF(@c = 0, 'ALTER TABLE lugares_guardado ADD COLUMN Tipo VARCHAR(10) NOT NULL DEFAULT ''Bolsillo''', 'SELECT ''Tipo ya existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE lugares_guardado SET Tipo = 'Meta' WHERE Nombre LIKE 'Meta%' AND Tipo = 'Bolsillo';
UPDATE lugares_guardado SET Banco = 'Nequi'       WHERE Banco IS NULL AND (Nombre LIKE '%nequi%' OR Nombre LIKE '% NQ');
UPDATE lugares_guardado SET Banco = 'Bancolombia' WHERE Banco IS NULL AND (Nombre LIKE '%bancolombia%' OR Nombre LIKE '% BC');
UPDATE lugares_guardado SET Banco = 'Físico'      WHERE Banco IS NULL AND (Nombre LIKE '%físico%' OR Nombre LIKE '%fisico%');

SELECT Banco, Tipo, COUNT(*) AS lugares FROM lugares_guardado GROUP BY Banco, Tipo ORDER BY Banco, Tipo;
