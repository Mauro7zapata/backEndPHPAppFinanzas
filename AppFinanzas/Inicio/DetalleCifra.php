<?php
require_once("../db.php");
require_once("../lib.php");

header('Content-Type: application/json; charset=utf-8');

// Traza de las cifras del presupuesto: de qué gastos (o conceptos) sale cada número del encabezado.
//   GET DetalleCifra.php?mes=M&anho=A&cifra=porPagar|pagado|previsto|guardado|acumulado|separado|queda|extras
//
// Devuelve {cifra, titulo, explicacion, items:[{nombre, estado, detalle, valor, cuenta}], total}.
//  - Usa las MISMAS fórmulas que Dashboard.php (sqlValorEfectivoGasto, sqlSeparadoGasto, mismos estados), así la suma de
//    los items cuyo `cuenta` es true es exactamente el número que se ve en pantalla.
//  - `cuenta` = false marca filas informativas que NO suman (p. ej. los gastos «No aplica» en Previsto).

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { echo json_encode(['error' => 'Método no permitido']); exit; }

$mes = appfinanzas_entero($_GET['mes'] ?? null);
$anho = appfinanzas_entero($_GET['anho'] ?? null);
$cifra = (string)($_GET['cifra'] ?? '');
$validas = ['porPagar', 'pagado', 'previsto', 'guardado', 'acumulado', 'separado', 'queda', 'extras'];
if ($mes === null || $anho === null || $mes < 1 || $mes > 12 || $anho < 2000 || $anho > 2100 || !in_array($cifra, $validas, true)) {
    echo json_encode(['error' => 'Mes, año o cifra no válidos']);
    exit;
}

function fila($nombre, $estado, $detalle, $valor, $cuenta = true) {
    return ['nombre' => $nombre, 'estado' => $estado, 'detalle' => $detalle, 'valor' => round((float)$valor, 2), 'cuenta' => $cuenta];
}

function totalFilas($items) {
    $t = 0.0;
    foreach ($items as $i) if ($i['cuenta']) $t += $i['valor'];
    return round($t, 2);
}

$q = $mysql->prepare("SELECT idPresupuesto, ValorPresupuesto, ExtrasMes FROM presupuestos WHERE IdUsuario = ? AND Mes = ? AND Anho = ? LIMIT 1");
$q->bind_param('iii', $uid, $mes, $anho);
$q->execute();
$p = $q->get_result()->fetch_assoc();
if (!$p) { echo json_encode(['error' => 'No existe el presupuesto de ese mes']); exit; }
$idPresupuesto = (int)$p['idPresupuesto'];

