<?php

$mysqli = new mysqli('localhost', 'root', 'soporte2017l', 'operaciones');

if ($mysqli->connect_error) {

	die('Error en la conexion' . $mysqli->connect_error);
}
