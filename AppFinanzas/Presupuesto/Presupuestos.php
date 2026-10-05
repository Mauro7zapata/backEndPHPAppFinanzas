<?php
require_once("../db.php");
require_once(__DIR__ . '/../lib.php');

// Función para manejar respuestas
function enviarRespuesta($status, $message, $extra = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge([
        "status" => $status,
        "message" => $message
    ], $extra), JSON_UNESCAPED_UNICODE);
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
    global $mysql, $uid;

    list($d, $error) = validarDatosPresupuesto($data);
    if ($error) {
        enviarRespuesta("error", $error);
        return;
    }

    // Verificar si el usuario ya tiene un presupuesto con el mismo año y mes
    $stmtVerificar = $mysql->prepare("SELECT idPresupuesto FROM presupuestos WHERE Anho = ? AND Mes = ? AND IdUsuario = ?");
    $stmtVerificar->bind_param("iii", $d["anho"], $d["mes"], $uid);
    $stmtVerificar->execute();
    if ($stmtVerificar->get_result()->num_rows > 0) {
        enviarRespuesta("error", "Ya existe un presupuesto para el mes y año proporcionados");
        return;
    }

    // Cierre de mes: si un mes ya terminado sigue sin finalizar, no se crean presupuestos nuevos hasta cerrarlo.
    if ($pendiente = presupuestoPendienteDeCierre()) {
        enviarRespuesta("error", "Antes de crear un presupuesto nuevo, finaliza el de " . nombreMesEs($pendiente['mes']) . " " . $pendiente['anho'] .
            ": ábrelo y usa «Finalizar presupuesto», o termina de pagar y acumular sus gastos pendientes.",
            ["bloqueado" => true, "mesPendiente" => $pendiente['mes'], "anhoPendiente" => $pendiente['anho']]);
        return;
    }

    $stmt = $mysql->prepare("INSERT INTO presupuestos (ValorPresupuesto, ExtrasMes, Anho, Mes, IdUsuario) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iiiii", $d["valor"], $d["extras"], $d["anho"], $d["mes"], $uid);

    if ($stmt->execute()) {
        if (presupuestosTieneEstado()) reabrirPresupuesto($mysql->insert_id); // lo deja «En curso»
        enviarRespuesta("success", "Presupuesto insertado correctamente");
    } else {
        enviarRespuesta("error", "Error al insertar el presupuesto");
    }
}

// Editar un presupuesto (se identifica por año y mes)
function editarPresupuesto($data) {
    global $mysql, $uid;

    list($d, $error) = validarDatosPresupuesto($data);
    if ($error) {
        enviarRespuesta("error", $error);
        return;
    }

    $stmtVerificar = $mysql->prepare("SELECT idPresupuesto FROM presupuestos WHERE Anho = ? AND Mes = ? AND IdUsuario = ?");
    $stmtVerificar->bind_param("iii", $d["anho"], $d["mes"], $uid);
    $stmtVerificar->execute();
    if ($stmtVerificar->get_result()->num_rows === 0) {
        enviarRespuesta("error", "No se encontró el presupuesto para el mes y año proporcionados");
        return;
    }

    $stmt = $mysql->prepare("UPDATE presupuestos SET ValorPresupuesto = ?, ExtrasMes = ? WHERE Anho = ? AND Mes = ? AND IdUsuario = ?");
    $stmt->bind_param("iiiii", $d["valor"], $d["extras"], $d["anho"], $d["mes"], $uid);

    if ($stmt->execute()) {
        enviarRespuesta("success", "Presupuesto actualizado correctamente");
    } else {
        enviarRespuesta("error", "Error al actualizar el presupuesto");
    }
}