// Gastos del presupuesto con lo necesario para cualquier cifra.
$q = $mysql->prepare("SELECT g.NombreGasto AS nombre, e.NombreEstado AS estado, g.CostoPrevisto AS previsto, g.valorGastosMovimiento AS movs,
        " . sqlValorEfectivoGasto() . " AS efectivo, " . sqlSeparadoGasto() . " AS separado
    FROM gastos g INNER JOIN estados e ON e.idEstado = g.IdEstado AND e.IdUsuario = ?
    WHERE g.idPresupuesto = ? ORDER BY e.NombreEstado, g.NombreGasto");
$q->bind_param('ii', $uid, $idPresupuesto);
$q->execute();
$gastos = $q->get_result()->fetch_all(MYSQLI_ASSOC);

$items = [];
$titulo = '';
$explicacion = '';
$ABIERTOS = ['Pendiente', 'En proceso', 'Acumulado'];

switch ($cifra) {
    case 'previsto':
        $titulo = 'Previsto';
        $explicacion = 'Suma del valor previsto de todos los gastos del mes. Los gastos «No aplica» se muestran pero no suman.';
        foreach ($gastos as $g) {
            $na = $g['estado'] === 'No aplica';
            $items[] = fila($g['nombre'], $g['estado'], $na ? 'No aplica: no cuenta este mes' : '', $g['previsto'], !$na);
        }
        break;

    case 'pagado':
        $titulo = 'Pagado';
        $explicacion = 'Lo ya movido en el mes: movimientos registrados en cada gasto. Un gasto Guardado cuenta su valor previsto aunque no tenga movimientos (ya está apartado).';
        foreach ($gastos as $g) {
            if ($g['estado'] === 'No aplica' || (float)$g['efectivo'] <= 0) continue;
            $d = $g['estado'] === 'Guardado' ? 'Guardado: cuenta lo previsto (o los movimientos si son más)'
                : ($g['estado'] === 'Acumulado' ? 'Acumulado: aportes registrados' : 'Movimientos registrados');
            $items[] = fila($g['nombre'], $g['estado'], $d, $g['efectivo']);
        }
        break;

    case 'porPagar':
        $titulo = 'Por pagar';
        $explicacion = 'Lo que falta de cada gasto abierto (Pendiente, En proceso o Acumulado incompleto): previsto menos lo registrado. Un gasto pagado de más no compensa a otro. Pagado, Guardado y No aplica no cuentan.';
        foreach ($gastos as $g) {
            if (!in_array($g['estado'], $ABIERTOS, true)) continue;
            $falta = max(0.0, (float)$g['previsto'] - (float)$g['efectivo']);
            if ($falta <= 0) continue;
            $items[] = fila($g['nombre'], $g['estado'], 'Previsto ' . number_format((float)$g['previsto'], 0, ',', '.') . ' · registrado ' . number_format((float)$g['efectivo'], 0, ',', '.'), $falta);
        }
        break;

    case 'guardado':
    case 'acumulado':
        $est = $cifra === 'guardado' ? 'Guardado' : 'Acumulado';
        $titulo = $est . ' del mes';
        $explicacion = $est === 'Guardado'
            ? 'Lo que sigue guardado de cada gasto Guardado: previsto menos lo que ya gastaste de ese gasto.'
            : 'Lo aportado hasta ahora a cada gasto Acumulado (se completa con aportes registrados).';
        foreach ($gastos as $g) {
            if ($g['estado'] !== $est) continue;
            $items[] = fila($g['nombre'], $est, 'Previsto ' . number_format((float)$g['previsto'], 0, ',', '.') . ' · movimientos ' . number_format((float)$g['movs'], 0, ',', '.'), $g['separado']);
        }
        break;

    case 'queda':
        $titulo = 'Te queda';
        $explicacion = 'Presupuesto del mes más ingresos extra, menos lo pagado (incluye lo guardado y acumulado, que ya no está disponible).';
        $items[] = fila('Presupuesto del mes', '', '', $p['ValorPresupuesto']);
        $items[] = fila('Ingresos extra', '', 'Ver el detalle con la cifra «extras»', $p['ExtrasMes']);
        $pagado = 0.0;
        foreach ($gastos as $g) if ($g['estado'] !== 'No aplica') $pagado += (float)$g['efectivo'];
        $items[] = fila('Pagado', '', 'Ver el detalle con la cifra «pagado»', -$pagado);
        break;

    case 'extras':
        $titulo = 'Ingresos extra';
        $explicacion = 'Ingresos del mes que no son tu presupuesto base (primas, ventas…), cada uno con su concepto.';
        $r = $mysql->query("SHOW TABLES LIKE 'presupuesto_ingresos_extra'");
        if ($r && $r->num_rows > 0) {
            $e = $mysql->prepare("SELECT Concepto, Valor, Fecha FROM presupuesto_ingresos_extra WHERE idPresupuesto = ? AND IdUsuario = ? ORDER BY COALESCE(Fecha, DATE(FechaCreacion)) DESC, idIngresoExtra DESC");
            $e->bind_param('ii', $idPresupuesto, $uid);
            $e->execute();
            foreach ($e->get_result()->fetch_all(MYSQLI_ASSOC) as $f) $items[] = fila($f['Concepto'], '', (string)($f['Fecha'] ?? ''), $f['Valor']);
        }
        // Si el valor extra viene del campo antiguo (sin conceptos), se muestra aparte para que la suma cuadre con el encabezado.
        $resto = (float)$p['ExtrasMes'] - totalFilas($items);
        if (abs($resto) > 0.5) $items[] = fila('Extras sin concepto', '', 'Registrados sin detallar', $resto);
        break;

    case 'separado':
        $titulo = 'Saldo separado';
        $explicacion = 'Todo lo guardado y acumulado de todos los meses, menos lo que ya usaste, más ajustes: el dinero que ya es tuyo y está fuera de tu disponible, por lugar.';
        $nombres = [0 => 'Sin lugar'];
        if (gastosTieneLugar()) {
            $l = $mysql->prepare("SELECT idLugar, Nombre FROM lugares_guardado WHERE IdUsuario = ?");
            $l->bind_param('i', $uid);
            $l->execute();
            foreach ($l->get_result()->fetch_all(MYSQLI_ASSOC) as $f) $nombres[(int)$f['idLugar']] = $f['Nombre'];
        }
        foreach (saldoSeparadoPorLugar() as $id => $v) {
            $items[] = fila($nombres[(int)$id] ?? ('Lugar ' . $id), '',
                'Guardado ' . number_format($v['guardado'], 0, ',', '.') . ' + acumulado ' . number_format($v['acumulado'], 0, ',', '.')
                . ' − usado ' . number_format($v['usado'], 0, ',', '.') . ' ± ajustes ' . number_format($v['ajuste'], 0, ',', '.'), $v['saldo']);
        }
        break;
}

echo json_encode(['cifra' => $cifra, 'titulo' => $titulo, 'explicacion' => $explicacion, 'mes' => $mes, 'anho' => $anho,
    'items' => $items, 'total' => totalFilas($items)], JSON_UNESCAPED_UNICODE);
