<?php
require_once("db.php");

// Consultar Categorías de Gastos
function consultarCategoriasGastos() {
    global $mysql;
    $query = "SELECT * FROM categoriagastos";
    $result = $mysql->query($query);
    
    $response = [];
    
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $response[] = [
                "idCategoriaGastos" => $row['idCategoriaGastos'],
                "NombreCategoria" => $row['NombreCategoria'],
                "ColorCategoria" => $row['ColorCategoria'],
                "ImagenCategoria" => $row['ImagenCategoria']
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
function consultarCategoriasGastosId($id) {
    global $mysql;
    $query = "SELECT * FROM categoriagastos 
                WHERE idCategoriaGastos = ?";
    $stmt = $mysql->prepare($query);

    if ($stmt) {
        $stmt->bind_param("i", $id); // Asegúrate de pasar el ID como un entero

        $stmt->execute();
        $result = $stmt->get_result();
    
        $response = [];
        
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $response[] = [
                    "idCategoriaGastos" => $row['idCategoriaGastos'],
                    "NombreCategoria" => $row['NombreCategoria'],
                    "ColorCategoria" => $row['ColorCategoria'],
                    "ImagenCategoria" => $row['ImagenCategoria']
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

// Insertar Categorías de Gastos
function insertarCategoriaGastos($data) {
    global $mysql;
    $query = "INSERT INTO categoriagastos (NombreCategoria, ColorCategoria, ImagenCategoria) VALUES ('".$data['NombreCategoria']."', '".$data['ColorCategoria']."', '".$data['ImagenCategoria']."')";
    if ($mysql->query($query) === TRUE) {
        echo "Categoría de gasto insertada correctamente.";
    } else {
        echo "Error al insertar la categoría de gasto: " . $mysql->error;
    }
}

// Editar Categorías de Gastos
function editarCategoriaGastos($data) {
    global $mysql;
    $query = "UPDATE categoriagastos SET NombreCategoria='".$data['NombreCategoria']."', ColorCategoria='".$data['ColorCategoria']."', ImagenCategoria='".$data['ImagenCategoria']."' WHERE idCategoriaGastos='".$data['id']."'";
    if ($mysql->query($query) === TRUE) {
        echo "Categoría de gasto actualizada correctamente.";
    } else {
        echo "Error al actualizar la categoría de gasto: " . $mysql->error;
    }
}

// Eliminar Categorías de Gastos
function eliminarCategoriaGastos($id) {
    global $mysql;
    $query = "DELETE FROM categoriagastos WHERE idCategoriaGastos='$id'";
    if ($mysql->query($query) === TRUE) {
        echo "Categoría de gasto eliminada correctamente.";
    } else {
        echo "Error al eliminar la categoría de gasto: " . $mysql->error;
    }
}

// Procesar acciones basadas en el formulario
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $accion = $_POST['accion'];
    $tabla = "categoriasgastos";

    if ($accion == 'insertar') {
        $data = $_POST;
        unset($data['accion']);
        unset($data['tabla']);
        insertarCategoriaGastos($data);
    } elseif ($accion == 'editar') {
        $data = $_POST;
        unset($data['accion']);
        unset($data['tabla']);
        editarCategoriaGastos($data);
    } elseif ($accion == 'eliminar') {
        $id = $_POST['id'];
        eliminarCategoriaGastos($id);
    }
}else if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    // Obtener el ID del parámetro GET
    if (isset($_GET['id']) && !empty($_GET['id'])) {
        $id = $_GET['id'];
        consultarCategoriasGastosId($id);
    } else {
        consultarCategoriasGastos(); // Si no se pasa un ID, consultar todos los registros
    }
} 

// Cerrar conexión
$mysql->close();
?>
