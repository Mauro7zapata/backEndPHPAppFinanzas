<?php
require_once("../db.php");

function procesarPlanPagos($data) {
    global $mysql;;

    $accion = isset($data['accion']) ? $data['accion'] : '';

    switch ($accion) {
        case 'crear':
            $stmt = $mysql;->prepare("INSERT INTO PlanPagos (idInversion, NroCuota, FechaPrevistaPago, FechaRealPago, InteresPagado, CapitalPagado, DividendoPagado) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('iissddd', 
                $data['idInversion'], 
                $data['NroCuota'], 
                $data['FechaPrevistaPago'], 
                $data['FechaRealPago'], 
                $data['InteresPagado'], 
                $data['CapitalPagado'], 
                $data['DividendoPagado']
            );
            $stmt->execute();
            echo json_encode(['id' => $mysql;->insert_id]);
            break;

        case 'actualizar':
            $stmt = $mysql;->prepare("UPDATE PlanPagos SET idInversion=?, NroCuota=?, FechaPrevistaPago=?, FechaRealPago=?, InteresPagado=?, CapitalPagado=?, DividendoPagado=? WHERE idPlan=?");
            $stmt->bind_param('iissdddi', 
                $data['idInversion'], 
                $data['NroCuota'], 
                $data['FechaPrevistaPago'], 
                $data['FechaRealPago'], 
                $data['InteresPagado'], 
                $data['CapitalPagado'], 
                $data['DividendoPagado'], 
                $data['idPlan']
            );
            $stmt->execute();
            echo json_encode(['updated' => $stmt->affected_rows > 0]);
            break;

        case 'eliminar':
            $stmt = $mysql;->prepare("DELETE FROM PlanPagos WHERE idPlan=?");
            $stmt->bind_param('i', $data['idPlan']);
            $stmt->execute();
            echo json_encode(['deleted' => $stmt->affected_rows > 0]);
            break;

        default:
            echo json_encode(['error' => 'Acción no válida']);
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
        $stmt = $mysql;->prepare("SELECT * FROM PlanPagos WHERE idPlan=?");
        $stmt->bind_param('i', $_GET['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        echo json_encode($result->fetch_assoc());
    } else {
        $result = $mysql;->query("SELECT * FROM PlanPagos");
        echo json_encode($result->fetch_all(mysql;_ASSOC));
    }
}
?>
