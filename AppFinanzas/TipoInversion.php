<?php
require_once 'db.php';

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

// Consultar Categorías de Gastos
function consultarTipoInversion() {
    global $mysql;
    $query = "SELECT * FROM TablaTipoInversion";
    $result = $mysql->query($query);
    
    $response = [];
    
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $response[] = [
                "idTipo" => $row['idTipo'],
                "Nombre" => $row['Nombre'],
                "Descripcion" => $row['Descripcion']
            ];
        }
        // Retornar la respuesta como JSON
        header('Content-Type: application/json');
        echo json_encode($response);
    } else {
        // Retornar un JSON vacío si no hay registros
        header('Content-Type: application/json');
        echo json_encode([]);
    }
}

// Consultar Categorías de Gastos
function consultarTipoInversionId($id) {
    global $mysql;
    $query = "SELECT * FROM TablaTipoInversion 
                WHERE idTipo = ?";
    $stmt = $mysql->prepare($query);

    if ($stmt) {
        $stmt->bind_param("i", $id); // Asegúrate de pasar el ID como un entero

        $stmt->execute();
        $result = $stmt->get_result();
    
        $response = [];
        
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $response[] = [
                    "idTipo" => $row['idTipo'],
                    "Nombre" => $row['Nombre'],
                    "Descripcion" => $row['Descripcion']
                ];
            }
            // Retornar la respuesta como JSON
            header('Content-Type: application/json');
            echo json_encode($response);
        } else {
            // Retornar un JSON vacío si no hay registros
            header('Content-Type: application/json');
            echo json_encode([]);
        }
    } else {
        echo "Error al preparar la consulta de Categoria: " . $mysql->error;
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
        consultarTipoInversionId($_GET['id'])
    } else {
        consultarTipoInversion()
    }
}
?>
