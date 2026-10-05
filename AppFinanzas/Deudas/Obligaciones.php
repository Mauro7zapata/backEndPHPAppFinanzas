<?php
require_once("../db.php");
require_once("../lib.php");

header('Content-Type: application/json; charset=utf-8');

// Obligaciones anuales (renta, impuesto vehicular, SOAT, tecnomecánica, ...).
//
//   GET  Obligaciones.php?mes=M&anho=A  -> lista con ahorrado y cuota de provisión sugerida para ese mes (por defecto, el actual)
//   GET  Obligaciones.php?detalle=ID    -> movimientos y gastos del presupuesto vinculados a la obligación (acumulado y pagos)
//   POST Obligaciones.php (JSON)        -> accion: crear | actualizar | eliminar | pagar
//
// "pagar" cierra el ciclo: el vencimiento avanza un año y el conteo de lo ahorrado empieza de nuevo.

function validarObligacion($d) {
    if (trim($d['nombre'] ?? '') === '' || mb_strlen($d['nombre'], 'UTF-8') > 90) return 'El nombre es obligatorio (máx. 90 caracteres)';
    if (appfinanzas_entero($d['idCategoria'] ?? null) === null) return 'La categoría no es válida';
    if (!is_numeric($d['valorEstimado'] ?? null) || $d['valorEstimado'] <= 0 || $d['valorEstimado'] >= 1000000000) return 'El valor estimado no es válido';
    if (!appfinanzas_fecha_valida($d['fechaVencimiento'] ?? '')) return 'La fecha de vencimiento no es válida (use AAAA-MM-DD)';
    $ini = $d['ahorradoInicial'] ?? 0;
    if ($ini !== null && $ini !== '' && (!is_numeric($ini) || $ini < 0 || $ini >= 1000000000)) return 'El valor ya ahorrado no es válido';
    return null;
}

// La categoría debe ser del usuario autenticado (no se revela si existe para otro usuario).
function categoriaDelUsuario($idCategoria) {
    global $mysql, $uid;
    $id = appfinanzas_entero($idCategoria);
    if ($id === null) return false;
    $q = $mysql->prepare("SELECT 1 FROM categoriagastos WHERE idCategoriaGastos = ? AND IdUsuario = ?");
    $q->bind_param('ii', $id, $uid);
    $q->execute();
    return (bool)$q->get_result()->fetch_row();
}

