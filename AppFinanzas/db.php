<?php
$host = "localhost";
$user = "root";
$password = "";
$db = "dbfinanzas";

$mysql = new mysqli($host, $user, $password, $db);

if ($mysql->connect_error) {
    die("Error de conexión: " . $mysql->connect_error);
}
?>