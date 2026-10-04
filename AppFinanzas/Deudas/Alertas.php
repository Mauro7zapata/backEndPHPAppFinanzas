<?php
require_once("../db.php");
require_once("../lib.php");
require_once("../libInversiones.php");

header('Content-Type: application/json; charset=utf-8');

// Alertas de pagos para las notificaciones del celular.
//
//   GET  Alertas.php[?dias=3&diasObligaciones=30]
//        -> pagos por vencer o vencidos (gastos Pendiente/En proceso con fecha límite), obligaciones
//           anuales próximas a vencer y cortes de tarjeta próximos.
//   (pagarGasto y cobrarCuota aceptan además 'observaciones': nota opcional que escribe el usuario en la notificación)
//   POST Alertas.php {accion:'pagarGasto', idGasto}        -> registra un movimiento por lo que falta del gasto
//   POST Alertas.php {accion:'pagarObligacion', idObligacion} -> cierra el ciclo (vencimiento +1 año)
//   POST Alertas.php {accion:'cobrarCuota', idPlan}           -> marca como cobrada una cuota de una inversión (tipo 'cobro')
//
// Una alerta de pago desaparece sola cuando el gasto pasa a Pagado (o la obligación avanza de ciclo):
// la app mantiene la notificación fija hasta entonces.

