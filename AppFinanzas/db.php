<?php
$host = "bdidzco0unzuysu7jmce-mysql.services.clever-cloud.com";
$user = "u4dw71da3y2wnydo";
$password = "5EHl7uGP3gttYW1hiaRI";
$db = "bdidzco0unzuysu7jmce";

$mysql = new mysqli($host, $user, $password, $db);

if ($mysql->connect_error) {
    die("Error de conexión: " . $mysql->connect_error);
}
?>
