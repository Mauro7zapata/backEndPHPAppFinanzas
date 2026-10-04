<?php
// Lógica compartida entre los endpoints (Movimientos, Gastos, Plantilla, Deudas, Obligaciones, Alertas).
// Este archivo solo define funciones: no ejecuta nada por sí mismo. Requiere que db.php ya esté cargado.

// Porcentaje del costo previsto a partir del cual un gasto se considera pagado.
const UMBRAL_PAGADO = 0.95;

// Ajusta el estado del gasto según lo ya abonado mediante movimientos.
//  - suma >= 95% del costo previsto  -> Pagado (y se registra la fecha de pago)
//  - suma > 0 y < 95%                -> En proceso
//  - suma = 0 (se borraron los movimientos) -> vuelve a Pendiente
// Solo toca gastos que están en Pendiente / En proceso / Pagado: los estados
// Guardado, Acumulado y No aplica los decide el usuario y no se modifican.
function sincronizarGasto($idGasto) {
    global $mysql;
    $idGasto = (int)$idGasto;
    if ($idGasto <= 0) return;

    $stmt = $mysql->prepare("SELECT g.CostoPrevisto, g.IdEstado, e.NombreEstado,
            (SELECT COALESCE(SUM(m.valorMovimiento),0) FROM movimientos m WHERE m.idGasto = g.idGastos) AS total,
            (SELECT MAX(m.fechaMovimiento) FROM movimientos m WHERE m.idGasto = g.idGastos) AS ultima
        FROM gastos g INNER JOIN estados e ON e.idEstado = g.IdEstado WHERE g.idGastos = ?");
    $stmt->bind_param('i', $idGasto);
    $stmt->execute();
    $g = $stmt->get_result()->fetch_assoc();
    if (!$g || !in_array($g['NombreEstado'], ['Pendiente', 'En proceso', 'Pagado'], true)) return;

    $total = (float)$g['total'];
    $previsto = (float)$g['CostoPrevisto'];
    if ($total <= 0) {
        $nuevo = 'Pendiente';
    } elseif ($previsto > 0 && $total >= $previsto * UMBRAL_PAGADO) {
        $nuevo = 'Pagado';
    } else {
        $nuevo = 'En proceso';
    }
    if ($nuevo === $g['NombreEstado']) return;

    $est = estadoGastoPorNombre($nuevo);
    if (!$est) return;

    if ($nuevo === 'Pagado') {
        $stmt = $mysql->prepare("UPDATE gastos SET IdEstado = ?, FechaPago = ? WHERE idGastos = ?");
        $fecha = $g['ultima'];
        $stmt->bind_param('isi', $est, $fecha, $idGasto);
    } else {
        $stmt = $mysql->prepare("UPDATE gastos SET IdEstado = ? WHERE idGastos = ?");
        $stmt->bind_param('ii', $est, $idGasto);
    }
    $stmt->execute();
}

// id del estado de tipo "Gastos" con ese nombre (o null).
function estadoGastoPorNombre($nombre) {
    global $mysql;
    $stmt = $mysql->prepare("SELECT idEstado FROM estados WHERE TipoEstado = 'Gastos' AND NombreEstado = ? LIMIT 1");
    $stmt->bind_param('s', $nombre);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    return $fila ? (int)$fila['idEstado'] : null;
}

// Mantiene movimientos_deuda alineado con un movimiento del presupuesto:
// si el gasto está vinculado a una deuda, el movimiento es un abono a esa deuda.
function sincronizarAbonoMovimiento($idMovimiento) {
    global $mysql;
    $idMovimiento = (int)$idMovimiento;
    $stmt = $mysql->prepare("SELECT m.valorMovimiento, m.tipoMovimiento, m.fechaMovimiento, m.nombreGasto, g.idDeuda
        FROM movimientos m LEFT JOIN gastos g ON g.idGastos = m.idGasto WHERE m.idMovimiento = ?");
    $stmt->bind_param('i', $idMovimiento);
    $stmt->execute();
    $m = $stmt->get_result()->fetch_assoc();

    if (!$m || $m['idDeuda'] === null || $m['tipoMovimiento'] !== 'Gasto' || (float)$m['valorMovimiento'] <= 0) {
        $del = $mysql->prepare("DELETE FROM movimientos_deuda WHERE idMovimiento = ?");
        $del->bind_param('i', $idMovimiento);
        $del->execute();
        return;
    }
    $stmt = $mysql->prepare("INSERT INTO movimientos_deuda (idDeuda, Tipo, Valor, Fecha, Nota, idMovimiento)
        VALUES (?, 'Abono', ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE idDeuda = VALUES(idDeuda), Valor = VALUES(Valor), Fecha = VALUES(Fecha), Nota = VALUES(Nota)");
    $valor = (float)$m['valorMovimiento'];
    $stmt->bind_param('idssi', $m['idDeuda'], $valor, $m['fechaMovimiento'], $m['nombreGasto'], $idMovimiento);
    $stmt->execute();
}

// Recalcula los abonos de todos los movimientos de un gasto (p. ej. al vincularlo/desvincularlo de una deuda).
function resincronizarAbonosGasto($idGasto) {
    global $mysql;
    $stmt = $mysql->prepare("SELECT idMovimiento FROM movimientos WHERE idGasto = ?");
    $stmt->bind_param('i', $idGasto);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
        sincronizarAbonoMovimiento($f['idMovimiento']);
    }
}

// Registra un movimiento por lo que falta de un gasto (acción "Ya pagué"). Devuelve el valor registrado.
function registrarPagoGasto($idGasto) {
    global $mysql;
    $stmt = $mysql->prepare("SELECT NombreGasto, CostoPrevisto, valorGastosMovimiento FROM gastos WHERE idGastos = ?");
    $stmt->bind_param('i', $idGasto);
    $stmt->execute();
    $g = $stmt->get_result()->fetch_assoc();
    if (!$g) return null;
    $falta = round((float)$g['CostoPrevisto'] - (float)$g['valorGastosMovimiento'], 2);
    if ($falta <= 0) return 0.0;

    $tipo = 'Gasto';
    $obs = 'Pago registrado desde la notificación';
    $fecha = date('Y-m-d');
    $ins = $mysql->prepare("INSERT INTO movimientos (tipoMovimiento, valorMovimiento, nombreGasto, observacionMovimiento, fechaMovimiento, idGasto) VALUES (?, ?, ?, ?, ?, ?)");
    $ins->bind_param('sdsssi', $tipo, $falta, $g['NombreGasto'], $obs, $fecha, $idGasto);
    $ins->execute();
    $idMov = $mysql->insert_id;
    sincronizarGasto($idGasto);
    sincronizarAbonoMovimiento($idMov);
    return $falta;
}

// ---------------------------------------------------------------- Deudas

const SQL_SALDO_DEUDA = "(d.SaldoInicial
    + COALESCE((SELECT SUM(CASE WHEN md.Tipo = 'Abono' THEN -md.Valor ELSE md.Valor END) FROM movimientos_deuda md WHERE md.idDeuda = d.idDeuda), 0))";

// Próxima fecha (Y-m-d) en que cae el día $dia (1-31) a partir de hoy, ajustando a meses cortos. null si no hay día.
function proximaFechaDia($dia, $desde = null) {
    if (!$dia) return null;
    $hoy = $desde ? new DateTime($desde) : new DateTime('today');
    for ($i = 0; $i < 2; $i++) {
        $anho = (int)$hoy->format('Y');
        $mes = (int)$hoy->format('n') + $i;
        if ($mes > 12) { $mes -= 12; $anho++; }
        $ultimo = (int)date('t', mktime(0, 0, 0, $mes, 1, $anho));
        $f = new DateTime(sprintf('%04d-%02d-%02d', $anho, $mes, min((int)$dia, $ultimo)));
        if ($f >= $hoy) return $f->format('Y-m-d');
    }
    return null;
}

// ---------------------------------------------------------------- Obligaciones anuales

// Cuota mensual de provisión: lo que falta por ahorrar repartido en los meses que quedan (mínimo 1).
function cuotaProvision($valorEstimado, $ahorrado, $fechaVencimiento, $mes, $anho) {
    $falta = (float)$valorEstimado - (float)$ahorrado;
    if ($falta <= 0) return 0.0;
    $v = new DateTime($fechaVencimiento);
    $meses = ((int)$v->format('Y') * 12 + (int)$v->format('n')) - ($anho * 12 + $mes);
    return ceil($falta / max(1, $meses));
}

// Valor ahorrado en el ciclo actual: movimientos de los gastos de provisión desde CicloInicio.
function ahorradoObligacion($idObligacion, $cicloInicio) {
    global $mysql;
    $c = new DateTime($cicloInicio);
    $indice = (int)$c->format('Y') * 12 + (int)$c->format('n');
    $stmt = $mysql->prepare("SELECT COALESCE(SUM(g.valorGastosMovimiento), 0) AS t
        FROM gastos g INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
        WHERE g.idObligacion = ? AND (p.Anho * 12 + p.Mes) >= ?");
    $stmt->bind_param('ii', $idObligacion, $indice);
    $stmt->execute();
    return (float)$stmt->get_result()->fetch_assoc()['t'];
}

// Crea, en el presupuesto de $mes/$anho, un gasto "Acumulado" de provisión por cada obligación activa
// que aún no tenga uno. Devuelve ['insertados' => n, 'omitidos' => n].
function provisionarObligaciones($mes, $anho, $idPresupuesto) {
    global $mysql;
    $idAcumulado = estadoGastoPorNombre('Acumulado');
    if (!$idAcumulado) return ['insertados' => 0, 'omitidos' => 0];

    $insertados = 0; $omitidos = 0;
    $res = $mysql->query("SELECT * FROM obligaciones WHERE Activa = 1");
    foreach ($res->fetch_all(MYSQLI_ASSOC) as $o) {
        $v = new DateTime($o['FechaVencimiento']);
        $indiceVenc = (int)$v->format('Y') * 12 + (int)$v->format('n');
        if ($indiceVenc < $anho * 12 + $mes) { $omitidos++; continue; } // ya venció: ver alertas

        $chk = $mysql->prepare("SELECT COUNT(*) c FROM gastos WHERE idPresupuesto = ? AND idObligacion = ?");
        $chk->bind_param('ii', $idPresupuesto, $o['idObligacion']);
        $chk->execute();
        if ((int)$chk->get_result()->fetch_assoc()['c'] > 0) { $omitidos++; continue; }

        $cuota = cuotaProvision($o['ValorEstimado'], ahorradoObligacion($o['idObligacion'], $o['CicloInicio']), $o['FechaVencimiento'], $mes, $anho);
        if ($cuota <= 0) { $omitidos++; continue; }

        $nombre = mb_substr('Provisión: ' . $o['Nombre'], 0, 100, 'UTF-8');
        $obs = 'Vence ' . $o['FechaVencimiento'];
        $fechaPago = sprintf('%04d-%02d-01', $anho, $mes);
        $ins = $mysql->prepare("INSERT INTO gastos (IdCategoria, NombreGasto, CostoPrevisto, CostoReal, FechaLimite, FechaPago, Observaciones, IdEstado, idPresupuesto, idObligacion)
            VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)");
        $ins->bind_param('isddssiii', $o['IdCategoria'], $nombre, $cuota, $cuota, $fechaPago, $obs, $idAcumulado, $idPresupuesto, $o['idObligacion']);
        $ins->execute();
        $insertados++;
    }
    return ['insertados' => $insertados, 'omitidos' => $omitidos];
}
