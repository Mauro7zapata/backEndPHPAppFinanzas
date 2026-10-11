<?php
require_once("../db.php");
require_once(__DIR__ . '/../lib.php');

header('Content-Type: application/json; charset=utf-8');

// Ingresos extra de un presupuesto, con su concepto (migración 020). presupuestos.ExtrasMes se mantiene como la suma.
//
//   GET  IngresosExtra.php?idPresupuesto=N -> {ingresos:[{idIngresoExtra, concepto, valor, fecha}], total}
//   POST (JSON) accion: crear {idPresupuesto, concepto, valor, fecha?} | eliminar {idIngresoExtra}
//        Responde {ingresos, total} actualizados.

define('MSG_MIGRACION_020', 'Falta ejecutar la migración 020 en la base de datos');

function extrasLista($idPresupuesto) {
    global $mysql, $uid;
    $q = $mysql->prepare("SELECT idIngresoExtra, Concepto, Valor, Fecha FROM presupuesto_ingresos_extra
        WHERE idPresupuesto = ? AND IdUsuario = ? ORDER BY COALESCE(Fecha, DATE(FechaCreacion)) DESC, idIngresoExtra DESC");
    $q->bind_param('ii', $idPresupuesto, $uid);
    $q->execute();
    $total = 0;
    $lista = [];
    foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
        $total += (int)$f['Valor'];
        $lista[] = ['idIngresoExtra' => (int)$f['idIngresoExtra'], 'concepto' => $f['Concepto'], 'valor' => (int)$f['Valor'], 'fecha' => $f['Fecha']];
    }
    return ['ingresos' => $lista, 'total' => $total];
}

// Deja presupuestos.ExtrasMes igual a la suma del detalle y devuelve la lista.
function extrasSincronizar($idPresupuesto) {
    global $mysql, $uid;
    $r = extrasLista($idPresupuesto);
    $t = $r['total'];
    $u = $mysql->prepare("UPDATE presupuestos SET ExtrasMes = ? WHERE idPresupuesto = ? AND IdUsuario = ?");
    $u->bind_param('iii', $t, $idPresupuesto, $uid);
    $u->execute();
    return $r;
}

function extrasProcesar($d) {
    global $mysql, $uid;
    switch ($d['accion'] ?? '') {
        case 'crear':
            $idp = appfinanzas_entero($d['idPresupuesto'] ?? null);
            if ($idp === null || !appfinanzas_es_propio('presupuestos', $idp)) { echo json_encode(['error' => 'No se encontró el presupuesto']); return; }
            $concepto = mb_substr(trim((string)($d['concepto'] ?? '')), 0, 120, 'UTF-8');
            if (mb_strlen($concepto, 'UTF-8') < 2) { echo json_encode(['error' => 'Escribe de dónde salió el ingreso (ej. Prima, Venta…)']); return; }
            $valor = appfinanzas_entero($d['valor'] ?? null);
            if ($valor === null || $valor <= 0) { echo json_encode(['error' => 'El valor debe ser mayor que 0']); return; }
            $fecha = trim((string)($d['fecha'] ?? ''));
            if ($fecha === '') $fecha = null;
            elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) { echo json_encode(['error' => 'La fecha no es válida']); return; }
            $ins = $mysql->prepare("INSERT INTO presupuesto_ingresos_extra (idPresupuesto, IdUsuario, Concepto, Valor, Fecha) VALUES (?, ?, ?, ?, ?)");
            $ins->bind_param('iisis', $idp, $uid, $concepto, $valor, $fecha);
            $ins->execute();
            echo json_encode(extrasSincronizar($idp));
            break;

        case 'eliminar':
            $id = appfinanzas_entero($d['idIngresoExtra'] ?? null);
            if ($id === null) { echo json_encode(['error' => 'Identificador no válido']); return; }
            $q = $mysql->prepare("SELECT idPresupuesto FROM presupuesto_ingresos_extra WHERE idIngresoExtra = ? AND IdUsuario = ?");
            $q->bind_param('ii', $id, $uid);
            $q->execute();
            $f = $q->get_result()->fetch_assoc();
            if (!$f) { echo json_encode(['error' => 'No se encontró el ingreso']); return; }
            $del = $mysql->prepare("DELETE FROM presupuesto_ingresos_extra WHERE idIngresoExtra = ? AND IdUsuario = ?");
            $del->bind_param('ii', $id, $uid);
            $del->execute();
            echo json_encode(extrasSincronizar((int)$f['idPresupuesto']));
            break;

        default:
            echo json_encode(['error' => 'Acción no válida']);
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        extrasProcesar(is_array($input) ? $input : $_POST);
    } else {
        $idp = appfinanzas_entero($_GET['idPresupuesto'] ?? null);
        if ($idp === null || !appfinanzas_es_propio('presupuestos', $idp)) { echo json_encode(['error' => 'No se encontró el presupuesto']); exit; }
        echo json_encode(extrasLista($idp));
    }
} catch (mysqli_sql_exception $ex) {
    error_log('[AppFinanzas] IngresosExtra.php: ' . $ex->getMessage());
    echo json_encode(['error' => (int)$ex->getCode() === 1146 ? MSG_MIGRACION_020 : 'Error al procesar el ingreso extra']);
}
