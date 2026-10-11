-- ============================================================================
-- Migración 020: detalle de los ingresos extra del mes (concepto, valor y fecha)
-- ANTES DE EJECUTAR: haz una copia de seguridad (phpMyAdmin > Exportar).
-- Es idempotente. Ejecutar con la base de datos seleccionada.
--
-- presupuestos.ExtrasMes se conserva y pasa a ser la SUMA de estas filas (la mantiene el servidor),
-- así el resto de la app y los reportes siguen funcionando sin cambios.
-- Los extras que ya existían quedan como una fila «Ingreso extra (sin detalle)» para que no se pierdan.
-- ============================================================================
CREATE TABLE IF NOT EXISTS presupuesto_ingresos_extra (
    idIngresoExtra INT AUTO_INCREMENT PRIMARY KEY,
    idPresupuesto  INT NOT NULL,
    IdUsuario      INT NOT NULL,
    Concepto       VARCHAR(120) NOT NULL,          -- de dónde salió (ej. «Prima», «Venta de la moto»)
    Valor          BIGINT NOT NULL,                -- pesos enteros
    Fecha          DATE NULL,
    FechaCreacion  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_extra_presupuesto (idPresupuesto),
    KEY ix_extra_usuario (IdUsuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO presupuesto_ingresos_extra (idPresupuesto, IdUsuario, Concepto, Valor, Fecha)
SELECT p.idPresupuesto, p.IdUsuario, 'Ingreso extra (sin detalle)', p.ExtrasMes, NULL
FROM presupuestos p
WHERE COALESCE(p.ExtrasMes, 0) > 0
  AND NOT EXISTS (SELECT 1 FROM presupuesto_ingresos_extra x WHERE x.idPresupuesto = p.idPresupuesto);

SELECT COUNT(*) AS ingresos_extra FROM presupuesto_ingresos_extra;
