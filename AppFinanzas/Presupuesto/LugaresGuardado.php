<?php
require_once("../db.php");
require_once(__DIR__ . '/../lib.php');

header('Content-Type: application/json; charset=utf-8');

// Catálogo «lugares de guardado»: dónde tiene el usuario el dinero de los gastos Guardado/Acumulado
// (bolsillo físico, bolsillo Nequi, meta Nequi...). Requiere la migración 012.
//
//   GET  LugaresGuardado.php                    -> {lugares:[{idLugar, nombre, guardado, acumulado, total, gastos}],
//                                                   sinLugar:{guardado, acumulado, total, gastos}, total}
//   GET  LugaresGuardado.php?idPresupuesto=ID   -> igual, pero solo con lo separado en ese presupuesto
//   POST (JSON) accion: crear {nombre} | renombrar {idLugar, nombre} | eliminar {idLugar}
//        Al eliminar un lugar, los gastos que lo usaban quedan «sin lugar» (no se pierde ningún dato).
//
// guardado = movimientos de gastos en estado «Guardado»; acumulado = en estado «Acumulado».

define('MSG_MIGRACION_012', 'Falta ejecutar la migración 012 en la base de datos');

function nombreLugarValido($nombre) {
    $n = mb_substr(trim((string)$nombre), 0, 60, 'UTF-8');
    return $n === '' ? null : $n;
}

function detalleLugares($idPresupuesto) {
    global $mysql, $uid;

    $q = $mysql->prepare("SELECT idLugar, Nombre FROM lugares_guardado WHERE IdUsuario = ? ORDER BY Nombre");
    $q->bind_param('i', $uid);
    $q->execute();
    $lugares = [];
    foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $l) {
        $lugares[(int)$l['idLugar']] = ['idLugar' => (int)$l['idLugar'], 'nombre' => $l['Nombre'],
            'guardado' => 0.0, 'acumulado' => 0.0, 'total' => 0.0, 'gastos' => 0];
    }
    $sinLugar = ['guardado' => 0.0, 'acumulado' => 0.0, 'total' => 0.0, 'gastos' => 0];

    $sql = "SELECT g.idLugar, e.NombreEstado, COALESCE(SUM(g.valorGastosMovimiento), 0) AS t, COUNT(*) AS n
            FROM gastos g INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
            INNER JOIN estados e ON e.idEstado = g.IdEstado AND e.IdUsuario = p.IdUsuario
            WHERE p.IdUsuario = ? AND e.NombreEstado IN ('Guardado', 'Acumulado')";
    if ($idPresupuesto > 0) $sql .= " AND p.idPresupuesto = ?";
    $sql .= " GROUP BY g.idLugar, e.NombreEstado";
    $s = $mysql->prepare($sql);
    if ($idPresupuesto > 0) $s->bind_param('ii', $uid, $idPresupuesto); else $s->bind_param('i', $uid);
    $s->execute();
    foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
        $campo = $f['NombreEstado'] === 'Guardado' ? 'guardado' : 'acumulado';
        $id = $f['idLugar'] === null ? 0 : (int)$f['idLugar'];
        $ref = &$sinLugar;
        if ($id > 0 && isset($lugares[$id])) { $ref = &$lugares[$id]; }
        $ref[$campo] += (float)$f['t'];
        $ref['total'] += (float)$f['t'];
        $ref['gastos'] += (int)$f['n'];
        unset($ref);
    }
    $total = $sinLugar['total'];
    foreach ($lugares as $l) $total += $l['total'];
    return ['lugares' => array_values($lugares), 'sinLugar' => $sinLugar, 'total' => $total];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $idP = appfinanzas_entero($_GET['idPresupuesto'] ?? null) ?? 0;
        if ($idP > 0 && !appfinanzas_es_propio('presupuestos', $idP)) { echo json_encode(['error' => 'El presupuesto no existe']); exit; }
        echo json_encode(detalleLugares($idP), JSON_UNESCAPED_UNICODE);
    } catch (mysqli_sql_exception $ex) {
        error_log('[AppFinanzas] LugaresGuardado.php GET: ' . $ex->getMessage());
        echo json_encode(['error' => MSG_MIGRACION_012]);
    }
    exit;
}

$d = json_decode(file_get_contents('php://input'), true);
if (!is_array($d)) $d = $_POST;

try {
    switch ($d['accion'] ?? '') {
        case 'crear':
            $nombre = nombreLugarValido($d['nombre'] ?? '');
            if ($nombre === null) { echo json_encode(['error' => 'Escribe el nombre del lugar']); break; }
            $q = $mysql->prepare("INSERT INTO lugares_guardado (IdUsuario, Nombre) VALUES (?, ?)");
            $q->bind_param('is', $uid, $nombre);
            $q->execute();
            echo json_encode(['id' => $mysql->insert_id]);
            break;

        case 'renombrar':
            $id = appfinanzas_entero($d['idLugar'] ?? null);
            $nombre = nombreLugarValido($d['nombre'] ?? '');
            if ($id === null || $id <= 0 || $nombre === null) { echo json_encode(['error' => 'Datos no válidos']); break; }
            $q = $mysql->prepare("UPDATE lugares_guardado SET Nombre = ? WHERE idLugar = ? AND IdUsuario = ?");
            $q->bind_param('sii', $nombre, $id, $uid);
            $q->execute();
            echo json_encode(['updated' => true]);
            break;

        case 'eliminar':
            $id = appfinanzas_entero($d['idLugar'] ?? null);
            if ($id === null || $id <= 0) { echo json_encode(['error' => 'Identificador no válido']); break; }
            $q = $mysql->prepare("DELETE FROM lugares_guardado WHERE idLugar = ? AND IdUsuario = ?");
            $q->bind_param('ii', $id, $uid);
            $q->execute();
            echo json_encode(['deleted' => $q->affected_rows > 0]);
            break;

        default:
            echo json_encode(['error' => 'Acción no válida']);
    }
} catch (mysqli_sql_exception $ex) {
    $codigo = (int)$ex->getCode();
    if ($codigo === 1062) { echo json_encode(['error' => 'Ya tienes un lugar con ese nombre']); exit; }
    error_log('[AppFinanzas] LugaresGuardado.php: ' . $ex->getMessage());
    echo json_encode(['error' => $codigo === 1146 ? MSG_MIGRACION_012 : 'Error al procesar la acción']);
}