// Eliminar un presupuesto (solo si no tiene gastos: la BD borraría los gastos en cascada)
function eliminarPresupuesto($idPresupuesto) {
    global $mysql, $uid;

    $id = appfinanzas_entero($idPresupuesto);
    if ($id === null || $id <= 0) {
        enviarRespuesta("error", "Identificador de presupuesto no válido");
        return;
    }

    $stmtGastos = $mysql->prepare("SELECT COUNT(*) AS total FROM gastos g INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
        WHERE g.idPresupuesto = ? AND p.IdUsuario = ?");
    $stmtGastos->bind_param("ii", $id, $uid);
    $stmtGastos->execute();
    $total = (int)$stmtGastos->get_result()->fetch_assoc()['total'];
    if ($total > 0) {
        enviarRespuesta("error", "No se puede eliminar: el presupuesto tiene $total gasto(s) registrados. Elimínalos primero.");
        return;
    }

    $stmt = $mysql->prepare("DELETE FROM presupuestos WHERE idPresupuesto = ? AND IdUsuario = ?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        enviarRespuesta("success", "Presupuesto eliminado correctamente");
    } else {
        enviarRespuesta("error", "No se encontró el presupuesto a eliminar");
    }
}

// Finaliza el presupuesto a mano. Si aún tiene gastos abiertos pide confirmación (status "confirmar"; reenviar con forzar=1).
function finalizarPresupuestoManual($idPresupuesto, $forzar) {
    $id = appfinanzas_entero($idPresupuesto);
    if ($id === null || $id <= 0 || !appfinanzas_es_propio('presupuestos', $id)) {
        enviarRespuesta("error", "No se encontró el presupuesto");
        return;
    }
    if (!presupuestosTieneEstado()) {
        enviarRespuesta("error", "Falta ejecutar la migración 010 en la base de datos");
        return;
    }
    $r = resumenCierrePresupuesto($id);
    if ($r['abiertos'] > 0 && !$forzar) {
        enviarRespuesta("confirmar", "Aún hay " . $r['abiertos'] . ($r['abiertos'] == 1 ? " gasto sin pagar o acumular." : " gastos sin pagar o acumular.") .
            " ¿Finalizar el presupuesto de todos modos?", ["abiertos" => $r['abiertos']]);
        return;
    }
    finalizarPresupuesto($id, $r['abiertos'] > 0);
    enviarRespuesta("success", "Presupuesto finalizado correctamente");
}

function reabrirPresupuestoManual($idPresupuesto) {
    $id = appfinanzas_entero($idPresupuesto);
    if ($id === null || $id <= 0 || !appfinanzas_es_propio('presupuestos', $id)) {
        enviarRespuesta("error", "No se encontró el presupuesto");
        return;
    }
    if (!presupuestosTieneEstado()) {
        enviarRespuesta("error", "Falta ejecutar la migración 010 en la base de datos");
        return;
    }
    reabrirPresupuesto($id);
    enviarRespuesta("success", "Presupuesto reabierto correctamente");
}

// Consultar todos los presupuestos (lista vacía si no hay)
function consultarPresupuestos() {
    global $mysql, $uid;

    list($selEstado, $joinEstado) = sqlEstadoPresupuesto();
    $query = "SELECT p.idPresupuesto, p.ValorPresupuesto, COALESCE(p.ExtrasMes, 0) AS ExtrasMes, p.Anho, p.Mes, $selEstado
              FROM presupuestos p $joinEstado WHERE p.IdUsuario = ? ORDER BY p.Anho, p.Mes";
    $stmt = $mysql->prepare($query);
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $response = appfinanzas_filas_texto($stmt->get_result());
    header('Content-Type: application/json');
    echo json_encode($response);
}

function consultarPresupuestoPorMesAnho($mes, $anho) {
    global $mysql, $uid;

    $mes = appfinanzas_entero($mes);
    $anho = appfinanzas_entero($anho);
    if ($mes === null || $anho === null) {
        enviarRespuesta("error", "Mes o año no válidos");
        return;
    }

    list($selEstado, $joinEstado) = sqlEstadoPresupuesto();
    $query = "SELECT p.idPresupuesto, p.ValorPresupuesto, COALESCE(p.ExtrasMes, 0) AS ExtrasMes, p.Anho, p.Mes, $selEstado
              FROM presupuestos p $joinEstado WHERE p.Mes = ? AND p.Anho = ? AND p.IdUsuario = ?";
    $stmt = $mysql->prepare($query);
    $stmt->bind_param("iii", $mes, $anho, $uid);
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
    global $mysql, $uid;

    $id = appfinanzas_entero($idPresupuesto);
    if ($id === null) {
        enviarRespuesta("error", "Identificador de presupuesto no válido");
        return;
    }

    $query = "
        SELECT e.NombreEstado, e.ColorEstado, SUM(g.CostoPrevisto) AS TotalCostoPrevisto
        FROM gastos g
        INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
        INNER JOIN estados e ON g.IdEstado = e.idEstado AND e.IdUsuario = p.IdUsuario
        WHERE g.idPresupuesto = ? AND p.IdUsuario = ?
        GROUP BY e.NombreEstado, e.ColorEstado";
    $stmt = $mysql->prepare($query);
    $stmt->bind_param("ii", $id, $uid);
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
    } elseif ($accion == 'finalizar') {
        finalizarPresupuestoManual($_POST['idPresupuesto'] ?? null, !empty($_POST['forzar']));
    } elseif ($accion == 'reabrir') {
        reabrirPresupuestoManual($_POST['idPresupuesto'] ?? null);
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
