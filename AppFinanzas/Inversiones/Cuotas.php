<?php
require_once("../db.php");
require_once("../libInversiones.php");

header('Content-Type: application/json; charset=utf-8');

// Plan de cobros (cuotas) de una inversión.
//
//   GET  Cuotas.php?idInversion=N                       -> resumen de la inversión + sus cuotas
//   GET  Cuotas.php?idInversion=N&propuesta=anterior    -> propone la siguiente cuota copiando la última (no guarda)
//   GET  Cuotas.php?idInversion=N&propuesta=recalculada -> propone la siguiente cuota recalculada según el tipo (no guarda)
//   POST Cuotas.php (JSON) accion:
//        generar {idInversion, simular?}   crea en Pendiente todas las cuotas que faltan hasta que se pague (o solo las simula)
//        crear {idInversion, fechaPrevista, interes, capital, dividendo?}   agrega una cuota (p. ej. la propuesta, ya editada)
//        actualizar {idPlan, fechaPrevista, interes, capital, dividendo, observaciones?, recalcularSiguientes?}
//                                                                           corrige una cuota; si cambia la fecha y recalcularSiguientes=true,
//                                                                           las cuotas pendientes siguientes se corren mes a mes desde la nueva fecha
//        observar {idPlan, observaciones}                                   guarda (reemplaza) la observación de la cuota
//        cobrar {idPlan, fecha?, interes?, capital?, dividendo?, observaciones?}  la marca como cobrada
//        deshacer {idPlan}                                                  vuelve a Pendiente
//        eliminar {idPlan}

function numeroValor($d, $k, $defecto = 0.0) {
    $v = $d[$k] ?? null;
    if ($v === null || $v === '') return $defecto;
    return is_numeric($v) ? (float)$v : null;
}

