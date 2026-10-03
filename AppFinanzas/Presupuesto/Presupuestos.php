<?php
require_once("../db.php");

// Función para manejar respuestas
function enviarRespuesta($status, $message) {
    header('Content-Type: application/json');
    echo json_encode([
        "status" => $status,
        "message" => $message
    ]);
}

// Valida y normaliza los datos de un presupuesto. Devuelve [datos, error].
function validarDatosPresupuesto($data) {
    $valor = appfinanzas_entero($data['ValorPresupuesto'] ?? null);
    $extras = ($data['ExtrasMes'] ?? '') === '' ? 0 : appfinanzas_entero($data['ExtrasMes']);
    $anho = appfinanzas_entero($data['Anho'] ?? null);
    $mes = appfinanzas_entero($data['Mes'] ?? null);

    if ($valor === null || $valor < 0) {
        return [null, "El valor del presupuesto no es válido"];
    }
    if ($extras === null || $extras < 0) {
        return [null, "El valor de extras del mes no es válido"];
    }
    if ($anho === null || $anho < 2000 || $anho > 2100) {
        return [null, "El año no es válido"];
    }
    if ($mes === null || $mes < 1 || $mes > 12) {
        return [null, "El mes no es válido"];
    }
    return [["valor" => $valor, "extras" => $extras, "anho" => $anho, "mes" => $mes], null];
}

function insertarPresupuesto($data) {
    global $mysql;

    list($d, $error) = validarDatosPresupuesto($data);
    if ($error) {
        enviarRespuesta("error", $error);
        return;
    }

    // Verificar si ya existe un presupuesto con el mismo año y mes
    $stmtVerificar = $mysql->prepare("SELECT idPresupuesto FROM presupuestos WHERE Anho = ? AND Mes = ?");
    $stmtVerificar->bind_param("ii", $d["anho"], $d["mes"]);
    $stmtVerificar->execute();
    if ($stmtVerificar->get_result()->num_rows > 0) {
        enviarRespuesta("error", "Ya existe un presupuesto para el mes y año proporcionados");
        return;
    }

    $stmt = $mysql->prepare("INSERT INTO presupuestos (ValorPresupuesto, ExtrasMes, Anho, Mes) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiii", $d["valor"], $d["extras"], $d["anho"], $d["mes"]);

    if ($stmt->execute()) {
        enviarRespuesta("success", "Presupuesto insertado correctamente");
    } else {
        enviarRespuesta("error", "Error al insertar el presupuesto");
    }
}

// Editar un presupuesto (se identifica por año y mes)
function editarPresupuesto($data) {
    global $mysql;

    list($d, $error) = validarDatosPresupuesto($data);
    if ($error) {
        enviarRespuesta("error", $error);
        return;
    }

    $stmtVerificar = $mysql->prepare("SELECT idPresupuesto FROM presupuestos WHERE Anho = ? AND Mes = ?");
    $stmtVerificar->bind_param("ii", $d["anho"], $d["mes"]);
    $stmtVerificar->execute();
    if ($stmtVerificar->get_result()->num_rows === 0) {
        enviarRespuesta("error", "No se encontró el presupuesto para el mes y año proporcionados");
        return;
    }

    $stmt = $mysql->prepare("UPDATE presupuestos SET ValorPresupuesto = ?, ExtrasMes = ? WHERE Anho = ? AND Mes = ?");
    $stmt->bind_param("iiii", $d["valor"], $d["extras"], $d["anho"], $d["mes"]);

    if ($stmt->execute()) {
        enviarRespuesta("success", "Presupuesto actualizado correctamente");
    } else {
        enviarRespuesta("error", "Error al actualizar el presupuesto");
    }
}

