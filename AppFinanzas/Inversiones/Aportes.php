<?php
require_once("../db.php");
require_once("../libInversiones.php");

header('Content-Type: application/json; charset=utf-8');

// Aportes de capital a una inversión (dinero adicional que se pone después del desembolso inicial).
//   GET  Aportes.php?idInversion=N  -> {aportes: [{idAporte, fecha, valor, observaciones}], total}
//   POST Aportes.php (JSON) accion:
//        crear {idInversion, fecha, valor, observaciones?}   suma el valor al capital de la inversión
//        actualizar {idAporte, fecha, valor, observaciones?} corrige el aporte y ajusta el capital por la diferencia
//        eliminar {idAporte}                                 resta el valor del capital

function aporteDelUsuario($idAporte) {
    global $mysql, $uid;
    $q = $mysql->prepare("SELECT * FROM aportes_inversion WHERE idAporte = ? AND IdUsuario = ?");
    $q->bind_param('ii', $idAporte, $uid);
    $q->execute();
    return $q->get_result()->fetch_assoc() ?: null;
}

function validarAporte($d) {
    $v = $d['valor'] ?? null;
    if ($v === null || $v === '' || !is_numeric($v) || (float)$v <= 0 || (float)$v >= 10000000000) return 'El valor del aporte debe ser mayor que cero';
    if (!appfinanzas_fecha_valida($d['fecha'] ?? '')) return 'La fecha no es válida (AAAA-MM-DD)';
    return null;
}

// Suma $delta al capital de la inversión; rechaza si el capital quedaría por debajo de lo ya recuperado.
function ajustarCapital($idInversion, $delta) {
    global $mysql, $uid;
    $inv = invCargar($idInversion);
    if (!$inv) return 'La inversión no existe';
    $nuevo = round((float)$inv['CapitalInvertido'] + $delta, 2);
    $cobrado = 0.0;
    foreach (invCuotas($idInversion) as $c) if ($c['cobrada']) $cobrado += (float)$c['CapitalPagado'];
    if ($nuevo <= 0 || $nuevo < $cobrado - 0.5) return 'No se puede: el capital quedaría por debajo de lo que ya recuperaste';
    $q = $mysql->prepare("UPDATE Inversiones SET CapitalInvertido = ? WHERE idInversion = ? AND IdUsuario = ?");
    $q->bind_param('dii', $nuevo, $idInversion, $uid);
    $q->execute();
    return null;
}

function aporteJson($f) {
    return ['idAporte' => (int)$f['idAporte'], 'fecha' => $f['Fecha'], 'valor' => (float)$f['Valor'], 'observaciones' => $f['Observaciones']];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = appfinanzas_entero($_GET['idInversion'] ?? null);
    if ($id === null || !invCargar($id)) { echo json_encode(['error' => 'La inversión no existe']); exit; }
    echo json_encode(invAportes($id), JSON_UNESCAPED_UNICODE);
    exit;
}

$d = json_decode(file_get_contents('php://input'), true);
if (!is_array($d)) $d = $_POST;

try {
    switch ($d['accion'] ?? '') {
        case 'crear':
            $id = appfinanzas_entero($d['idInversion'] ?? null);
            $inv = $id === null ? null : invCargar($id);
            if (!$inv) { echo json_encode(['error' => 'La inversión no existe']); break; }
            if ($inv['NombreEstado'] !== 'Desembolsado') { echo json_encode(['error' => 'Solo se puede agregar capital a una inversión activa']); break; }
            if ($e = validarAporte($d)) { echo json_encode(['error' => $e]); break; }
            $valor = round((float)$d['valor'], 2); $fecha = $d['fecha'];
            $obs = invObservacion($d['observaciones'] ?? null);
            $mysql->begin_transaction();
            try {
                if ($e = ajustarCapital($id, $valor)) { $mysql->rollback(); echo json_encode(['error' => $e], JSON_UNESCAPED_UNICODE); break; }
                $q = $mysql->prepare("INSERT INTO aportes_inversion (idInversion, IdUsuario, Fecha, Valor, Observaciones) VALUES (?, ?, ?, ?, ?)");
                $q->bind_param('iisds', $id, $uid, $fecha, $valor, $obs);
                $q->execute();
                $nuevoId = $mysql->insert_id;
                $mysql->commit();
            } catch (Throwable $e) { $mysql->rollback(); throw $e; }
            echo json_encode(['id' => $nuevoId]);
            break;

        case 'actualizar':
            $a = aporteDelUsuario(appfinanzas_entero($d['idAporte'] ?? null) ?? 0);
            if (!$a) { echo json_encode(['error' => 'El aporte no existe']); break; }
            if (!empty($a['idPlan'])) { echo json_encode(['error' => 'Este aporte es la reinversión de un dividendo: cámbialo desde el dividendo (cuota)'], JSON_UNESCAPED_UNICODE); break; }
            if ($e = validarAporte($d)) { echo json_encode(['error' => $e]); break; }
            $valor = round((float)$d['valor'], 2); $fecha = $d['fecha'];
            $obs = invObservacion($d['observaciones'] ?? null);
            $idAporte = (int)$a['idAporte'];
            $mysql->begin_transaction();
            try {
                if ($e = ajustarCapital((int)$a['idInversion'], $valor - (float)$a['Valor'])) { $mysql->rollback(); echo json_encode(['error' => $e], JSON_UNESCAPED_UNICODE); break; }
                $q = $mysql->prepare("UPDATE aportes_inversion SET Fecha = ?, Valor = ?, Observaciones = ? WHERE idAporte = ? AND IdUsuario = ?");
                $q->bind_param('sdsii', $fecha, $valor, $obs, $idAporte, $uid);
                $q->execute();
                $mysql->commit();
            } catch (Throwable $e) { $mysql->rollback(); throw $e; }
            echo json_encode(['updated' => true]);
            break;

        case 'eliminar':
            $a = aporteDelUsuario(appfinanzas_entero($d['idAporte'] ?? null) ?? 0);
            if (!$a) { echo json_encode(['error' => 'El aporte no existe']); break; }
            if (!empty($a['idPlan'])) { echo json_encode(['error' => 'Este aporte es la reinversión de un dividendo: cámbialo desde el dividendo (cuota)'], JSON_UNESCAPED_UNICODE); break; }
            $idAporte = (int)$a['idAporte'];
            $mysql->begin_transaction();
            try {
                if ($e = ajustarCapital((int)$a['idInversion'], -(float)$a['Valor'])) { $mysql->rollback(); echo json_encode(['error' => $e], JSON_UNESCAPED_UNICODE); break; }
                $q = $mysql->prepare("DELETE FROM aportes_inversion WHERE idAporte = ? AND IdUsuario = ?");
                $q->bind_param('ii', $idAporte, $uid);
                $q->execute();
                $mysql->commit();
            } catch (Throwable $e) { $mysql->rollback(); throw $e; }
            echo json_encode(['deleted' => true]);
            break;

        default:
            echo json_encode(['error' => 'Acción no válida']);
    }
} catch (mysqli_sql_exception $e) {
    error_log('[AppFinanzas] Aportes.php: ' . $e->getMessage());
    echo json_encode(['error' => 'Error al procesar la acción']);
}
