<?php
require_once("db.php");

header('Content-Type: application/json');

function procesarTipoInversion($data) {
    global $mysqli;

    $accion = isset($data['accion']) ? $data['accion'] : '';

    try {
        switch ($accion) {
            case 'crear':
                $stmt = $mysqli->prepare("INSERT INTO TablaTipoInversion (Nombre, Descripcion) VALUES (?, ?)");
                if (!$stmt) throw new Exception($mysqli->error);
                $stmt->bind_param('ss', $data['Nombre'], $data['Descripcion']);
                $stmt->execute();
                echo json_encode(['id' => $mysqli->insert_id]);
                break;

            case 'actualizar':
                $stmt = $mysqli->prepare("UPDATE TablaTipoInversion SET Nombre = ?, Descripcion = ? WHERE idTipo = ?");
                if (!$stmt) throw new Exception($mysqli->error);
                $stmt->bind_param('ssi', $data['Nombre'], $data['Descripcion'], $data['idTipo']);
                $stmt->execute();
                echo json_encode(['updated' => $stmt->affected_rows > 0]);
                break;

            case 'eliminar':
                $stmt = $mysqli->prepare("DELETE FROM TablaTipoInversion WHERE idTipo = ?");
                if (!$stmt) throw new Exception($mysqli->error);
                $stmt->bind_param('i', $data['idTipo']);
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

function consultarTipoInversion() {
    global $mysqli;
    $query = "SELECT * FROM TablaTipoInversion";
    $result = $mysqli->query($query);

    if ($result) {
        $response = [];
        while ($row = $result->fetch_assoc()) {
            $response[] = [
                "idTipo" => $row['idTipo'],
                "Nombre" => $row['Nombre'],
                "Descripcion" => $row['Descripcion']
            ];
        }
        echo json_encode($response);
    } else {
        echo json_encode(['error' => $mysqli->error]);
    }
}

function consultarTipoInversionId($id) {
    global $mysqli;
    $query = "SELECT * FROM TablaTipoInversion WHERE idTipo = ?";
    $stmt = $mysqli->prepare($query);

    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            echo json_encode($result->fetch_assoc());
        } else {
            echo json_encode([]);
        }
    } else {
        echo json_encode(['error' => $mysqli->error]);
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $contentType = isset($_SERVER["CONTENT_TYPE"]) ? $_SERVER["CONTENT_TYPE"] : '';

    if (strpos($contentType, "application/json") !== false) {
        $input = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() === JSON_ERROR_NONE) {
            procesarTipoInversion($input);
        } else {
            echo json_encode(['error' => 'JSON inválido']);
        }
    } else {
        procesarTipoInversion($_POST);
    }
} elseif ($_SERVER['REQUEST_METHOD'] == 'GET') {
    if (isset($_GET['id']) && !empty($_GET['id'])) {
        consultarTipoInversionId($_GET['id']);
    } else {
        consultarTipoInversion();
    }
}
?>
