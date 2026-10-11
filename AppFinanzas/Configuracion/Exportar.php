<?php
require_once("../db.php");
require_once("../lib.php");

header('Content-Type: application/json; charset=utf-8');

// Exportación de toda la información financiera del usuario en un texto plano pensado para pasárselo a una IA
// (revisar hábitos, deudas, plan de pagos, metas...). No incluye correo, nombre ni credenciales.
//
//   GET Exportar.php -> {"archivo": "AppFinanzas_AAAA-MM-DD.txt", "texto": "..."}
//
// Cada sección se arma por separado: si una falla (p. ej. una migración sin ejecutar) se marca como no disponible
// y el resto se exporta igual.

const EXPORTAR_MESES = 6;

function exp_cop($v) { return '$' . number_format((float)$v, 0, ',', '.') . ' COP'; }
function exp_usd($v) { return 'US$ ' . number_format((float)$v, 2, '.', ',') . ' USD'; }

// Imprime los campos de una fila tal cual (sin ids internos ni usuario) para tablas cuyas columnas pueden crecer.
function exp_fila(array $f) {
    $partes = [];
    foreach ($f as $k => $v) {
        if ($v === null || $v === '' || preg_match('/^(idPlan|idAporte|idMovFondo|idValoracion|IdUsuario|idEstado|IdEstado|IdTipo)$/i', $k) || strpos((string)$v, '0000-00-00') === 0) continue;
        $partes[] = "$k: $v";
    }
    return '- ' . implode(' | ', $partes);
}

function exp_seccion(array &$out, $titulo, callable $armar) {
    $out[] = '';
    $out[] = "## $titulo";
    try {
        $armar($out);
    } catch (Throwable $e) {
        error_log('[AppFinanzas] Exportar «' . $titulo . '»: ' . $e->getMessage());
        $out[] = '(sección no disponible)';
    }
}

function exp_consulta($sql, $tipos = '', ...$params) {
    global $mysql;
    $q = $mysql->prepare($sql);
    if ($tipos !== '') $q->bind_param($tipos, ...$params);
    $q->execute();
    return $q->get_result()->fetch_all(MYSQLI_ASSOC);
}

$hoy = date('Y-m-d');
$out = [];
$out[] = "# AppFinanzas — información financiera completa";
$out[] = "Generado el $hoy.";
$out[] = "";
$out[] = "Instrucciones para la IA: este archivo es el resumen de las finanzas personales de un usuario colombiano. Los valores están en pesos colombianos (COP) salvo que se indique USD; los dólares nunca se convierten ni se suman a los pesos. Usa solo estos datos, no inventes información. Analiza hábitos de gasto, nivel de endeudamiento, costo de intereses, capacidad de ahorro y riesgos, y propón un plan concreto y priorizado (qué pagar primero, cuánto ahorrar, qué recortar).";