// Cuota (con su inversión) solo si pertenece al usuario autenticado; null si no existe o es de otro usuario.
function cuotaDelUsuario($idPlan) {
    global $mysql, $uid;
    $q = $mysql->prepare("SELECT p.idPlan, p.idInversion, p.NroCuota, p.FechaPrevistaPago FROM PlanPagos p
        INNER JOIN Inversiones i ON i.idInversion = p.idInversion WHERE p.idPlan = ? AND i.IdUsuario = ?");
    $q->bind_param('ii', $idPlan, $uid);
    $q->execute();
    return $q->get_result()->fetch_assoc() ?: null;
}

function validarImportes($d) {
    foreach (['interes', 'capital', 'dividendo'] as $k) {
        $v = numeroValor($d, $k, 0.0);
        if ($v === null || $v < 0 || $v >= 10000000000) return "El valor de $k no es válido";
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = appfinanzas_entero($_GET['idInversion'] ?? null);
    if ($id === null) { echo json_encode(['error' => 'Falta idInversion']); exit; }
    $inv = invCargar($id);
    if (!$inv) { echo json_encode(['error' => 'La inversión no existe']); exit; }
    $cuotas = invCuotas($id);
    $hoy = date('Y-m-d');

    if (isset($_GET['propuesta'])) {
        $modo = $_GET['propuesta'] === 'anterior' ? 'anterior' : 'recalculada';
        $p = invProponerCuota($inv, $cuotas, $modo);
        echo json_encode($p['ok'] ? ['propuesta' => $p['cuota']] : ['error' => $p['mensaje']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'inversion' => invResumen($inv, $cuotas, $hoy),
        'cuotas' => array_map(function ($c) use ($hoy) { return invCuotaJson($c, $hoy); }, $cuotas),
        'aportes' => invAportes($id),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$d = json_decode(file_get_contents('php://input'), true);
if (!is_array($d)) $d = $_POST;

try {
    switch ($d['accion'] ?? '') {
        case 'generar':
            $id = appfinanzas_entero($d['idInversion'] ?? null);
            if ($id === null) { echo json_encode(['error' => 'Falta idInversion']); break; }
            $r = invGenerarCuotas($id, empty($d['simular']));
            if (!$r['ok']) { echo json_encode(['error' => $r['mensaje']], JSON_UNESCAPED_UNICODE); break; }
            echo json_encode(['creadas' => count($r['cuotas']), 'simulado' => !empty($d['simular']), 'cuotas' => $r['cuotas']], JSON_UNESCAPED_UNICODE);
            break;

        case 'crear':
            $id = appfinanzas_entero($d['idInversion'] ?? null);
            if ($id === null || !invCargar($id)) { echo json_encode(['error' => 'La inversión no existe']); break; }
            if (!appfinanzas_fecha_valida($d['fechaPrevista'] ?? '')) { echo json_encode(['error' => 'La fecha prevista no es válida (AAAA-MM-DD)']); break; }
            if ($e = validarImportes($d)) { echo json_encode(['error' => $e]); break; }
            $cuotas = invCuotas($id);
            $nro = 0; foreach ($cuotas as $c) $nro = max($nro, (int)$c['NroCuota']);
            $nro = isset($d['nroCuota']) && appfinanzas_entero($d['nroCuota']) ? appfinanzas_entero($d['nroCuota']) : $nro + 1;
            $interes = numeroValor($d, 'interes'); $capital = numeroValor($d, 'capital'); $dividendo = numeroValor($d, 'dividendo');
            $estado = invEstadoId('Pagos', 'Pendiente');
            $fecha = $d['fechaPrevista'];
            $stmt = $mysql->prepare("INSERT INTO PlanPagos (idInversion, NroCuota, FechaPrevistaPago, FechaRealPago, InteresPagado, CapitalPagado, DividendoPagado, IdEstado)
                SELECT i.idInversion, ?, ?, NULL, ?, ?, ?, ? FROM Inversiones i WHERE i.idInversion = ? AND i.IdUsuario = ?");
            $stmt->bind_param('isdddiii', $nro, $fecha, $interes, $capital, $dividendo, $estado, $id, $uid);
            $stmt->execute();
            echo json_encode(['id' => $mysql->insert_id, 'nroCuota' => $nro]);
            break;

        case 'actualizar':
            $idPlan = appfinanzas_entero($d['idPlan'] ?? null);
            if ($idPlan === null) { echo json_encode(['error' => 'Falta idPlan']); break; }
            if (!appfinanzas_fecha_valida($d['fechaPrevista'] ?? '')) { echo json_encode(['error' => 'La fecha prevista no es válida (AAAA-MM-DD)']); break; }
            if ($e = validarImportes($d)) { echo json_encode(['error' => $e]); break; }
            $interes = numeroValor($d, 'interes'); $capital = numeroValor($d, 'capital'); $dividendo = numeroValor($d, 'dividendo');
            $fecha = $d['fechaPrevista'];
            $fila = cuotaDelUsuario($idPlan);
            if (!$fila) { echo json_encode(['updated' => false]); break; }
            $stmt = $mysql->prepare("UPDATE PlanPagos p INNER JOIN Inversiones i ON i.idInversion = p.idInversion
                SET p.FechaPrevistaPago = ?, p.InteresPagado = ?, p.CapitalPagado = ?, p.DividendoPagado = ? WHERE p.idPlan = ? AND i.IdUsuario = ?");
            $stmt->bind_param('sdddii', $fecha, $interes, $capital, $dividendo, $idPlan, $uid);
            $stmt->execute();
            if (array_key_exists('observaciones', $d)) {
                $obs = invObservacion($d['observaciones']);
                $st = $mysql->prepare("UPDATE PlanPagos p INNER JOIN Inversiones i ON i.idInversion = p.idInversion
                    SET p.Observaciones = ? WHERE p.idPlan = ? AND i.IdUsuario = ?");
                $st->bind_param('sii', $obs, $idPlan, $uid); $st->execute();
            }
            // Si se movió la fecha, las pendientes siguientes se corren mes a mes desde la nueva fecha (las cobradas no se tocan).
            $corridas = 0;
            if (!empty($d['recalcularSiguientes']) && $fila['FechaPrevistaPago'] !== $fecha) {
                $cobrado = invEstadoId('Pagos', 'Cobrado');
                $idInv = (int)$fila['idInversion']; $nro = (int)$fila['NroCuota'];
                $sg = $mysql->prepare("SELECT p.idPlan, p.NroCuota FROM PlanPagos p INNER JOIN Inversiones i ON i.idInversion = p.idInversion
                    WHERE p.idInversion = ? AND p.NroCuota > ? AND p.IdEstado <> ? AND i.IdUsuario = ? ORDER BY p.NroCuota, p.idPlan");
                $sg->bind_param('iiii', $idInv, $nro, $cobrado, $uid); $sg->execute();
                $siguientes = $sg->get_result()->fetch_all(MYSQLI_ASSOC);
                $up = $mysql->prepare("UPDATE PlanPagos p INNER JOIN Inversiones i ON i.idInversion = p.idInversion
                    SET p.FechaPrevistaPago = ? WHERE p.idPlan = ? AND i.IdUsuario = ?");
                $k = 0;
                foreach ($siguientes as $s) {
                    $k++;
                    $nueva = invSumarMeses($fecha, $k);
                    $idp = (int)$s['idPlan'];
                    $up->bind_param('sii', $nueva, $idp, $uid); $up->execute();
                    $corridas++;
                }
            }
            invRevisarLiquidacion((int)$fila['idInversion']);
            echo json_encode(['updated' => true, 'siguientesRecalculadas' => $corridas]);
            break;

        case 'cobrar':
            $idPlan = appfinanzas_entero($d['idPlan'] ?? null);
            if ($idPlan === null) { echo json_encode(['error' => 'Falta idPlan']); break; }
            $fecha = $d['fecha'] ?? null;
            if ($fecha !== null && $fecha !== '' && !appfinanzas_fecha_valida($fecha)) { echo json_encode(['error' => 'La fecha de cobro no es válida (AAAA-MM-DD)']); break; }
            $interes = ($d['interes'] ?? null) === null ? null : numeroValor($d, 'interes');
            $capital = ($d['capital'] ?? null) === null ? null : numeroValor($d, 'capital');
            $dividendo = ($d['dividendo'] ?? null) === null ? null : numeroValor($d, 'dividendo');
            foreach ([$interes, $capital, $dividendo] as $v) {
                if ($v !== null && ($v < 0 || $v >= 10000000000)) { echo json_encode(['error' => 'Un importe no es válido']); exit; }
            }
            $r = invCobrarCuota($idPlan, $fecha ?: null, $interes, $capital, $dividendo, $d['observaciones'] ?? null);
            echo json_encode($r === null ? ['error' => 'La cuota no existe'] : $r);
            break;

        case 'observar':
            $idPlan = appfinanzas_entero($d['idPlan'] ?? null);
            if ($idPlan === null) { echo json_encode(['error' => 'Falta idPlan']); break; }
            if (!cuotaDelUsuario($idPlan)) { echo json_encode(['error' => 'La cuota no existe']); break; }
            $obs = invObservacion($d['observaciones'] ?? null);
            $stmt = $mysql->prepare("UPDATE PlanPagos p INNER JOIN Inversiones i ON i.idInversion = p.idInversion
                SET p.Observaciones = ? WHERE p.idPlan = ? AND i.IdUsuario = ?");
            $stmt->bind_param('sii', $obs, $idPlan, $uid);
            $stmt->execute();
            echo json_encode(['updated' => true]);
            break;

        case 'deshacer':
            $idPlan = appfinanzas_entero($d['idPlan'] ?? null);
            if ($idPlan === null) { echo json_encode(['error' => 'Falta idPlan']); break; }
            $fila = cuotaDelUsuario($idPlan);
            if ($fila) {
                $estado = invEstadoId('Pagos', 'Pendiente');
                $stmt = $mysql->prepare("UPDATE PlanPagos p INNER JOIN Inversiones i ON i.idInversion = p.idInversion
                    SET p.IdEstado = ?, p.FechaRealPago = NULL WHERE p.idPlan = ? AND i.IdUsuario = ?");
                $stmt->bind_param('iii', $estado, $idPlan, $uid);
                $stmt->execute();
            }
            if ($fila) invRevisarLiquidacion((int)$fila['idInversion'], true);
            echo json_encode(['updated' => (bool)$fila]);
            break;

        case 'eliminar':
            $idPlan = appfinanzas_entero($d['idPlan'] ?? null);
            if ($idPlan === null) { echo json_encode(['error' => 'Falta idPlan']); break; }
            $fila = cuotaDelUsuario($idPlan);
            $stmt = $mysql->prepare("DELETE p FROM PlanPagos p INNER JOIN Inversiones i ON i.idInversion = p.idInversion
                WHERE p.idPlan = ? AND i.IdUsuario = ?");
            $stmt->bind_param('ii', $idPlan, $uid);
            $stmt->execute();
            if ($fila) invRevisarLiquidacion((int)$fila['idInversion']);
            echo json_encode(['deleted' => $stmt->affected_rows > 0]);
            break;

        default:
            echo json_encode(['error' => 'Acción no válida']);
    }
} catch (mysqli_sql_exception $e) {
    error_log('[AppFinanzas] Cuotas: ' . $e->getMessage());
    echo json_encode(['error' => 'Error al procesar la acción']);
}
