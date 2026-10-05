<?php
require_once("../db.php");
require_once(__DIR__ . '/../lib.php');
// Orden de los gastos según su estado: lo que requiere atención primero y lo ya resuelto al final.
// Pendiente -> En proceso -> Guardado -> Acumulado -> Pagado -> No aplica
define('ORDEN_ESTADO_SQL', "CASE e.NombreEstado WHEN 'Pendiente' THEN 1 WHEN 'En proceso' THEN 2 WHEN 'Guardado' THEN 3 WHEN 'Acumulado' THEN 4 WHEN 'Pagado' THEN 5 WHEN 'No aplica' THEN 6 ELSE 7 END");


// Consultar Gastos
function consultarGastos() {
    global $mysql, $uid;
    $query = "SELECT g.idGastos, g.NombreGasto, g.CostoPrevisto, g.CostoReal, g.FechaLimite, g.Observaciones, g.IdEstado, g.IdCategoria, g.FechaPago, g.idPresupuesto, g.valorGastosMovimiento, g.idDeuda, g.idObligacion, (SELECT dd.Nombre FROM deudas dd WHERE dd.idDeuda = g.idDeuda AND dd.IdUsuario = p.IdUsuario) AS NombreDeuda
            FROM gastos g INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
            INNER JOIN categoriagastos c ON g.IdCategoria = c.idCategoriaGastos AND c.IdUsuario = p.IdUsuario
            WHERE p.IdUsuario = ?
            ORDER BY c.NombreCategoria, g.NombreGasto";
    $stmt = $mysql->prepare($query);
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $result = $stmt->get_result();

    $response = [];

    if ($result->num_rows > 0) {
        while ($row = appfinanzas_fila_texto($result->fetch_assoc())) {
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
                "valorGastosMovimiento" => $row['valorGastosMovimiento'],
                "idDeuda" => $row['idDeuda'],
                "idObligacion" => $row['idObligacion'],
                "NombreDeuda" => $row['NombreDeuda']
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
    global $mysql, $uid;

    // Consulta SQL para seleccionar y agrupar los datos por Mes y Año
    $query = "SELECT g.idGastos, g.NombreGasto, g.CostoPrevisto, g.CostoReal, g.FechaLimite, g.Observaciones, g.IdEstado, g.IdCategoria, 
                    g.FechaPago,g.idPresupuesto, e.NombreEstado, c.NombreCategoria, g.valorGastosMovimiento, g.idDeuda, g.idObligacion, (SELECT dd.Nombre FROM deudas dd WHERE dd.idDeuda = g.idDeuda AND dd.IdUsuario = p.IdUsuario) AS NombreDeuda
            FROM gastos g INNER JOIN presupuestos p ON g.idPresupuesto = p.idPresupuesto
            INNER JOIN categoriagastos c ON g.IdCategoria = c.idCategoriaGastos AND c.IdUsuario = p.IdUsuario
            INNER JOIN estados e ON g.IdEstado = e.idEstado AND e.IdUsuario = p.IdUsuario
            WHERE p.Mes = ? AND p.Anho = ? AND p.IdUsuario = ?
            ORDER BY " . ORDEN_ESTADO_SQL . ", g.FechaLimite IS NULL, g.FechaLimite, c.NombreCategoria, g.NombreGasto";

    // Preparar la consulta para evitar inyecciones SQL
    $stmt = $mysql->prepare($query);

    // Verificar si la consulta se preparó correctamente
    if (!$stmt) {
        echo "Error al preparar la consulta: " . $mysql->error;
        return;
    }

    // Vincular los parámetros
    $stmt->bind_param("iii", $mes, $anho, $uid);

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
                "NombreCategoria" => $row['NombreCategoria'],
                "valorGastosMovimiento" => $row['valorGastosMovimiento'],
                "idDeuda" => $row['idDeuda'],
                "idObligacion" => $row['idObligacion'],
                "NombreDeuda" => $row['NombreDeuda']
            ];
        }
    }

    // Retornar los resultados como JSON
    header('Content-Type: application/json');
    echo json_encode($response);
}

