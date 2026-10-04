<?php
require_once("../db.php");
require_once("../lib.php");
require_once("../libInversiones.php");

header('Content-Type: application/json; charset=utf-8');

// Dashboard de inicio: cómo está el usuario en todo, en una sola llamada.
//   GET Dashboard.php[?mes=10&anho=2026]   (por defecto, el mes actual en hora de Colombia)
//
// Devuelve: presupuesto del mes, categorías, tendencia de 6 meses, deudas, obligaciones, inversiones,
// agenda (próximos pagos y cobros), hábito de registro (racha, puntaje) y mensajes de ánimo.
// Todo se calcula con los datos de la app; no se inventa nada.

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

$hoyTxt = date('Y-m-d');
$hoy = new DateTime($hoyTxt);
$mes = appfinanzas_entero($_GET['mes'] ?? null) ?? (int)$hoy->format('n');
$anho = appfinanzas_entero($_GET['anho'] ?? null) ?? (int)$hoy->format('Y');
if ($mes < 1 || $mes > 12 || $anho < 2000 || $anho > 2100) {
    echo json_encode(['error' => 'Mes o año no válido']);
    exit;
}
$esMesActual = ($mes === (int)$hoy->format('n') && $anho === (int)$hoy->format('Y'));
$diasMes = (int)date('t', mktime(0, 0, 0, $mes, 1, $anho));
$diaActual = $esMesActual ? (int)$hoy->format('j') : $diasMes;

function dias_hasta($fecha) {
    global $hoy;
    return (int)$hoy->diff(new DateTime($fecha))->format('%r%a');
}

// ------------------------------------------------------------------ presupuesto del mes
$stmt = $mysql->prepare("SELECT idPresupuesto, ValorPresupuesto, ExtrasMes FROM presupuestos WHERE Mes = ? AND Anho = ? LIMIT 1");
$stmt->bind_param('ii', $mes, $anho);
$stmt->execute();
$p = $stmt->get_result()->fetch_assoc();

$presupuesto = ['existe' => false, 'id' => null, 'valor' => 0.0, 'extras' => 0.0, 'total' => 0.0,
    'previsto' => 0.0, 'pagado' => 0.0, 'ahorrado' => 0.0, 'gastadoConsumo' => 0.0, 'porPagar' => 0.0,
    'restante' => 0.0, 'libre' => 0.0, 'porcentajeUsado' => 0.0, 'porcentajeMes' => round($diaActual / $diasMes, 4),
    'gastosTotal' => 0, 'pendientes' => 0, 'enProceso' => 0, 'pagados' => 0, 'vencidos' => 0, 'valorVencido' => 0.0,
    'diasRestantes' => $esMesActual ? $diasMes - $diaActual : 0, 'gastoDiarioDisponible' => null, 'proyeccionCierre' => null];
$categorias = [];
$idPresupuesto = null;

