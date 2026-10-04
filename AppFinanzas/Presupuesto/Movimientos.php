<?php
require_once("../db.php");
require_once("../lib.php");

header('Content-Type: application/json');

// Valida los datos de un movimiento. Devuelve un mensaje de error o null si es válido.
function validarMovimiento($data) {
    if (trim($data['tipoMovimiento'] ?? '') === '') {
        return 'El tipo de movimiento es obligatorio';
    }
    if (!is_numeric($data['valorMovimiento'] ?? null) || $data['valorMovimiento'] == 0
        || abs($data['valorMovimiento']) >= 100000000) {
        return 'El valor del movimiento no es válido';
    }
    if (trim($data['nombreGasto'] ?? '') === '') {
        return 'El nombre del gasto es obligatorio';
    }
    if (!appfinanzas_fecha_valida($data['fechaMovimiento'] ?? '')) {
        return 'La fecha del movimiento no es válida (use AAAA-MM-DD)';
    }
    if (appfinanzas_entero($data['idGasto'] ?? null) === null) {
        return 'El gasto asociado no es válido';
    }
    return null;
}

// Condición de pertenencia: movimientos -> gastos -> presupuestos.IdUsuario (los movimientos no tienen dueño propio).
const SQL_MOVIMIENTO_PROPIO = "FROM movimientos m INNER JOIN gastos g ON g.idGastos = m.idGasto
                INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto";

function procesarMovimiento($data) {
    global $mysql, $uid;

    $accion = isset($data['accion']) ? $data['accion'] : '';

    try {
        switch ($accion) {
            case 'crear':
                if ($error = validarMovimiento($data)) { echo json_encode(['error' => $error]); break; }
                // El gasto debe ser del usuario (no se revela si existe para otro usuario).
                if (!appfinanzas_gasto_es_propio($data['idGasto'])) { echo json_encode(['error' => 'El gasto asociado no existe']); break; }
                $stmt = $mysql->prepare("INSERT INTO movimientos (tipoMovimiento, valorMovimiento, nombreGasto, observacionMovimiento, fechaMovimiento, idGasto) VALUES (?, ?, ?, ?, ?, ?)");
                if (!$stmt) throw new Exception($mysql->error);
                $stmt->bind_param('sdsssi', $data['tipoMovimiento'], $data['valorMovimiento'], $data['nombreGasto'], $data['observacionMovimiento'], $data['fechaMovimiento'], $data['idGasto']);
                $stmt->execute();
                $idNuevo = $mysql->insert_id;
                sincronizarGasto($data['idGasto']);
                sincronizarAbonoMovimiento($idNuevo);
                echo json_encode(['id' => $idNuevo]);
                break;

            case 'actualizar':
                if ($error = validarMovimiento($data)) { echo json_encode(['error' => $error]); break; }
                // Gasto anterior: el movimiento podría moverse a otro gasto. Debe ser un movimiento del usuario.
                $gastoAnterior = null;
                $q = $mysql->prepare("SELECT m.idGasto " . SQL_MOVIMIENTO_PROPIO . " WHERE m.idMovimiento = ? AND p.IdUsuario = ?");
                $q->bind_param('ii', $data['idMovimiento'], $uid);
                $q->execute();
                if ($fila = $q->get_result()->fetch_assoc()) $gastoAnterior = $fila['idGasto'];
                // Movimiento ajeno o inexistente: misma respuesta que un id que no existe.
                if ($gastoAnterior === null) { echo json_encode(['updated' => false]); break; }
                // El gasto destino también debe ser del usuario.
                if (!appfinanzas_gasto_es_propio($data['idGasto'])) { echo json_encode(['error' => 'El gasto asociado no existe']); break; }
                // Los triggers de movimientos actualizan gastos, así que MySQL no permite usar gastos (JOIN/subconsulta) en este
                // mismo UPDATE: la propiedad del movimiento ya quedó verificada arriba con el SELECT por usuario.
                $stmt = $mysql->prepare("UPDATE movimientos SET tipoMovimiento = ?, valorMovimiento = ?, nombreGasto = ?, observacionMovimiento = ?, fechaMovimiento = ?, idGasto = ? WHERE idMovimiento = ?");
                if (!$stmt) throw new Exception($mysql->error);
                $stmt->bind_param('sdsssii', $data['tipoMovimiento'], $data['valorMovimiento'], $data['nombreGasto'], $data['observacionMovimiento'], $data['fechaMovimiento'], $data['idGasto'], $data['idMovimiento']);
                $stmt->execute();
                $actualizado = $stmt->affected_rows > 0;
                sincronizarGasto($data['idGasto']);
                sincronizarAbonoMovimiento($data['idMovimiento']);
                if ($gastoAnterior !== null && $gastoAnterior != $data['idGasto']) sincronizarGasto($gastoAnterior);
                echo json_encode(['updated' => $actualizado]);
                break;

            case 'eliminar':
                $gastoAnterior = null;
                $q = $mysql->prepare("SELECT m.idGasto " . SQL_MOVIMIENTO_PROPIO . " WHERE m.idMovimiento = ? AND p.IdUsuario = ?");
                $q->bind_param('ii', $data['idMovimiento'], $uid);
                $q->execute();
                if ($fila = $q->get_result()->fetch_assoc()) $gastoAnterior = $fila['idGasto'];
                // Movimiento ajeno o inexistente: misma respuesta que un id que no existe.
                if ($gastoAnterior === null) { echo json_encode(['deleted' => false]); break; }
                // (Ver nota en 'actualizar': el trigger impide usar gastos en el DELETE; la propiedad ya se verificó arriba.)
                $stmt = $mysql->prepare("DELETE FROM movimientos WHERE idMovimiento = ?");
                if (!$stmt) throw new Exception($mysql->error);
                $stmt->bind_param('i', $data['idMovimiento']);
                $stmt->execute();
                $eliminado = $stmt->affected_rows > 0;
                if ($gastoAnterior !== null) sincronizarGasto($gastoAnterior);
                echo json_encode(['deleted' => $eliminado]);
                break;

            default:
                echo json_encode(['error' => 'Acción no válida']);
        }
    } catch (mysqli_sql_exception $e) {
        error_log('[AppFinanzas] procesarMovimiento: ' . $e->getMessage());
        // 1452: el gasto asociado no existe
        echo json_encode(['error' => $e->getCode() == 1452 ? 'El gasto asociado no existe' : 'Error al procesar el movimiento']);
    } catch (Exception $e) {
        error_log('[AppFinanzas] procesarMovimiento: ' . $e->getMessage());
        echo json_encode(['error' => 'Error al procesar el movimiento']);
    }
}

