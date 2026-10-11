<?php
require_once("../db.php");

header('Content-Type: application/json; charset=utf-8');

// Sugerencias de mejora y reportes de errores del usuario (migración 019). Cada usuario ve solo los suyos.
//
//   GET  Comentarios.php -> {comentarios:[{idComentario, tipo (Mejora|Error), titulo, descripcion,
//                                          estado (Nuevo|En revision|Planeado|Resuelto|Descartado), respuesta, fecha}]}
//   POST (JSON) accion: crear {tipo, titulo, descripcion, version?, dispositivo?} | eliminar {idComentario}
//        Máximo 10 envíos cada 24 horas por usuario. Solo se pueden eliminar los que siguen en estado «Nuevo».
//        El estado y la respuesta los cambia el administrador (phpMyAdmin).

define('MSG_MIGRACION_019', 'Falta ejecutar la migración 019 en la base de datos');
const COMENTARIOS_TIPOS = ['Mejora', 'Error'];
const COMENTARIOS_MAX_DIA = 10;

function texto_limpio($v, $max) {
    return mb_substr(trim((string)$v), 0, $max, 'UTF-8');
}

function listarComentarios() {
    global $mysql, $uid;
    $q = $mysql->prepare("SELECT idComentario, Tipo, Titulo, Descripcion, Estado, Respuesta, FechaCreacion
        FROM comentarios_app WHERE IdUsuario = ? ORDER BY idComentario DESC LIMIT 100");
    $q->bind_param('i', $uid);
    $q->execute();
    $filas = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['comentarios' => array_map(function ($f) {
        return ['idComentario' => (int)$f['idComentario'], 'tipo' => $f['Tipo'], 'titulo' => $f['Titulo'],
            'descripcion' => $f['Descripcion'], 'estado' => $f['Estado'], 'respuesta' => $f['Respuesta'],
            'fecha' => substr($f['FechaCreacion'], 0, 10)];
    }, $filas)]);
}

function procesarComentario($d) {
    global $mysql, $uid;
    switch ($d['accion'] ?? '') {
        case 'crear':
            $tipo = (string)($d['tipo'] ?? '');
            if (!in_array($tipo, COMENTARIOS_TIPOS, true)) { echo json_encode(['error' => 'Elige si es una mejora o un error']); return; }
            $titulo = texto_limpio($d['titulo'] ?? '', 120);
            $desc = texto_limpio($d['descripcion'] ?? '', 2000);
            if (mb_strlen($titulo, 'UTF-8') < 3) { echo json_encode(['error' => 'Escribe un título (mínimo 3 letras)']); return; }
            if (mb_strlen($desc, 'UTF-8') < 10) { echo json_encode(['error' => 'Cuéntanos un poco más (mínimo 10 letras)']); return; }
            $q = $mysql->prepare("SELECT COUNT(*) n FROM comentarios_app WHERE IdUsuario = ? AND FechaCreacion > (NOW() - INTERVAL 1 DAY)");
            $q->bind_param('i', $uid);
            $q->execute();
            $n = (int)$q->get_result()->fetch_assoc()['n'];
            if ($n >= COMENTARIOS_MAX_DIA) { echo json_encode(['error' => 'Ya enviaste varios reportes hoy. Inténtalo de nuevo mañana.']); return; }
            $version = texto_limpio($d['version'] ?? '', 30) ?: null;
            $disp = texto_limpio($d['dispositivo'] ?? '', 120) ?: null;
            $amb = preg_replace('/[^a-z]/', '', strtolower(texto_limpio($d['ambiente'] ?? '', 10))) ?: null;
            $ins = $mysql->prepare("INSERT INTO comentarios_app (IdUsuario, Tipo, Titulo, Descripcion, VersionApp, Dispositivo, Ambiente) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $ins->bind_param('issssss', $uid, $tipo, $titulo, $desc, $version, $disp, $amb);
            $ins->execute();
            echo json_encode(['id' => $mysql->insert_id]);
            break;

        case 'eliminar':
            $id = appfinanzas_entero($d['idComentario'] ?? null);
            if ($id === null) { echo json_encode(['error' => 'Identificador no válido']); return; }
            $q = $mysql->prepare("DELETE FROM comentarios_app WHERE idComentario = ? AND IdUsuario = ? AND Estado = 'Nuevo'");
            $q->bind_param('ii', $id, $uid);
            $q->execute();
            echo json_encode($q->affected_rows > 0 ? ['deleted' => true]
                : ['error' => 'Solo se pueden eliminar los reportes que aún no se han revisado']);
            break;

        default:
            echo json_encode(['error' => 'Acción no válida']);
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        procesarComentario(is_array($input) ? $input : $_POST);
    } else {
        listarComentarios();
    }
} catch (mysqli_sql_exception $ex) {
    error_log('[AppFinanzas] Comentarios.php: ' . $ex->getMessage());
    echo json_encode(['error' => (int)$ex->getCode() === 1146 ? MSG_MIGRACION_019 : 'Error al procesar el reporte']);
}
