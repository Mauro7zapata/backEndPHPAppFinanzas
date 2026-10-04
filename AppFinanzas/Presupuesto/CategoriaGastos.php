<?php
require_once("../db.php");
require_once(__DIR__ . '/../lib.php');

// Consultar Categorías de Gastos
function consultarCategoriasGastos() {
    global $mysql, $uid;
    $query = "SELECT * FROM categoriagastos WHERE IdUsuario = ?";
    $stmt = $mysql->prepare($query);
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $response = [];
    
    if ($result->num_rows > 0) {
        while ($row = appfinanzas_fila_texto($result->fetch_assoc())) {
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
    global $mysql, $uid;
    $query = "SELECT * FROM categoriagastos 
                WHERE idCategoriaGastos = ? AND IdUsuario = ?";
    $stmt = $mysql->prepare($query);

    if ($stmt) {
        $stmt->bind_param("ii", $id, $uid); // Asegúrate de pasar el ID como un entero

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
    global $mysql, $uid;
    $query = "INSERT INTO categoriagastos (NombreCategoria, ColorCategoria, ImagenCategoria, IdUsuario) VALUES (?, ?, ?, ?)";
    $stmt = $mysql->prepare($query);
    if ($stmt) {
        $stmt->bind_param("sssi", $data['NombreCategoria'], $data['ColorCategoria'], $data['ImagenCategoria'], $uid);
        if ($stmt->execute()) {
            echo "Categoría de gasto insertada correctamente.";
        } else {
            echo "Error al insertar la categoría de gasto: " . $stmt->error;
        }
        $stmt->close();
    } else {
        echo "Error al preparar la consulta: " . $mysql->error;
    }
}

// Editar Categorías de Gastos
function editarCategoriaGastos($data) {
    global $mysql, $uid;
    // La categoría debe ser del usuario (no se revela si existe para otro usuario).
    if (!appfinanzas_es_propio('categoriagastos', $data['id'] ?? null)) {
        echo "Error al actualizar la categoría de gasto: la categoría no existe.";
        return;
    }
    $query = "UPDATE categoriagastos SET NombreCategoria = ?, ColorCategoria = ?, ImagenCategoria = ? WHERE idCategoriaGastos = ? AND IdUsuario = ?";
    $stmt = $mysql->prepare($query);
    if ($stmt) {
        $stmt->bind_param("sssii", $data['NombreCategoria'], $data['ColorCategoria'], $data['ImagenCategoria'], $data['id'], $uid);
        if ($stmt->execute()) {
            echo "Categoría de gasto actualizada correctamente.";
        } else {
            echo "Error al actualizar la categoría de gasto: " . $stmt->error;
        }
        $stmt->close();
    } else {
        echo "Error al preparar la consulta: " . $mysql->error;
    }
}


// Eliminar Categorías de Gastos
function eliminarCategoriaGastos($id) {
    global $mysql, $uid;

    // La categoría debe ser del usuario (no se revela si existe para otro usuario).
    if (!appfinanzas_es_propio('categoriagastos', $id)) {
        echo "Error al eliminar la categoría de gasto: la categoría no existe.";
        return;
    }
    // Evita dejar gastos huérfanos: las consultas hacen INNER JOIN con la categoría y desaparecerían del listado.
    $stmtUso = $mysql->prepare("SELECT COUNT(*) AS total FROM gastos g INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
        WHERE g.IdCategoria = ? AND p.IdUsuario = ?");
    $stmtUso->bind_param("ii", $id, $uid);
    $stmtUso->execute();
    $enUso = (int)$stmtUso->get_result()->fetch_assoc()['total'];
    if ($enUso > 0) {
        echo "No se puede eliminar la categoría porque tiene $enUso gasto(s) asociados. Reasígnalos primero.";
        return;
    }
    $query = "DELETE FROM categoriagastos WHERE idCategoriaGastos=? AND IdUsuario = ?";
    $stmt = $mysql->prepare($query);
    $stmt->bind_param(
        "ii", 
        $id,
        $uid
    );

    if ($stmt->execute()) {
        echo "Categoría de gasto eliminada correctamente.";
    } else {
        echo "Error al eliminar la categoría de gasto: " . $mysql->error;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Detectar si la entrada es JSON
    $contentType = isset($_SERVER["CONTENT_TYPE"]) ? $_SERVER["CONTENT_TYPE"] : '';

    if (strpos($contentType, "application/json") !== false) {
        // Leer y decodificar el JSON
        $input = json_decode(file_get_contents('php://input'), true);

        // Verificar si el JSON es un array
        if (is_array($input)) {
            foreach ($input as $data) {
                procesarAccion($data);
            }
        } else {
            procesarAccion($input);
        }
    } else {
        // Usar $_POST si no es JSON
        procesarAccion($_POST);
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

// Nueva función para procesar cada acción
function procesarAccion($data) {
    global $mysql;

    if (isset($data['accion'])) {
        $accion = $data['accion'];

        if ($accion == 'insertar') {
            unset($data['accion']);
            insertarCategoriaGastos($data);
        } elseif ($accion == 'editar') {
            unset($data['accion']);
            editarCategoriaGastos($data);
        } elseif ($accion == 'eliminar') {
            $id = appfinanzas_entero($data['id'] ?? null);
            if ($id === null) {
                echo "Identificador de categoría no válido.";
                return;
            }
            eliminarCategoriaGastos($id);
        } else {
            echo "Acción desconocida: $accion";
        }
    } else {
        echo "No se especificó ninguna acción.";
    }
}

// Cerrar conexión
$mysql->close();
?>