// Eliminar un presupuesto (solo si no tiene gastos: la BD borraría los gastos en cascada)
function eliminarPresupuesto($idPresupuesto) {
    global $mysql;

    $id = appfinanzas_entero($idPresupuesto);
    if ($id === null || $id <= 0) {
        enviarRespuesta("error", "Identificador de presupuesto no válido");
        return;
    }

    $stmtGastos = $mysql->prepare("SELECT COUNT(*) AS total FROM gastos WHERE idPresupuesto = ?");
    $stmtGastos->bind_param("i", $id);
    $stmtGastos->execute();
    $total = (int)$stmtGastos->get_result()->fetch_assoc()['total'];
    if ($total > 0) {
        enviarRespuesta("error", "No se puede eliminar: el presupuesto tiene $total gasto(s) registrados. Elimínalos primero.");
        return;
    }

    $stmt = $mysql->prepare("DELETE FROM presupuestos WHERE idPresupuesto = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        enviarRespuesta("success", "Presupuesto eliminado correctamente");
    } else {
        enviarRespuesta("error", "No se encontró el presupuesto a eliminar");
    }
}

// Consultar todos los presupuestos (lista vacía si no hay)
function consultarPresupuestos() {
    global $mysql;

    $query = "SELECT idPresupuesto, ValorPresupuesto, COALESCE(ExtrasMes, 0) AS ExtrasMes, Anho, Mes
              FROM presupuestos ORDER BY Anho, Mes";
    $result = $mysql->query($query);

    $response = [];
    while ($row = $result->fetch_assoc()) {
        $response[] = $row;
    }
    header('Content-Type: application/json');
    echo json_encode($response);
}

function consultarPresupuestoPorMesAnho($mes, $anho) {
    global $mysql;

    $mes = appfinanzas_entero($mes);
    $anho = appfinanzas_entero($anho);
    if ($mes === null || $anho === null) {
        enviarRespuesta("error", "Mes o año no válidos");
        return;
    }

    $query = "SELECT idPresupuesto, ValorPresupuesto, COALESCE(ExtrasMes, 0) AS ExtrasMes, Anho, Mes
              FROM presupuestos WHERE Mes = ? AND Anho = ?";
    $stmt = $mysql->prepare($query);
    $stmt->bind_param("ii", $mes, $anho);
    $stmt->execute();

    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        header('Content-Type: application/json');
        echo json_encode($result->fetch_assoc());
    } else {
        enviarRespuesta("error", "No se encontró el presupuesto para el mes y año proporcionados");
    }
}

// Consultar costos previstos por estado para un presupuesto (lista vacía si no hay gastos)
function consultarCostosPorEstado($idPresupuesto) {
    global $mysql;

    $id = appfinanzas_entero($idPresupuesto);
    if ($id === null) {
        enviarRespuesta("error", "Identificador de presupuesto no válido");
        return;
    }

    $query = "
        SELECT e.NombreEstado, e.ColorEstado, SUM(g.CostoPrevisto) AS TotalCostoPrevisto
        FROM gastos g
        INNER JOIN estados e ON g.IdEstado = e.idEstado
        WHERE g.idPresupuesto = ?
        GROUP BY e.NombreEstado, e.ColorEstado";
    $stmt = $mysql->prepare($query);
    $stmt->bind_param("i", $id);
    $stmt->execute();

    $response = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $response[] = $row;
    }
    header('Content-Type: application/json');
    echo json_encode($response);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion == 'insertar') {
        insertarPresupuesto($_POST);
    } elseif ($accion == 'editar') {
        editarPresupuesto($_POST);
    } elseif ($accion == 'eliminar') {
        // La app envía "idPresupuesto"; se acepta también "id" por compatibilidad.
        eliminarPresupuesto($_POST['idPresupuesto'] ?? ($_POST['id'] ?? null));
    } else {
        enviarRespuesta("error", "Acción no válida");
    }
} elseif ($_SERVER['REQUEST_METHOD'] == 'GET') {
    if (!empty($_GET['idPresupuesto'])) {
        consultarCostosPorEstado($_GET['idPresupuesto']);
    } elseif (!empty($_GET['mes']) && !empty($_GET['anho'])) {
        consultarPresupuestoPorMesAnho($_GET['mes'], $_GET['anho']);
    } else {
        consultarPresupuestos();
    }
}
