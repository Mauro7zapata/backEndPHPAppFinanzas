<?php
// Lógica compartida entre los endpoints (Movimientos, Gastos, Plantilla, Deudas, Obligaciones, Alertas).
// Este archivo solo define funciones: no ejecuta nada por sí mismo. Requiere que db.php ya esté cargado.

// Fracción del costo previsto a partir de la cual un gasto se considera pagado: el total de movimientos debe igualar o superar el previsto.
const UMBRAL_PAGADO = 1.0;

// ---------------------------------------------------------------- Aislamiento por usuario
// Todas las funciones de este archivo trabajan sobre el usuario autenticado ($uid, definido en db.php).

// true si el registro $id de una tabla raíz pertenece al usuario autenticado.
// Sirve para validar ids foráneos que llegan del cliente (categoría, estado, presupuesto, deuda, obligación).
function appfinanzas_es_propio($tabla, $id) {
    global $mysql, $uid;
    static $columnas = [
        'presupuestos' => 'idPresupuesto', 'categoriagastos' => 'idCategoriaGastos', 'estados' => 'idEstado',
        'deudas' => 'idDeuda', 'obligaciones' => 'idObligacion', 'Inversiones' => 'idInversion', 'lugares_guardado' => 'idLugar',
    ];
    if (!isset($columnas[$tabla])) return false;
    $id = appfinanzas_entero($id);
    if ($id === null || $id <= 0) return false;
    $stmt = $mysql->prepare("SELECT 1 FROM " . $tabla . " WHERE " . $columnas[$tabla] . " = ? AND IdUsuario = ? LIMIT 1");
    $stmt->bind_param('ii', $id, $uid);
    $stmt->execute();
    $existe = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $existe;
}

// Con consultas preparadas mysqli devuelve enteros/decimales nativos; los endpoints antiguos (query()) los devolvían como
// texto en el JSON. Esta función conserva ese formato exacto de respuesta ("7" y no 7).
function appfinanzas_fila_texto($fila) {
    if (!$fila) return $fila;
    foreach ($fila as $k => $v) $fila[$k] = $v === null ? null : (string)$v;
    return $fila;
}
function appfinanzas_filas_texto($result) {
    $filas = [];
    while ($f = appfinanzas_fila_texto($result->fetch_assoc())) $filas[] = $f;
    return $filas;
}

