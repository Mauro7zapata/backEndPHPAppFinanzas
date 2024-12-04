<?php
require_once("../db.php");

echo "conecto";

function procesarAccion($data) {
    global $mysql;

    $accion = isset($data['accion']) ? $data['accion'] : '';

    try {

        switch ($accion) {
            case 'crear':
                echo "va a crear";
                $stmt = $mysql->prepare("INSERT INTO Inversiones (Nombre, IdTipo, CapitalInvertido, FechaInicio, FechaFin, Interes, NroCuotas, CuotaPactada, PeriodicidadPagoDividendos, idEstado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('sidssdidii', 
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
                echo json_encode(['id' => $mysql->insert_id]);
                break;

            case 'actualizar':
                $stmt = $mysql->prepare("UPDATE Inversiones SET Nombre=?, IdTipo=?, CapitalInvertido=?, FechaInicio=?, FechaFin=?, Interes=?, NroCuotas=?, CuotaPactada=?, PeriodicidadPagoDividendos=?, idEstado=? WHERE idInversion=?");
                $stmt->bind_param('sidssdidiii', 
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
                $stmt = $mysql->prepare("DELETE FROM Inversiones WHERE idInversion=?");
                $stmt->bind_param('i', $data['idInversion']);
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

function consultarInversiones() {
    global $mysql;
    $query = "SELECT * FROM Inversiones";
    $result = $mysql->query($query);

    $response = [];

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $response[] = [
               "idInversion" => $row['idInversion'],
                "Nombre" => $row['Nombre'],
                "IdTipo" => $row['IdTipo'],
                "FechaInicio" => $row['FechaInicio'],
                "FechaFin" => $row['FechaFin'],
                "Interes" => $row['Interes'],
                "NroCuotas" => $row['NroCuotas'],
                "CuotaPactada" => $row['CuotaPactada'],
                "PeriodicidadPagoDividendos" => $row['PeriodicidadPagoDividendos'],
                "CapitalInvertido" => $row['CapitalInvertido'],
                "idEstado" => $row['idEstado']
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

function consultarInversionId($id) {
    global $mysql;
    $query = "SELECT * FROM Inversiones WHERE idInversion=?";
    $stmt = $mysql->prepare($query);
    if ($stmt) {
        $stmt->bind_param("i", $id); // Asegúrate de pasar el ID como un entero

        $stmt->execute();
        $result = $stmt->get_result();
    
        $response = [];

        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $response[] = [
                "idInversion" => $row['idInversion'],
                    "Nombre" => $row['Nombre'],
                    "IdTipo" => $row['IdTipo'],
                    "FechaInicio" => $row['FechaInicio'],
                    "FechaFin" => $row['FechaFin'],
                    "Interes" => $row['Interes'],
                    "NroCuotas" => $row['NroCuotas'],
                    "CuotaPactada" => $row['CuotaPactada'],
                    "PeriodicidadPagoDividendos" => $row['PeriodicidadPagoDividendos'],
                    "CapitalInvertido" => $row['CapitalInvertido'],
                    "idEstado" => $row['idEstado']
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
    } else {
        echo "Error al preparar la consulta de Inversiones: " . $mysql->error;
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
        $id = $_GET['id'];
        consultarInversionId($id);
    } else {
        consultarInversiones();
    }
}
?>
