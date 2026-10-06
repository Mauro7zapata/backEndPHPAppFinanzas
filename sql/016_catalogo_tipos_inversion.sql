-- ============================================================================
-- Migración 016: catálogo de tipos de inversión (para cualquier usuario)
-- ANTES DE EJECUTAR: haz una copia de seguridad (phpMyAdmin > Exportar).
-- Es idempotente. No toca las inversiones existentes (los ids no cambian).
--   - Crea los 6 tipos que usa la app si faltan (una instalación nueva no los tenía en ningún script).
--   - Les pone nombre y descripción en lenguaje claro: la descripción es la ayuda que ve el usuario al crear una inversión.
-- La app y el servidor dependen de estos ids: 1, 2, 3, 4, 9 y 10. No los cambies.
-- ============================================================================

INSERT INTO TablaTipoInversion (idTipo, Nombre, Descripcion)
SELECT 1, 'x', 'x' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM TablaTipoInversion WHERE idTipo = 1);
INSERT INTO TablaTipoInversion (idTipo, Nombre, Descripcion)
SELECT 2, 'x', 'x' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM TablaTipoInversion WHERE idTipo = 2);
INSERT INTO TablaTipoInversion (idTipo, Nombre, Descripcion)
SELECT 3, 'x', 'x' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM TablaTipoInversion WHERE idTipo = 3);
INSERT INTO TablaTipoInversion (idTipo, Nombre, Descripcion)
SELECT 4, 'x', 'x' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM TablaTipoInversion WHERE idTipo = 4);
INSERT INTO TablaTipoInversion (idTipo, Nombre, Descripcion)
SELECT 9, 'x', 'x' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM TablaTipoInversion WHERE idTipo = 9);
INSERT INTO TablaTipoInversion (idTipo, Nombre, Descripcion)
SELECT 10, 'x', 'x' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM TablaTipoInversion WHERE idTipo = 10);

UPDATE TablaTipoInversion SET Nombre = 'Acciones, fondos y cripto',
  Descripcion = 'Compras acciones, ETF, un fondo o criptomonedas. Registra el capital puesto, los dividendos (puedes reinvertirlos) y el saldo de tu plataforma para ver si ganas o pierdes.'
  WHERE idTipo = 1;
UPDATE TablaTipoInversion SET Nombre = 'Préstamo con cuotas',
  Descripcion = 'Prestas dinero y te pagan cuotas que incluyen interés y abono a capital. El interés se calcula sobre el saldo que queda.'
  WHERE idTipo = 2;
UPDATE TablaTipoInversion SET Nombre = 'Préstamo a interés fijo',
  Descripcion = 'Te pagan solo el interés cada mes y el capital te lo devuelven completo en la última cuota.'
  WHERE idTipo = 3;
UPDATE TablaTipoInversion SET Nombre = 'Préstamo sin plazo',
  Descripcion = 'Te pagan solo el interés cada mes, sin fecha final, hasta que se liquide el capital.'
  WHERE idTipo = 4;
UPDATE TablaTipoInversion SET Nombre = 'Ganancia fija (CDT, pagaré)',
  Descripcion = 'Pones un capital y en una fecha acordada recibes todo junto: el capital más una ganancia definida.'
  WHERE idTipo = 9;
UPDATE TablaTipoInversion SET Nombre = 'Cobro de una deuda',
  Descripcion = 'Alguien te debe un dinero, sin interés, y te lo paga en cuotas.'
  WHERE idTipo = 10;

SELECT idTipo, Nombre FROM TablaTipoInversion ORDER BY idTipo;
