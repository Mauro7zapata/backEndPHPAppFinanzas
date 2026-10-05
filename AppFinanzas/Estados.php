<?php
require_once("db.php");
require_once(__DIR__ . '/lib.php');

// Consultar Estados
function consultarEstados() {
    global $mysql, $uid;
    $query = "SELECT idEstado, TipoEstado, NombreEstado, ColorEstado FROM estados WHERE IdUsuario = ?";
    $stmt = $mysql->prepare($query);
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $result = $stmt->get_result();

    $response = [];

    if ($result->num_rows > 0) {
        while ($row = appfinanzas_fila_texto($result->fetch_assoc())) {
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
    global $mysql, $uid;
    $query = "SELECT idEstado, TipoEstado, NombreEstado, ColorEstado 
                FROM estados 
                WHERE idEstado= ? AND IdUsuario = ?";
       $stmt = $mysql->prepare($query);

    if ($stmt) {
        $stmt->bind_param("ii", $id, $uid); // Asegúrate de pasar el ID como un entero

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
    global $mysql, $uid;
    $query = "INSERT INTO estados (TipoEstado, NombreEstado, ColorEstado, IdUsuario) VALUES (?, ?, ?, ?)";
    $stmt = $mysql->prepare($query);
    $stmt->bind_param(
        "sssi", 
        $data['TipoEstado'], 
        $data['NombreEstado'], 
        $data['ColorEstado'],
        $uid
    );

    if ($stmt->execute()) {
        echo "Estado insertado correctamente.";
    } else {
        echo "Error al insertar el estado: " . $mysql->error;
    }
}

// Editar Estado
function editarEstado($data) {
    global $mysql, $uid;
    // El estado debe ser del usuario (no se revela si existe para otro usuario).
    if (!appfinanzas_es_propio('estados', $data['id'] ?? null)) {
        echo "Error al actualizar el estado: el estado no existe.";
        return;
    }
    $query = "UPDATE estados SET TipoEstado=?, NombreEstado=?, ColorEstado=? WHERE idEstado=? AND IdUsuario = ?";
    $stmt = $mysql->prepare($query);
    $stmt->bind_param(
        "sssii", 
        $data['TipoEstado'], 
        $data['NombreEstado'], 
        $data['ColorEstado'],
        $data['id'],
        $uid
    );

    if ($stmt->execute()) {
        echo "Estado actualizado correctamente.";
    } else {
        echo "Error al actualizar el estado: " . $mysql->error;
    }
}

// Eliminar Estado
function eliminarEstado($id) {
    global $mysql, $uid;

    // El estado debe ser del usuario (no se revela si existe para otro usuario).
    if (!appfinanzas_es_propio('estados', $id)) {
        echo "Error al eliminar el estado: el estado no existe.";
        return;
    }
    // No eliminar estados en uso: gastos, inversiones y pagos dependen de ellos (el estado es del usuario, así que solo cuenta lo suyo).
    $stmtUso = $mysql->prepare(
        "SELECT (SELECT COUNT(*) FROM gastos g INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto WHERE g.IdEstado = ? AND p.IdUsuario = ?) +
                (SELECT COUNT(*) FROM Inversiones WHERE idEstado = ? AND IdUsuario = ?) +
                (SELECT COUNT(*) FROM PlanPagos pp INNER JOIN Inversiones i ON i.idInversion = pp.idInversion WHERE pp.IdEstado = ? AND i.IdUsuario = ?) AS total");
    $stmtUso->bind_param("iiiiii", $id, $uid, $id, $uid, $id, $uid);
    $stmtUso->execute();
    $enUso = (int)$stmtUso->get_result()->fetch_assoc()['total'];
    if (presupuestosTieneEstado()) { // migración 010: los presupuestos también tienen estado
        $stmtP = $mysql->prepare("SELECT COUNT(*) AS total FROM presupuestos WHERE IdEstado = ? AND IdUsuario = ?");
        $stmtP->bind_param("ii", $id, $uid);
        $stmtP->execute();
        $enUso += (int)$stmtP->get_result()->fetch_assoc()['total'];
    }
    if ($enUso > 0) {
        echo "No se puede eliminar el estado porque está en uso en $enUso registro(s).";
        return;
    }
    $query = "DELETE FROM estados WHERE idEstado=? AND IdUsuario = ?";
    $stmt = $mysql->prepare($query);
    $stmt->bind_param(
        "ii", 
        $id,
        $uid
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
                $accion = $item['accion'] ?? ''; // Acción (insertar, editar, eliminar)
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
        $accion = $_POST['accion'] ?? '';
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
