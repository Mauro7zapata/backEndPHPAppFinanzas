-- 008: alinea los gastos con sus movimientos (una sola vez, idempotente).
-- Para gastos en Pendiente / En proceso / Pagado que tienen movimientos:
--   CostoReal = total de movimientos; si total >= 95% del previsto -> Pagado + FechaPago = último movimiento;
--   si total > 0 y < 95% -> En proceso.
-- Hacer copia de seguridad antes.
UPDATE gastos g
INNER JOIN estados e ON e.idEstado = g.IdEstado AND e.TipoEstado = 'Gastos' AND e.NombreEstado IN ('Pendiente','En proceso','Pagado')
INNER JOIN (SELECT idGasto, SUM(valorMovimiento) AS total, MAX(fechaMovimiento) AS ultima FROM movimientos GROUP BY idGasto) m ON m.idGasto = g.idGastos
LEFT JOIN estados ep ON ep.IdUsuario = e.IdUsuario AND ep.TipoEstado = 'Gastos' AND ep.NombreEstado = 'Pagado'
LEFT JOIN estados ec ON ec.IdUsuario = e.IdUsuario AND ec.TipoEstado = 'Gastos' AND ec.NombreEstado = 'En proceso'
SET g.CostoReal = m.total,
    g.IdEstado  = IF(g.CostoPrevisto > 0 AND m.total >= g.CostoPrevisto * 0.95, ep.idEstado, ec.idEstado),
    g.FechaPago = IF(g.CostoPrevisto > 0 AND m.total >= g.CostoPrevisto * 0.95, m.ultima, g.FechaPago)
WHERE m.total > 0 AND ep.idEstado IS NOT NULL AND ec.idEstado IS NOT NULL;
