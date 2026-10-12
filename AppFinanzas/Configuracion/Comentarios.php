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
//   Comunidad (migración 022): GET ?tablero=1 -> reportes públicos de todos los usuarios (sin datos del autor), con
//        apoyos (cuántos dicen «a mí también»), yoApoyo, mio, reaperturas. POST: apoyar {idComentario, nota?} | quitarApoyo {idComentario}
//        | reabrir {idComentario, nota?} (solo reportes Resuelto/Descartado; pasan a «Nuevo») | visibilidad {idComentario, publico} (solo el dueño).
//        crear acepta publico (por defecto 1). Las imágenes solo las ve el dueño.
//   GET  Comentarios.php?imagen=ID -> bytes de la imagen (solo si el reporte es del usuario, o con cabecera X-Codigo-Admin válida)
//        La lista incluye imagenes:[ids] por reporte.
//   Administración (el código se valida contra config.local.php -> codigo_admin_sha256):
//        POST admin_validar {codigo} | admin_listar {codigo, estado?} (TODOS los reportes con versión, dispositivo, ambiente e imágenes)
//        | admin_estado {codigo, idComentario, estado, respuesta?} (cambia el estado y, si se envía, la respuesta).
//        Máx. 5 códigos erróneos cada 15 minutos por usuario; cada acción de administrador queda en el log (AUDITORIA).

define('MSG_MIGRACION_019', 'Falta ejecutar la migración 019 en la base de datos');
const COMENTARIOS_TIPOS = ['Mejora', 'Error'];
const COMENTARIOS_MAX_DIA = 10;
const COMENTARIOS_MAX_IMAGENES = 3;
const COMENTARIOS_MAX_BYTES_IMAGEN = 1572864; // 1,5 MB ya reducida por la app
const COMENTARIOS_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
define('MSG_MIGRACION_022', 'Falta ejecutar la migración 022 en la base de datos');
define('MSG_MIGRACION_021', 'Falta ejecutar la migración 021 en la base de datos');

function columnaPublicoExiste() {
    global $mysql;
    return $mysql->query("SHOW COLUMNS FROM comentarios_app LIKE 'Publico'")->num_rows > 0;
}

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


// ---------------------------------------------------------------------------------------------
// Administración: un código largo (solo lo conoce el dueño de la app) habilita ver/exportar todos los reportes y
// cambiar su estado. En config.local.php solo se guarda su hash SHA-256. Los intentos fallidos se limitan por usuario.
// ---------------------------------------------------------------------------------------------
const COMENTARIOS_ESTADOS = ['Nuevo', 'En revision', 'Planeado', 'Resuelto', 'Descartado'];
const ADMIN_MAX_FALLOS = 5;
const ADMIN_VENTANA_SEG = 900; // 15 minutos

// Archivo temporal con los fallos recientes del usuario: [marcas de tiempo]. No requiere migración.
function adminArchivoFallos() {
    global $uid;
    return sys_get_temp_dir() . '/appfinanzas_admin_' . (int)$uid . '.json';
}

function adminFallosRecientes() {
    $ruta = adminArchivoFallos();
    $lista = is_file($ruta) ? json_decode((string)@file_get_contents($ruta), true) : [];
    $limite = time() - ADMIN_VENTANA_SEG;
    return array_values(array_filter(is_array($lista) ? $lista : [], function ($t) use ($limite) { return (int)$t >= $limite; }));
}

