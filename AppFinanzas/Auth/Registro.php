<?php
define('APPFINANZAS_SIN_SESION', true);
require_once("../db.php");
require_once("../libAuth.php");

// POST {correo, clave, nombre?, codigoCuentaInicial?}
//  -> {status:'ok', accessToken, refreshToken, expiraEn, usuario}
// Si el correo es el de la "cuenta inicial" sin reclamar (datos anteriores a la migración 005),
// se reclama con 'codigoCuentaInicial' (el valor 'codigo_cuenta_inicial' de config.local.php).
authSoloPost();
$d = authCuerpo();
$correo = authNormalizarCorreo($d['correo'] ?? '');
$clave = $d['clave'] ?? '';
$nombre = mb_substr(trim((string)($d['nombre'] ?? '')), 0, 100);

if (!authCorreoValido($correo)) appfinanzas_responder_error(400, 'Correo no válido');
authValidarClave($clave);
authExigirCupo($correo);
authAnotarIntento(authIp(), 'ip');   // cada registro cuenta contra la IP (evita crear cuentas en masa)

$hash = password_hash($clave, PASSWORD_DEFAULT);
$u = authBuscarPorCorreo($correo);

if ($u) {
    // Cuenta inicial sin reclamar: sin clave y sin Google.
    if (empty($u['ClaveHash']) && empty($u['GoogleSub'])) {
        $codigo = (string)($d['codigoCuentaInicial'] ?? '');
        if ($appConfig['codigo_cuenta_inicial'] === '' || !hash_equals($appConfig['codigo_cuenta_inicial'], $codigo)) {
            authFalloIntento($correo);
            appfinanzas_responder_error(403, 'Esta cuenta ya tiene datos. Ingresa el código de la cuenta inicial para reclamarla.');
        }
        $q = $mysql->prepare("UPDATE usuarios SET ClaveHash = ?, Nombre = COALESCE(NULLIF(?, ''), Nombre) WHERE IdUsuario = ? AND ClaveHash IS NULL AND GoogleSub IS NULL");
        $q->bind_param('ssi', $hash, $nombre, $u['IdUsuario']);
        $q->execute();
        $q->close();
        authRespuestaSesion(authBuscarPorId((int)$u['IdUsuario']));
    }
    appfinanzas_responder_error(409, 'Ya existe una cuenta con ese correo. Inicia sesión.');
}

try {
    $nuevo = authCrearUsuario($correo, $hash, null, $nombre !== '' ? $nombre : null);
} catch (mysqli_sql_exception $e) {
    if ($e->getCode() === 1062) appfinanzas_responder_error(409, 'Ya existe una cuenta con ese correo. Inicia sesión.');
    throw $e;
}
authRespuestaSesion($nuevo);
