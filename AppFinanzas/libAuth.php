<?php
/**
 * Utilidades de autenticación (registro, login, Google, sesiones).
 * Se incluye desde los endpoints de Auth/ después de db.php.
 *
 * Seguridad:
 *  - Claves con password_hash (bcrypt/argon según PHP); nunca se guardan ni se registran en claro.
 *  - Tokens aleatorios de 256 bits; en BD solo su hash SHA-256 (una filtración de la BD no da sesiones válidas).
 *  - Token de acceso de 1 hora + token de renovación de 90 días que ROTA en cada renovación.
 *  - Límite de intentos por correo e IP; respuesta idéntica para correo inexistente o clave errónea.
 */

const AUTH_TOKEN_SEGUNDOS   = 3600;          // 1 hora
const AUTH_REFRESH_SEGUNDOS = 90 * 86400;    // 90 días
const AUTH_CLAVE_MINIMA     = 8;
const AUTH_CLAVE_MAXIMA     = 72;            // límite de bcrypt

function authCuerpo()
{
    $d = json_decode(file_get_contents('php://input'), true);
    return is_array($d) ? $d : $_POST;
}

function authResponder($datos)
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function authSoloPost()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        appfinanzas_responder_error(405, 'Método no permitido');
    }
}

function authCorreoValido($correo)
{
    return strlen($correo) <= 190 && filter_var($correo, FILTER_VALIDATE_EMAIL) !== false;
}

function authNormalizarCorreo($correo)
{
    return mb_strtolower(trim((string)$correo), 'UTF-8');
}

function authIp()
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function authDispositivo()
{
    return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 100);
}

// --- Límite de intentos ---------------------------------------------------------------------------
function authIntentos($clave, $tipo, $minutos)
{
    global $mysql;
    $q = $mysql->prepare("SELECT COUNT(*) FROM intentos_acceso WHERE Clave = ? AND Tipo = ? AND Fecha > (NOW() - INTERVAL ? MINUTE)");
    $q->bind_param('ssi', $clave, $tipo, $minutos);
    $q->execute();
    $q->bind_result($n);
    $q->fetch();
    $q->close();
    return (int)$n;
}

function authAnotarIntento($clave, $tipo)
{
    global $mysql;
    $q = $mysql->prepare("INSERT INTO intentos_acceso (Clave, Tipo) VALUES (?, ?)");
    $q->bind_param('ss', $clave, $tipo);
    $q->execute();
    $q->close();
    // Limpieza ocasional de intentos viejos.
    if (random_int(1, 50) === 1) {
        $mysql->query("DELETE FROM intentos_acceso WHERE Fecha < (NOW() - INTERVAL 1 DAY)");
    }
}

function authLimpiarIntentos($clave, $tipo)
{
    global $mysql;
    $q = $mysql->prepare("DELETE FROM intentos_acceso WHERE Clave = ? AND Tipo = ?");
    $q->bind_param('ss', $clave, $tipo);
    $q->execute();
    $q->close();
}

// Corta con 429 si hay demasiados intentos recientes.
function authExigirCupo($correo)
{
    if (authIntentos($correo, 'correo', 15) >= 5 || authIntentos(authIp(), 'ip', 15) >= 30) {
        appfinanzas_responder_error(429, 'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.');
    }
}

function authFalloIntento($correo)
{
    authAnotarIntento($correo, 'correo');
    authAnotarIntento(authIp(), 'ip');
}