// true si el código es el de administrador. Devuelve false (y registra el fallo) si no; nunca revela por qué
// (salvo con 'depurar' => true en config.local.php, que deja el motivo en $GLOBALS['admin_motivo'] para diagnosticar).
function codigoAdminValido($codigo) {
    global $appConfig, $uid;   // $config ya no existe: db.php la borra y deja en $appConfig los ajustes no sensibles
    $esperado = strtolower((string)($appConfig['codigo_admin_sha256'] ?? ''));
    if ($esperado === '' || strlen($esperado) !== 64) {                        // administración desactivada
        $GLOBALS['admin_motivo'] = 'config.local.php no tiene un hash SHA-256 válido (largo ' . strlen($esperado) . ', debe ser 64)';
        return false;
    }
    $fallos = adminFallosRecientes();
    if (count($fallos) >= ADMIN_MAX_FALLOS) {                                  // bloqueado temporalmente
        $GLOBALS['admin_motivo'] = 'bloqueado por intentos fallidos';
        return false;
    }
    // Se ignoran espacios, guiones y mayúsculas/minúsculas: así no importa cómo lo escriba o lo pegue el teclado.
    $codigo = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$codigo));
    $recibido = hash('sha256', $codigo);
    if ($codigo !== '' && hash_equals($esperado, $recibido)) return true;
    $GLOBALS['admin_motivo'] = 'no coincide: recibí ' . strlen($codigo) . ' caracteres, hash ' . substr($recibido, 0, 8) . '…, esperado ' . substr($esperado, 0, 8) . '…';
    $fallos[] = time();
    @file_put_contents(adminArchivoFallos(), json_encode($fallos), LOCK_EX);
    error_log('[AppFinanzas][AUDITORIA] código de administrador incorrecto (usuario ' . (int)$uid . ', fallos recientes ' . count($fallos) . ')');
    usleep(800000); // frena la adivinanza
    return false;
}

// Mensaje cuando el código no sirve (distingue el bloqueo para que la persona sepa que debe esperar).
function adminMensajeRechazo() {
    $mensaje = count(adminFallosRecientes()) >= ADMIN_MAX_FALLOS
        ? 'Demasiados intentos. Espera 15 minutos e inténtalo de nuevo.'
        : 'Código de administrador incorrecto';
    // Solo en modo depuración se añade el motivo exacto (nunca en producción).
    if (!empty($GLOBALS['appfinanzas_depurar']) && !empty($GLOBALS['admin_motivo'])) $mensaje .= ' [' . $GLOBALS['admin_motivo'] . ']';
    return $mensaje;
}

// TODOS los reportes (de todos los usuarios) con los datos para reproducir errores y las ids de sus imágenes.
function listarParaAdmin($estado) {
    global $mysql;
    $colPublico = columnaPublicoExiste() ? 'c.Publico' : '0';
    $sql = "SELECT c.idComentario, c.IdUsuario, c.Tipo, c.Titulo, c.Descripcion, c.Estado, c.Respuesta, c.VersionApp, c.Dispositivo,
            c.Ambiente, c.FechaCreacion, $colPublico AS Publico";
    $sql .= tablaApoyosExiste()
        ? ", (SELECT COUNT(*) FROM comentarios_apoyos a WHERE a.idComentario = c.idComentario AND a.Tipo = 'Tambien') AS apoyos,
            (SELECT COUNT(*) FROM comentarios_apoyos a WHERE a.idComentario = c.idComentario AND a.Tipo = 'Reabrir') AS reaperturas"
        : ", 0 AS apoyos, 0 AS reaperturas";
    $sql .= " FROM comentarios_app c";
    if ($estado !== null) $sql .= " WHERE c.Estado = ?";
    $sql .= " ORDER BY (c.Estado IN ('Resuelto','Descartado')) ASC, c.idComentario DESC LIMIT 500";
    $q = $mysql->prepare($sql);
    if ($estado !== null) $q->bind_param('s', $estado);
    $q->execute();
    $filas = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $imagenes = [];
    if (tablaImagenesExiste()) {
        foreach ($mysql->query("SELECT idImagen, idComentario FROM comentarios_app_imagenes ORDER BY idImagen")->fetch_all(MYSQLI_ASSOC) as $i) {
            $imagenes[(int)$i['idComentario']][] = (int)$i['idImagen'];
        }
    }
    return array_map(function ($f) use ($imagenes) {
        return ['idComentario' => (int)$f['idComentario'], 'usuario' => (int)$f['IdUsuario'], 'tipo' => $f['Tipo'], 'titulo' => $f['Titulo'],
            'descripcion' => $f['Descripcion'], 'estado' => $f['Estado'], 'respuesta' => $f['Respuesta'],
            'version' => $f['VersionApp'], 'dispositivo' => $f['Dispositivo'], 'ambiente' => $f['Ambiente'],
            'fecha' => substr($f['FechaCreacion'], 0, 10), 'publico' => (int)$f['Publico'] === 1, 'mio' => false,
            'apoyos' => (int)$f['apoyos'], 'reaperturas' => (int)$f['reaperturas'], 'imagenes' => $imagenes[(int)$f['idComentario']] ?? []];
    }, $filas);
}