// true si el gasto pertenece al usuario autenticado (gastos hereda el dueño de su presupuesto).
function appfinanzas_gasto_es_propio($idGasto) {
    global $mysql, $uid;
    $idGasto = appfinanzas_entero($idGasto);
    if ($idGasto === null || $idGasto <= 0) return false;
    $stmt = $mysql->prepare("SELECT 1 FROM gastos g INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
        WHERE g.idGastos = ? AND p.IdUsuario = ? LIMIT 1");
    $stmt->bind_param('ii', $idGasto, $uid);
    $stmt->execute();
    $existe = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $existe;
}

// Ajusta el estado del gasto según lo ya abonado mediante movimientos.
//  - suma >= costo previsto  -> Pagado (y se registra la fecha de pago)
//  - suma > 0 y < costo previsto     -> En proceso
//  - suma = 0 (se borraron los movimientos) -> vuelve a Pendiente
// Guardado: los movimientos son lo que ya gastaste de lo guardado. Sigue en Guardado mientras quede algo
// apartado y pasa a Pagado al completar el valor; si el pago se deshace y el gasto tenía lugar de guardado, vuelve a Guardado.
// Acumulado y No aplica los decide el usuario y no se modifican (en Acumulado los movimientos son ahorro, no gasto).
function sincronizarGasto($idGasto) {
    sincronizarGastoEstado($idGasto);
    evaluarCierrePorGasto($idGasto); // el presupuesto puede quedar Finalizado (o reabrirse) según cómo quedaron sus gastos
}

function sincronizarGastoEstado($idGasto) {
    global $mysql, $uid;
    $idGasto = (int)$idGasto;
    if ($idGasto <= 0) return;

    $colLugar = gastosTieneLugar() ? "g.idLugar" : "NULL";
    $stmt = $mysql->prepare("SELECT g.CostoPrevisto, g.IdEstado, e.NombreEstado, $colLugar AS lugar,
            (SELECT COALESCE(SUM(m.valorMovimiento),0) FROM movimientos m WHERE m.idGasto = g.idGastos) AS total,
            (SELECT MAX(m.fechaMovimiento) FROM movimientos m WHERE m.idGasto = g.idGastos) AS ultima
        FROM gastos g INNER JOIN estados e ON e.idEstado = g.IdEstado
        INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
        WHERE g.idGastos = ? AND p.IdUsuario = ?");
    $stmt->bind_param('ii', $idGasto, $uid);
    $stmt->execute();
    $g = $stmt->get_result()->fetch_assoc();
    if (!$g || !in_array($g['NombreEstado'], ['Pendiente', 'En proceso', 'Pagado', 'Guardado'], true)) return;

    $total = (float)$g['total'];
    $previsto = (float)$g['CostoPrevisto'];
    if ($total <= 0) {
        $nuevo = 'Pendiente';
    } elseif ($previsto > 0 && round($total, 2) >= round($previsto * UMBRAL_PAGADO, 2)) {
        $nuevo = 'Pagado';
    } else {
        $nuevo = 'En proceso';
    }
    if ($g['NombreEstado'] === 'Guardado') {
        // Pago parcial de lo guardado: sigue Guardado (se anota lo gastado); al completar el valor pasa a Pagado.
        if ($nuevo !== 'Pagado') {
            $q = $mysql->prepare("UPDATE gastos SET CostoReal = ? WHERE idGastos = ?");
            $q->bind_param('di', $total, $idGasto);
            $q->execute();
            return;
        }
    } elseif ($g['NombreEstado'] === 'Pagado' && $nuevo !== 'Pagado' && $g['lugar'] !== null) {
        $nuevo = 'Guardado'; // se deshizo un pago de algo que estaba guardado: lo no gastado vuelve a estar guardado
    }
    $est = estadoGastoPorNombre($nuevo);
    if (!$est) return;

    // Con movimientos, el valor pagado (CostoReal) siempre es el total registrado; pagado => fecha del último movimiento.
    // "En proceso" arranca desde el primer movimiento. Sin movimientos solo se revierte el estado (no se toca CostoReal).
    if ($nuevo === 'Pagado') {
        $stmt = $mysql->prepare("UPDATE gastos SET IdEstado = ?, CostoReal = ?, FechaPago = ? WHERE idGastos = ?");
        $stmt->bind_param('idsi', $est, $total, $g['ultima'], $idGasto);
    } elseif ($nuevo === 'En proceso' || $nuevo === 'Guardado') {
        $stmt = $mysql->prepare("UPDATE gastos SET IdEstado = ?, CostoReal = ? WHERE idGastos = ?");
        $stmt->bind_param('idi', $est, $total, $idGasto);
    } else {
        if ($nuevo === $g['NombreEstado']) return;
        $stmt = $mysql->prepare("UPDATE gastos SET IdEstado = ? WHERE idGastos = ?");
        $stmt->bind_param('ii', $est, $idGasto);
    }
    $stmt->execute();
}

// id del estado de tipo "Gastos" con ese nombre (o null).
function estadoGastoPorNombre($nombre) {
    global $mysql, $uid;
    $stmt = $mysql->prepare("SELECT idEstado FROM estados WHERE TipoEstado = 'Gastos' AND NombreEstado = ? AND IdUsuario = ? LIMIT 1");
    $stmt->bind_param('si', $nombre, $uid);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    return $fila ? (int)$fila['idEstado'] : null;
}

// Mantiene movimientos_deuda alineado con un movimiento del presupuesto:
// si el gasto está vinculado a una deuda, el movimiento es un abono a esa deuda.
function sincronizarAbonoMovimiento($idMovimiento) {
    global $mysql, $uid;
    $idMovimiento = (int)$idMovimiento;
    // El movimiento debe ser del usuario (movimientos -> gastos -> presupuestos) y la deuda también (si no, se trata como sin deuda).
    $stmt = $mysql->prepare("SELECT m.valorMovimiento, m.tipoMovimiento, m.fechaMovimiento, m.nombreGasto, d.idDeuda
        FROM movimientos m INNER JOIN gastos g ON g.idGastos = m.idGasto
        INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
        LEFT JOIN deudas d ON d.idDeuda = g.idDeuda AND d.IdUsuario = p.IdUsuario
        WHERE m.idMovimiento = ? AND p.IdUsuario = ?");
    $stmt->bind_param('ii', $idMovimiento, $uid);
    $stmt->execute();
    $m = $stmt->get_result()->fetch_assoc();

    if (!$m || $m['idDeuda'] === null || $m['tipoMovimiento'] !== 'Gasto' || (float)$m['valorMovimiento'] <= 0) {
        // Solo se borran abonos de deudas del usuario (el movimiento pudo haberse eliminado ya).
        $del = $mysql->prepare("DELETE md FROM movimientos_deuda md INNER JOIN deudas d ON d.idDeuda = md.idDeuda
            WHERE md.idMovimiento = ? AND d.IdUsuario = ?");
        $del->bind_param('ii', $idMovimiento, $uid);
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
    global $mysql, $uid;
    $stmt = $mysql->prepare("SELECT m.idMovimiento FROM movimientos m INNER JOIN gastos g ON g.idGastos = m.idGasto
        INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto WHERE m.idGasto = ? AND p.IdUsuario = ?");
    $stmt->bind_param('ii', $idGasto, $uid);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
        sincronizarAbonoMovimiento($f['idMovimiento']);
    }
}

// Registra un movimiento por lo que falta de un gasto (acción "Ya pagué"). Devuelve el valor registrado.
function registrarPagoGasto($idGasto, $observaciones = null) {
    global $mysql, $uid;
    $stmt = $mysql->prepare("SELECT g.NombreGasto, g.CostoPrevisto, g.valorGastosMovimiento FROM gastos g
        INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto WHERE g.idGastos = ? AND p.IdUsuario = ?");
    $stmt->bind_param('ii', $idGasto, $uid);
    $stmt->execute();
    $g = $stmt->get_result()->fetch_assoc();
    if (!$g) return null;
    $falta = round((float)$g['CostoPrevisto'] - (float)$g['valorGastosMovimiento'], 2);
    if ($falta <= 0) return 0.0;

    $tipo = 'Gasto';
    $nota = trim((string)$observaciones);
    $obs = 'Pago registrado desde la notificación' . ($nota !== '' ? ': ' . mb_substr($nota, 0, 400, 'UTF-8') : '');
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

// Migración 018: monedas en las deudas (movimientos_deuda.Moneda y deudas.SaldoInicialUsd). Sin ella todo es COP.
function deudaTieneMoneda() {
    global $mysql;
    static $tiene = null;
    if ($tiene === null) {
        $r = $mysql->query("SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema = DATABASE()
            AND ((table_name = 'movimientos_deuda' AND column_name = 'Moneda') OR (table_name = 'deudas' AND column_name = 'SaldoInicialUsd'))");
        $tiene = $r && (int)$r->fetch_assoc()['n'] >= 2;
    }
    return $tiene;
}

// Saldo de una deuda (alias d) en una moneda: saldo inicial + cargos + intereses - abonos de esa moneda.
// El saldo en pesos NUNCA incluye movimientos en dólares (no se convierten ni se suman).
function sqlSaldoDeuda($moneda = 'COP') {
    $tiene = deudaTieneMoneda();
    if ($moneda === 'USD') {
        if (!$tiene) return "0";
        $inicial = "d.SaldoInicialUsd"; $filtro = " AND md.Moneda = 'USD'";
    } else {
        $inicial = "d.SaldoInicial"; $filtro = $tiene ? " AND md.Moneda = 'COP'" : "";
    }
    return "($inicial + COALESCE((SELECT SUM(CASE WHEN md.Tipo = 'Abono' THEN -md.Valor ELSE md.Valor END)
        FROM movimientos_deuda md WHERE md.idDeuda = d.idDeuda$filtro), 0))";
}

// Condición SQL para limitar sumas de movimientos_deuda (alias md) a pesos; vacía sin la migración 018.
function sqlSoloPesosDeuda() {
    return deudaTieneMoneda() ? " AND md.Moneda = 'COP'" : "";
}

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

// Corte del ciclo que se paga en $fechaPago: el último corte estrictamente anterior a esa fecha
// (corte 15 y pago 5 -> pago 5 oct se refiere al corte del 15 sep). null si no hay día de corte.
function corteAnteriorA($diaCorte, $fechaPago) {
    if (!$diaCorte || !$fechaPago) return null;
    $p = new DateTime($fechaPago);
    $mes = (int)$p->format('n'); $anho = (int)$p->format('Y');
    for ($i = 0; $i < 2; $i++) {
        $ultimo = (int)date('t', mktime(0, 0, 0, $mes, 1, $anho));
        $c = new DateTime(sprintf('%04d-%02d-%02d', $anho, $mes, min((int)$diaCorte, $ultimo)));
        if ($c < $p) return $c->format('Y-m-d');
        $mes--; if ($mes < 1) { $mes = 12; $anho--; }
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

// true si existe la migración 012 (gastos.idLugar y tabla lugares_guardado). Así el backend funciona antes de migrar.
function gastosTieneLugar() {
    global $mysql;
    static $tiene = null;
    if ($tiene === null) {
        $r = $mysql->query("SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'gastos' AND column_name = 'idLugar'");
        $tiene = $r && (int)$r->fetch_assoc()['n'] > 0;
    }
    return $tiene;
}

// Fragmento SELECT con el lugar de guardado del gasto (alias g = gastos, p = presupuestos).
function sqlLugarGasto() {
    if (gastosTieneLugar()) {
        return ", g.idLugar, (SELECT ll.Nombre FROM lugares_guardado ll WHERE ll.idLugar = g.idLugar AND ll.IdUsuario = p.IdUsuario) AS NombreLugar";
    }
    return ", NULL AS idLugar, NULL AS NombreLugar";
}

// Valor que cuenta un gasto en el dashboard: Guardado ya está apartado aunque no tenga movimientos (cuenta lo previsto,
// o los movimientos si son mayores); los demás estados cuentan solo sus movimientos reales.
function sqlValorEfectivoGasto($g = 'g', $e = 'e') {
    return "(CASE WHEN $e.NombreEstado = 'Guardado' THEN GREATEST($g.valorGastosMovimiento, $g.CostoPrevisto) ELSE $g.valorGastosMovimiento END)";
}

// Lo que un gasto aporta al saldo separado: Guardado = lo apartado que aún no se ha gastado (previsto - movimientos);
// Acumulado = lo juntado (movimientos); otros estados no separan nada.
function sqlSeparadoGasto($g = 'g', $e = 'e') {
    return "(CASE WHEN $e.NombreEstado = 'Guardado' THEN GREATEST($g.CostoPrevisto - $g.valorGastosMovimiento, 0)
        WHEN $e.NombreEstado = 'Acumulado' THEN $g.valorGastosMovimiento ELSE 0 END)";
}

// true si existe la tabla separado_usos (migración 014). Sin ella el saldo separado = lo aportado.
function separadoTieneUsos() {
    global $mysql;
    static $tiene = null;
    if ($tiene === null) {
        $r = $mysql->query("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'separado_usos'");
        $tiene = $r && (int)$r->fetch_assoc()['n'] > 0;
    }
    return $tiene;
}

// Saldo separado por lugar (clave 0 = «sin lugar»), de todos los meses:
//   saldo = separado (Guardado: lo no gastado; Acumulado: movimientos) + ajustes - usado.
// Cada elemento: guardado, acumulado, usado, ajuste, gastos, saldo.
function saldoSeparadoPorLugar() {
    global $mysql, $uid;
    $vacio = function () { return ['guardado' => 0.0, 'acumulado' => 0.0, 'usado' => 0.0, 'ajuste' => 0.0, 'gastos' => 0, 'saldo' => 0.0]; };
    $lugares = [];
    $colLugar = gastosTieneLugar() ? "COALESCE(g.idLugar, 0)" : "0";
    $q = $mysql->prepare("SELECT $colLugar AS l, e.NombreEstado AS est, COALESCE(SUM(" . sqlSeparadoGasto() . "), 0) AS t, COUNT(*) AS n
        FROM gastos g INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
        INNER JOIN estados e ON e.idEstado = g.IdEstado AND e.IdUsuario = p.IdUsuario
        WHERE p.IdUsuario = ? AND e.NombreEstado IN ('Guardado', 'Acumulado') GROUP BY l, e.NombreEstado");
    $q->bind_param('i', $uid);
    $q->execute();
    foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
        $l = (int)$f['l'];
        if (!isset($lugares[$l])) $lugares[$l] = $vacio();
        $lugares[$l][$f['est'] === 'Guardado' ? 'guardado' : 'acumulado'] += (float)$f['t'];
        $lugares[$l]['gastos'] += (int)$f['n'];
    }
    if (separadoTieneUsos()) {
        $q = $mysql->prepare("SELECT COALESCE(idLugar, 0) AS l, Tipo, COALESCE(SUM(Valor), 0) AS t FROM separado_usos WHERE IdUsuario = ? GROUP BY l, Tipo");
        $q->bind_param('i', $uid);
        $q->execute();
        foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
            $l = (int)$f['l'];
            if (!isset($lugares[$l])) $lugares[$l] = $vacio();
            $lugares[$l][$f['Tipo'] === 'Uso' ? 'usado' : 'ajuste'] += (float)$f['t'];
        }
    }
    foreach ($lugares as $l => $v) {
        $lugares[$l]['saldo'] = round($v['guardado'] + $v['acumulado'] + $v['ajuste'] - $v['usado'], 2);
    }
    return $lugares;
}

function saldoSeparadoTotal() {
    $t = 0.0;
    foreach (saldoSeparadoPorLugar() as $v) $t += $v['saldo'];
    return round($t, 2);
}

function obligacionesTieneAhorroInicial() {
    global $mysql;
    static $tiene = null;
    if ($tiene === null) {
        $r = $mysql->query("SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'obligaciones' AND column_name = 'AhorradoInicial'");
        $tiene = $r && (int)$r->fetch_assoc()['n'] > 0;
    }
    return $tiene;
}

// Ahorrado del ciclo = lo ya ahorrado antes de usar la app (AhorradoInicial, migración 009) + movimientos de los gastos
// vinculados a la obligación que están en estado «Acumulado» (los «Pagado» son el pago de la obligación, no ahorro).
function ahorradoObligacion($idObligacion, $cicloInicio) {
    global $mysql, $uid;
    $c = new DateTime($cicloInicio);
    $indice = (int)$c->format('Y') * 12 + (int)$c->format('n');
    $stmt = $mysql->prepare("SELECT COALESCE(SUM(g.valorGastosMovimiento), 0) AS t
        FROM gastos g INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
        INNER JOIN estados e ON e.idEstado = g.IdEstado AND e.IdUsuario = p.IdUsuario
        WHERE g.idObligacion = ? AND (p.Anho * 12 + p.Mes) >= ? AND p.IdUsuario = ? AND e.NombreEstado = 'Acumulado'");
    $stmt->bind_param('iii', $idObligacion, $indice, $uid);
    $stmt->execute();
    $total = (float)$stmt->get_result()->fetch_assoc()['t'];
    if (obligacionesTieneAhorroInicial()) {
        $q = $mysql->prepare("SELECT AhorradoInicial FROM obligaciones WHERE idObligacion = ? AND IdUsuario = ?");
        $q->bind_param('ii', $idObligacion, $uid);
        $q->execute();
        $f = $q->get_result()->fetch_assoc();
        $total += $f ? (float)$f['AhorradoInicial'] : 0.0;
    }
    return $total;
}

// Crea, en el presupuesto de $mes/$anho, un gasto "Acumulado" de provisión por cada obligación activa
// que aún no tenga uno. Devuelve ['insertados' => n, 'omitidos' => n].
function provisionarObligaciones($mes, $anho, $idPresupuesto) {
    global $mysql, $uid;
    if (!appfinanzas_es_propio('presupuestos', $idPresupuesto)) return ['insertados' => 0, 'omitidos' => 0];
    $idAcumulado = estadoGastoPorNombre('Acumulado');
    if (!$idAcumulado) return ['insertados' => 0, 'omitidos' => 0];

    $insertados = 0; $omitidos = 0;
    $stmtObl = $mysql->prepare("SELECT * FROM obligaciones WHERE Activa = 1 AND IdUsuario = ?");
    $stmtObl->bind_param('i', $uid);
    $stmtObl->execute();
    foreach ($stmtObl->get_result()->fetch_all(MYSQLI_ASSOC) as $o) {
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

// ---------------------------------------------------------------- Parámetros de la app (tabla configuracion)

// Valores por defecto y reglas de cada parámetro: [defecto, mínimo, máximo].
const PARAMETROS_APP = [
    'dias_aviso_pagos' => [3, 0, 30],                 // días de anticipación para avisar pagos y cobros
    'dias_aviso_obligaciones' => [30, 0, 120],        // anticipación de las obligaciones anuales (SOAT, impuestos...)
    'dia_inicio_mes' => [1, 1, 31],                   // día en que empieza el mes financiero (p. ej. 25 = pago de nómina)
    'porcentaje_alerta_presupuesto' => [80, 0, 100],  // avisa al llegar a este % del presupuesto; 0 = desactivado
];

// Devuelve todos los parámetros como enteros (si la tabla no existe todavía, usa los valores por defecto).
function parametrosApp() {
    global $mysql, $uid;
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    foreach (PARAMETROS_APP as $k => $r) $cache[$k] = $r[0];
    try {
        // Cada usuario tiene sus propias filas; si no tiene ninguna se usan los valores por defecto.
        $stmt = $mysql->prepare("SELECT Clave, Valor FROM configuracion WHERE IdUsuario = ?");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
            if (isset(PARAMETROS_APP[$f['Clave']]) && is_numeric($f['Valor'])) {
                $r = PARAMETROS_APP[$f['Clave']];
                $cache[$f['Clave']] = max($r[1], min($r[2], (int)$f['Valor']));
            }
        }
    } catch (mysqli_sql_exception $e) {
        // Migración 004 sin ejecutar: se usan los valores por defecto.
    }
    return $cache;
}

function parametroApp($clave) {
    return parametrosApp()[$clave] ?? (PARAMETROS_APP[$clave][0] ?? null);
}

// Día de inicio ajustado al mes: si el mes es más corto (p. ej. día 31 en febrero) empieza el último día.
function diaInicioEnMes($mes, $anho, $diaInicio) {
    return min((int)$diaInicio, (int)date('t', mktime(0, 0, 0, $mes, 1, $anho)));
}

// Mes financiero que contiene la fecha dada. Se nombra por el mes en que TERMINA:
// con inicio el 28, el 28 de septiembre empieza "octubre" (del 28/sep al 27/oct). Con inicio el día 1 es el mes calendario.
function mesFinanciero($fechaTxt, $diaInicio) {
    $f = new DateTime($fechaTxt);
    $mes = (int)$f->format('n'); $anho = (int)$f->format('Y');
    if ((int)$diaInicio > 1 && (int)$f->format('j') >= diaInicioEnMes($mes, $anho, $diaInicio)) {
        $mes++; if ($mes > 12) { $mes = 1; $anho++; }
    }
    return [$mes, $anho];
}

// Primer día y último día (Y-m-d) del mes financiero $mes/$anho.
function periodoFinanciero($mes, $anho, $diaInicio) {
    if ((int)$diaInicio <= 1) {
        return [sprintf('%04d-%02d-01', $anho, $mes), date('Y-m-t', mktime(0, 0, 0, $mes, 1, $anho))];
    }
    $mp = $mes - 1; $ap = $anho; if ($mp < 1) { $mp = 12; $ap--; }
    $ini = sprintf('%04d-%02d-%02d', $ap, $mp, diaInicioEnMes($mp, $ap, $diaInicio));
    $siguiente = sprintf('%04d-%02d-%02d', $anho, $mes, diaInicioEnMes($mes, $anho, $diaInicio));
    return [$ini, date('Y-m-d', strtotime($siguiente . ' -1 day'))];
}

// ---------------------------------------------------------------- Presupuesto: estado «Finalizado» y cierre de mes
// Un presupuesto está «En curso» o «Finalizado» (estados de tipo «Presupuestos», editables en Configuraciones).
// Se finaliza solo cuando TODOS sus gastos quedan resueltos (pagados, no aplican, o guardados/acumulados por completo)
// y se reabre solo si aparece un gasto abierto (salvo cierre manual). Mientras un mes ya terminado siga sin finalizar,
// no se pueden crear presupuestos nuevos. Requiere la migración 010; sin ella todo esto se ignora.

const COLORES_ESTADO_PRESUPUESTO = ['En curso' => -14575885, 'Finalizado' => -11751600];

function nombreMesEs($mes) {
    static $n = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return $n[(int)$mes] ?? (string)$mes;
}

function presupuestosTieneEstado() {
    global $mysql;
    static $tiene = null;
    if ($tiene === null) {
        $r = $mysql->query("SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'presupuestos' AND column_name IN ('IdEstado','FechaFinalizado','CierreManual')");
        $tiene = $r && (int)$r->fetch_assoc()['n'] === 3;
    }
    return $tiene;
}

// id del estado de tipo «Presupuestos» con ese nombre; lo crea si el usuario aún no lo tiene.
function estadoPresupuestoPorNombre($nombre) {
    global $mysql, $uid;
    $stmt = $mysql->prepare("SELECT idEstado FROM estados WHERE TipoEstado = 'Presupuestos' AND NombreEstado = ? AND IdUsuario = ? LIMIT 1");
    $stmt->bind_param('si', $nombre, $uid);
    $stmt->execute();
    $f = $stmt->get_result()->fetch_assoc();
    if ($f) return (int)$f['idEstado'];
    $color = COLORES_ESTADO_PRESUPUESTO[$nombre] ?? -7829368;
    $ins = $mysql->prepare("INSERT INTO estados (TipoEstado, NombreEstado, ColorEstado, IdUsuario) VALUES ('Presupuestos', ?, ?, ?)");
    $ins->bind_param('sii', $nombre, $color, $uid);
    $ins->execute();
    return (int)$mysql->insert_id;
}

// Partes del SELECT/JOIN para traer el estado de un presupuesto (alias p) aunque la migración 010 no se haya ejecutado.
function sqlEstadoPresupuesto() {
    if (presupuestosTieneEstado()) {
        return ["COALESCE(ep.NombreEstado, 'En curso') AS EstadoPresupuesto, p.FechaFinalizado, p.CierreManual",
            "LEFT JOIN estados ep ON ep.idEstado = p.IdEstado AND ep.IdUsuario = p.IdUsuario"];
    }
    return ["'En curso' AS EstadoPresupuesto, NULL AS FechaFinalizado, 0 AS CierreManual", ""];
}

// Gastos del presupuesto y cuántos siguen sin resolver: Pendiente / En proceso, o Guardado / Acumulado sin completar su valor.
function resumenCierrePresupuesto($idPresupuesto) {
    global $mysql, $uid;
    $stmt = $mysql->prepare("SELECT COUNT(*) AS total,
            COALESCE(SUM(CASE
                WHEN e.NombreEstado IN ('Pendiente','En proceso') THEN 1
                WHEN e.NombreEstado = 'Acumulado' AND ROUND(g.valorGastosMovimiento, 2) < ROUND(g.CostoPrevisto, 2) THEN 1
                ELSE 0 END), 0) AS abiertos
        FROM gastos g INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
        INNER JOIN estados e ON e.idEstado = g.IdEstado AND e.IdUsuario = p.IdUsuario
        WHERE g.idPresupuesto = ? AND p.IdUsuario = ?");
    $stmt->bind_param('ii', $idPresupuesto, $uid);
    $stmt->execute();
    $f = $stmt->get_result()->fetch_assoc();
    return ['total' => (int)$f['total'], 'abiertos' => (int)$f['abiertos']];
}

function estadoCierrePresupuesto($idPresupuesto) {
    global $mysql, $uid;
    $stmt = $mysql->prepare("SELECT IdEstado, CierreManual FROM presupuestos WHERE idPresupuesto = ? AND IdUsuario = ?");
    $stmt->bind_param('ii', $idPresupuesto, $uid);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function finalizarPresupuesto($idPresupuesto, $manual) {
    global $mysql, $uid;
    $fin = estadoPresupuestoPorNombre('Finalizado');
    $hoy = date('Y-m-d'); $m = $manual ? 1 : 0;
    $stmt = $mysql->prepare("UPDATE presupuestos SET IdEstado = ?, FechaFinalizado = ?, CierreManual = ? WHERE idPresupuesto = ? AND IdUsuario = ?");
    $stmt->bind_param('isiii', $fin, $hoy, $m, $idPresupuesto, $uid);
    $stmt->execute();
}

function reabrirPresupuesto($idPresupuesto) {
    global $mysql, $uid;
    $curso = estadoPresupuestoPorNombre('En curso');
    $stmt = $mysql->prepare("UPDATE presupuestos SET IdEstado = ?, FechaFinalizado = NULL, CierreManual = 0 WHERE idPresupuesto = ? AND IdUsuario = ?");
    $stmt->bind_param('iii', $curso, $idPresupuesto, $uid);
    $stmt->execute();
}

// Finaliza o reabre el presupuesto según el estado de sus gastos. Nunca rompe la operación que lo invoca.
function evaluarCierrePresupuesto($idPresupuesto) {
    try {
        $idPresupuesto = (int)$idPresupuesto;
        if ($idPresupuesto <= 0 || !presupuestosTieneEstado()) return;
        $p = estadoCierrePresupuesto($idPresupuesto);
        if (!$p) return;
        $r = resumenCierrePresupuesto($idPresupuesto);
        $finalizado = $p['IdEstado'] !== null && (int)$p['IdEstado'] === estadoPresupuestoPorNombre('Finalizado');
        if ($finalizado) {
            if (!(int)$p['CierreManual'] && $r['abiertos'] > 0) reabrirPresupuesto($idPresupuesto);
        } elseif ($r['total'] > 0 && $r['abiertos'] === 0) {
            finalizarPresupuesto($idPresupuesto, false);
        } elseif ($p['IdEstado'] === null) {
            reabrirPresupuesto($idPresupuesto); // deja el estado «En curso» explícito
        }
    } catch (mysqli_sql_exception $e) {
        error_log('[AppFinanzas] evaluarCierrePresupuesto: ' . $e->getMessage());
    }
}

function evaluarCierrePorGasto($idGasto) {
    global $mysql, $uid;
    try {
        if (!presupuestosTieneEstado()) return;
        $idGasto = (int)$idGasto;
        if ($idGasto <= 0) return;
        $stmt = $mysql->prepare("SELECT g.idPresupuesto FROM gastos g INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto WHERE g.idGastos = ? AND p.IdUsuario = ?");
        $stmt->bind_param('ii', $idGasto, $uid);
        $stmt->execute();
        $f = $stmt->get_result()->fetch_assoc();
        if ($f) evaluarCierrePresupuesto((int)$f['idPresupuesto']);
    } catch (mysqli_sql_exception $e) {
        error_log('[AppFinanzas] evaluarCierrePorGasto: ' . $e->getMessage());
    }
}

// true si el presupuesto está finalizado por cierre manual (no admite gastos nuevos hasta reabrirlo).
function presupuestoCerradoManual($idPresupuesto) {
    if (!presupuestosTieneEstado()) return false;
    $p = estadoCierrePresupuesto((int)$idPresupuesto);
    return $p && $p['IdEstado'] !== null && (int)$p['CierreManual'] === 1 && (int)$p['IdEstado'] === estadoPresupuestoPorNombre('Finalizado');
}

// Presupuesto más antiguo cuyo mes financiero ya terminó, que tiene gastos y no está finalizado: ['mes','anho'] o null.
function presupuestoPendienteDeCierre() {
    global $mysql, $uid;
    if (!presupuestosTieneEstado()) return null;
    [$mesAct, $anhoAct] = mesFinanciero(date('Y-m-d'), parametroApp('dia_inicio_mes'));
    $indice = $anhoAct * 12 + $mesAct;
    $fin = estadoPresupuestoPorNombre('Finalizado');
    $stmt = $mysql->prepare("SELECT p.idPresupuesto, p.Mes, p.Anho FROM presupuestos p
        WHERE p.IdUsuario = ? AND p.Anho * 12 + p.Mes < ? AND (p.IdEstado IS NULL OR p.IdEstado <> ?)
          AND EXISTS (SELECT 1 FROM gastos g WHERE g.idPresupuesto = p.idPresupuesto)
        ORDER BY p.Anho, p.Mes LIMIT 1");
    $stmt->bind_param('iii', $uid, $indice, $fin);
    $stmt->execute();
    $f = $stmt->get_result()->fetch_assoc();
    return $f ? ['idPresupuesto' => (int)$f['idPresupuesto'], 'mes' => (int)$f['Mes'], 'anho' => (int)$f['Anho']] : null;
}
