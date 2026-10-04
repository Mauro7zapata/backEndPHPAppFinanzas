<?php
require_once("../db.php");
require_once("../libAuth.php");

// POST (con Bearer) {claveActual?, claveNueva}
//  Si la cuenta ya tiene clave, 'claveActual' es obligatoria. Una cuenta solo-Google puede crear su clave aquí.
//  Cierra las demás sesiones del usuario.
authSoloPost();
$d = authCuerpo();
$u = authBuscarPorId($uid);
if (!$u) appfinanzas_responder_error(401, 'Sesión expirada');
authValidarClave($d['claveNueva'] ?? '');
authExigirCupo('u' . $uid);
if (!empty($u['ClaveHash'])) {
    if (!password_verify((string)($d['claveActual'] ?? ''), $u['ClaveHash'])) {
        authFalloIntento('u' . $uid);
        appfinanzas_responder_error(401, 'La contraseña actual no es correcta');
    }
}
$hash = password_hash($d['claveNueva'], PASSWORD_DEFAULT);
$q = $mysql->prepare("UPDATE usuarios SET ClaveHash = ? WHERE IdUsuario = ?");
$q->bind_param('si', $hash, $uid);
$q->execute();
$q->close();
$q = $mysql->prepare("DELETE FROM sesiones WHERE IdUsuario = ? AND IdSesion <> ?");
$q->bind_param('ii', $uid, $sesionActual);
$q->execute();
$q->close();
authResponder(['status' => 'ok']);