function tablaApoyosExiste() {
    global $mysql;
    return $mysql->query("SHOW TABLES LIKE 'comentarios_apoyos'")->num_rows > 0;
}

function enviarImagen($id) {
    global $mysql, $uid;
    // El administrador (cabecera X-Codigo-Admin) puede ver las imágenes de cualquier reporte; los demás, solo las suyas.
    $admin = isset($_SERVER['HTTP_X_CODIGO_ADMIN']) && codigoAdminValido($_SERVER['HTTP_X_CODIGO_ADMIN']);
    if ($admin) {
        $q = $mysql->prepare("SELECT Mime, Imagen FROM comentarios_app_imagenes WHERE idImagen = ?");
        $q->bind_param('i', $id);
    } else {
        $q = $mysql->prepare("SELECT i.Mime, i.Imagen FROM comentarios_app_imagenes i
            INNER JOIN comentarios_app c ON c.idComentario = i.idComentario WHERE i.idImagen = ? AND c.IdUsuario = ?");
        $q->bind_param('ii', $id, $uid);
    }
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
    $colPublico = columnaPublicoExiste() ? 'Publico' : '0 AS Publico';
    $q = $mysql->prepare("SELECT idComentario, Tipo, Titulo, Descripcion, Estado, Respuesta, FechaCreacion, $colPublico
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
            'fecha' => substr($f['FechaCreacion'], 0, 10), 'imagenes' => $imagenes[(int)$f['idComentario']] ?? [],
            'publico' => (int)$f['Publico'] === 1, 'mio' => true];
    }, $filas)]);
}

