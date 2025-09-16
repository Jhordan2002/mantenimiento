<?php
session_start();
if (isset($_SESSION["autentica"]) && $_SESSION["autentica"] === "SIP") {
    if (isset($_SESSION["estado_usuario"])) {
        switch ($_SESSION["estado_usuario"]) {
            case 6:
                header("Location: menu.php");
                break;
        }
        exit();
    }
}


$error = "";
// Procesamos el formulario cuando se envía por POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Parámetros de conexión a la base de datos
    $host   = 'localhost';
    $dbUser = 'root';
    $dbPass = 'soporte2017l';
    $dbName = 'operaciones';

    // Conexión con mysqli
    $conn = new mysqli($host, $dbUser, $dbPass, $dbName);
    if ($conn->connect_error) {
        die("Error en la conexión: " . $conn->connect_error);
    }

    if (isset($_POST['usuario']) && isset($_POST['clave'])) {
        $usuario = $_POST['usuario'];
        $clave   = $_POST['clave'];

        // Consulta preparada para evitar inyecciones SQL
        $stmt = $conn->prepare("SELECT 
                                    idusuario,
                                    estado,
                                    clave_suc,
                                    sucursal,
                                    nozona,
                                    token,
                                    nombre
                                FROM usuarios
                                WHERE idusuario = ? AND clave = ?");
        if ($stmt) {
            $stmt->bind_param("ss", $usuario, $clave);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                // El usuario existe, así que revisamos el 'estado'
                $row = $result->fetch_assoc();

                // Creamos la sesión
                $_SESSION["autentica"]     = "SIP";
                $_SESSION["usuarioactual"] = $row['idusuario'];
                $_SESSION["nombre"]        = $row['nombre'];   // Para registrar acciones a nombre del usuario
                // Almacenamos las variables adicionales en la sesión
                $_SESSION["nozona"]        = $row['nozona'];
                $_SESSION["numsuc"]        = $row['clave_suc'];
                $_SESSION["sucursal"]      = $row['sucursal'];
                $_SESSION["estado_usuario"] = $row['estado']; // <--- ESTO ES LO NUEVO

                // Redirigir según el 'estado' del usuario
                switch ($row['estado']) {
                    case 6:  // estado=1
                        header("Location: menu.php");
                        exit();
                    default:
                        // Si 'estado' no coincide con ninguno de los anteriores
                        $error = "El usuario no tiene un estado asignado válido.";
                        // Podrías redirigir a un error o mostrar un mensaje
                }
            } else {
                // No hay registros -> usuario o contraseña incorrectos
                $error = "Usuario o contraseña incorrectos.";
            }
            $stmt->close();
        } else {
            $error = "Error en la consulta: " . $conn->error;
        }
    } else {
        $error = "Por favor, ingrese usuario y contraseña.";
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="iso-8859-1">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>LORES - Iniciar Sesión</title>
    <!-- Nuestro CSS personalizado -->
    <link rel="stylesheet" href="css/styles.css">
    <link rel="icon" type="image/png" href="img/flor.png">
    <!-- Font Awesome para el ícono del ojo -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.css">
</head>

<body>
    <div class="login-wrapper">
        <div class="logo-header">
            <img src="img/Recurso 1.svg" alt="Logo LORES">
        </div>
        <div class="login-container">
            <h2 style="color:black;">Iniciar Sesión</h2>
            <?php if (!empty($error)): ?>
                <div class="alert error"><?php echo $error; ?></div>
            <?php endif; ?>

            <?php if (empty($_SESSION["autentica"])): ?>
                <form method="post" action="index.php">
                    <div class="form-group">
                        <label for="usuario" style="color:black;">Correo electrónico👤</label>
                        <input type="text" id="usuario" name="usuario" placeholder="usuario@dominio.com" required>
                    </div>
                    <div class="form-group">
                        <label for="clave" style="color:black;">Contraseña🔑</label>
                        <div class="password-wrapper">
                            <input type="password" id="clave" name="clave" placeholder="Contraseña" required>
                            <span id="togglePassword" class="fa fa-eye"></span>
                        </div>
                    </div>
                    <button type="submit" class="btn">Entrar</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <footer>
        <p>&copy; <?php echo date("Y"); ?> LORES. Todos los derechos reservados.</p>
    </footer>

    <!-- Overlay de carga -->
    <div id="loading-overlay">
        <img src="img/FLORAPP_7.gif" alt="Cargando...">
    </div>

    <!-- Nuestro script personalizado -->
    <script src="js/script.js"></script>
    <script>
        // Manejador para el envío del formulario con retraso de 2 segundos
        document.querySelector("form").addEventListener("submit", function(e) {
            e.preventDefault(); // Prevenir el envío inmediato
            document.getElementById("loading-overlay").style.display = "flex";
            // Esperar 2 segundos y luego enviar el formulario
            setTimeout(() => {
                e.target.submit();
            }, 2000);
        });
    </script>
</body>

</html>