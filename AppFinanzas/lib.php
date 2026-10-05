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
        'deudas' => 'idDeuda', 'obligaciones' => 'idObligacion', 'Inversiones' => 'idInversion',
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
// Solo toca gastos que están en Pendiente / En proceso / Pagado: los estados
// Guardado, Acumulado y No aplica los decide el usuario y no se modifican.
function sincronizarGasto($idGasto) {
    global $mysql, $uid;
    $idGasto = (int)$idGasto;
    if ($idGasto <= 0) return;

    $stmt = $mysql->prepare("SELECT g.CostoPrevisto, g.IdEstado, e.NombreEstado,
            (SELECT COALESCE(SUM(m.valorMovimiento),0) FROM movimientos m WHERE m.idGasto = g.idGastos) AS total,
            (SELECT MAX(m.fechaMovimiento) FROM movimientos m WHERE m.idGasto = g.idGastos) AS ultima
        FROM gastos g INNER JOIN estados e ON e.idEstado = g.IdEstado
        INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
        WHERE g.idGastos = ? AND p.IdUsuario = ?");
    $stmt->bind_param('ii', $idGasto, $uid);
    $stmt->execute();
    $g = $stmt->get_result()->fetch_assoc();
    if (!$g || !in_array($g['NombreEstado'], ['Pendiente', 'En proceso', 'Pagado'], true)) return;

    $total = (float)$g['total'];
    $previsto = (float)$g['CostoPrevisto'];
    if ($total <= 0) {
        $nuevo = 'Pendiente';
    } elseif ($previsto > 0 && round($total, 2) >= round($previsto * UMBRAL_PAGADO, 2)) {
        $nuevo = 'Pagado';
    } else {
        $nuevo = 'En proceso';
    }
    $est = estadoGastoPorNombre($nuevo);
    if (!$est) return;

    // Con movimientos, el valor pagado (CostoReal) siempre es el total registrado; pagado => fecha del último movimiento.
    // "En proceso" arranca desde el primer movimiento. Sin movimientos solo se revierte el estado (no se toca CostoReal).
    if ($nuevo === 'Pagado') {
        $stmt = $mysql->prepare("UPDATE gastos SET IdEstado = ?, CostoReal = ?, FechaPago = ? WHERE idGastos = ?");
        $stmt->bind_param('idsi', $est, $total, $g['ultima'], $idGasto);
    } elseif ($nuevo === 'En proceso') {
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

// Valor ahorrado en el ciclo actual: movimientos de los gastos de provisión desde CicloInicio.
function obligacionesTieneAhorroInicial() {
    global $mysql;
    static $tiene = null;
    if ($tiene === null) {
        $r = $mysql->query("SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'obligaciones' AND column_name = 'AhorradoInicial'");
        $tiene = $r && (int)$r->fetch_assoc()['n'] > 0;
    }
    return $tiene;
}

// Ahorrado del ciclo = lo ya ahorrado antes de usar la app (AhorradoInicial, migración 009) + movimientos de los gastos de provisión.
function ahorradoObligacion($idObligacion, $cicloInicio) {
    global $mysql, $uid;
    $c = new DateTime($cicloInicio);
    $indice = (int)$c->format('Y') * 12 + (int)$c->format('n');
    $stmt = $mysql->prepare("SELECT COALESCE(SUM(g.valorGastosMovimiento), 0) AS t
        FROM gastos g INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
        WHERE g.idObligacion = ? AND (p.Anho * 12 + p.Mes) >= ? AND p.IdUsuario = ?");
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
