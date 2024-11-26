<?php
require_once("db.php");

// Consultar Presupuesto Personal
function consultarGastos() {
    global $mysql;
    $query = "SELECT idGastos, NombreGasto, CostoPrevisto, CostoReal, FechaLimite, Observaciones, IdEstado, IdCategoria, FechaPago,idPresupuesto 
            FROM gastos INNER JOIN categoriagastos c ON  IdCategoria = c.idCategoriaGastos order by c.NombreCategoria";
    $result = $mysql->query($query);

    $response = [];

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $response[] = [
                "idGastos" => $row['idGastos'],
                "NombreGasto" => $row['NombreGasto'],
                "CostoPrevisto" => $row['CostoPrevisto'],
                "CostoReal" => $row['CostoReal'],
                "FechaLimite" => $row['FechaLimite'],
                "Observaciones" => $row['Observaciones'],
                "IdEstado" => $row['IdEstado'],
                "IdCategoria" => $row['IdCategoria'],
                "FechaPago" => $row['FechaPago'],
                "idPresupuesto" => $row['idPresupuesto']
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

function consultarGastosPorMesYAnho($mes, $anho) {
    global $mysql;

    // Consulta SQL para seleccionar y agrupar los datos por Mes y Año
    $query = "SELECT g.idGastos, g.NombreGasto, g.CostoPrevisto, g.CostoReal, g.FechaLimite, g.Observaciones, g.IdEstado, g.IdCategoria, 
                    g.FechaPago,g.idPresupuesto, e.NombreEstado, c.NombreCategoria
            FROM gastos g INNER JOIN categoriagastos c ON  IdCategoria = c.idCategoriaGastos
            INNER JOIN estados e ON g.IdEstado = e.idEstado
            INNER JOIN presupuestos p ON g.idPresupuesto = p.idPresupuesto
            WHERE p.Mes = ? AND p.Anho = ?
            order by c.NombreCategoria";

    // Preparar la consulta para evitar inyecciones SQL
    $stmt = $mysql->prepare($query);

    // Verificar si la consulta se preparó correctamente
    if (!$stmt) {
        echo "Error al preparar la consulta: " . $mysql->error;
        return;
    }

    // Vincular los parámetros
    $stmt->bind_param("ii", $mes, $anho);

    // Ejecutar la consulta
    $stmt->execute();

    // Obtener los resultados
    $result = $stmt->get_result();
    $response = [];

    // Procesar los resultados
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $response[] = [
                "idGastos" => $row['idGastos'],
                "NombreGasto" => $row['NombreGasto'],
                "CostoPrevisto" => $row['CostoPrevisto'],
                "CostoReal" => $row['CostoReal'],
                "FechaLimite" => $row['FechaLimite'],
                "Observaciones" => $row['Observaciones'],
                "IdEstado" => $row['IdEstado'],
                "IdCategoria" => $row['IdCategoria'],
                "FechaPago" => $row['FechaPago'],
                "idPresupuesto" => $row['idPresupuesto'],
                "NombreEstado" => $row['NombreEstado'],
                "NombreCategoria" => $row['NombreCategoria']
            ];
        }
    }

    // Retornar los resultados como JSON
    header('Content-Type: application/json');
    echo json_encode($response);
}

