<?php
require_once("db.php");

// Función para manejar respuestas
function enviarRespuesta($status, $message) {
    header('Content-Type: application/json');
    echo json_encode([
        "status" => $status,
        "message" => $message
    ]);
}


// Insertar un nuevo presupuesto
function insertarPresupuesto($data) {
    global $mysql;

    $ValorPresupuesto = $data['ValorPresupuesto'];
    $ExtrasMes = $data['ExtrasMes'];
    $Anho = $data['Anho'];
    $Mes = $data['Mes'];

    $query = "INSERT INTO presupuestos (ValorPresupuesto, ExtrasMes, Anho, Mes) VALUES (?, ?, ?, ?)";
    $stmt = $mysql->prepare($query);
    $stmt->bind_param("diis", $ValorPresupuesto, $ExtrasMes, $Anho, $Mes);

    if ($stmt->execute()) {
        enviarRespuesta("success", "Presupuesto insertado correctamente");
    } else {
        enviarRespuesta("error", "Error al insertar el presupuesto");
    }
}

// Editar un presupuesto
function editarPresupuesto($data) {
    global $mysql;

    $ValorPresupuesto = $data['ValorPresupuesto'];
    $ExtrasMes = $data['ExtrasMes'];
    $Anho = $data['Anho'];
    $Mes = $data['Mes'];

    $query = "UPDATE presupuestos SET ValorPresupuesto = ?, ExtrasMes = ? 
    WHERE Anho = ? AND Mes = ?";
    $stmt = $mysql->prepare($query);
    $stmt->bind_param("diis", $ValorPresupuesto, $ExtrasMes, $Anho, $Mes);

    if ($stmt->execute()) {
        enviarRespuesta("success", "Presupuesto actualizado correctamente");
    } else {
        enviarRespuesta("error", "Error al actualizar el presupuesto");
    }
}

// Eliminar un presupuesto
function eliminarPresupuesto($idPresupuesto) {
    global $mysql;

    $query = "DELETE FROM presupuestos WHERE idPresupuesto = ?";
    $stmt = $mysql->prepare($query);
    $stmt->bind_param("i", $idPresupuesto);

    if ($stmt->execute()) {
        enviarRespuesta("success", "Presupuesto eliminado correctamente");
    } else {
        enviarRespuesta("error", "Error al eliminar el presupuesto");
    }
}

// Consultar todos los presupuestos
function consultarPresupuestos() {
    global $mysql;

    $query = "SELECT idPresupuesto, ValorPresupuesto, ExtrasMes, Anho, Mes FROM presupuestos";
    $result = $mysql->query($query);

    if ($result->num_rows > 0) {
        $response = [];
        while ($row = $result->fetch_assoc()) {
            $response[] = $row;
        }
        header('Content-Type: application/json');
        echo json_encode($response);
    } else {
        enviarRespuesta("error", "No se encontraron presupuestos");
    }
}

function consultarPresupuestoPorMesAnho($mes, $anho) {
    global $mysql;

    // Consulta SQL para buscar el presupuesto por mes y año
    $query = "SELECT idPresupuesto, ValorPresupuesto, ExtrasMes, Anho, Mes FROM presupuestos WHERE Mes = ? AND Anho = ?";
    $stmt = $mysql->prepare($query);
    
    // Asociar los parámetros: mes y año (enteros)
    $stmt->bind_param("ii", $mes, $anho);
    $stmt->execute();

    $result = $stmt->get_result();

    // Verificar si se encontraron resultados
    if ($result->num_rows > 0) {
        $response = $result->fetch_assoc();
        header('Content-Type: application/json');
        echo json_encode($response);
    } else {
        enviarRespuesta("error", "No se encontró el presupuesto para el mes y año proporcionados");
    }
}
// Consultar costos previstos por estado para un presupuesto
function consultarCostosPorEstado($idPresupuesto) {
    global $mysql;

    $query = "
        SELECT e.NombreEstado, e.ColorEstado, SUM(g.CostoPrevisto) AS TotalCostoPrevisto 
        FROM gastos g
        INNER JOIN estados e ON g.IdEstado = e.idEstado
        WHERE g.idPresupuesto = ?
        GROUP BY e.NombreEstado, e.ColorEstado";
    $stmt = $mysql->prepare($query);
    $stmt->bind_param("i", $idPresupuesto);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $response = [];
        while ($row = $result->fetch_assoc()) {
            $response[] = $row;
        }
        header('Content-Type: application/json');
        echo json_encode($response);
    } else {
        enviarRespuesta("error", "No se encontraron costos previstos para el presupuesto proporcionado");
    }
}




if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $accion = $_POST['accion'];
    $tabla = "presupuestos";

    // Procesar la acción según el valor de 'accion'
    if ($accion == 'insertar') {
        $data = $_POST;
        unset($data['accion']);
        unset($data['tabla']);
        insertarPresupuesto($data);
    } elseif ($accion == 'editar') {
        $data = $_POST;
        unset($data['accion']);
        unset($data['tabla']);
        editarPresupuesto($data);
    } elseif ($accion == 'eliminar') {
        $id = $_POST['id'];
        eliminarPresupuesto($id);
    }
} if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    if (isset($_GET['idPresupuesto']) && !empty($_GET['idPresupuesto'])) {
        $idPresupuesto = $_GET['idPresupuesto'];
        consultarCostosPorEstado($idPresupuesto);
    } elseif (isset($_GET['mes']) && !empty($_GET['mes']) && isset($_GET['anho']) && !empty($_GET['anho'])) {
        $mes = $_GET['mes'];
        $anho = $_GET['anho'];
        consultarPresupuestoPorMesAnho($mes, $anho);
    } else {
        consultarPresupuestos();
    }
}
?>  