if ($p) {
    $idPresupuesto = (int)$p['idPresupuesto'];
    $presupuesto['existe'] = true;
    $presupuesto['id'] = $idPresupuesto;
    $presupuesto['valor'] = (float)$p['ValorPresupuesto'];
    $presupuesto['extras'] = (float)$p['ExtrasMes'];
    $presupuesto['total'] = $presupuesto['valor'] + $presupuesto['extras'];

    $stmt = $mysql->prepare("SELECT e.NombreEstado AS estado, COUNT(*) AS n,
            COALESCE(SUM(g.CostoPrevisto),0) AS previsto, COALESCE(SUM(g.valorGastosMovimiento),0) AS pagado
        FROM gastos g INNER JOIN estados e ON e.idEstado = g.IdEstado
        WHERE g.idPresupuesto = ? GROUP BY e.NombreEstado");
    $stmt->bind_param('i', $idPresupuesto);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
        $presupuesto['gastosTotal'] += (int)$f['n'];
        $presupuesto['previsto'] += (float)$f['previsto'];
        $presupuesto['pagado'] += (float)$f['pagado'];
        if (in_array($f['estado'], ['Guardado', 'Acumulado'], true)) $presupuesto['ahorrado'] += (float)$f['pagado'];
        if ($f['estado'] === 'Pendiente') $presupuesto['pendientes'] += (int)$f['n'];
        if ($f['estado'] === 'En proceso') $presupuesto['enProceso'] += (int)$f['n'];
        if ($f['estado'] === 'Pagado') $presupuesto['pagados'] += (int)$f['n'];
    }
    $presupuesto['gastadoConsumo'] = $presupuesto['pagado'] - $presupuesto['ahorrado'];
    $presupuesto['porPagar'] = max(0.0, $presupuesto['previsto'] - $presupuesto['pagado']);
    $presupuesto['restante'] = $presupuesto['total'] - $presupuesto['pagado'];
    $presupuesto['libre'] = $presupuesto['total'] - $presupuesto['previsto'];
    $presupuesto['porcentajeUsado'] = $presupuesto['total'] > 0 ? round($presupuesto['pagado'] / $presupuesto['total'], 4) : 0.0;
    if ($esMesActual) {
        if ($presupuesto['diasRestantes'] >= 0 && $presupuesto['restante'] > 0) {
            $presupuesto['gastoDiarioDisponible'] = round($presupuesto['restante'] / ($presupuesto['diasRestantes'] + 1));
        }
        if ($diaActual >= 5 && $presupuesto['pagado'] > 0) {
            $presupuesto['proyeccionCierre'] = round($presupuesto['pagado'] / $diaActual * $diasMes);
        }
    }

    $stmt = $mysql->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(g.CostoPrevisto - g.valorGastosMovimiento),0) AS falta
        FROM gastos g INNER JOIN estados e ON e.idEstado = g.IdEstado
        WHERE g.idPresupuesto = ? AND e.NombreEstado IN ('Pendiente','En proceso') AND g.FechaLimite IS NOT NULL AND g.FechaLimite < ?");
    $stmt->bind_param('is', $idPresupuesto, $hoyTxt);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc();
    $presupuesto['vencidos'] = (int)$v['n'];
    $presupuesto['valorVencido'] = max(0.0, (float)$v['falta']);

    $stmt = $mysql->prepare("SELECT c.NombreCategoria AS nombre, SUM(g.valorGastosMovimiento) AS valor
        FROM gastos g INNER JOIN categoriagastos c ON c.idCategoriaGastos = g.IdCategoria
        WHERE g.idPresupuesto = ? GROUP BY c.idCategoriaGastos, c.NombreCategoria HAVING valor > 0 ORDER BY valor DESC LIMIT 5");
    $stmt->bind_param('i', $idPresupuesto);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $c) {
        $categorias[] = ['nombre' => $c['nombre'], 'valor' => (float)$c['valor'],
            'porcentaje' => $presupuesto['pagado'] > 0 ? round((float)$c['valor'] / $presupuesto['pagado'], 4) : 0.0];
    }
}

// ------------------------------------------------------------------ tendencia: últimos 6 meses (hasta el mes consultado)
$tendencia = [];
for ($k = 5; $k >= 0; $k--) {
    $t = mktime(0, 0, 0, $mes - $k, 1, $anho);
    $tendencia[] = ['mes' => date('Y-m', $t), 'anho' => (int)date('Y', $t), 'm' => (int)date('n', $t), 'gastado' => 0.0, 'presupuesto' => 0.0];
}
$desdeIdx = $tendencia[0]['anho'] * 12 + $tendencia[0]['m'];
$hastaIdx = $anho * 12 + $mes;
$stmt = $mysql->prepare("SELECT p.Anho, p.Mes, p.ValorPresupuesto + COALESCE(p.ExtrasMes,0) AS total,
        COALESCE((SELECT SUM(g.valorGastosMovimiento) FROM gastos g WHERE g.idPresupuesto = p.idPresupuesto),0) AS gastado
    FROM presupuestos p WHERE p.Anho * 12 + p.Mes BETWEEN ? AND ?");
$stmt->bind_param('ii', $desdeIdx, $hastaIdx);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    foreach ($tendencia as &$t) {
        if ($t['anho'] == $r['Anho'] && $t['m'] == $r['Mes']) { $t['gastado'] = (float)$r['gastado']; $t['presupuesto'] = (float)$r['total']; }
    }
    unset($t);
}
$tendencia = array_map(function ($t) { return ['mes' => $t['mes'], 'gastado' => $t['gastado'], 'presupuesto' => $t['presupuesto']]; }, $tendencia);

