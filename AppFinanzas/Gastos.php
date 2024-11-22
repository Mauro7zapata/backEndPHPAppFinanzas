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

    // Extraer mes y año de $data
    $mes = $data['Mes']; // Debe contener el mes
    $anio = $data['Anho']; // Debe contener el año

    // Consultar el idPresupuesto correspondiente
    $consultaPresupuesto = "SELECT idPresupuesto 
                            FROM presupuestos 
                            WHERE Mes = '$mes' AND Anho = '$anio'
                            ORDER BY idPresupuesto ASC 
                            LIMIT 1";

    $resultadoPresupuesto = $mysql->query($consultaPresupuesto);
    if ($resultadoPresupuesto && $resultadoPresupuesto->num_rows > 0) {
        // Obtener el primer idPresupuesto
        $fila = $resultadoPresupuesto->fetch_assoc();

        $idPresupuesto = $fila['idPresupuesto'];
    } else {
        echo "Error: No se encontró un presupuesto para el mes $mes y año $anio.";
        return; // Salir de la función si no se encuentra el presupuesto
    }

    // Construir el query de inserción
    $query = "INSERT INTO gastos (NombreGasto, CostoPrevisto, CostoReal, FechaLimite, idPresupuesto, Observaciones, IdEstado, IdCategoria, FechaPago) 
              VALUES ('".$data['NombreGasto']."', '".$data['CostoPrevisto']."', '".$data['CostoReal']."','".$data['FechaLimite']."', 
                  '$idPresupuesto', '".$data['Observaciones']."', '".$data['IdEstado']."', '".$data['IdCategoria']."', '".$data['FechaPago']."')";

    // Ejecutar el query de inserción
    if ($mysql->query($query) === TRUE) {
        echo "Gasto insertado correctamente.";
    } else {
        echo "Error al insertar el gasto: " . $mysql->error;
        echo "Query: " . $query;
    }
}


// Editar Presupuesto Personal
function editarGastos($data) {
    global $mysql;
    $query = "UPDATE gastos 
                SET NombreGasto='".$data['NombreGasto']."', CostoPrevisto='".$data['CostoPrevisto']."', 
                CostoReal='".$data['CostoReal']."', FechaLimite='".$data['FechaLimite']."', Observaciones='".$data['Observaciones']."', 
                IdEstado='".$data['IdEstado']."', IdCategoria='".$data['IdCategoria']."', FechaPago='".$data['FechaPago']."' 
                WHERE idGastos='".$data['id']."'";
    if ($mysql->query($query) === TRUE) {
        echo "Gasto actualizado correctamente.";
    } else {
        echo "Error al actualizar el Gasto: " . $mysql->error;
        echo "query" . $query;
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

    if ($accion == 'insertar') {
        $data = $_POST;
        unset($data['accion']);
        unset($data['tabla']);
        insertarGastos($data);
    } elseif ($accion == 'editar') {
        $data = $_POST;
        unset($data['accion']);
        unset($data['tabla']);
        editarGastos($data);
    } elseif ($accion == 'eliminar') {
        $id = $_POST['id'];
        eliminarGastos($id);
    }
}else if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    // Obtener el ID del parámetro GET
    if (isset($_GET['id']) && !empty($_GET['id'])) {
        $id = $_GET['id'];
        consultarGastosID($id);
        // Obtener el ID del parámetro GET
    }else if (isset($_GET['mes']) && !empty($_GET['mes'])  && isset($_GET['anho']) && !empty($_GET['anho'])) {
        $mes = $_GET['mes'];
        $anho = $_GET['anho'];
        consultarTotalesGastos($mes,$anho);
    } else {
        consultarGastos(); // Si no se pasa un ID, consultar todos los registros
    }
}   

// Cerrar conexión
$mysql->close();
?>