exp_seccion($out, 'Presupuesto por mes (más recientes primero)', function (&$out) use ($uid) {
    $meses = exp_consulta("SELECT idPresupuesto, Mes, Anho, ValorPresupuesto, ExtrasMes FROM presupuestos WHERE IdUsuario = ? ORDER BY Anho DESC, Mes DESC LIMIT " . EXPORTAR_MESES, 'i', $uid);
    if (!$meses) { $out[] = 'Sin presupuestos registrados.'; return; }
    foreach ($meses as $i => $m) {
        $tot = exp_consulta("SELECT COUNT(*) n, COALESCE(SUM(CostoPrevisto),0) previsto, COALESCE(SUM(valorGastosMovimiento),0) pagado FROM gastos WHERE idPresupuesto = ?", 'i', $m['idPresupuesto'])[0];
        $out[] = '';
        $out[] = sprintf('### %04d-%02d — ingreso/presupuesto %s, extras %s, previsto en gastos %s, pagado %s (%d gastos)',
            $m['Anho'], $m['Mes'], exp_cop($m['ValorPresupuesto']), exp_cop($m['ExtrasMes']), exp_cop($tot['previsto']), exp_cop($tot['pagado']), $tot['n']);
        $cats = exp_consulta("SELECT c.NombreCategoria AS nombre, SUM(g.valorGastosMovimiento) AS pagado, SUM(g.CostoPrevisto) AS previsto
            FROM gastos g INNER JOIN categoriagastos c ON c.idCategoriaGastos = g.IdCategoria AND c.IdUsuario = ?
            WHERE g.idPresupuesto = ? GROUP BY c.idCategoriaGastos, c.NombreCategoria ORDER BY pagado DESC, previsto DESC", 'ii', $uid, $m['idPresupuesto']);
        foreach ($cats as $c) $out[] = '- ' . $c['nombre'] . ': pagado ' . exp_cop($c['pagado']) . ', previsto ' . exp_cop($c['previsto']);
        if ($i < 2) { // detalle de gastos solo de los dos meses más recientes
            $out[] = 'Detalle de gastos:';
            $gs = exp_consulta("SELECT g.NombreGasto, c.NombreCategoria, e.NombreEstado, g.CostoPrevisto, g.valorGastosMovimiento
                FROM gastos g INNER JOIN estados e ON e.idEstado = g.IdEstado AND e.IdUsuario = ?
                INNER JOIN categoriagastos c ON c.idCategoriaGastos = g.IdCategoria AND c.IdUsuario = ?
                WHERE g.idPresupuesto = ? ORDER BY c.NombreCategoria, g.NombreGasto LIMIT 200", 'iii', $uid, $uid, $m['idPresupuesto']);
            foreach ($gs as $g) $out[] = '  · ' . $g['NombreGasto'] . ' [' . $g['NombreCategoria'] . ', ' . $g['NombreEstado'] . ']: previsto ' . exp_cop($g['CostoPrevisto']) . ', pagado ' . exp_cop($g['valorGastosMovimiento']);
        }
    }
});

exp_seccion($out, 'Deudas activas', function (&$out) use ($uid) {
    $filas = exp_consulta("SELECT d.*, " . sqlSaldoDeuda('COP') . " AS saldo, " . sqlSaldoDeuda('USD') . " AS saldoUsd
        FROM deudas d WHERE d.IdUsuario = ? AND d.Activa = 1 ORDER BY saldo DESC", 'i', $uid);
    if (!$filas) { $out[] = 'Sin deudas activas.'; return; }
    $total = 0.0;
    foreach ($filas as $d) {
        $total += (float)$d['saldo'];
        $l = '- ' . $d['Nombre'] . ' (' . $d['Tipo'] . ($d['Acreedor'] ? ', ' . $d['Acreedor'] : '') . '): debe ' . exp_cop($d['saldo']);
        if ((float)$d['saldoUsd'] > 0.005) $l .= ' + ' . exp_usd($d['saldoUsd']);
        if ($d['CupoTotal'] !== null && (float)$d['CupoTotal'] > 0) {
            $l .= '; cupo ' . exp_cop($d['CupoTotal']) . ' (usado ' . round(100 * (float)$d['saldo'] / (float)$d['CupoTotal']) . '%)';
        }
        if ($d['TasaAnual'] !== null) $l .= '; tasa E.A. ' . $d['TasaAnual'] . '%';
        if ($d['CuotaMensual'] !== null) $l .= '; cuota mensual ' . exp_cop($d['CuotaMensual']);
        if ($d['DiaCorte']) $l .= '; corte día ' . $d['DiaCorte'];
        if ($d['DiaPago']) $l .= '; pago día ' . $d['DiaPago'];
        if ($d['FechaFin']) $l .= '; termina ' . $d['FechaFin'];
        if ($d['Notas']) $l .= '; notas: ' . $d['Notas'];
        $out[] = $l;
    }
    $out[] = 'Deuda total en pesos: ' . exp_cop($total);
    $out[] = '';
    $out[] = 'Movimientos de deudas (últimos 90 días):';
    $movs = exp_consulta("SELECT d.Nombre, md.Tipo, md.Valor, md.Fecha, md.Nota" . (deudaTieneMoneda() ? ", md.Moneda" : "") . "
        FROM movimientos_deuda md INNER JOIN deudas d ON d.idDeuda = md.idDeuda
        WHERE d.IdUsuario = ? AND md.Fecha >= (CURDATE() - INTERVAL 90 DAY) ORDER BY md.Fecha DESC, md.idMovDeuda DESC LIMIT 100", 'i', $uid);
    if (!$movs) $out[] = '(sin movimientos)';
    foreach ($movs as $m) {
        $val = (($m['Moneda'] ?? 'COP') === 'USD') ? exp_usd($m['Valor']) : exp_cop($m['Valor']);
        $out[] = '- ' . $m['Fecha'] . ' ' . $m['Nombre'] . ': ' . $m['Tipo'] . ' ' . $val . ($m['Nota'] ? ' (' . $m['Nota'] . ')' : '');
    }
});

exp_seccion($out, 'Obligaciones anuales (renta, SOAT, impuestos...)', function (&$out) use ($uid) {
    $filas = exp_consulta("SELECT Nombre, ValorEstimado, FechaVencimiento, Notas FROM obligaciones WHERE IdUsuario = ? AND Activa = 1 ORDER BY FechaVencimiento", 'i', $uid);
    if (!$filas) { $out[] = 'Sin obligaciones registradas.'; return; }
    foreach ($filas as $o) $out[] = '- ' . $o['Nombre'] . ': ' . exp_cop($o['ValorEstimado']) . ', vence ' . $o['FechaVencimiento'] . ($o['Notas'] ? ' (' . $o['Notas'] . ')' : '');
});

exp_seccion($out, 'Inversiones', function (&$out) use ($uid) {
    $filas = exp_consulta("SELECT i.*, t.Nombre AS Tipo, e.NombreEstado AS Estado FROM Inversiones i
        LEFT JOIN TablaTipoInversion t ON t.idTipo = i.IdTipo
        LEFT JOIN estados e ON e.idEstado = i.idEstado AND e.IdUsuario = i.IdUsuario
        WHERE i.IdUsuario = ? ORDER BY i.idEstado ASC, i.FechaInicio DESC", 'i', $uid);
    if (!$filas) { $out[] = 'Sin inversiones registradas.'; return; }
    foreach ($filas as $f) $out[] = exp_fila($f);
    $out[] = '';
    $out[] = 'Próximas cuotas/pagos de inversiones:';
    $cuotas = exp_consulta("SELECT p.* FROM PlanPagos p INNER JOIN Inversiones i ON i.idInversion = p.idInversion
        WHERE i.IdUsuario = ? AND p.FechaPrevistaPago >= CURDATE() ORDER BY p.FechaPrevistaPago LIMIT 15", 'i', $uid);
    if (!$cuotas) $out[] = '(ninguna)';
    foreach ($cuotas as $c) $out[] = exp_fila($c);
});

exp_seccion($out, 'Fondo de inversión (últimos movimientos)', function (&$out) use ($uid) {
    $filas = exp_consulta("SELECT * FROM fondo_inversion WHERE IdUsuario = ? ORDER BY Fecha DESC, idMovFondo DESC LIMIT 20", 'i', $uid);
    if (!$filas) { $out[] = 'Sin movimientos.'; return; }
    foreach ($filas as $f) $out[] = exp_fila($f);
});

$out[] = '';
$out[] = '— Fin del archivo —';
echo json_encode(['archivo' => "AppFinanzas_$hoy.txt", 'texto' => implode("\n", $out)], JSON_UNESCAPED_UNICODE);
