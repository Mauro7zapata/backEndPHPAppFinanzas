<?php
// Lógica compartida del módulo de Inversiones (dinero prestado o invertido) y su plan de cobros.
// Solo define funciones. Requiere que db.php ya esté cargado.
//
// Tipos de inversión (tabla TablaTipoInversion):
//   1 Acciones con dividendos     -> las cuotas (dividendos) se registran a mano
//   2 P1 Préstamo por amortización -> cada cuota paga interés sobre el saldo y puede abonar capital
//   3 P2 Préstamo de interés fijo  -> cuota = interés; el capital se devuelve en la última cuota
//   4 P3 Préstamo indefinido       -> cuota = interés; sin fecha límite, hasta que se liquide
//   9 Inversión de ganancia fija   -> una sola cuota en la fecha final (capital + ganancia)
//  10 Cobranza de deudores         -> sin interés; se cobra el capital en cuotas
//
// "Interes" es la tasa MENSUAL (%) sobre el saldo de capital pendiente.
// Una cuota está cobrada cuando su estado (tipo "Pagos") es "Cobrado".

const INV_ACCIONES = 1;
const INV_AMORTIZACION = 2;
const INV_INTERES_FIJO = 3;
const INV_INDEFINIDO = 4;
const INV_GANANCIA_FIJA = 9;
const INV_COBRANZA = 10;

// Cuántas cuotas se crean como máximo cuando el plan no tiene fin definido.
const INV_HORIZONTE_CUOTAS = 12;

// Estado del usuario autenticado por tipo y nombre (cada usuario tiene su propio juego de estados).
function invEstadoId($tipoEstado, $nombre) {
    global $mysql, $uid;
    static $cache = [];
    $k = $uid . '|' . $tipoEstado . '|' . $nombre;
    if (!array_key_exists($k, $cache)) {
        $stmt = $mysql->prepare("SELECT idEstado FROM estados WHERE IdUsuario = ? AND TipoEstado = ? AND NombreEstado = ? LIMIT 1");
        $stmt->bind_param('iss', $uid, $tipoEstado, $nombre);
        $stmt->execute();
        $f = $stmt->get_result()->fetch_assoc();
        $cache[$k] = $f ? (int)$f['idEstado'] : null;
    }
    return $cache[$k];
}

// '0000-00-00' y vacío significan "sin fecha".
function invFecha($v) {
    return ($v === null || $v === '' || strpos((string)$v, '0000-00-00') === 0) ? null : $v;
}

// Suma $meses a una fecha conservando el día ancla (si el mes es más corto, usa su último día).
function invSumarMeses($fecha, $meses, $diaAncla = null) {
    $d = new DateTime($fecha);
    $dia = $diaAncla ?: (int)$d->format('j');
    $total = ((int)$d->format('Y')) * 12 + ((int)$d->format('n') - 1) + $meses;
    $anho = intdiv($total, 12);
    $mes = $total % 12 + 1;
    $ultimo = (int)date('t', mktime(0, 0, 0, $mes, 1, $anho));
    return sprintf('%04d-%02d-%02d', $anho, $mes, min($dia, $ultimo));
}