// ------------------------------------------------------------------ deudas
$deudas = ['total' => 0.0, 'tarjetas' => 0.0, 'prestamos' => 0.0, 'informales' => 0.0, 'cantidad' => 0, 'proximoPago' => null, 'cupoUsado' => null];
$agenda = [];
$res = $mysql->query("SELECT d.*, " . SQL_SALDO_DEUDA . " AS saldo FROM deudas d WHERE d.Activa = 1");
$cupoTotal = 0.0; $cupoUsado = 0.0;
foreach ($res->fetch_all(MYSQLI_ASSOC) as $d) {
    $saldo = max(0.0, (float)$d['saldo']);
    $deudas['cantidad']++;
    $deudas['total'] += $saldo;
    if ($d['Tipo'] === 'Tarjeta') {
        $deudas['tarjetas'] += $saldo;
        if ($d['CupoTotal'] > 0) { $cupoTotal += (float)$d['CupoTotal']; $cupoUsado += $saldo; }
    } elseif ($d['Tipo'] === 'Prestamo') $deudas['prestamos'] += $saldo;
    else $deudas['informales'] += $saldo;

    if ($saldo > 0 && $d['DiaPago']) {
        $f = proximaFechaDia($d['DiaPago']);
        if ($f && dias_hasta($f) <= 30) {
            $valor = $d['CuotaMensual'] !== null ? (float)$d['CuotaMensual'] : null;
            $agenda[] = ['tipo' => 'deuda', 'id' => (int)$d['idDeuda'], 'titulo' => 'Pago de ' . $d['Nombre'], 'fecha' => $f,
                'dias' => dias_hasta($f), 'valor' => $valor, 'entra' => false];
            if ($deudas['proximoPago'] === null || $f < $deudas['proximoPago']['fecha']) {
                $deudas['proximoPago'] = ['nombre' => $d['Nombre'], 'fecha' => $f, 'dias' => dias_hasta($f)];
            }
        }
    }
}
if ($cupoTotal > 0) $deudas['cupoUsado'] = round($cupoUsado / $cupoTotal, 4);

// ------------------------------------------------------------------ obligaciones anuales
$obligaciones = ['cantidad' => 0, 'proxima' => null, 'provisionMes' => 0.0, 'vencidas' => 0];
$res = $mysql->query("SELECT idObligacion, Nombre, ValorEstimado, FechaVencimiento FROM obligaciones WHERE Activa = 1 ORDER BY FechaVencimiento");
foreach ($res->fetch_all(MYSQLI_ASSOC) as $o) {
    $obligaciones['cantidad']++;
    $d = dias_hasta($o['FechaVencimiento']);
    if ($obligaciones['proxima'] === null) {
        $obligaciones['proxima'] = ['nombre' => $o['Nombre'], 'fecha' => $o['FechaVencimiento'], 'dias' => $d, 'valor' => (float)$o['ValorEstimado']];
    }
    if ($d < 0) $obligaciones['vencidas']++;
    if ($d <= 45) {
        $agenda[] = ['tipo' => 'obligacion', 'id' => (int)$o['idObligacion'], 'titulo' => $o['Nombre'], 'fecha' => $o['FechaVencimiento'],
            'dias' => $d, 'valor' => (float)$o['ValorEstimado'], 'entra' => false];
    }
}
if ($idPresupuesto) {
    $stmt = $mysql->prepare("SELECT COALESCE(SUM(CostoPrevisto),0) AS t FROM gastos WHERE idPresupuesto = ? AND idObligacion IS NOT NULL");
    $stmt->bind_param('i', $idPresupuesto);
    $stmt->execute();
    $obligaciones['provisionMes'] = (float)$stmt->get_result()->fetch_assoc()['t'];
}

// ------------------------------------------------------------------ inversiones
$inv = invCalcularResumen($hoyTxt)['kpi'];
$inversiones = ['activas' => $inv['activas'], 'capitalActivo' => $inv['capitalActivo'], 'interesMes' => $inv['interesMes'],
    'porCobrar30' => $inv['porCobrar30'], 'vencido' => $inv['vencido'], 'cuotasVencidas' => $inv['cuotasVencidas'],
    'rendimientoMensual' => $inv['rendimientoMensual'], 'necesitanCuota' => $inv['necesitanCuota']];