function consultarGastosPorIdPresupuesto($idPresupuesto) {
    global $mysql, $uid;

    // Consulta SQL para seleccionar y agrupar los datos por Mes y Año
    $query = "SELECT g.idGastos, g.NombreGasto, g.CostoPrevisto, g.CostoReal, g.FechaLimite, g.Observaciones, g.IdEstado, g.IdCategoria, 
                    g.FechaPago,g.idPresupuesto, e.NombreEstado, c.NombreCategoria, g.valorGastosMovimiento, g.idDeuda, g.idObligacion, (SELECT dd.Nombre FROM deudas dd WHERE dd.idDeuda = g.idDeuda AND dd.IdUsuario = p.IdUsuario) AS NombreDeuda
            FROM gastos g INNER JOIN presupuestos p ON g.idPresupuesto = p.idPresupuesto
            INNER JOIN categoriagastos c ON g.IdCategoria = c.idCategoriaGastos AND c.IdUsuario = p.IdUsuario
            INNER JOIN estados e ON g.IdEstado = e.idEstado AND e.IdUsuario = p.IdUsuario
            WHERE g.idPresupuesto = ? AND p.IdUsuario = ?
            ORDER BY " . ORDEN_ESTADO_SQL . ", g.FechaLimite IS NULL, g.FechaLimite, c.NombreCategoria, g.NombreGasto";

    // Preparar la consulta para evitar inyecciones SQL
    $stmt = $mysql->prepare($query);

    // Verificar si la consulta se preparó correctamente
    if (!$stmt) {
        echo "Error al preparar la consulta: " . $mysql->error;
        return;
    }

    // Vincular los parámetros
    $stmt->bind_param("ii", $idPresupuesto, $uid);

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
                "NombreCategoria" => $row['NombreCategoria'],
                "valorGastosMovimiento" => $row['valorGastosMovimiento'],
                "idDeuda" => $row['idDeuda'],
                "idObligacion" => $row['idObligacion'],
                "NombreDeuda" => $row['NombreDeuda']
            ];
        }
    }

    // Retornar los resultados como JSON
    header('Content-Type: application/json');
    echo json_encode($response);
}