// --- Sesiones -------------------------------------------------------------------------------------
function authCrearSesion($idUsuario)
{
    global $mysql;
    $token = bin2hex(random_bytes(32));
    $refresh = bin2hex(random_bytes(32));
    $hT = hash('sha256', $token);
    $hR = hash('sha256', $refresh);
    $disp = authDispositivo();
    $q = $mysql->prepare("INSERT INTO sesiones (IdUsuario, TokenHash, RefreshHash, ExpiraToken, ExpiraRefresh, Dispositivo)
                          VALUES (?, ?, ?, NOW() + INTERVAL " . AUTH_TOKEN_SEGUNDOS . " SECOND, NOW() + INTERVAL " . AUTH_REFRESH_SEGUNDOS . " SECOND, ?)");
    $q->bind_param('isss', $idUsuario, $hT, $hR, $disp);
    $q->execute();
    $q->close();
    // Máximo 10 sesiones activas por usuario: se borran las más antiguas.
    $mysql->query("DELETE FROM sesiones WHERE IdUsuario = " . (int)$idUsuario . " AND IdSesion NOT IN (
                       SELECT IdSesion FROM (SELECT IdSesion FROM sesiones WHERE IdUsuario = " . (int)$idUsuario . " ORDER BY IdSesion DESC LIMIT 10) t)");
    $mysql->query("UPDATE usuarios SET UltimoAcceso = NOW() WHERE IdUsuario = " . (int)$idUsuario);
    return ['accessToken' => $token, 'refreshToken' => $refresh, 'expiraEn' => AUTH_TOKEN_SEGUNDOS];
}

function authUsuarioJson($u)
{
    return [
        'id'            => (int)$u['IdUsuario'],
        'correo'        => $u['Correo'],
        'nombre'        => $u['Nombre'],
        'tieneClave'    => !empty($u['ClaveHash']),
        'googleVinculado' => !empty($u['GoogleSub']),
    ];
}

function authBuscarPorCorreo($correo)
{
    global $mysql;
    $q = $mysql->prepare("SELECT * FROM usuarios WHERE Correo = ? LIMIT 1");
    $q->bind_param('s', $correo);
    $q->execute();
    $u = $q->get_result()->fetch_assoc();
    $q->close();
    return $u ?: null;
}

function authBuscarPorId($id)
{
    global $mysql;
    $q = $mysql->prepare("SELECT * FROM usuarios WHERE IdUsuario = ? LIMIT 1");
    $q->bind_param('i', $id);
    $q->execute();
    $u = $q->get_result()->fetch_assoc();
    $q->close();
    return $u ?: null;
}

function authRespuestaSesion($u)
{
    $s = authCrearSesion((int)$u['IdUsuario']);
    authResponder(['status' => 'ok'] + $s + ['usuario' => authUsuarioJson($u)]);
}

function authValidarClave($clave)
{
    if (!is_string($clave) || mb_strlen($clave) < AUTH_CLAVE_MINIMA) {
        appfinanzas_responder_error(400, 'La contraseña debe tener al menos ' . AUTH_CLAVE_MINIMA . ' caracteres');
    }
    if (strlen($clave) > AUTH_CLAVE_MAXIMA) {
        appfinanzas_responder_error(400, 'La contraseña es demasiado larga (máximo ' . AUTH_CLAVE_MAXIMA . ' caracteres)');
    }
    if (!preg_match('/\pL/u', $clave) || !preg_match('/\d/', $clave)) {
        appfinanzas_responder_error(400, 'La contraseña debe combinar letras y números');
    }
}

// --- Datos iniciales de un usuario nuevo ------------------------------------------------------------
// Estados que usa la lógica (se buscan por nombre) y categorías básicas editables.
function authSembrarUsuario($idUsuario)
{
    global $mysql;
    $estados = [
        ['Gastos', 'Pendiente', -3289651], ['Gastos', 'Pagado', -14910720], ['Gastos', 'Guardado', -10240513],
        ['Gastos', 'Acumulado', -7791], ['Gastos', 'En proceso', -4915975], ['Gastos', 'No aplica', -60934],
        ['Inversion', 'Desembolsado', -9250817], ['Inversion', 'Liquidado', 1866496], ['Inversion', 'Perdida', -56575],
        ['Pagos', 'Pendiente', 6536703], ['Pagos', 'Cobrado', 1866496], ['Pagos', 'En gestion', -56064],
        ['Presupuestos', 'En curso', -14575885], ['Presupuestos', 'Finalizado', -11751600],
    ];
    $q = $mysql->prepare("INSERT INTO estados (TipoEstado, NombreEstado, ColorEstado, IdUsuario) VALUES (?, ?, ?, ?)");
    foreach ($estados as [$tipo, $nombre, $color]) {
        $q->bind_param('ssii', $tipo, $nombre, $color, $idUsuario);
        $q->execute();
    }
    $q->close();
    $categorias = [
        ['Ahorros o inversiones', 2555903, 'ic_savings'], ['Alimentos', 16737095, 'ic_food'],
        ['Cuidados personales', 9055202, 'ic_personal_care'], ['Entretenimiento', 16766720, 'ic_entertainment'],
        ['Impuestos', 11674146, 'ic_taxes'], ['Mascotas', 16738740, 'ic_pets'], ['Niños', 3329330, 'ic_kids'],
        ['Préstamos', 9127187, 'ic_loans'], ['Seguros', 4620980, 'ic_insurance'], ['Transporte', 49151, 'ic_transport'],
        ['Vivienda', 3066991, 'ic_housing'], ['Otros', 52945, 'ic_extra'],
    ];
    $q = $mysql->prepare("INSERT INTO categoriagastos (NombreCategoria, ColorCategoria, ImagenCategoria, IdUsuario) VALUES (?, ?, ?, ?)");
    foreach ($categorias as [$nombre, $color, $img]) {
        $q->bind_param('sisi', $nombre, $color, $img, $idUsuario);
        $q->execute();
    }
    $q->close();
    // Lugares de guardado de ejemplo (migración 012); si aún no existe la tabla, se omite sin romper el registro.
    try {
        $q = $mysql->prepare("INSERT INTO lugares_guardado (IdUsuario, Nombre) VALUES (?, ?)");
        foreach (['Bolsillo físico', 'Bolsillo Nequi', 'Bolsillo Bancolombia', 'Meta Nequi'] as $lugar) {
            $q->bind_param('is', $idUsuario, $lugar);
            $q->execute();
        }
        $q->close();
    } catch (mysqli_sql_exception $e) {
        if ((int)$e->getCode() !== 1146) throw $e;
    }
}

// Crea un usuario nuevo con sus datos iniciales (transacción). Devuelve el usuario.
function authCrearUsuario($correo, $claveHash, $googleSub, $nombre)
{
    global $mysql;
    $mysql->begin_transaction();
    try {
        $q = $mysql->prepare("INSERT INTO usuarios (Correo, ClaveHash, GoogleSub, Nombre) VALUES (?, ?, ?, ?)");
        $q->bind_param('ssss', $correo, $claveHash, $googleSub, $nombre);
        $q->execute();
        $id = $mysql->insert_id;
        $q->close();
        authSembrarUsuario($id);
        $mysql->commit();
    } catch (Throwable $e) {
        $mysql->rollback();
        throw $e;
    }
    return authBuscarPorId($id);
}

// Verifica un ID token de Google con el servicio oficial tokeninfo. Devuelve los datos o null.
function authVerificarGoogle($idToken)
{
    global $appConfig;
    if (!$appConfig['google_client_ids'] || !is_string($idToken) || strlen($idToken) < 20 || strlen($idToken) > 4096) return null;
    $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4]);
    $r = curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($r === false || $codigo !== 200) return null;
    $d = json_decode($r, true);
    if (!is_array($d)) return null;
    if (!in_array($d['aud'] ?? '', $appConfig['google_client_ids'], true)) return null;
    if (!in_array($d['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) return null;
    if (($d['exp'] ?? 0) < time()) return null;
    if (($d['email_verified'] ?? '') !== 'true' && ($d['email_verified'] ?? false) !== true) return null;
    if (empty($d['sub']) || empty($d['email'])) return null;
    return ['sub' => (string)$d['sub'], 'correo' => authNormalizarCorreo($d['email']), 'nombre' => mb_substr((string)($d['name'] ?? ''), 0, 100)];
}
