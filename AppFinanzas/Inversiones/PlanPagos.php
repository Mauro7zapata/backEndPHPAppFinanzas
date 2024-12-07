<?php
require_once("../db.php");

function procesarPlanPagos($data) {
    global $mysql;

    $accion = isset($data['accion']) ? $data['accion'] : '';

    switch ($accion) {
        case 'crear':
            $stmt = $mysql->prepare("INSERT INTO PlanPagos (idInversion, NroCuota, FechaPrevistaPago, FechaRealPago, InteresPagado, CapitalPagado, DividendoPagado, idEstado) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                echo json_encode(['error' => $mysql->error]);
                return;
            }
            $stmt->bind_param('iissdddi', 
                $data['idInversion'], 
                $data['NroCuota'], 
                $data['FechaPrevistaPago'], 
                $data['FechaRealPago'], 
                $data['InteresPagado'], 
                $data['CapitalPagado'], 
                $data['DividendoPagado'],
                $data['idEstado'])
            $stmt->execute();
            echo json_encode(['id' => $mysql->insert_id]);
            break;

        case 'actualizar':
            $stmt = $mysql->prepare("UPDATE PlanPagos SET idInversion=?, NroCuota=?, FechaPrevistaPago=?, FechaRealPago=?, InteresPagado=?, CapitalPagado=?, DividendoPagado=?,idEstado=? WHERE idPlan=?");
            if (!$stmt) {
                echo json_encode(['error' => $mysql->error]);
                return;
            }
            $stmt->bind_param('iissdddii', 
                $data['idInversion'], 
                $data['NroCuota'], 
                $data['FechaPrevistaPago'], 
                $data['FechaRealPago'], 
                $data['InteresPagado'], 
                $data['CapitalPagado'], 
                $data['DividendoPagado'],
                $data['idEstado'], 
                $data['idPlan']
            );
            $stmt->execute();
            echo json_encode(['updated' => $stmt->affected_rows > 0]);
            break;

        case 'eliminar':
            $stmt = $mysql->prepare("DELETE FROM PlanPagos WHERE idPlan=?");
            if (!$stmt) {
                echo json_encode(['error' => $mysql->error]);
                return;
            }
            $stmt->bind_param('i', $data['idPlan']);
            $stmt->execute();
            echo json_encode(['deleted' => $stmt->affected_rows > 0]);
            break;

        default:
            echo json_encode(['error' => 'Acción no válida']);
    }
}

function consultarPagosPorInversion($idInversion) {
    global $mysql;
    $query = "SELECT * FROM PlanPagos WHERE idInversion=?";
    $stmt = $mysql->prepare($query);
    if (!$stmt) {
        echo json_encode(['error' => $mysql->error]);
        return;
    }
    $stmt->bind_param("i", $idInversion);
    $stmt->execute();
    $result = $stmt->get_result();
    $response = [];

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $response[] = [
                "idPlan" => $row['idPlan'],
                "idInversion" => $row['idInversion'],
                "NroCuota" => $row['NroCuota'],
                "FechaPrevistaPago" => $row['FechaPrevistaPago'],
                "FechaRealPago" => $row['FechaRealPago'],
                "InteresPagado" => $row['InteresPagado'],
                "CapitalPagado" => $row['CapitalPagado'],
                "DividendoPagado" => $row['DividendoPagado'],
                "idEstado" => $row['IdEstado']
            ];
        }
        // Retornar los resultados como JSON
        header('Content-Type: application/json');
        echo json_encode($response);
    } else {
        // Retornar un JSON vacío si no hay registros
        header('Content-Type: application/json');
        echo json_encode([]);
    }
}

function consultarPagoPorId($id) {
    global $mysql;
    $query = "SELECT * FROM PlanPagos WHERE idPlan=?";
    $stmt = $mysql->prepare($query);
    if (!$stmt) {
        echo json_encode(['error' => $mysql->error]);
        return;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $response = [];

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $response[] = [
                "idPlan" => $row['idPlan'],
                "idInversion" => $row['idInversion'],
                "NroCuota" => $row['NroCuota'],
                "FechaPrevistaPago" => $row['FechaPrevistaPago'],
                "FechaRealPago" => $row['FechaRealPago'],
                "InteresPagado" => $row['InteresPagado'],
                "CapitalPagado" => $row['CapitalPagado'],
                "DividendoPagado" => $row['DividendoPagado'],
                "idEstado" => $row['IdEstado']
            ];
        }
        // Retornar los resultados como JSON
        header('Content-Type: application/json');
        echo json_encode($response);
    } else {
        // Retornar un JSON vacío si no hay registros
        header('Content-Type: application/json');
        echo json_encode([]);
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $contentType = isset($_SERVER["CONTENT_TYPE"]) ? $_SERVER["CONTENT_TYPE"] : '';

    if (strpos($contentType, "application/json") !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (is_array($input)) {
            foreach ($input as $data) {
                procesarPlanPagos($data);
            }
        } else {
            procesarPlanPagos($input);
        }
    } else { 
        procesarPlanPagos($_POST);
    }
} else if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    if (isset($_GET['id']) && !empty($_GET['id'])) {
        $id = $_GET['id'];
        consultarPagoPorId($id);
    } else if (isset($_GET['idInversion']) && !empty($_GET['idInversion'])) {
        $id = $_GET['idInversion'];
        consultarPagosPorInversion($id);
    }  else {
        echo "No se envio ningun parametro o no es valido";
    }
}
?>