function consultarGastosID($id) {
    global $mysql;
    $query = "SELECT idGastos, NombreGasto, CostoPrevisto, CostoReal, FechaLimite, Observaciones, IdEstado, IdCategoria, FechaPago, idPresupuesto 
              FROM gastos 
              WHERE idGastos = ?";
    $stmt = $mysql->prepare($query);

    if ($stmt) {
        $stmt->bind_param("i", $id); // Asegúrate de pasar el ID como un entero

        $stmt->execute();
        $result = $stmt->get_result();

        $response = [];

        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $response[] = [
                    "idGastos" => $row['idGastos'],
                    "NombreGasto" => $row['NombreGasto'],
                    "CostoPrevisto" => $row['CostoPrevisto'],
                    "CostoReal" => $row['CostoReal'],
                    "FechaLimite" => $row['FechaLimite'],
                    "Observaciones" => $row['Observaciones'],
                    "IdEstado" => $row['IdEstado'],
                    "IdCategoria" => $row['IdCategoria'],
                    "FechaPago" => $row['FechaPago'],
                    "idPresupuesto" => $row['idPresupuesto']
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
        echo "Error al preparar la consulta: " . $mysql->error;
    }
}
// Consultar Totales de Gastos por Mes y Año de la tabla presupuestos
function consultarTotalesGastos($mes, $anho) {
    global $mysql;

    // Consulta SQL con JOIN entre 'gastos' y 'presupuestos' para filtrar por Mes y Año
    $query = "
        SELECT 
            SUM(g.CostoPrevisto) AS TotalCostoPrevisto,
            SUM(g.CostoReal) AS TotalCostoReal
        FROM gastos g
        JOIN presupuestos p ON g.idPresupuesto = p.idPresupuesto
        WHERE p.Mes = ? AND p.Anho = ?
    ";

    // Preparar la consulta para evitar inyecciones SQL
    $stmt = $mysql->prepare($query);

    // Vincular parámetros
    $stmt->bind_param("ii", $mes, $anho);

    // Ejecutar la consulta
    $stmt->execute();

    // Obtener el resultado
    $result = $stmt->get_result();
    $totales = $result->fetch_assoc();

    // Crear la respuesta
    $response = [
        "TotalCostoPrevisto" => $totales['TotalCostoPrevisto'] ?? 0,
        "TotalCostoReal" => $totales['TotalCostoReal'] ?? 0
    ];

    // Retornar los resultados como JSON
    header('Content-Type: application/json');
    echo json_encode($response);
}


function insertarGastos($data) {
    global $mysql;
    
    // Consultar idPresupuesto
    $mes = $data['Mes'];
    $anio = $data['Anho'];
    $consultaPresupuesto = "SELECT idPresupuesto FROM presupuestos WHERE Mes = ? AND Anho = ? LIMIT 1";
    
    $stmt = $mysql->prepare($consultaPresupuesto);
    $stmt->bind_param("ii", $mes, $anio);
    $stmt->execute();
    $resultadoPresupuesto = $stmt->get_result();
    
    if ($resultadoPresupuesto && $resultadoPresupuesto->num_rows > 0) {
        $fila = $resultadoPresupuesto->fetch_assoc();
        $idPresupuesto = $fila['idPresupuesto'];
    } else {
        echo "Error: No se encontró presupuesto para el mes $mes y año $anio.";
        return;
    }

    // Consulta de inserción
    $query = "INSERT INTO gastos (NombreGasto, CostoPrevisto, CostoReal, FechaLimite, idPresupuesto, Observaciones, IdEstado, IdCategoria, FechaPago)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $mysql->prepare($query);
    $stmt->bind_param(
        "sddsisiis", 
        $data['NombreGasto'], 
        $data['CostoPrevisto'], 
        $data['CostoReal'], 
        $data['FechaLimite'], 
        $idPresupuesto, 
        $data['Observaciones'], 
        $data['IdEstado'], 
        $data['IdCategoria'], 
        $data['FechaPago']
    );

    if ($stmt->execute()) {
        echo "Gasto insertado correctamente.";
    } else {
        echo "Error al insertar el gasto: " . $mysql->error;
    }
}


// Editar Presupuesto Personal
function editarGastos($data) {
    global $mysql;

    // Consulta de actualización
    $query = "UPDATE gastos 
              SET NombreGasto = ?, CostoPrevisto = ?, CostoReal = ?, FechaLimite = ?, Observaciones = ?, 
                  IdEstado = ?, IdCategoria = ?, FechaPago = ? 
              WHERE idGastos = ?";

    $stmt = $mysql->prepare($query);
    $stmt->bind_param(
        "sddssiisi", 
        $data['NombreGasto'], 
        $data['CostoPrevisto'], 
        $data['CostoReal'], 
        $data['FechaLimite'], 
        $data['Observaciones'], 
        $data['IdEstado'], 
        $data['IdCategoria'], 
        $data['FechaPago'], 
        $data['id']
    );

    if ($stmt->execute()) {
        echo "Gasto actualizado correctamente.";
    } else {
        echo "Error al actualizar el gasto: " . $mysql->error;
    }
}

// Eliminar Presupuesto Personal
function eliminarGastos($id) {
    global $mysql;
    $query = "DELETE FROM gastos WHERE idGastos='$id'";
    if ($mysql->query($query) === TRUE) {
        echo "Gasto eliminado correctamente.";
    } else {
        echo "Error al eliminar el Gasto: " . $mysql->error;
    }
}

// Procesar acciones basadas en el formulario
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $accion = $_POST['accion'];
    $tabla = "gastos";

    // Obtener los datos JSON enviados (puedes usar json_decode en lugar de $_POST si es un JSON completo)
    $data = json_decode(file_get_contents('php://input'), true); 

    if ($accion == 'insertar') {
        insertarGastos($data);
    } elseif ($accion == 'editar') {
        editarGastos($data);
    } elseif ($accion == 'eliminar') {
        $id = $_POST['id'];
        eliminarGastos($id);
    }
}
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Recibir la solicitud JSON y decodificarla en un array PHP
    $data = json_decode(file_get_contents("php://input"), true);

    // Verificar si se ha decodificado correctamente
    if (is_array($data)) {
        // Iterar sobre cada elemento de la data
        foreach ($data as $item) {
            $accion = $item['accion']; // Acción (insertar, editar, etc.)
            
            if ($accion == 'insertar') {
                insertarGastos($item);
            } elseif ($accion == 'editar') {
                editarGastos($item);
            } elseif ($accion == 'eliminar') {
                $id = $_POST['id'];
                eliminarGastos($id);
            } else {
                echo "Acción no válida: " . $accion;
            }
        }
    } else {
        echo "La data no está en el formato correcto.";
    }
}else if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    // Obtener el ID del parámetro GET
    if (isset($_GET['id']) && !empty($_GET['id'])) {
        $id = $_GET['id'];
        consultarGastosID($id);
        // Obtener el ID del parámetro GET
    }else if (isset($_GET['mes']) && !empty($_GET['mes'])  && isset($_GET['anho']) && !empty($_GET['anho'])
        && $_GET['detalle'] === 'totales') {
        $mes = $_GET['mes'];
        $anho = $_GET['anho'];
        consultarTotalesGastos($mes,$anho);
    } else if (isset($_GET['mes']) && !empty($_GET['mes'])  && isset($_GET['anho']) && !empty($_GET['anho'])
        && $_GET['detalle'] === 'completo') {
        $mes = $_GET['mes'];
        $anho = $_GET['anho'];
        consultarGastosPorMesYAnho($mes, $anho);
    } else {
        consultarGastos(); // Si no se pasa un ID, consultar todos los registros
    }
}   

// Cerrar conexión
$mysql->close();
?>
