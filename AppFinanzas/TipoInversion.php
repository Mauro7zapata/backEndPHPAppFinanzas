<?php
require_once("db.php");

echo "Conexion realizada";

header('Content-Type: application/json');

function procesarTipoInversion($data) {
    global $mysql;

    $accion = isset($data['accion']) ? $data['accion'] : '';

    try {
        switch ($accion) {
            case 'crear':
                $stmt = $mysql->prepare("INSERT INTO TablaTipoInversion (Nombre, Descripcion) VALUES (?, ?)");
                if (!$stmt) throw new Exception($mysql->error);
                $stmt->bind_param('ss', $data['Nombre'], $data['Descripcion']);
                $stmt->execute();
                echo json_encode(['id' => $mysql->insert_id]);
                break;

            case 'actualizar':
                $stmt = $mysql->prepare("UPDATE TablaTipoInversion SET Nombre = ?, Descripcion = ? WHERE idTipo = ?");
                if (!$stmt) throw new Exception($mysql->error);
                $stmt->bind_param('ssi', $data['Nombre'], $data['Descripcion'], $data['idTipo']);
                $stmt->execute();
                echo json_encode(['updated' => $stmt->affected_rows > 0]);
                break;

            case 'eliminar':
                $stmt = $mysql->prepare("DELETE FROM TablaTipoInversion WHERE idTipo = ?");
                if (!$stmt) throw new Exception($mysql->error);
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
    global $mysql;
    $query = "SELECT * FROM TablaTipoInversion";
    $result = $mysql->query($query);

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
        echo json_encode(['error' => $mysql->error]);
    }
}

function consultarTipoInversionId($id) {
    global $mysql;
    $query = "SELECT * FROM TablaTipoInversion WHERE idTipo = ?";
    $stmt = $mysql->prepare($query);

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
        echo json_encode(['error' => $mysql->error]);
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    $contentType = isset($_SERVER["CONTENT_TYPE"]) ? $_SERVER["CONTENT_TYPE"] : '';

    if (strpos($contentType, "application/json") !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (is_array($input)) {
            foreach ($input as $data) {
                procesarTipoInversion($data);
            }
        } else {
            procesarTipoInversion($input);
        }
    } else {
        procesarTipoInversion($_POST);
    }
}elseif ($_SERVER['REQUEST_METHOD'] == 'GET') {
    if (isset($_GET['id']) && !empty($_GET['id'])) {
        consultarTipoInversionId($_GET['id']);
    } else {
        consultarTipoInversion();
    }
}
?>
