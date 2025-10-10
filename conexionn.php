<?php

$mysqli = new mysqli('lores.dyndns.org:3306', 'lores', 'reportes', 'operaciones');

if ($mysqli->connect_error) {

	die('Error en la conexion' . $mysqli->connect_error);
}