// Inversión del usuario autenticado (null si no existe o es de otro usuario).
function invCargar($idInversion) {
    global $mysql, $uid;
    $stmt = $mysql->prepare("SELECT i.*, t.Nombre AS NombreTipo, e.NombreEstado
        FROM Inversiones i
        LEFT JOIN TablaTipoInversion t ON t.idTipo = i.IdTipo
        LEFT JOIN estados e ON e.idEstado = i.idEstado AND e.IdUsuario = i.IdUsuario
        WHERE i.idInversion = ? AND i.IdUsuario = ?");
    $stmt->bind_param('ii', $idInversion, $uid);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

// Cuotas de una inversión, ordenadas por número. 'cobrada' indica si ya se cobró.
// Solo devuelve cuotas si la inversión es del usuario autenticado.
function invCuotas($idInversion) {
    global $mysql, $uid;
    $stmt = $mysql->prepare("SELECT p.*, e.NombreEstado FROM PlanPagos p
        INNER JOIN Inversiones i ON i.idInversion = p.idInversion
        LEFT JOIN estados e ON e.idEstado = p.IdEstado AND e.IdUsuario = i.IdUsuario
        WHERE p.idInversion = ? AND i.IdUsuario = ? ORDER BY p.NroCuota, p.idPlan");
    $stmt->bind_param('ii', $idInversion, $uid);
    $stmt->execute();
    $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($filas as &$f) {
        $f['FechaPrevistaPago'] = invFecha($f['FechaPrevistaPago']);
        $f['FechaRealPago'] = invFecha($f['FechaRealPago']);
        $f['cobrada'] = ($f['NombreEstado'] === 'Cobrado');
    }
    return $filas;
}

// Capital que falta por recuperar según lo ya cobrado.
function invSaldoCobrado($inversion, $cuotas) {
    $cobrado = 0.0;
    foreach ($cuotas as $c) if ($c['cobrada']) $cobrado += (float)$c['CapitalPagado'];
    return max(0.0, round((float)$inversion['CapitalInvertido'] - $cobrado, 2));
}

// Propone la siguiente cuota (sin guardarla).
//   $modo 'anterior'    -> copia los valores de la última cuota y avanza un mes.
//   $modo 'recalculada' -> calcula interés y capital con el saldo vigente según el tipo de inversión.
// $cuotas: cuotas existentes (cobradas y pendientes) tal como las devuelve invCuotas().
// Devuelve [ok => true, cuota => [...]] o [ok => false, mensaje => '...'].
function invProponerCuota($inv, $cuotas, $modo) {
    $tipo = (int)$inv['IdTipo'];
    $tasa = (float)$inv['Interes'] / 100;
    $capital = (float)$inv['CapitalInvertido'];
    $nroCuotas = (int)$inv['NroCuotas'];
    $pactada = (float)$inv['CuotaPactada'];
    $fechaFin = invFecha($inv['FechaFin']);

    // Todo lo ya previsto (cobrado o pendiente) cuenta como comprometido para el cálculo del saldo.
    $comprometido = 0.0;
    $nro = 0;
    $ultima = null;
    foreach ($cuotas as $c) {
        $comprometido += (float)$c['CapitalPagado'];
        if ((int)$c['NroCuota'] >= $nro) { $nro = (int)$c['NroCuota']; $ultima = $c; }
    }
    $saldo = max(0.0, round($capital - $comprometido, 2));
    $numero = $nro + 1;

    if ($ultima && $ultima['FechaPrevistaPago']) {
        $fecha = invSumarMeses($ultima['FechaPrevistaPago'], 1);
    } else {
        $fecha = invSumarMeses($inv['FechaInicio'], 1);
    }

    if ($modo === 'anterior') {
        if (!$ultima) return ['ok' => false, 'mensaje' => 'No hay una cuota anterior para copiar. Usa "Recalculada".'];
        $interes = (float)$ultima['InteresPagado'];
        $capitalCuota = (float)$ultima['CapitalPagado'];
        $dividendo = (float)$ultima['DividendoPagado'];
        $nota = 'Copia de la cuota ' . $ultima['NroCuota'];
        $esUltima = false;
        $saldoDespues = max(0.0, round($saldo - $capitalCuota, 2));
    } else {
        if ($tipo === INV_ACCIONES) {
            if (!$ultima) return ['ok' => false, 'mensaje' => 'Los dividendos de acciones se registran a mano.'];
            return invProponerCuota($inv, $cuotas, 'anterior');
        }
        if ($saldo <= 0.5 && $tipo !== INV_ACCIONES) {
            return ['ok' => false, 'mensaje' => 'El capital de esta inversión ya está cubierto por las cuotas del plan.'];
        }

        $esUltima = ($nroCuotas > 0 && $numero >= $nroCuotas)
            || ($fechaFin !== null && invSumarMeses($fecha, 1) > $fechaFin);
        $interes = 0.0;
        $capitalCuota = 0.0;
        $dividendo = 0.0;
        $nota = 'Recalculada sobre un saldo de capital de ' . number_format($saldo, 0, ',', '.');

        switch ($tipo) {
            case INV_AMORTIZACION:
                $interes = round($saldo * $tasa);
                if ($esUltima) {
                    $capitalCuota = $saldo;
                } elseif ($pactada > $interes + 0.5) {
                    $capitalCuota = min($saldo, round($pactada - $interes));
                }
                break;
            case INV_INTERES_FIJO:
            case INV_INDEFINIDO:
                $interes = round($saldo * $tasa);
                if ($esUltima) $capitalCuota = $saldo;
                break;
            case INV_GANANCIA_FIJA:
                // Una sola cuota: capital + ganancia en la fecha final.
                $capitalCuota = $saldo;
                if ($pactada > $capital) {
                    $interes = round($pactada - $capital);
                } else {
                    $meses = $fechaFin ? max(1, (int)round((strtotime($fechaFin) - strtotime($inv['FechaInicio'])) / (86400 * 30.4))) : 1;
                    $interes = round($capital * $tasa * $meses);
                }
                if ($fechaFin) $fecha = $fechaFin;
                $esUltima = true;
                break;
            case INV_COBRANZA:
                $capitalCuota = $pactada > 0 ? min($saldo, $pactada) : $saldo;
                if ($esUltima) $capitalCuota = $saldo;
                break;
        }
        $saldoDespues = max(0.0, round($saldo - $capitalCuota, 2));
        if ($saldoDespues <= 0.5) $esUltima = true;
    }

    return ['ok' => true, 'cuota' => [
        'nroCuota' => $numero,
        'fechaPrevista' => $fecha,
        'interes' => $interes,
        'capital' => $capitalCuota,
        'dividendo' => $dividendo,
        'saldoDespues' => $saldoDespues,
        'esUltima' => $esUltima,
        'nota' => $nota,
    ]];
}

// Genera (o simula) todas las cuotas que faltan hasta que la inversión se pague.
// Devuelve [ok, cuotas => [...], mensaje?]. Si $guardar es true las inserta como Pendiente.
function invGenerarCuotas($idInversion, $guardar, $conTransaccion = true) {
    global $mysql, $uid;
    $inv = invCargar($idInversion);
    if (!$inv) return ['ok' => false, 'mensaje' => 'La inversión no existe'];
    if ($inv['NombreEstado'] !== 'Desembolsado') return ['ok' => false, 'mensaje' => 'Solo se generan cuotas para inversiones en estado Desembolsado'];
    if ((int)$inv['IdTipo'] === INV_ACCIONES) return ['ok' => false, 'mensaje' => 'Los dividendos de acciones se registran a mano'];

    $cuotas = invCuotas($idInversion);
    $nuevas = [];
    $limite = ((int)$inv['NroCuotas'] > 0 || invFecha($inv['FechaFin']) !== null || (int)$inv['IdTipo'] === INV_GANANCIA_FIJA)
        ? 120 : INV_HORIZONTE_CUOTAS;

    for ($i = 0; $i < $limite; $i++) {
        $p = invProponerCuota($inv, $cuotas, 'recalculada');
        if (!$p['ok']) {
            if (!$nuevas) return ['ok' => false, 'mensaje' => $p['mensaje']];
            break;
        }
        $c = $p['cuota'];
        $nuevas[] = $c;
        $cuotas[] = ['NroCuota' => $c['nroCuota'], 'FechaPrevistaPago' => $c['fechaPrevista'],
                     'InteresPagado' => $c['interes'], 'CapitalPagado' => $c['capital'], 'DividendoPagado' => $c['dividendo'],
                     'cobrada' => false];
        if ($c['esUltima']) break;
    }
    if (!$nuevas) return ['ok' => false, 'mensaje' => 'No hay cuotas por generar'];

    if ($guardar) {
        $pendiente = invEstadoId('Pagos', 'Pendiente');
        if ($conTransaccion) $mysql->begin_transaction();
        try {
            // INSERT ... SELECT: solo inserta si la inversión pertenece al usuario autenticado.
            $stmt = $mysql->prepare("INSERT INTO PlanPagos (idInversion, NroCuota, FechaPrevistaPago, FechaRealPago, InteresPagado, CapitalPagado, DividendoPagado, IdEstado)
                SELECT i.idInversion, ?, ?, NULL, ?, ?, ?, ? FROM Inversiones i WHERE i.idInversion = ? AND i.IdUsuario = ?");
            foreach ($nuevas as $c) {
                $stmt->bind_param('isdddiii', $c['nroCuota'], $c['fechaPrevista'], $c['interes'], $c['capital'], $c['dividendo'], $pendiente, $idInversion, $uid);
                $stmt->execute();
            }
            if ($conTransaccion) $mysql->commit();
        } catch (Throwable $e) {
            if ($conTransaccion) $mysql->rollback();
            throw $e;
        }
    }
    return ['ok' => true, 'cuotas' => $nuevas];
}

// Texto de observación limpio (máx. 500 caracteres) o null si viene vacío.
function invObservacion($v) {
    if ($v === null) return null;
    $t = trim((string)$v);
    return $t === '' ? null : mb_substr($t, 0, 500, 'UTF-8');
}

// Guarda la observación de una cuota. Si ya tenía una distinta, la nueva se anexa para no perder el historial.
// Solo actúa sobre cuotas de inversiones del usuario autenticado.
function invGuardarObservacion($idPlan, $texto, $anexar = true) {
    global $mysql, $uid;
    if ($anexar) {
        $q = $mysql->prepare("SELECT p.Observaciones FROM PlanPagos p INNER JOIN Inversiones i ON i.idInversion = p.idInversion
            WHERE p.idPlan = ? AND i.IdUsuario = ?");
        $q->bind_param('ii', $idPlan, $uid); $q->execute();
        $fila = $q->get_result()->fetch_assoc();
        $previa = trim((string)($fila['Observaciones'] ?? ''));
        if ($previa !== '' && $previa !== $texto && strpos($previa, $texto) === false) $texto = mb_substr($previa . ' · ' . $texto, 0, 500, 'UTF-8');
    }
    $stmt = $mysql->prepare("UPDATE PlanPagos p INNER JOIN Inversiones i ON i.idInversion = p.idInversion
        SET p.Observaciones = ? WHERE p.idPlan = ? AND i.IdUsuario = ?");
    $stmt->bind_param('sii', $texto, $idPlan, $uid);
    $stmt->execute();
}

// Marca una cuota como cobrada. Ajusta importes si se envían. Liquida la inversión si ya no queda nada por cobrar.
// Devuelve null si la cuota no existe; si no, ['cobrada' => true, 'liquidada' => bool].
function invCobrarCuota($idPlan, $fecha = null, $interes = null, $capital = null, $dividendo = null, $observaciones = null) {
    global $mysql, $uid;
    $stmt = $mysql->prepare("SELECT p.idPlan, p.idInversion, p.InteresPagado, p.CapitalPagado, p.DividendoPagado FROM PlanPagos p
        INNER JOIN Inversiones i ON i.idInversion = p.idInversion WHERE p.idPlan = ? AND i.IdUsuario = ?");
    $stmt->bind_param('ii', $idPlan, $uid);
    $stmt->execute();
    $p = $stmt->get_result()->fetch_assoc();
    if (!$p) return null;

    $fecha = $fecha ?: date('Y-m-d');
    $interes = $interes === null ? (float)$p['InteresPagado'] : (float)$interes;
    $capital = $capital === null ? (float)$p['CapitalPagado'] : (float)$capital;
    $dividendo = $dividendo === null ? (float)$p['DividendoPagado'] : (float)$dividendo;
    $cobrado = invEstadoId('Pagos', 'Cobrado');

    $stmt = $mysql->prepare("UPDATE PlanPagos p INNER JOIN Inversiones i ON i.idInversion = p.idInversion
        SET p.IdEstado = ?, p.FechaRealPago = ?, p.InteresPagado = ?, p.CapitalPagado = ?, p.DividendoPagado = ? WHERE p.idPlan = ? AND i.IdUsuario = ?");
    $stmt->bind_param('isdddii', $cobrado, $fecha, $interes, $capital, $dividendo, $idPlan, $uid);
    $stmt->execute();
    $obs = invObservacion($observaciones);
    if ($obs !== null) invGuardarObservacion($idPlan, $obs);

    return ['cobrada' => true, 'liquidada' => invRevisarLiquidacion((int)$p['idInversion'])];
}

// Recalcula el plan cuando cambia la fecha final de una inversión con plazo (p. ej. octubre -> diciembre: 7 -> 9 cuotas).
//  - Las cuotas ya cobradas no se tocan nunca.
//  - Las pendientes se regeneran hasta la nueva fecha final (si el plazo se acorta, sobran y se eliminan).
//  - Las observaciones de las pendientes se conservan por número de cuota.
// Devuelve ['total' => cuotas del plan, 'agregadas' => n, 'eliminadas' => n] o null si no aplica.
function invSincronizarPlan($idInversion) {
    global $mysql, $uid;
    $inv = invCargar($idInversion);
    if (!$inv || $inv['NombreEstado'] !== 'Desembolsado') return null;
    $tipo = (int)$inv['IdTipo'];
    if (in_array($tipo, [INV_ACCIONES, INV_GANANCIA_FIJA], true) || invFecha($inv['FechaFin']) === null) return null;

    $antes = invCuotas($idInversion);
    $pendientesAntes = array_values(array_filter($antes, function ($c) { return !$c['cobrada']; }));
    $obs = [];
    foreach ($pendientesAntes as $c) if (!empty($c['Observaciones'])) $obs[(int)$c['NroCuota']] = $c['Observaciones'];

    $mysql->begin_transaction();
    try {
        $stmt = $mysql->prepare("DELETE p FROM PlanPagos p INNER JOIN Inversiones i ON i.idInversion = p.idInversion
            WHERE p.idInversion = ? AND p.IdEstado <> ? AND i.IdUsuario = ?");
        $cobrado = invEstadoId('Pagos', 'Cobrado');
        $stmt->bind_param('iii', $idInversion, $cobrado, $uid);
        $stmt->execute();

        $r = invGenerarCuotas($idInversion, true, false);
        $nuevas = $r['ok'] ? count($r['cuotas']) : 0;

        if ($obs) {
            $up = $mysql->prepare("UPDATE PlanPagos p INNER JOIN Inversiones i ON i.idInversion = p.idInversion
                SET p.Observaciones = ? WHERE p.idInversion = ? AND p.NroCuota = ? AND p.IdEstado <> ? AND i.IdUsuario = ?");
            foreach ($obs as $nro => $texto) { $up->bind_param('siiii', $texto, $idInversion, $nro, $cobrado, $uid); $up->execute(); }
        }
        // El número de cuotas del plan se mantiene al día con el plazo.
        $total = count(invCuotas($idInversion));
        $up = $mysql->prepare("UPDATE Inversiones SET NroCuotas = ? WHERE idInversion = ? AND IdUsuario = ?");
        $up->bind_param('iii', $total, $idInversion, $uid);
        $up->execute();
        $mysql->commit();
    } catch (Throwable $e) {
        $mysql->rollback();
        throw $e;
    }
    invRevisarLiquidacion($idInversion);
    return ['total' => $total, 'agregadas' => max(0, $nuevas - count($pendientesAntes)), 'eliminadas' => max(0, count($pendientesAntes) - $nuevas)];
}

// Si ya se recuperó todo el capital y no quedan cuotas por cobrar, la inversión pasa a Liquidado.
// Con $permitirReabrir, si estaba Liquidado y de nuevo hay capital por recuperar (se deshizo un cobro), vuelve a Desembolsado.
function invRevisarLiquidacion($idInversion, $permitirReabrir = false) {
    global $mysql, $uid;
    $inv = invCargar($idInversion);
    if (!$inv) return false;
    $cuotas = invCuotas($idInversion);
    $saldo = invSaldoCobrado($inv, $cuotas);
    $pendientes = 0;
    foreach ($cuotas as $c) if (!$c['cobrada']) $pendientes++;

    $liquidado = invEstadoId('Inversion', 'Liquidado');
    $desembolsado = invEstadoId('Inversion', 'Desembolsado');
    if ($inv['NombreEstado'] === 'Desembolsado' && $saldo <= 0.5 && $pendientes === 0 && (int)$inv['IdTipo'] !== INV_ACCIONES && $liquidado) {
        $stmt = $mysql->prepare("UPDATE Inversiones SET idEstado = ? WHERE idInversion = ? AND IdUsuario = ?");
        $stmt->bind_param('iii', $liquidado, $idInversion, $uid);
        $stmt->execute();
        return true;
    }
    // Solo se reabre cuando el usuario deshace un cobro: hay inversiones liquidadas con cuotas viejas sin marcar y no deben reabrirse solas.
    if ($permitirReabrir && $inv['NombreEstado'] === 'Liquidado' && $saldo > 0.5 && $desembolsado) {
        $stmt = $mysql->prepare("UPDATE Inversiones SET idEstado = ? WHERE idInversion = ? AND IdUsuario = ?");
        $stmt->bind_param('iii', $desembolsado, $idInversion, $uid);
        $stmt->execute();
    }
    return false;
}

// Resumen de una inversión a partir de sus cuotas (usado por la lista y el detalle).
function invResumen($inv, $cuotas, $hoy) {
    $capital = (float)$inv['CapitalInvertido'];
    $capitalCobrado = 0.0; $interesCobrado = 0.0; $dividendoCobrado = 0.0;
    $cobradas = 0; $pendientes = 0; $vencidas = 0; $maxAtraso = 0; $valorVencido = 0.0;
    $proxima = null; $ultimaFecha = null;
    foreach ($cuotas as $c) {
        $valor = (float)$c['InteresPagado'] + (float)$c['CapitalPagado'] + (float)$c['DividendoPagado'];
        if ($c['cobrada']) {
            $cobradas++;
            $capitalCobrado += (float)$c['CapitalPagado'];
            $interesCobrado += (float)$c['InteresPagado'];
            $dividendoCobrado += (float)$c['DividendoPagado'];
        } else {
            $pendientes++;
            $f = $c['FechaPrevistaPago'];
            if ($f !== null) {
                $dias = (int)(new DateTime($hoy))->diff(new DateTime($f))->format('%r%a');
                if ($dias < 0) { $vencidas++; $maxAtraso = max($maxAtraso, -$dias); $valorVencido += $valor; }
                if ($proxima === null || $f < $proxima['fecha']) $proxima = ['fecha' => $f, 'valor' => $valor, 'dias' => $dias];
            }
        }
        if ($c['FechaPrevistaPago'] !== null && ($ultimaFecha === null || $c['FechaPrevistaPago'] > $ultimaFecha)) $ultimaFecha = $c['FechaPrevistaPago'];
    }
    $saldo = max(0.0, round($capital - $capitalCobrado, 2));
    $activa = ($inv['NombreEstado'] === 'Desembolsado');
    $esAcciones = ((int)$inv['IdTipo'] === INV_ACCIONES);
    // Hace falta una cuota nueva si la inversión sigue activa, queda capital y no hay cuotas por cobrar futuras.
    $necesitaCuota = $activa && !$esAcciones && $saldo > 0.5 && ($pendientes === 0 || ($ultimaFecha !== null && $ultimaFecha <= $hoy));

    return [
        'idInversion' => (int)$inv['idInversion'],
        'nombre' => $inv['Nombre'],
        'idTipo' => (int)$inv['IdTipo'],
        'tipo' => $inv['NombreTipo'],
        'idEstado' => (int)$inv['idEstado'],
        'estado' => $inv['NombreEstado'],
        'capital' => $capital,
        'saldo' => $saldo,
        'capitalCobrado' => $capitalCobrado,
        'interesCobrado' => $interesCobrado,
        'dividendoCobrado' => $dividendoCobrado,
        'tasa' => (float)$inv['Interes'],
        'nroCuotas' => (int)$inv['NroCuotas'],
        'cuotaPactada' => (float)$inv['CuotaPactada'],
        'periodicidad' => (int)$inv['PeriodicidadPagoDividendos'],
        'fechaInicio' => invFecha($inv['FechaInicio']),
        'fechaFin' => invFecha($inv['FechaFin']),
        'cuotasTotal' => count($cuotas),
        'cuotasCobradas' => $cobradas,
        'cuotasPendientes' => $pendientes,
        'cuotasVencidas' => $vencidas,
        'diasMaxAtraso' => $maxAtraso,
        'valorVencido' => $valorVencido,
        'proximaFecha' => $proxima ? $proxima['fecha'] : null,
        'proximoValor' => $proxima ? $proxima['valor'] : null,
        'progreso' => $capital > 0 ? min(1.0, round($capitalCobrado / $capital, 4)) : 0.0,
        'rendimientoMensual' => $activa ? round($saldo * (float)$inv['Interes'] / 100) : 0.0,
        'necesitaCuota' => $necesitaCuota,
    ];
}

function invCuotaJson($c, $hoy) {
    $valor = (float)$c['InteresPagado'] + (float)$c['CapitalPagado'] + (float)$c['DividendoPagado'];
    $dias = null;
    if (!$c['cobrada'] && $c['FechaPrevistaPago'] !== null) {
        $dias = (int)(new DateTime($hoy))->diff(new DateTime($c['FechaPrevistaPago']))->format('%r%a');
    }
    return [
        'idPlan' => (int)$c['idPlan'],
        'idInversion' => (int)$c['idInversion'],
        'nroCuota' => (int)$c['NroCuota'],
        'fechaPrevista' => $c['FechaPrevistaPago'],
        'fechaReal' => $c['FechaRealPago'],
        'interes' => (float)$c['InteresPagado'],
        'capital' => (float)$c['CapitalPagado'],
        'dividendo' => (float)$c['DividendoPagado'],
        'valor' => $valor,
        'idEstado' => (int)$c['IdEstado'],
        'estado' => $c['NombreEstado'],
        'observaciones' => $c['Observaciones'] ?? null,
        'cobrada' => $c['cobrada'],
        'diasRestantes' => $dias,
        'vencida' => $dias !== null && $dias < 0,
    ];
}

// Resumen general del módulo: KPIs, cobros por mes, capital por tipo/estado y cada inversión con su avance.
// Lo usan Inversiones/Resumen.php y el dashboard de inicio.
function invCalcularResumen($hoy) {
    global $mysql, $uid;
    $mesActual = substr($hoy, 0, 7);
    $anhoActual = substr($hoy, 0, 4);

    $stmt = $mysql->prepare("SELECT i.*, t.Nombre AS NombreTipo, e.NombreEstado
        FROM Inversiones i
        LEFT JOIN TablaTipoInversion t ON t.idTipo = i.IdTipo
        LEFT JOIN estados e ON e.idEstado = i.idEstado AND e.IdUsuario = i.IdUsuario
        WHERE i.IdUsuario = ?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $inversiones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Todas las cuotas de una vez (evita una consulta por inversión).
    $porInversion = [];
    $stmt = $mysql->prepare("SELECT p.*, e.NombreEstado FROM PlanPagos p
        INNER JOIN Inversiones i ON i.idInversion = p.idInversion
        LEFT JOIN estados e ON e.idEstado = p.IdEstado AND e.IdUsuario = i.IdUsuario
        WHERE i.IdUsuario = ? ORDER BY p.idInversion, p.NroCuota, p.idPlan");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
        $f['FechaPrevistaPago'] = invFecha($f['FechaPrevistaPago']);
        $f['FechaRealPago'] = invFecha($f['FechaRealPago']);
        $f['cobrada'] = ($f['NombreEstado'] === 'Cobrado');
        $porInversion[(int)$f['idInversion']][] = $f;
    }

    $kpi = [
        'capitalActivo' => 0.0, 'capitalPrestado' => 0.0, 'rendimientoMensual' => 0.0,
        'porCobrar30' => 0.0, 'vencido' => 0.0, 'cuotasVencidas' => 0, 'inversionesAtrasadas' => 0,
        'interesMes' => 0.0, 'interesAnho' => 0.0, 'interesTotal' => 0.0,
        'activas' => 0, 'liquidadas' => 0, 'perdidas' => 0, 'necesitanCuota' => 0,
    ];
    $meses = [];
    for ($k = 11; $k >= 0; $k--) {
        $m = date('Y-m', strtotime("first day of -$k month", strtotime($hoy)));
        $meses[$m] = ['mes' => $m, 'interes' => 0.0, 'capital' => 0.0];
    }
    $porTipo = [];
    $porEstado = [];
    $lista = [];
    $limite30 = date('Y-m-d', strtotime('+30 days', strtotime($hoy)));

    foreach ($inversiones as $inv) {
        $cuotas = $porInversion[(int)$inv['idInversion']] ?? [];
        $r = invResumen($inv, $cuotas, $hoy);
        $lista[] = $r;

        $estado = $r['estado'] ?? 'Sin estado';
        if (!isset($porEstado[$estado])) $porEstado[$estado] = ['estado' => $estado, 'cantidad' => 0, 'capital' => 0.0];
        $porEstado[$estado]['cantidad']++;
        $porEstado[$estado]['capital'] += $r['capital'];

        if ($estado === 'Liquidado') $kpi['liquidadas']++;
        if ($estado === 'Perdida') $kpi['perdidas']++;
        if ($estado === 'Desembolsado') {
            $kpi['activas']++;
            $kpi['capitalActivo'] += $r['saldo'];
            $kpi['capitalPrestado'] += $r['capital'];
            $kpi['rendimientoMensual'] += $r['rendimientoMensual'];
            if ($r['necesitaCuota']) $kpi['necesitanCuota']++;
            $tipo = $r['tipo'] ?? 'Otro';
            if (!isset($porTipo[$tipo])) $porTipo[$tipo] = ['tipo' => $tipo, 'cantidad' => 0, 'capital' => 0.0];
            $porTipo[$tipo]['cantidad']++;
            $porTipo[$tipo]['capital'] += $r['saldo'];
        }

        $conAtraso = false;
        foreach ($cuotas as $c) {
            $valor = (float)$c['InteresPagado'] + (float)$c['CapitalPagado'] + (float)$c['DividendoPagado'];
            if ($c['cobrada']) {
                $interes = (float)$c['InteresPagado'] + (float)$c['DividendoPagado'];
                $fecha = $c['FechaRealPago'] ?: $c['FechaPrevistaPago'];
                $kpi['interesTotal'] += $interes;
                if ($fecha) {
                    if (substr($fecha, 0, 4) === $anhoActual) $kpi['interesAnho'] += $interes;
                    $m = substr($fecha, 0, 7);
                    if ($m === $mesActual) $kpi['interesMes'] += $interes;
                    if (isset($meses[$m])) { $meses[$m]['interes'] += $interes; $meses[$m]['capital'] += (float)$c['CapitalPagado']; }
                }
            } elseif ($estado === 'Desembolsado' && $c['FechaPrevistaPago']) {
                if ($c['FechaPrevistaPago'] < $hoy) { $kpi['vencido'] += $valor; $kpi['cuotasVencidas']++; $conAtraso = true; }
                if ($c['FechaPrevistaPago'] <= $limite30) $kpi['porCobrar30'] += $valor;
            }
        }
        // Una inversión con varias cuotas atrasadas cuenta una sola vez aquí (la lista de la app muestra inversiones, no cuotas).
        if ($conAtraso) $kpi['inversionesAtrasadas']++;
    }

    // Orden: activas primero, luego las que más atraso tienen y por nombre.
    $ordenEstado = ['Desembolsado' => 0, 'En proceso' => 1, 'Liquidado' => 2, 'Perdida' => 3];
    usort($lista, function ($a, $b) use ($ordenEstado) {
        $ea = $ordenEstado[$a['estado']] ?? 9; $eb = $ordenEstado[$b['estado']] ?? 9;
        if ($ea !== $eb) return $ea <=> $eb;
        if ($a['cuotasVencidas'] !== $b['cuotasVencidas']) return $b['cuotasVencidas'] <=> $a['cuotasVencidas'];
        return strcasecmp($a['nombre'], $b['nombre']);
    });

    return [
        'kpi' => $kpi,
        'meses' => array_values($meses),
        'porTipo' => array_values($porTipo),
        'porEstado' => array_values($porEstado),
        'inversiones' => $lista,
    ];
}
