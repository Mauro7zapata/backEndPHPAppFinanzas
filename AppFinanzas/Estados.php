<?php
require_once("db.php");

// Consultar Estados
function consultarEstados() {
    global $mysql;
    $query = "SELECT idEstado, TipoEstado, NombreEstado, ColorEstado FROM estados";
    $result = $mysql->query($query);

    $response = [];

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $response[] = [
                "idEstado" => $row['idEstado'],
                "TipoEstado" => $row['TipoEstado'],
                "NombreEstado" => $row['NombreEstado'],
                "ColorEstado" => $row['ColorEstado']
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

// Consultar Estados
function consultarEstadosId($id) {
    global $mysql;
    $query = "SELECT idEstado, TipoEstado, NombreEstado, ColorEstado 
                FROM estados 
                WHERE idEstado= ?";
       $stmt = $mysql->prepare($query);

    if ($stmt) {
        $stmt->bind_param("i", $id); // Asegúrate de pasar el ID como un entero

        $stmt->execute();
        $result = $stmt->get_result();

        $response = [];
        
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $response[] = [
                    "idEstado" => $row['idEstado'],
                    "TipoEstado" => $row['TipoEstado'],
                    "NombreEstado" => $row['NombreEstado'],
                    "ColorEstado" => $row['ColorEstado']
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
        echo "Error al preparar la consulta de estados: " . $mysql->error;
    } 
}

// Insertar Estado
function insertarEstado($data) {
    global $mysql;
    $query = "INSERT INTO estados (TipoEstado, NombreEstado, ColorEstado) VALUES (?, ?, ?)";
    $stmt = $mysql->prepare($query);
    $stmt->bind_param(
        "sss", 
        $data['TipoEstado'], 
        $data['NombreEstado'], 
        $data['ColorEstado']
    );

    if ($stmt->execute()) {
        echo "Estado insertado correctamente.";
    } else {
        echo "Error al insertar el estado: " . $mysql->error;
    }
}

// Editar Estado
function editarEstado($data) {
    global $mysql;
    $query = "UPDATE estados SET TipoEstado=?, NombreEstado=?, ColorEstado=? WHERE idEstado=?";
    $stmt = $mysql->prepare($query);
    $stmt->bind_param(
        "sssi", 
        $data['TipoEstado'], 
        $data['NombreEstado'], 
        $data['ColorEstado'],
        $data['id']
    );

    if ($stmt->execute()) {
        echo "Estado actualizado correctamente.";
    } else {
        echo "Error al actualizar el estado: " . $mysql->error;
    }
}

// Eliminar Estado
function eliminarEstado($id) {
    global $mysql;
    $query = "DELETE FROM estados WHERE idEstado=?";
    $stmt = $mysql->prepare($query);
    $stmt->bind_param(
        "i", 
        $id
    );

    if ($stmt->execute()) {
        echo "Estado eliminado correctamente.";
    } else {
        echo "Error al eliminar el estado: " . $mysql->error;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Verificar si la solicitud es JSON
    if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        // Solicitud JSON
        $data = json_decode(file_get_contents("php://input"), true);

        // Verificar si la data es un array
        if (is_array($data)) {
            foreach ($data as $item) {
                $accion = $item['accion']; // Acción (insertar, editar, eliminar)
                $tabla = "estados"; // Tabla para mantener consistencia

                // Procesar cada acción basada en el JSON recibido
                if ($accion == 'insertar') {
                    unset($item['accion']);  // Eliminar la acción para solo enviar los datos
                    unset($item['tabla']);   // Eliminar la tabla para solo enviar los datos
                    insertarEstado($item);   // Llamar a la función para insertar
                } elseif ($accion == 'editar') {
                    unset($item['accion']);  // Eliminar la acción
                    unset($item['tabla']);   // Eliminar la tabla
                    editarEstado($item);     // Llamar a la función para editar
                } elseif ($accion == 'eliminar') {
                    $id = $item['id'];  // Obtener el id del JSON
                    eliminarEstado($id); // Llamar a la función para eliminar
                }
            }
        } else {
            echo "La data no está en el formato correcto.";
        }
    } else {
        // Solicitud de formulario
        $accion = $_POST['accion'];
        $tabla = "estados";

        if ($accion == 'insertar') {
            $data = $_POST;
            unset($data['accion']);
            unset($data['tabla']);
            insertarEstado($data);
        } elseif ($accion == 'editar') {
            $data = $_POST;
            unset($data['accion']);
            unset($data['tabla']);
            editarEstado($data);
        } elseif ($accion == 'eliminar') {
            $id = $_POST['id'];
            eliminarEstado($id);
        }
    }
}  else if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    // Obtener el ID del parámetro GET
    if (isset($_GET['id']) && !empty($_GET['id'])) {
        $id = $_GET['id'];
        consultarEstadosId($id);
    } else {
        consultarEstados(); // Si no se pasa un ID, consultar todos los registros
    }
}    

// Cerrar conexión
$mysql->close();
?>
