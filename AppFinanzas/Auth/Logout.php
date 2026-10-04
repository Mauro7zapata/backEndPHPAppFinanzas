<?php
require_once("../db.php");
require_once("../libAuth.php");

// POST (con Bearer) -> cierra SOLO esta sesión. Con {"todas": true} cierra todas las sesiones del usuario.
authSoloPost();
$d = authCuerpo();
if (!empty($d['todas'])) {
    $q = $mysql->prepare("DELETE FROM sesiones WHERE IdUsuario = ?");
    $q->bind_param('i', $uid);
} else {
    $q = $mysql->prepare("DELETE FROM sesiones WHERE IdSesion = ? AND IdUsuario = ?");
    $q->bind_param('ii', $sesionActual, $uid);
}
$q->execute();
$q->close();
authResponder(['status' => 'ok']);
