<?php
require_once("../db.php");

header('Content-Type: application/json');

function procesarMovimiento($data) {
    global $mysql;

    $accion = isset($data['accion']) ? $data['accion'] : '';

    try {
        switch ($accion) {
            case 'crear':
                $stmt = $mysql->prepare("INSERT INTO movimientos (tipoMovimiento, valorMovimiento, nombreGasto, observacionMovimiento, fechaMovimiento, idGasto) VALUES (?, ?, ?, ?, ?, ?)");
                if (!$stmt) throw new Exception($mysql->error);
                $stmt->bind_param('sdsssi', $data['tipoMovimiento'], $data['valorMovimiento'], $data['nombreGasto'], $data['observacionMovimiento'], $data['fechaMovimiento'], $data['idGasto']);
                $stmt->execute();
                echo json_encode(['id' => $mysql->insert_id]);
                break;

            case 'actualizar':
                $stmt = $mysql->prepare("UPDATE movimientos SET tipoMovimiento = ?, valorMovimiento = ?, nombreGasto = ?, observacionMovimiento = ?, fechaMovimiento = ?, idGasto = ? WHERE idMovimiento = ?");
                if (!$stmt) throw new Exception($mysql->error);
                $stmt->bind_param('sdsssii', $data['tipoMovimiento'], $data['valorMovimiento'], $data['nombreGasto'], $data['observacionMovimiento'], $data['fechaMovimiento'], $data['idGasto'], $data['idMovimiento']);
                $stmt->execute();
                echo json_encode(['updated' => $stmt->affected_rows > 0]);
                break;

            case 'eliminar':
                $stmt = $mysql->prepare("DELETE FROM movimientos WHERE idMovimiento = ?");
                if (!$stmt) throw new Exception($mysql->error);
                $stmt->bind_param('i', $data['idMovimiento']);
                $stmt->execute();
                echo json_encode(['deleted' => $stmt->affected_rows > 0]);
                break;

            default:
                echo json_encode(['error' => 'Acción no válida']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function consultarMovimientos() {
    global $mysql;
    $query = "SELECT * FROM movimientos";
    $result = $mysql->query($query);

    if ($result) {
        $response = [];
        while ($row = $result->fetch_assoc()) {
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
    global $mysql;
    $query = "SELECT * FROM movimientos WHERE idMovimiento = ?";
    $stmt = $mysql->prepare($query);

    if ($stmt) {
        $stmt->bind_param("i", $id);
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
                    "idGasto" => $row['idGasto']
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
    global $mysql;
    $query = "SELECT m.* FROM movimientos m INNER JOIN gastos g ON m.idGasto = g.idGastos WHERE g.idPresupuesto = ?";
    $stmt = $mysql->prepare($query);

    if ($stmt) {
        $stmt->bind_param("i", $idPresupuesto);
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
                    "idGasto" => $row['idGasto']
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
        if (is_array($input)) {
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
    } else {
        consultarMovimientos();
    }
}
?>