$stmt = $mysql->prepare("SELECT p.idPlan, p.NroCuota, p.FechaPrevistaPago, p.InteresPagado, p.CapitalPagado, p.DividendoPagado, i.Nombre
    FROM PlanPagos p
    INNER JOIN Inversiones i ON i.idInversion = p.idInversion
    INNER JOIN estados ei ON ei.idEstado = i.idEstado AND ei.NombreEstado = 'Desembolsado'
    LEFT JOIN estados ep ON ep.idEstado = p.IdEstado
    WHERE (ep.NombreEstado IS NULL OR ep.NombreEstado <> 'Cobrado') AND p.FechaPrevistaPago IS NOT NULL
      AND p.FechaPrevistaPago <= ? AND p.FechaPrevistaPago >= ?
    ORDER BY p.FechaPrevistaPago LIMIT 12");
$hasta30 = date('Y-m-d', strtotime('+30 days'));
$desde60 = date('Y-m-d', strtotime('-60 days'));
$stmt->bind_param('ss', $hasta30, $desde60);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $c) {
    $agenda[] = ['tipo' => 'cobro', 'id' => (int)$c['idPlan'], 'titulo' => 'Cobrar a ' . trim($c['Nombre']) . ' · cuota ' . $c['NroCuota'],
        'fecha' => $c['FechaPrevistaPago'], 'dias' => dias_hasta($c['FechaPrevistaPago']),
        'valor' => (float)$c['InteresPagado'] + (float)$c['CapitalPagado'] + (float)$c['DividendoPagado'], 'entra' => true];
}

// Pagos del presupuesto pendientes (vencidos hace menos de 60 días o dentro de 30 días).
$stmt = $mysql->prepare("SELECT g.idGastos, g.NombreGasto, g.CostoPrevisto, g.valorGastosMovimiento, g.FechaLimite
    FROM gastos g INNER JOIN estados e ON e.idEstado = g.IdEstado
    WHERE e.NombreEstado IN ('Pendiente','En proceso') AND g.FechaLimite IS NOT NULL AND g.FechaLimite <= ? AND g.FechaLimite >= ?
    ORDER BY g.FechaLimite LIMIT 12");
$stmt->bind_param('ss', $hasta30, $desde60);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $g) {
    $agenda[] = ['tipo' => 'gasto', 'id' => (int)$g['idGastos'], 'titulo' => $g['NombreGasto'], 'fecha' => $g['FechaLimite'],
        'dias' => dias_hasta($g['FechaLimite']), 'valor' => max(0.0, (float)$g['CostoPrevisto'] - (float)$g['valorGastosMovimiento']), 'entra' => false];
}
usort($agenda, function ($a, $b) { return strcmp($a['fecha'], $b['fecha']) ?: strcmp($a['titulo'], $b['titulo']); });
$agendaTotal = count($agenda);
$agenda = array_slice($agenda, 0, 10);

// ------------------------------------------------------------------ hábito de registro
$res = $mysql->query("SELECT DISTINCT fechaMovimiento AS f FROM movimientos WHERE fechaMovimiento >= DATE_SUB('$hoyTxt', INTERVAL 90 DAY) AND fechaMovimiento <= '$hoyTxt' ORDER BY f DESC");
$dias = array_column($res->fetch_all(MYSQLI_ASSOC), 'f');
$tieneHoy = in_array($hoyTxt, $dias, true);
$racha = 0;
$cursor = new DateTime($hoyTxt);
if (!$tieneHoy) $cursor->modify('-1 day');   // la racha sigue viva si el último registro fue ayer
while (in_array($cursor->format('Y-m-d'), $dias, true)) { $racha++; $cursor->modify('-1 day'); }
$ultimo = $mysql->query("SELECT MAX(fechaMovimiento) AS f FROM movimientos WHERE fechaMovimiento <= '$hoyTxt'")->fetch_assoc()['f'];
$diasSinRegistrar = $ultimo ? max(0, -dias_hasta($ultimo)) : null;
$movHoy = (int)$mysql->query("SELECT COUNT(*) AS n FROM movimientos WHERE fechaMovimiento = '$hoyTxt'")->fetch_assoc()['n'];
$stmt = $mysql->prepare("SELECT COUNT(*) AS n FROM movimientos m INNER JOIN gastos g ON g.idGastos = m.idGasto WHERE g.idPresupuesto = ?");
$movMes = 0;
if ($idPresupuesto) {
    $stmt->bind_param('i', $idPresupuesto);
    $stmt->execute();
    $movMes = (int)$stmt->get_result()->fetch_assoc()['n'];
}

