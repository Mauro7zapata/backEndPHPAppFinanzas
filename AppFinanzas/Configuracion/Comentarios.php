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
//        Imágenes (migración 021): crear acepta imagenes:[base64,…] (máx. 3, ≤ 1,5 MB c/u, JPEG/PNG/WebP).
//   GET  Comentarios.php?imagen=ID -> bytes de la imagen (solo si el reporte es del usuario)
//        La lista incluye imagenes:[ids] por reporte.

define('MSG_MIGRACION_019', 'Falta ejecutar la migración 019 en la base de datos');
const COMENTARIOS_TIPOS = ['Mejora', 'Error'];
const COMENTARIOS_MAX_DIA = 10;
const COMENTARIOS_MAX_IMAGENES = 3;
const COMENTARIOS_MAX_BYTES_IMAGEN = 1572864; // 1,5 MB ya reducida por la app
const COMENTARIOS_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
define('MSG_MIGRACION_021', 'Falta ejecutar la migración 021 en la base de datos');

function tablaImagenesExiste() {
    global $mysql;
    return $mysql->query("SHOW TABLES LIKE 'comentarios_app_imagenes'")->num_rows > 0;
}

// Decodifica y valida las imágenes del envío. Devuelve [lista de [mime, bytes], error].
function validarImagenes($crudas) {
    if (!is_array($crudas) || !$crudas) return [[], null];
    if (count($crudas) > COMENTARIOS_MAX_IMAGENES) return [null, 'Máximo ' . COMENTARIOS_MAX_IMAGENES . ' imágenes por reporte'];
    $lista = [];
    foreach ($crudas as $b64) {
        $bin = base64_decode((string)$b64, true);
        if ($bin === false || $bin === '') return [null, 'Una de las imágenes no es válida'];
        if (strlen($bin) > COMENTARIOS_MAX_BYTES_IMAGEN) return [null, 'Una imagen es demasiado pesada'];
        $info = @getimagesizefromstring($bin);
        if (!$info || !in_array($info['mime'], COMENTARIOS_MIMES, true)) return [null, 'Solo se aceptan imágenes JPG, PNG o WebP'];
        $lista[] = [$info['mime'], $bin];
    }
    return [$lista, null];
}

function enviarImagen($id) {
    global $mysql, $uid;
    $q = $mysql->prepare("SELECT i.Mime, i.Imagen FROM comentarios_app_imagenes i
        INNER JOIN comentarios_app c ON c.idComentario = i.idComentario WHERE i.idImagen = ? AND c.IdUsuario = ?");
    $q->bind_param('ii', $id, $uid);
    $q->execute();
    $f = $q->get_result()->fetch_assoc();
    if (!$f) { http_response_code(404); echo json_encode(['error' => 'No se encontró la imagen']); return; }
    header('Content-Type: ' . $f['Mime']);
    header('Cache-Control: private, max-age=86400');
    echo $f['Imagen'];
}

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
    // Imágenes adjuntas (migración 021); si la tabla aún no existe, los reportes salen sin imágenes.
    $imagenes = [];
    try {
        if (tablaImagenesExiste()) {
            $qi = $mysql->prepare("SELECT i.idImagen, i.idComentario FROM comentarios_app_imagenes i
                INNER JOIN comentarios_app c ON c.idComentario = i.idComentario WHERE c.IdUsuario = ? ORDER BY i.idImagen");
            $qi->bind_param('i', $uid);
            $qi->execute();
            foreach ($qi->get_result()->fetch_all(MYSQLI_ASSOC) as $i) $imagenes[(int)$i['idComentario']][] = (int)$i['idImagen'];
        }
    } catch (mysqli_sql_exception $e) { /* sin imágenes */ }
    echo json_encode(['comentarios' => array_map(function ($f) use ($imagenes) {
        return ['idComentario' => (int)$f['idComentario'], 'tipo' => $f['Tipo'], 'titulo' => $f['Titulo'],
            'descripcion' => $f['Descripcion'], 'estado' => $f['Estado'], 'respuesta' => $f['Respuesta'],
            'fecha' => substr($f['FechaCreacion'], 0, 10), 'imagenes' => $imagenes[(int)$f['idComentario']] ?? []];
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
            list($imgs, $errImg) = validarImagenes($d['imagenes'] ?? null);
            if ($errImg) { echo json_encode(['error' => $errImg]); return; }
            if ($imgs && !tablaImagenesExiste()) { echo json_encode(['error' => MSG_MIGRACION_021]); return; }
            $version = texto_limpio($d['version'] ?? '', 30) ?: null;
            $disp = texto_limpio($d['dispositivo'] ?? '', 120) ?: null;
            $amb = preg_replace('/[^a-z]/', '', strtolower(texto_limpio($d['ambiente'] ?? '', 10))) ?: null;
            $ins = $mysql->prepare("INSERT INTO comentarios_app (IdUsuario, Tipo, Titulo, Descripcion, VersionApp, Dispositivo, Ambiente) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $ins->bind_param('issssss', $uid, $tipo, $titulo, $desc, $version, $disp, $amb);
            $ins->execute();
            $idNuevo = $mysql->insert_id;
            foreach ($imgs as [$mime, $bin]) {
                $n = strlen($bin);
                $im = $mysql->prepare("INSERT INTO comentarios_app_imagenes (idComentario, Mime, Bytes, Imagen) VALUES (?, ?, ?, ?)");
                $nulo = null;
                $im->bind_param('isib', $idNuevo, $mime, $n, $nulo);
                $im->send_long_data(3, $bin);
                $im->execute();
            }
            echo json_encode(['id' => $idNuevo]);
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
    } elseif (isset($_GET['imagen'])) {
        $idImg = appfinanzas_entero($_GET['imagen']);
        if ($idImg === null) { echo json_encode(['error' => 'Identificador no válido']); exit; }
        enviarImagen($idImg);
    } else {
        listarComentarios();
    }
} catch (mysqli_sql_exception $ex) {
    error_log('[AppFinanzas] Comentarios.php: ' . $ex->getMessage());
    echo json_encode(['error' => (int)$ex->getCode() === 1146 ? MSG_MIGRACION_019 : 'Error al procesar el reporte']);
}