function listarObligaciones($mes, $anho) {
    global $mysql, $uid;
    $stmt = $mysql->prepare("SELECT o.*, c.NombreCategoria FROM obligaciones o
        INNER JOIN categoriagastos c ON c.idCategoriaGastos = o.IdCategoria AND c.IdUsuario = o.IdUsuario
        WHERE o.IdUsuario = ? ORDER BY o.Activa DESC, o.FechaVencimiento");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $hoy = new DateTime('today');
    $salida = [];
    foreach ($res->fetch_all(MYSQLI_ASSOC) as $o) {
        $ahorrado = ahorradoObligacion($o['idObligacion'], $o['CicloInicio']);
        $venc = new DateTime($o['FechaVencimiento']);
        $salida[] = [
            'idObligacion' => (int)$o['idObligacion'],
            'nombre' => $o['Nombre'],
            'idCategoria' => (int)$o['IdCategoria'],
            'nombreCategoria' => $o['NombreCategoria'],
            'valorEstimado' => (float)$o['ValorEstimado'],
            'fechaVencimiento' => $o['FechaVencimiento'],
            'diasParaVencer' => (int)$hoy->diff($venc)->format('%r%a'),
            'ahorrado' => $ahorrado,
            'ahorradoInicial' => obligacionesTieneAhorroInicial() ? (float)$o['AhorradoInicial'] : 0.0,
            'faltante' => max(0, round((float)$o['ValorEstimado'] - $ahorrado, 2)),
            'cuotaProvision' => cuotaProvision($o['ValorEstimado'], $ahorrado, $o['FechaVencimiento'], $mes, $anho),
            'activa' => (int)$o['Activa'] === 1,
            'notas' => $o['Notas'],
        ];
    }
    echo json_encode($salida, JSON_UNESCAPED_UNICODE);
}

// Movimientos y gastos del presupuesto vinculados a una obligación, con lo acumulado y lo pagado.
function detalleObligacion($idObligacion) {
    global $mysql, $uid;
    $q = $mysql->prepare("SELECT idObligacion, Nombre, CicloInicio FROM obligaciones WHERE idObligacion = ? AND IdUsuario = ?");
    $q->bind_param('ii', $idObligacion, $uid);
    $q->execute();
    $o = $q->get_result()->fetch_assoc();
    if (!$o) { echo json_encode(['error' => 'La obligación no existe']); return; }
    $c = new DateTime($o['CicloInicio']);
    $indiceCiclo = (int)$c->format('Y') * 12 + (int)$c->format('n');

    $stmt = $mysql->prepare("SELECT g.idGastos, g.NombreGasto, g.CostoPrevisto, g.valorGastosMovimiento, e.NombreEstado, c.NombreCategoria, p.Mes, p.Anho
        FROM gastos g INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto AND p.IdUsuario = ?
        LEFT JOIN estados e ON e.idEstado = g.IdEstado AND e.IdUsuario = p.IdUsuario
        LEFT JOIN categoriagastos c ON c.idCategoriaGastos = g.IdCategoria AND c.IdUsuario = p.IdUsuario
        WHERE g.idObligacion = ? ORDER BY p.Anho DESC, p.Mes DESC, g.NombreGasto");
    $stmt->bind_param('ii', $uid, $idObligacion);
    $stmt->execute();
    $gastos = [];
    $acumulado = 0.0; $pagado = 0.0;
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $g) {
        $delCiclo = ((int)$g['Anho'] * 12 + (int)$g['Mes']) >= $indiceCiclo;
        if ($delCiclo && $g['NombreEstado'] === 'Acumulado') $acumulado += (float)$g['valorGastosMovimiento'];
        if ($delCiclo && $g['NombreEstado'] === 'Pagado') $pagado += (float)$g['valorGastosMovimiento'];
        $gastos[] = ['idGasto' => (int)$g['idGastos'], 'nombre' => $g['NombreGasto'], 'estado' => $g['NombreEstado'], 'categoria' => $g['NombreCategoria'],
            'previsto' => (float)$g['CostoPrevisto'], 'pagado' => (float)$g['valorGastosMovimiento'], 'mes' => (int)$g['Mes'], 'anho' => (int)$g['Anho'], 'delCiclo' => $delCiclo];
    }

    $stmt = $mysql->prepare("SELECT m.idMovimiento, m.fechaMovimiento, m.valorMovimiento, g.NombreGasto, e.NombreEstado, c.NombreCategoria, p.Mes, p.Anho
        FROM movimientos m INNER JOIN gastos g ON g.idGastos = m.idGasto
        INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto AND p.IdUsuario = ?
        LEFT JOIN estados e ON e.idEstado = g.IdEstado AND e.IdUsuario = p.IdUsuario
        LEFT JOIN categoriagastos c ON c.idCategoriaGastos = g.IdCategoria AND c.IdUsuario = p.IdUsuario
        WHERE g.idObligacion = ? ORDER BY m.fechaMovimiento DESC, m.idMovimiento DESC LIMIT 200");
    $stmt->bind_param('ii', $uid, $idObligacion);
    $stmt->execute();
    $movs = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $m) {
        $movs[] = ['idMovimiento' => (int)$m['idMovimiento'], 'fecha' => substr((string)$m['fechaMovimiento'], 0, 10), 'valor' => (float)$m['valorMovimiento'],
            'gasto' => $m['NombreGasto'], 'estado' => $m['NombreEstado'], 'categoria' => $m['NombreCategoria'], 'mes' => (int)$m['Mes'], 'anho' => (int)$m['Anho']];
    }
    echo json_encode(['idObligacion' => (int)$o['idObligacion'], 'nombre' => $o['Nombre'], 'acumuladoCiclo' => $acumulado, 'pagadoCiclo' => $pagado,
        'ahorradoInicial' => obligacionesTieneAhorroInicial() ? (float)$mysql->query("SELECT AhorradoInicial FROM obligaciones WHERE idObligacion = " . (int)$idObligacion)->fetch_assoc()['AhorradoInicial'] : 0.0,
        'gastos' => $gastos, 'movimientos' => $movs], JSON_UNESCAPED_UNICODE);
}

// Avance previo al uso de la app (requiere la migración 009; sin ella se ignora).
function guardarAhorroInicial($idObligacion, $d) {
    global $mysql, $uid;
    if (!obligacionesTieneAhorroInicial()) return;
    $v = ($d['ahorradoInicial'] ?? 0) === '' ? 0 : (float)($d['ahorradoInicial'] ?? 0);
    $q = $mysql->prepare("UPDATE obligaciones SET AhorradoInicial = ? WHERE idObligacion = ? AND IdUsuario = ?");
    $q->bind_param('dii', $v, $idObligacion, $uid);
    $q->execute();
}

function procesarObligacion($d) {
    global $mysql, $uid;
    $accion = $d['accion'] ?? '';
    try {
        switch ($accion) {
            case 'crear':
            case 'actualizar':
                if ($e = validarObligacion($d)) { echo json_encode(['error' => $e]); return; }
                if (!categoriaDelUsuario($d['idCategoria'])) { echo json_encode(['error' => 'La categoría no existe']); return; }
                $notas = $d['notas'] ?? null;
                $activa = array_key_exists('activa', $d) ? ($d['activa'] ? 1 : 0) : 1;
                if ($accion === 'crear') {
                    // El ciclo empieza el mes actual: lo provisionado antes de crear la obligación no se cuenta.
                    $ciclo = date('Y-m-01');
                    $stmt = $mysql->prepare("INSERT INTO obligaciones (Nombre, IdCategoria, ValorEstimado, FechaVencimiento, CicloInicio, Activa, Notas, IdUsuario) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('sidssisi', $d['nombre'], $d['idCategoria'], $d['valorEstimado'], $d['fechaVencimiento'], $ciclo, $activa, $notas, $uid);
                    $stmt->execute();
                    guardarAhorroInicial($mysql->insert_id, $d);
                    echo json_encode(['id' => $mysql->insert_id]);
                } else {
                    $idObl = appfinanzas_entero($d['idObligacion'] ?? null);
                    if ($idObl === null) { echo json_encode(['error' => 'Identificador no válido']); return; }
                    $q = $mysql->prepare("SELECT 1 FROM obligaciones WHERE idObligacion = ? AND IdUsuario = ?");
                    $q->bind_param('ii', $idObl, $uid); $q->execute();
                    if (!$q->get_result()->fetch_row()) { echo json_encode(['error' => 'La obligación no existe']); return; }
                    $stmt = $mysql->prepare("UPDATE obligaciones SET Nombre=?, IdCategoria=?, ValorEstimado=?, FechaVencimiento=?, Activa=?, Notas=? WHERE idObligacion=? AND IdUsuario=?");
                    $stmt->bind_param('sidsisii', $d['nombre'], $d['idCategoria'], $d['valorEstimado'], $d['fechaVencimiento'], $activa, $notas, $idObl, $uid);
                    $stmt->execute();
                    if (array_key_exists('ahorradoInicial', $d)) guardarAhorroInicial($idObl, $d);
                    echo json_encode(['updated' => true]);
                }
                break;

            case 'eliminar':
                $id = appfinanzas_entero($d['idObligacion'] ?? null);
                if ($id === null) { echo json_encode(['error' => 'Identificador no válido']); return; }
                $stmt = $mysql->prepare("DELETE FROM obligaciones WHERE idObligacion = ? AND IdUsuario = ?");
                $stmt->bind_param('ii', $id, $uid);
                $stmt->execute();
                echo json_encode(['deleted' => $stmt->affected_rows > 0]);
                break;

            case 'pagar':
                $id = appfinanzas_entero($d['idObligacion'] ?? null);
                if ($id === null) { echo json_encode(['error' => 'Identificador no válido']); return; }
                $ciclo = date('Y-m-01', strtotime('first day of next month'));
                $stmt = $mysql->prepare("UPDATE obligaciones SET FechaVencimiento = DATE_ADD(FechaVencimiento, INTERVAL 1 YEAR), CicloInicio = ? WHERE idObligacion = ? AND IdUsuario = ?");
                $stmt->bind_param('sii', $ciclo, $id, $uid);
                $stmt->execute();
                $actualizado = $stmt->affected_rows > 0;
                if (obligacionesTieneAhorroInicial()) { // al cerrar el ciclo el avance previo se acaba
                    $r = $mysql->prepare("UPDATE obligaciones SET AhorradoInicial = 0 WHERE idObligacion = ? AND IdUsuario = ?");
                    $r->bind_param('ii', $id, $uid);
                    $r->execute();
                }
                echo json_encode(['updated' => $actualizado]);
                break;

            default:
                echo json_encode(['error' => 'Acción no válida']);
        }
    } catch (mysqli_sql_exception $e) {
        error_log('[AppFinanzas] Obligaciones: ' . $e->getMessage());
        echo json_encode(['error' => $e->getCode() == 1452 ? 'La categoría no existe' : 'Error al procesar la obligación']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    procesarObligacion(is_array($input) ? $input : $_POST);
} elseif (isset($_GET['detalle'])) {
    detalleObligacion((int)appfinanzas_entero($_GET['detalle']));
} else {
    $mes = appfinanzas_entero($_GET['mes'] ?? null) ?: (int)date('n');
    $anho = appfinanzas_entero($_GET['anho'] ?? null) ?: (int)date('Y');
    listarObligaciones($mes, $anho);
}