function alertas($dias, $diasObligaciones) {
    global $mysql;
    $salida = [];
    $hoy = new DateTime('today');

    // Pagos del presupuesto: vencen en los próximos $dias o vencieron hace menos de 60 días.
    $stmt = $mysql->prepare("SELECT g.idGastos, g.NombreGasto, g.CostoPrevisto, g.valorGastosMovimiento, g.FechaLimite, g.idDeuda, e.NombreEstado, pr.Mes, pr.Anho
        FROM gastos g INNER JOIN estados e ON e.idEstado = g.IdEstado
        LEFT JOIN presupuestos pr ON pr.idPresupuesto = g.idPresupuesto
        WHERE e.NombreEstado IN ('Pendiente','En proceso') AND g.FechaLimite IS NOT NULL
          AND g.FechaLimite <= ? AND g.FechaLimite >= ?
        ORDER BY g.FechaLimite");
    $hasta = date('Y-m-d', strtotime("+$dias days"));
    $desde = date('Y-m-d', strtotime('-60 days'));
    $stmt->bind_param('ss', $hasta, $desde);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $g) {
        $falta = round((float)$g['CostoPrevisto'] - (float)$g['valorGastosMovimiento'], 2);
        $d = (int)$hoy->diff(new DateTime($g['FechaLimite']))->format('%r%a');
        $salida[] = [
            'clave' => 'gasto-' . $g['idGastos'],
            'tipo' => 'gasto',
            'id' => (int)$g['idGastos'],
            'titulo' => $g['NombreGasto'],
            'fecha' => $g['FechaLimite'],
            'diasRestantes' => $d,
            'vencida' => $d < 0,
            'valor' => $falta,
            'esDeuda' => $g['idDeuda'] !== null,
            'mes' => $g['Mes'] !== null ? (int)$g['Mes'] : null,
            'anho' => $g['Anho'] !== null ? (int)$g['Anho'] : null,
            'persistente' => true,
        ];
    }

    // Cobros de inversiones: cuotas por cobrar que vencen en los próximos $dias o vencieron hace menos de 60 días.
    $stmt = $mysql->prepare("SELECT p.idPlan, p.idInversion, p.NroCuota, p.FechaPrevistaPago, p.InteresPagado, p.CapitalPagado, p.DividendoPagado, i.Nombre
        FROM PlanPagos p
        INNER JOIN Inversiones i ON i.idInversion = p.idInversion
        INNER JOIN estados ei ON ei.idEstado = i.idEstado AND ei.NombreEstado = 'Desembolsado'
        LEFT JOIN estados ep ON ep.idEstado = p.IdEstado
        WHERE (ep.NombreEstado IS NULL OR ep.NombreEstado <> 'Cobrado')
          AND p.FechaPrevistaPago IS NOT NULL AND p.FechaPrevistaPago <= ? AND p.FechaPrevistaPago >= ?
        ORDER BY p.FechaPrevistaPago LIMIT 25");
    $stmt->bind_param('ss', $hasta, $desde);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $c) {
        $d = (int)$hoy->diff(new DateTime($c['FechaPrevistaPago']))->format('%r%a');
        $salida[] = [
            'clave' => 'cobro-' . $c['idPlan'],
            'tipo' => 'cobro',
            'id' => (int)$c['idPlan'],
            'idInversion' => (int)$c['idInversion'],
            'titulo' => trim($c['Nombre']) . ' · cuota ' . $c['NroCuota'],
            'fecha' => $c['FechaPrevistaPago'],
            'diasRestantes' => $d,
            'vencida' => $d < 0,
            'valor' => (float)$c['InteresPagado'] + (float)$c['CapitalPagado'] + (float)$c['DividendoPagado'],
            'esDeuda' => false,
            'persistente' => true,
        ];
    }

    // Obligaciones anuales por vencer.
    $stmt = $mysql->prepare("SELECT idObligacion, Nombre, ValorEstimado, FechaVencimiento, CicloInicio FROM obligaciones
        WHERE Activa = 1 AND FechaVencimiento <= ? ORDER BY FechaVencimiento");
    $hastaObl = date('Y-m-d', strtotime("+$diasObligaciones days"));
    $stmt->bind_param('s', $hastaObl);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $o) {
        $d = (int)$hoy->diff(new DateTime($o['FechaVencimiento']))->format('%r%a');
        $salida[] = [
            'clave' => 'obligacion-' . $o['idObligacion'],
            'tipo' => 'obligacion',
            'id' => (int)$o['idObligacion'],
            'titulo' => $o['Nombre'],
            'fecha' => $o['FechaVencimiento'],
            'diasRestantes' => $d,
            'vencida' => $d < 0,
            'valor' => (float)$o['ValorEstimado'],
            'ahorrado' => ahorradoObligacion($o['idObligacion'], $o['CicloInicio']),
            'esDeuda' => false,
            'persistente' => true,
        ];
    }

    // Corte de tarjeta en los próximos 3 días (informativa, se puede descartar).
    $res = $mysql->query("SELECT idDeuda, Nombre, DiaCorte FROM deudas WHERE Activa = 1 AND Tipo = 'Tarjeta' AND DiaCorte IS NOT NULL");
    foreach ($res->fetch_all(MYSQLI_ASSOC) as $t) {
        $f = proximaFechaDia($t['DiaCorte']);
        $d = (int)$hoy->diff(new DateTime($f))->format('%r%a');
        if ($d <= 3) {
            $salida[] = [
                'clave' => 'corte-' . $t['idDeuda'] . '-' . str_replace('-', '', $f),
                'tipo' => 'corte', 'id' => (int)$t['idDeuda'], 'titulo' => 'Corte de ' . $t['Nombre'],
                'fecha' => $f, 'diasRestantes' => $d, 'vencida' => false, 'valor' => null,
                'esDeuda' => true, 'persistente' => false,
            ];
        }
    }
    echo json_encode($salida, JSON_UNESCAPED_UNICODE);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true);
    if (!is_array($d)) $d = $_POST;
    try {
        switch ($d['accion'] ?? '') {
            case 'pagarGasto':
                $id = appfinanzas_entero($d['idGasto'] ?? null);
                if ($id === null) { echo json_encode(['error' => 'Identificador no válido']); break; }
                $valor = registrarPagoGasto($id, $d['observaciones'] ?? null);
                echo json_encode($valor === null ? ['error' => 'El gasto no existe'] : ['pagado' => $valor]);
                break;
            case 'cobrarCuota':
                $id = appfinanzas_entero($d['idPlan'] ?? null);
                if ($id === null) { echo json_encode(['error' => 'Identificador no válido']); break; }
                $r = invCobrarCuota($id, null, null, null, null, $d['observaciones'] ?? null);
                echo json_encode($r === null ? ['error' => 'La cuota no existe'] : $r);
                break;
            case 'pagarObligacion':
                $id = appfinanzas_entero($d['idObligacion'] ?? null);
                if ($id === null) { echo json_encode(['error' => 'Identificador no válido']); break; }
                $ciclo = date('Y-m-01', strtotime('first day of next month'));
                $stmt = $mysql->prepare("UPDATE obligaciones SET FechaVencimiento = DATE_ADD(FechaVencimiento, INTERVAL 1 YEAR), CicloInicio = ? WHERE idObligacion = ?");
                $stmt->bind_param('si', $ciclo, $id);
                $stmt->execute();
                echo json_encode(['updated' => $stmt->affected_rows > 0]);
                break;
            default:
                echo json_encode(['error' => 'Acción no válida']);
        }
    } catch (mysqli_sql_exception $e) {
        error_log('[AppFinanzas] Alertas: ' . $e->getMessage());
        echo json_encode(['error' => 'Error al procesar la acción']);
    }
} else {
    $dias = min(30, max(0, appfinanzas_entero($_GET['dias'] ?? null) ?? parametroApp('dias_aviso_pagos')));
    $diasObl = min(120, max(0, appfinanzas_entero($_GET['diasObligaciones'] ?? null) ?? parametroApp('dias_aviso_obligaciones')));
    alertas($dias, $diasObl);
}
