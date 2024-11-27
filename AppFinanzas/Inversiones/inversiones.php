<?php
require_once("../db.php");

function procesarAccion($data) {
    global $mysql;;

    $accion = isset($data['accion']) ? $data['accion'] : '';

    switch ($accion) {
        case 'crear':
            $stmt = $mysql;->prepare("INSERT INTO Inversiones (Nombre, IdTipo, CapitalInvertido, FechaInicio, FechaFin, Interes, NroCuotas, CuotaPactada, PeriodicidadPagoDividendos, idEstado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('sidsdsdsii', 
                $data['Nombre'], 
                $data['IdTipo'], 
                $data['CapitalInvertido'], 
                $data['FechaInicio'], 
                $data['FechaFin'], 
                $data['Interes'], 
                $data['NroCuotas'], 
                $data['CuotaPactada'], 
                $data['PeriodicidadPagoDividendos'], 
                $data['idEstado']
            );
            $stmt->execute();
            echo json_encode(['id' => $mysql;->insert_id]);
            break;

        case 'actualizar':
            $stmt = $mysql;->prepare("UPDATE Inversiones SET Nombre=?, IdTipo=?, CapitalInvertido=?, FechaInicio=?, FechaFin=?, Interes=?, NroCuotas=?, CuotaPactada=?, PeriodicidadPagoDividendos=?, idEstado=? WHERE idInversion=?");
            $stmt->bind_param('sidsdsdsiii', 
                $data['Nombre'], 
                $data['IdTipo'], 
                $data['CapitalInvertido'], 
                $data['FechaInicio'], 
                $data['FechaFin'], 
                $data['Interes'], 
                $data['NroCuotas'], 
                $data['CuotaPactada'], 
                $data['PeriodicidadPagoDividendos'], 
                $data['idEstado'], 
                $data['idInversion']
            );
            $stmt->execute();
            echo json_encode(['updated' => $stmt->affected_rows > 0]);
            break;

        case 'eliminar':
            $stmt = $mysql;->prepare("DELETE FROM Inversiones WHERE idInversion=?");
            $stmt->bind_param('i', $data['idInversion']);
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
                procesarAccion($data);
            }
        } else {
            procesarAccion($input);
        }
    } else {
        procesarAccion($_POST);
    }
} else if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    if (isset($_GET['id']) && !empty($_GET['id'])) {
        $stmt = $mysql;->prepare("SELECT * FROM Inversiones WHERE idInversion=?");
        $stmt->bind_param('i', $_GET['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        echo json_encode($result->fetch_assoc());
    } else {
        $result = $mysql;->query("SELECT * FROM Inversiones");
        echo json_encode($result->fetch_all(mysql;_ASSOC));
    }
}
?>
