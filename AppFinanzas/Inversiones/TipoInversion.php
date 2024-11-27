<?php
require_once __DIR__ . '/../db.php';

echo "Conexión establecida";

function procesarTipoInversion($data) {
    global $mysqli;

    $accion = isset($data['accion']) ? $data['accion'] : '';

    switch ($accion) {
        case 'crear':
            $stmt = $mysqli->prepare("INSERT INTO TablaTipoInversion (Nombre,Descripcion) VALUES (?,?)");
            $stmt->bind_param('ss', $data['Nombre'],$data['Descripcion']);
            $stmt->execute();
            echo json_encode(['id' => $mysqli->insert_id]);
            break;

        case 'actualizar':
            $stmt = $mysqli->prepare("UPDATE TablaTipoInversion SET Nombre=?, Descripcion=? WHERE idTipo=?");
            $stmt->bind_param('ssi', $data['Nombre'],$data['Descripcion'], $data['idTipo']);
            $stmt->execute();
            echo json_encode(['updated' => $stmt->affected_rows > 0]);
            break;

        case 'eliminar':
            $stmt = $mysqli->prepare("DELETE FROM TablaTipoInversion WHERE idTipo=?");
            $stmt->bind_param('i', $data['idTipo']);
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
                procesarTipoInversion($data);
            }
        } else {
            procesarTipoInversion($input);
        }
    } else {
        procesarTipoInversion($_POST);
    }
} else if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    if (isset($_GET['id']) && !empty($_GET['id'])) {
        $stmt = $mysqli->prepare("SELECT * FROM TablaTipoInversion WHERE idTipo=?");
        $stmt->bind_param('i', $_GET['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        echo json_encode($result->fetch_assoc());
    } else {
        $result = $mysqli->query("SELECT * FROM TablaTipoInversion");
        echo json_encode($result->fetch_all(MYSQLI_ASSOC));
    }
}
?>