// Tablero de la comunidad: reportes públicos de todos, sin ningún dato del autor. Imágenes solo para el dueño.
function listarTablero() {
    global $mysql, $uid;
    $q = $mysql->prepare("SELECT c.idComentario, c.Tipo, c.Titulo, c.Descripcion, c.Estado, c.Respuesta, c.FechaCreacion,
            (c.IdUsuario = ?) AS mio,
            (SELECT COUNT(*) FROM comentarios_apoyos a WHERE a.idComentario = c.idComentario AND a.Tipo = 'Tambien') AS apoyos,
            (SELECT COUNT(*) FROM comentarios_apoyos a WHERE a.idComentario = c.idComentario AND a.Tipo = 'Tambien' AND a.IdUsuario = ?) AS yo,
            (SELECT COUNT(*) FROM comentarios_apoyos a WHERE a.idComentario = c.idComentario AND a.Tipo = 'Reabrir') AS reaperturas
        FROM comentarios_app c
        WHERE c.Publico = 1 OR c.IdUsuario = ?
        ORDER BY (c.Estado IN ('Resuelto','Descartado')) ASC, apoyos DESC, c.idComentario DESC LIMIT 200");
    $q->bind_param('iii', $uid, $uid, $uid);
    $q->execute();
    echo json_encode(['comentarios' => array_map(function ($f) {
        return ['idComentario' => (int)$f['idComentario'], 'tipo' => $f['Tipo'], 'titulo' => $f['Titulo'],
            'descripcion' => $f['Descripcion'], 'estado' => $f['Estado'], 'respuesta' => $f['Respuesta'],
            'fecha' => substr($f['FechaCreacion'], 0, 10), 'mio' => (int)$f['mio'] === 1, 'apoyos' => (int)$f['apoyos'],
            'yoApoyo' => (int)$f['yo'] > 0, 'reaperturas' => (int)$f['reaperturas'], 'publico' => true, 'imagenes' => []];
    }, $q->get_result()->fetch_all(MYSQLI_ASSOC))]);
}

// Un reporte al que el usuario puede acceder (suyo o público); null si no.
function reporteAccesible($id) {
    global $mysql, $uid;
    $q = $mysql->prepare("SELECT idComentario, IdUsuario, Estado FROM comentarios_app WHERE idComentario = ? AND (Publico = 1 OR IdUsuario = ?)");
    $q->bind_param('ii', $id, $uid);
    $q->execute();
    return $q->get_result()->fetch_assoc() ?: null;
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
            $publico = !isset($d['publico']) || $d['publico'] ? 1 : 0;
            if (columnaPublicoExiste()) {
                $ins = $mysql->prepare("INSERT INTO comentarios_app (IdUsuario, Tipo, Titulo, Descripcion, VersionApp, Dispositivo, Ambiente, Publico) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->bind_param('issssssi', $uid, $tipo, $titulo, $desc, $version, $disp, $amb, $publico);
            } else {
                $ins = $mysql->prepare("INSERT INTO comentarios_app (IdUsuario, Tipo, Titulo, Descripcion, VersionApp, Dispositivo, Ambiente) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $ins->bind_param('issssss', $uid, $tipo, $titulo, $desc, $version, $disp, $amb);
            }
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
            $conApoyos = false;
            try {
                $qa = $mysql->prepare("SELECT 1 FROM comentarios_apoyos WHERE idComentario = ? LIMIT 1");
                $qa->bind_param('i', $id);
                $qa->execute();
                $conApoyos = (bool)$qa->get_result()->fetch_row();
            } catch (mysqli_sql_exception $e) { /* migración 022 pendiente */ }
            if ($conApoyos) { echo json_encode(['error' => 'Otras personas ya se sumaron a este reporte, así que no se puede eliminar']); return; }
            $q = $mysql->prepare("DELETE FROM comentarios_app WHERE idComentario = ? AND IdUsuario = ? AND Estado = 'Nuevo'");
            $q->bind_param('ii', $id, $uid);
            $q->execute();
            echo json_encode($q->affected_rows > 0 ? ['deleted' => true]
                : ['error' => 'Solo se pueden eliminar los reportes que aún no se han revisado']);
            break;

        case 'apoyar':
        case 'quitarApoyo':
            $id = appfinanzas_entero($d['idComentario'] ?? null);
            $r = $id === null ? null : reporteAccesible($id);
            if (!$r) { echo json_encode(['error' => 'No se encontró el reporte']); return; }
            if ((int)$r['IdUsuario'] === $uid) { echo json_encode(['error' => 'Es tu propio reporte']); return; }
            if ($d['accion'] === 'apoyar') {
                $nota = texto_limpio($d['nota'] ?? '', 300) ?: null;
                $q = $mysql->prepare("INSERT INTO comentarios_apoyos (idComentario, IdUsuario, Tipo, Nota) VALUES (?, ?, 'Tambien', ?)
                    ON DUPLICATE KEY UPDATE Nota = VALUES(Nota)");
                $q->bind_param('iis', $id, $uid, $nota);
            } else {
                $q = $mysql->prepare("DELETE FROM comentarios_apoyos WHERE idComentario = ? AND IdUsuario = ? AND Tipo = 'Tambien'");
                $q->bind_param('ii', $id, $uid);
            }
            $q->execute();
            echo json_encode(['ok' => true]);
            break;

        case 'reabrir':
            $id = appfinanzas_entero($d['idComentario'] ?? null);
            $r = $id === null ? null : reporteAccesible($id);
            if (!$r) { echo json_encode(['error' => 'No se encontró el reporte']); return; }
            if (!in_array($r['Estado'], ['Resuelto', 'Descartado'], true)) { echo json_encode(['error' => 'Solo se puede reabrir un reporte cerrado']); return; }
            $nota = texto_limpio($d['nota'] ?? '', 300) ?: null;
            $q = $mysql->prepare("INSERT INTO comentarios_apoyos (idComentario, IdUsuario, Tipo, Nota) VALUES (?, ?, 'Reabrir', ?)
                ON DUPLICATE KEY UPDATE Nota = VALUES(Nota), Fecha = NOW()");
            $q->bind_param('iis', $id, $uid, $nota);
            $q->execute();
            $u = $mysql->prepare("UPDATE comentarios_app SET Estado = 'Nuevo' WHERE idComentario = ?");
            $u->bind_param('i', $id);
            $u->execute();
            echo json_encode(['estado' => 'Nuevo']);
            break;

        case 'visibilidad':
            $id = appfinanzas_entero($d['idComentario'] ?? null);
            if ($id === null) { echo json_encode(['error' => 'Identificador no válido']); return; }
            if (!columnaPublicoExiste()) { echo json_encode(['error' => MSG_MIGRACION_022]); return; }
            $pub = !empty($d['publico']) ? 1 : 0;
            $q = $mysql->prepare("UPDATE comentarios_app SET Publico = ? WHERE idComentario = ? AND IdUsuario = ?");
            $q->bind_param('iii', $pub, $id, $uid);
            $q->execute();
            echo json_encode(['publico' => $pub === 1]);
            break;

        case 'admin_validar':
            if (!codigoAdminValido($d['codigo'] ?? '')) { echo json_encode(['error' => adminMensajeRechazo()]); return; }
            error_log('[AppFinanzas][AUDITORIA] administrador validado (usuario ' . (int)$uid . ')');
            echo json_encode(['ok' => true]);
            break;

        case 'admin_listar':
            if (!codigoAdminValido($d['codigo'] ?? '')) { echo json_encode(['error' => adminMensajeRechazo()]); return; }
            $estado = isset($d['estado']) && in_array($d['estado'], COMENTARIOS_ESTADOS, true) ? $d['estado'] : null;
            $todos = listarParaAdmin($estado);
            error_log('[AppFinanzas][AUDITORIA] administrador listó ' . count($todos) . ' reportes (usuario ' . (int)$uid . ')');
            echo json_encode(['comentarios' => $todos]);
            break;

        case 'admin_estado':
            if (!codigoAdminValido($d['codigo'] ?? '')) { echo json_encode(['error' => adminMensajeRechazo()]); return; }
            $id = appfinanzas_entero($d['idComentario'] ?? null);
            $estado = (string)($d['estado'] ?? '');
            if ($id === null || !in_array($estado, COMENTARIOS_ESTADOS, true)) { echo json_encode(['error' => 'Reporte o estado no válido']); return; }
            $antes = $mysql->prepare("SELECT Estado FROM comentarios_app WHERE idComentario = ?");
            $antes->bind_param('i', $id);
            $antes->execute();
            $previo = $antes->get_result()->fetch_assoc();
            if (!$previo) { echo json_encode(['error' => 'No se encontró el reporte']); return; }
            if (array_key_exists('respuesta', $d)) {
                $resp = texto_limpio($d['respuesta'], 1000) ?: null;   // vacío = borra la respuesta
                $q = $mysql->prepare("UPDATE comentarios_app SET Estado = ?, Respuesta = ? WHERE idComentario = ?");
                $q->bind_param('ssi', $estado, $resp, $id);
            } else {
                $q = $mysql->prepare("UPDATE comentarios_app SET Estado = ? WHERE idComentario = ?");
                $q->bind_param('si', $estado, $id);
            }
            $q->execute();
            error_log('[AppFinanzas][AUDITORIA] reporte ' . $id . ': estado ' . $previo['Estado'] . ' -> ' . $estado . ' (usuario ' . (int)$uid . ')');
            echo json_encode(['estado' => $estado]);
            break;

        default:
            echo json_encode(['error' => 'Acción no válida']);
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        procesarComentario(is_array($input) ? $input : $_POST);
    } elseif (isset($_GET['tablero'])) {
        if (!columnaPublicoExiste()) { echo json_encode(['error' => MSG_MIGRACION_022]); exit; }
        listarTablero();
    } elseif (isset($_GET['imagen'])) {
        $idImg = appfinanzas_entero($_GET['imagen']);
        if ($idImg === null) { echo json_encode(['error' => 'Identificador no válido']); exit; }
        enviarImagen($idImg);
    } else {
        listarComentarios();
    }
} catch (mysqli_sql_exception $ex) {
    error_log('[AppFinanzas] Comentarios.php: ' . $ex->getMessage());
    echo json_encode(['error' => (int)$ex->getCode() === 1146 ? 'Falta ejecutar una migración (019, 021 o 022) en la base de datos' : 'Error al procesar el reporte']);
}
