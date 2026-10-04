<?php
require_once("../db.php");
require_once("../libAuth.php");

// GET (con Bearer) -> {status:'ok', usuario:{id, correo, nombre, tieneClave, googleVinculado}}
$u = authBuscarPorId($uid);
if (!$u) appfinanzas_responder_error(401, 'Sesión expirada');
authResponder(['status' => 'ok', 'usuario' => authUsuarioJson($u)]);