function consultarGastosID($id) {
    global $mysql, $uid;
    $query = "SELECT g.idGastos, g.NombreGasto, g.CostoPrevisto, g.CostoReal, g.FechaLimite,
                        g.Observaciones, g.IdEstado, g.IdCategoria, g.FechaPago, g.idPresupuesto,
                        g.valorGastosMovimiento, g.idDeuda, g.idObligacion, (SELECT dd.Nombre FROM deudas dd WHERE dd.idDeuda = g.idDeuda AND dd.IdUsuario = p.IdUsuario) AS NombreDeuda
              FROM gastos g INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
              WHERE g.idGastos = ? AND p.IdUsuario = ?";
    $stmt = $mysql->prepare($query);

    if ($stmt) {
        $stmt->bind_param("ii", $id, $uid); // Asegúrate de pasar el ID como un entero

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
                    "idPresupuesto" => $row['idPresupuesto'],
                    "valorGastosMovimiento" => $row['valorGastosMovimiento'],
                    "idDeuda" => $row['idDeuda'],
                    "idObligacion" => $row['idObligacion'],
                "NombreDeuda" => $row['NombreDeuda']
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
    global $mysql, $uid;

    // Consulta SQL con JOIN entre 'gastos' y 'presupuestos' para filtrar por Mes y Año
    $query = "
        SELECT 
            SUM(g.CostoPrevisto) AS TotalCostoPrevisto,
            SUM(g.CostoReal) AS TotalCostoReal
        FROM gastos g
        JOIN presupuestos p ON g.idPresupuesto = p.idPresupuesto
        WHERE p.Mes = ? AND p.Anho = ? AND p.IdUsuario = ?
    ";

    // Preparar la consulta para evitar inyecciones SQL
    $stmt = $mysql->prepare($query);

    // Vincular parámetros
    $stmt->bind_param("iii", $mes, $anho, $uid);

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


// Valida los campos comunes de un gasto. Devuelve un mensaje de error o null si es válido.
function validarGasto($data) {
    if (trim($data['NombreGasto'] ?? '') === '') {
        return "El nombre del gasto es obligatorio.";
    }
    if (!appfinanzas_monto_valido($data['CostoPrevisto'] ?? null)) {
        return "El costo previsto no es válido.";
    }
    if (($data['CostoReal'] ?? '') !== '' && !appfinanzas_monto_valido($data['CostoReal'])) {
        return "El costo real no es válido.";
    }
    return null;
}

// Vincula (o desvincula) un gasto con una deuda. Solo actúa si el cliente envía "idDeuda"
// (los clientes antiguos no lo envían y el vínculo existente no se toca). 0 o vacío = sin deuda.
function vincularDeudaGasto($idGasto, $data) {
    global $mysql, $uid;
    if (!array_key_exists('idDeuda', $data)) return;
    $idDeuda = appfinanzas_entero($data['idDeuda']);
    if ($idDeuda !== null && $idDeuda <= 0) $idDeuda = null;
    // El gasto y la deuda deben ser del usuario (la validación previa ya lo comprobó; aquí se garantiza en el UPDATE).
    $stmt = $mysql->prepare("UPDATE gastos g INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
        SET g.idDeuda = ? WHERE g.idGastos = ? AND p.IdUsuario = ?");
    $stmt->bind_param('iii', $idDeuda, $idGasto, $uid);
    $stmt->execute();
    resincronizarAbonosGasto((int)$idGasto);
}

// Verifica que la categoría, el estado y la deuda (si se envía) del gasto sean del usuario. Devuelve un mensaje de error o null.
function validarPropiedadGasto($data) {
    if (!appfinanzas_es_propio('categoriagastos', $data['IdCategoria'] ?? null) || !appfinanzas_es_propio('estados', $data['IdEstado'] ?? null)) {
        return "la categoría o el estado seleccionado no existe.";
    }
    if (array_key_exists('idDeuda', $data)) {
        $idDeuda = appfinanzas_entero($data['idDeuda']);
        if ($idDeuda !== null && $idDeuda > 0 && !appfinanzas_es_propio('deudas', $idDeuda)) {
            return "la deuda seleccionada no existe.";
        }
    }
    return null;
}

// Mensaje claro según el error de MySQL. Con 'depurar' => true en config.local.php agrega la causa real.
function mensajeErrorGasto($accion, mysqli_sql_exception $e) {
    $codigo = (int)$e->getCode();
    if ($codigo === 1452) return "Error al $accion el gasto: la categoría o el estado seleccionado no existe.";
    if ($codigo === 1062) return "Error al $accion el gasto: ya existe un gasto igual en este presupuesto.";
    $detalle = !empty($GLOBALS['appfinanzas_depurar']) ? ' [' . $codigo . ': ' . $e->getMessage() . ']' : ' (código ' . $codigo . ')';
    return "Error al $accion el gasto. Verifica los datos (fechas y valores)." . $detalle;
}

function insertarGastos($data) {
    global $mysql, $uid;

    $errorValidacion = validarGasto($data);
    if ($errorValidacion) {
        echo "Error: " . $errorValidacion;
        return;
    }

    // FechaPago vacía = sin pago: la columna es NOT NULL, se guarda '0000-00-00'.
    if (!isset($data['FechaPago']) || $data['FechaPago'] === '' || $data['FechaPago'] === '0000-00-00') { $data['FechaPago'] = '0000-00-00'; }

    // Consultar idPresupuesto
    $mes = $data['Mes'] ?? null;
    $anio = $data['Anho'] ?? null;
    $consultaPresupuesto = "SELECT idPresupuesto FROM presupuestos WHERE Mes = ? AND Anho = ? AND IdUsuario = ? LIMIT 1";
    
    $stmt = $mysql->prepare($consultaPresupuesto);
    $stmt->bind_param("iii", $mes, $anio, $uid);
    $stmt->execute();
    $resultadoPresupuesto = $stmt->get_result();
    
    if ($resultadoPresupuesto && $resultadoPresupuesto->num_rows > 0) {
        $fila = $resultadoPresupuesto->fetch_assoc();
        $idPresupuesto = $fila['idPresupuesto'];
    } else {
        echo "Error: No se encontró presupuesto para el mes $mes y año $anio.";
        return;
    }

    // Categoría, estado y deuda deben ser del usuario.
    if ($errorPropiedad = validarPropiedadGasto($data)) {
        echo "Error al insertar el gasto: " . $errorPropiedad;
        return;
    }

    // sentencia de inserción
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

    try {
        $stmt->execute();
        vincularDeudaGasto($mysql->insert_id, $data);
        echo "Gasto insertado correctamente.";
    } catch (mysqli_sql_exception $e) {
        error_log('[AppFinanzas] insertarGastos: ' . $e->getMessage());
        echo mensajeErrorGasto("insertar", $e);
    }
}


// Editar Gasto
function editarGastos($data) {
    global $mysql, $uid;

    $errorValidacion = validarGasto($data);
    if ($errorValidacion || appfinanzas_entero($data['id'] ?? null) === null) {
        echo "Error: " . ($errorValidacion ?: "Identificador de gasto no válido.");
        return;
    }

    // FechaPago vacía = sin pago: la columna es NOT NULL, se guarda '0000-00-00'.
    if (!isset($data['FechaPago']) || $data['FechaPago'] === '' || $data['FechaPago'] === '0000-00-00') { $data['FechaPago'] = '0000-00-00'; }

    // El gasto, la categoría, el estado y la deuda deben ser del usuario.
    if (!appfinanzas_gasto_es_propio($data['id'])) {
        echo "Error al actualizar el gasto: el gasto no existe.";
        return;
    }
    if ($errorPropiedad = validarPropiedadGasto($data)) {
        echo "Error al actualizar el gasto: " . $errorPropiedad;
        return;
    }

    // sentencia de actualización (el JOIN con presupuestos garantiza el dueño)
    $query = "UPDATE gastos g INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
              SET g.NombreGasto = ?, g.CostoPrevisto = ?, g.CostoReal = ?, g.FechaLimite = ?, g.Observaciones = ?, 
                  g.IdEstado = ?, g.IdCategoria = ?, g.FechaPago = ? 
              WHERE g.idGastos = ? AND p.IdUsuario = ?";

    $stmt = $mysql->prepare($query);
    $stmt->bind_param(
        "sddssiisii", 
        $data['NombreGasto'], 
        $data['CostoPrevisto'], 
        $data['CostoReal'], 
        $data['FechaLimite'], 
        $data['Observaciones'], 
        $data['IdEstado'], 
        $data['IdCategoria'], 
        $data['FechaPago'], 
        $data['id'],
        $uid
    );

    try {
        $stmt->execute();
        vincularDeudaGasto($data['id'], $data);
        echo "Gasto actualizado correctamente.";
    } catch (mysqli_sql_exception $e) {
        error_log('[AppFinanzas] editarGastos: ' . $e->getMessage());
        echo mensajeErrorGasto("actualizar", $e);
    }
}

// Eliminar Gasto
function eliminarGastos($id) {
    global $mysql, $uid;

    $id = appfinanzas_entero($id);
    if ($id === null || $id <= 0) {
        echo "Error: Identificador de gasto no válido.";
        return;
    }
    $query = "DELETE g FROM gastos g INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
              WHERE g.idGastos = ? AND p.IdUsuario = ?";
    $stmt = $mysql->prepare($query);
    $stmt->bind_param(
        "ii", 
        $id,
        $uid
    );

    try {
        $stmt->execute();
        echo $stmt->affected_rows > 0 ? "Gasto eliminado correctamente." : "No se encontró el gasto a eliminar.";
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() == 1451) { // violación de llave foránea: tiene movimientos
            echo "No se puede eliminar el gasto porque tiene movimientos registrados. Elimina primero sus movimientos.";
        } else {
            error_log('[AppFinanzas] eliminarGastos: ' . $e->getMessage());
            echo "Error al eliminar el Gasto.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Verificar si la solicitud es JSON
    if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        // Solicitud JSON
        $data = json_decode(file_get_contents("php://input"), true);

        // Verificar si la decodificación fue exitosa
        if (is_array($data)) {
            foreach ($data as $item) {
                $accion = $item['accion'] ?? ''; // Acción (insertar, editar, etc.)

                if ($accion == 'insertar') {
                    insertarGastos($item);
                } elseif ($accion == 'editar') {
                    editarGastos($item);
                } elseif ($accion == 'eliminar') {
                    $id = $item['id'] ?? null;
                    eliminarGastos($id);
                } else {
                    echo "Acción no válida: " . $accion;
                }
            }
        } else {
            echo "La data no está en el formato correcto.";
        }
    } else {
        // Solicitud del formulario
        $accion = $_POST['accion'] ?? '';
        $tabla = "gastos";
        $data = $_POST; 

        if ($accion == 'insertar') {
            insertarGastos($data);
        } elseif ($accion == 'editar') {
            editarGastos($data);
        } elseif ($accion == 'eliminar') {
            $id = $_POST['id'] ?? null;
            eliminarGastos($id);
        }
    }
}else if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    // Obtener el ID del parámetro GET
    if (isset($_GET['id']) && !empty($_GET['id'])) {
        $id = $_GET['id'];
        consultarGastosID($id);
    }
    else if (isset($_GET['idPresupuesto']) && !empty($_GET['idPresupuesto'])) {
        $idPresupuesto = $_GET['idPresupuesto'];
        consultarGastosPorIdPresupuesto($idPresupuesto);
    }else if (isset($_GET['mes']) && !empty($_GET['mes'])  && isset($_GET['anho']) && !empty($_GET['anho'])
        && ($_GET['detalle'] ?? '') === "totales") {
        $mes = $_GET['mes'];
        $anho = $_GET['anho'];
        consultarTotalesGastos($mes,$anho);
    } else if (isset($_GET['mes']) && !empty($_GET['mes'])  && isset($_GET['anho']) && !empty($_GET['anho'])
        && ($_GET['detalle'] ?? '') === "completo") {
        $mes = $_GET['mes'];
        $anho = $_GET['anho'];
        consultarGastosPorMesYAnho($mes, $anho);
    } else {
        echo "No se envio los parametros correctos"; // Si no se pasa un ID, consultar todos los registros
    }
}   

// Cerrar conexión
$mysql->close();
?>
