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
    $query = "INSERT INTO estados (TipoEstado, NombreEstado, ColorEstado) VALUES ('".$data['TipoEstado']."', '".$data['NombreEstado']."', '".$data['ColorEstado']."')";
    if ($mysql->query($query) === TRUE) {
        echo "Estado insertado correctamente.";
    } else {
        echo "Error al insertar el estado: " . $mysql->error;
    }
}

// Editar Estado
function editarEstado($data) {
    global $mysql;
    $query = "UPDATE estados SET TipoEstado='".$data['TipoEstado']."', NombreEstado='".$data['NombreEstado']."', ColorEstado='".$data['ColorEstado']."' WHERE idEstado='".$data['id']."'";
    if ($mysql->query($query) === TRUE) {
        echo "Estado actualizado correctamente.";
    } else {
        echo "Error al actualizar el estado: " . $mysql->error;
    }
}

// Eliminar Estado
function eliminarEstado($id) {
    global $mysql;
    $query = "DELETE FROM estados WHERE idEstado='$id'";
    if ($mysql->query($query) === TRUE) {
        echo "Estado eliminado correctamente.";
    } else {
        echo "Error al eliminar el estado: " . $mysql->error;
    }
}

// Procesar acciones basadas en el formulario
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
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