function consultarMovimientos() {
    global $mysql, $uid;
    $query = "SELECT m.* " . SQL_MOVIMIENTO_PROPIO . " WHERE p.IdUsuario = ?";
    $stmt = $mysql->prepare($query);
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        $response = [];
        while ($row = appfinanzas_fila_texto($result->fetch_assoc())) {
            $response[] = [
                "idMovimiento" => $row['idMovimiento'],
                "tipoMovimiento" => $row['tipoMovimiento'],
                "valorMovimiento" => $row['valorMovimiento'],
                "nombreGasto" => $row['nombreGasto'],
                "observacionMovimiento" => $row['observacionMovimiento'],
                "fechaMovimiento" => $row['fechaMovimiento'],
                "idGasto" => $row['idGasto']
            ];
        }
        echo json_encode($response);
    } else {
        echo json_encode(['error' => $mysql->error]);
    }
}

function consultarMovimientoId($id) {
    global $mysql, $uid;
    $query = "SELECT m.*,g.idCategoria " . SQL_MOVIMIENTO_PROPIO . " WHERE m.idMovimiento = ? AND p.IdUsuario = ?";
    $stmt = $mysql->prepare($query);

    if ($stmt) {
        $stmt->bind_param("ii", $id, $uid);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $response = [];
            while ($row = $result->fetch_assoc()) {
                $response[] = [
                    "idMovimiento" => $row['idMovimiento'],
                    "tipoMovimiento" => $row['tipoMovimiento'],
                    "valorMovimiento" => $row['valorMovimiento'],
                    "nombreGasto" => $row['nombreGasto'],
                    "observacionMovimiento" => $row['observacionMovimiento'],
                    "fechaMovimiento" => $row['fechaMovimiento'],
                    "idGasto" => $row['idGasto'],
                    "idCategoria"=> $row['idCategoria']
                ];
            }
            echo json_encode($response);
        } else {
            echo json_encode([]);
        }
    } else {
        echo json_encode(['error' => $mysql->error]);
    }
}

function consultarMovimientosPorPresupuesto($idPresupuesto) {
    global $mysql, $uid;
    $query = "SELECT m.*,g.idCategoria " . SQL_MOVIMIENTO_PROPIO . " WHERE g.idPresupuesto = ? AND p.IdUsuario = ?";
    $stmt = $mysql->prepare($query);

    if ($stmt) {
        $stmt->bind_param("ii", $idPresupuesto, $uid);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $response = [];
            while ($row = $result->fetch_assoc()) {
                $response[] = [
                    "idMovimiento" => $row['idMovimiento'],
                    "tipoMovimiento" => $row['tipoMovimiento'],
                    "valorMovimiento" => $row['valorMovimiento'],
                    "nombreGasto" => $row['nombreGasto'],
                    "observacionMovimiento" => $row['observacionMovimiento'],
                    "fechaMovimiento" => $row['fechaMovimiento'],
                    "idGasto" => $row['idGasto'],
                    "idCategoria"=> $row['idCategoria']
                ];
            }
            echo json_encode($response);
        } else {
            echo json_encode([]);
        }
    } else {
        echo json_encode(['error' => $mysql->error]);
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $contentType = isset($_SERVER["CONTENT_TYPE"]) ? $_SERVER["CONTENT_TYPE"] : '';

    if (strpos($contentType, "application/json") !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (is_array($input) && !isset($input['accion'])) {
            foreach ($input as $data) {
                procesarMovimiento($data);
            }
        } else {
            procesarMovimiento($input);
        }
    } else {
        procesarMovimiento($_POST);
    }
} elseif ($_SERVER['REQUEST_METHOD'] == 'GET') {
    if (isset($_GET['id']) && !empty($_GET['id'])) {
        consultarMovimientoId($_GET['id']);
    }elseif (isset($_GET['idPresupuesto']) && !empty($_GET['idPresupuesto'])) {
        consultarMovimientosPorPresupuesto($_GET['idPresupuesto']);     
    } else {
        consultarMovimientos();
    }
}
?>