$items = [
    ['ok' => $presupuesto['existe'], 'texto' => 'Presupuesto del mes creado'],
    ['ok' => $diasSinRegistrar !== null && $diasSinRegistrar <= 2, 'texto' => 'Gastos registrados en los últimos 3 días'],
    ['ok' => $presupuesto['vencidos'] === 0, 'texto' => 'Sin pagos del presupuesto vencidos'],
    ['ok' => $inversiones['cuotasVencidas'] === 0, 'texto' => 'Cobros de inversiones al día'],
    ['ok' => $deudas['cantidad'] > 0, 'texto' => 'Deudas y tarjetas registradas'],
];
$registro = ['racha' => $racha, 'movimientosHoy' => $movHoy, 'movimientosMes' => $movMes, 'ultimoRegistro' => $ultimo,
    'diasSinRegistrar' => $diasSinRegistrar, 'puntaje' => count(array_filter($items, function ($i) { return $i['ok']; })),
    'total' => count($items), 'items' => $items];

// ------------------------------------------------------------------ mensajes de ánimo (solo con datos reales)
function pesos($v) { return '$' . number_format($v, 0, ',', '.'); }
$nombresMes = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$mensajes = [];
if (!$presupuesto['existe']) {
    $mensajes[] = ['emoji' => '🗓️', 'titulo' => 'Empieza ' . $nombresMes[$mes] . ' con un plan', 'detalle' => 'Crea el presupuesto del mes y aplica tu plantilla de gastos frecuentes: toma menos de un minuto.', 'tono' => 'info', 'accion' => 'crear_presupuesto', 'boton' => 'Crear presupuesto'];
}
if ($presupuesto['vencidos'] > 0) {
    $mensajes[] = ['emoji' => '⏰', 'titulo' => $presupuesto['vencidos'] . ($presupuesto['vencidos'] == 1 ? ' pago vencido' : ' pagos vencidos') . ' por ' . pesos($presupuesto['valorVencido']),
        'detalle' => 'Págalos o muévelos de fecha para que tu presupuesto siga limpio.', 'tono' => 'alerta', 'accion' => 'ver_agenda', 'boton' => 'Ver pagos'];
}
if ($inversiones['cuotasVencidas'] > 0) {
    $mensajes[] = ['emoji' => '💸', 'titulo' => $inversiones['cuotasVencidas'] . ($inversiones['cuotasVencidas'] == 1 ? ' cobro atrasado' : ' cobros atrasados') . ' por ' . pesos($inversiones['vencido']),
        'detalle' => 'Es plata tuya trabajando: cóbrala o ajusta la cuota.', 'tono' => 'alerta', 'accion' => 'ver_inversiones', 'boton' => 'Ver cobros'];
}
if ($racha >= 2) {
    $mensajes[] = ['emoji' => '🔥', 'titulo' => $racha . ' días seguidos registrando', 'detalle' => $tieneHoy ? '¡Hoy ya cumpliste! Mañana vas por el día ' . ($racha + 1) . '.' : 'Registra algo hoy para no perder tu racha.', 'tono' => 'ok', 'accion' => 'registrar', 'boton' => $tieneHoy ? 'Registrar otro' : 'Registrar ahora'];
} elseif (!$tieneHoy && $presupuesto['existe']) {
    $texto = $diasSinRegistrar === null ? 'Aún no hay movimientos. El primero es el más importante.' :
        ($diasSinRegistrar <= 1 ? 'Ayer fue tu último registro. ¿Qué has gastado hoy?' : 'Llevas ' . $diasSinRegistrar . ' días sin registrar. Ponte al día con un par de toques.');
    $mensajes[] = ['emoji' => '✍️', 'titulo' => 'Hoy aún no has registrado nada', 'detalle' => $texto, 'tono' => 'info', 'accion' => 'registrar', 'boton' => 'Registrar gasto'];
}
if ($presupuesto['existe'] && $presupuesto['total'] > 0) {
    $uso = $presupuesto['porcentajeUsado'];
    if ($presupuesto['pagado'] > $presupuesto['total']) {
        $mensajes[] = ['emoji' => '🚨', 'titulo' => 'Superaste el presupuesto por ' . pesos($presupuesto['pagado'] - $presupuesto['total']), 'detalle' => 'Revisa en qué categorías se fue más y ajusta lo que queda del mes.', 'tono' => 'alerta', 'accion' => 'ver_presupuesto', 'boton' => 'Ver presupuesto'];
    } elseif ($esMesActual && $diaActual >= 5 && $uso > $presupuesto['porcentajeMes'] + 0.15) {
        $mensajes[] = ['emoji' => '📈', 'titulo' => 'Vas rápido: ' . round($uso * 100) . '% del presupuesto en el ' . round($presupuesto['porcentajeMes'] * 100) . '% del mes', 'detalle' => 'Si sigues a este ritmo cerrarías cerca de ' . pesos($presupuesto['proyeccionCierre'] ?? 0) . '.', 'tono' => 'alerta', 'accion' => 'ver_presupuesto', 'boton' => 'Ver detalle'];
    } elseif ($esMesActual && $diaActual >= 5 && $presupuesto['pagado'] > 0 && $uso <= $presupuesto['porcentajeMes']) {
        $mensajes[] = ['emoji' => '👏', 'titulo' => 'Vas por buen camino', 'detalle' => 'Llevas el ' . round($uso * 100) . '% del presupuesto y el mes va en el ' . round($presupuesto['porcentajeMes'] * 100) . '%.', 'tono' => 'ok', 'accion' => 'ver_presupuesto', 'boton' => 'Ver presupuesto'];
    }
    if ($presupuesto['libre'] < 0) {
        $mensajes[] = ['emoji' => '⚖️', 'titulo' => 'Tus gastos previstos superan el presupuesto en ' . pesos(-$presupuesto['libre']), 'detalle' => 'Ajusta el presupuesto o recorta gastos previstos.', 'tono' => 'info', 'accion' => 'ver_presupuesto', 'boton' => 'Ajustar'];
    }
}
if ($presupuesto['ahorrado'] > 0) {
    $mensajes[] = ['emoji' => '🏦', 'titulo' => 'Has apartado ' . pesos($presupuesto['ahorrado']) . ' este mes', 'detalle' => 'Ahorro y provisiones que ya tienes cubiertos. ¡Sigue así!', 'tono' => 'ok', 'accion' => 'ver_presupuesto', 'boton' => 'Ver'];
}
if ($deudas['cantidad'] === 0) {
    $mensajes[] = ['emoji' => '💳', 'titulo' => 'Registra tus tarjetas y deudas', 'detalle' => 'Así sabrás cuánto debes en total y cuándo toca pagar.', 'tono' => 'info', 'accion' => 'ver_deudas', 'boton' => 'Registrar'];
}
if (!$mensajes) {
    $mensajes[] = ['emoji' => '✅', 'titulo' => 'Todo al día', 'detalle' => 'Tu registro está completo. Revisa tu progreso o adelanta algo del mes.', 'tono' => 'ok', 'accion' => 'ver_presupuesto', 'boton' => 'Ver presupuesto'];
}
$mensajes = array_slice($mensajes, 0, 4);

echo json_encode([
    'fecha' => $hoyTxt, 'mes' => $mes, 'anho' => $anho, 'esMesActual' => $esMesActual,
    'presupuesto' => $presupuesto, 'categorias' => $categorias, 'tendencia' => $tendencia,
    'deudas' => $deudas, 'obligaciones' => $obligaciones, 'inversiones' => $inversiones,
    'agenda' => $agenda, 'agendaTotal' => $agendaTotal, 'registro' => $registro, 'mensajes' => $mensajes,
], JSON_UNESCAPED_UNICODE);
