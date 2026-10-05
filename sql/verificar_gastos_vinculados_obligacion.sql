-- (reservado) La vinculación gasto <-> obligación usa gastos.idObligacion, creada en la migración 002. No requiere cambios de BD.
SELECT COUNT(*) AS gastos_vinculados_a_obligacion FROM gastos WHERE idObligacion IS NOT NULL;
