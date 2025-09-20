<?php
session_start();
session_destroy();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cerrando sesión...</title>
    <style>
        /* 🔹 Fondo negro y centrado */
        body {
            background: #fff;
            color: #fff;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            font-family: Arial, sans-serif;
            text-align: center;
        }

        /* 🔹 Contenedor centrado */
        .logout-container {
            text-align: center;
        }

        /* 🔹 Imagen GIF */
        .logout-container img {
            width: 100px;
            margin-bottom: 15px;
        }

        /* 🔹 Texto con animación de puntitos */
        .logout-text {
            font-size: 22px;
            font-weight: bold;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        /* 🔹 Puntitos animados */
        .dot {
            width: 8px;
            height: 8px;
            background-color: black;
            border-radius: 50%;
            margin-left: 5px; 
            animation: fade 1.5s infinite;
        }

        /* 🔹 Animación de aparición secuencial */
        .dot:nth-child(1) { animation-delay: 0s; }
        .dot:nth-child(2) { animation-delay: 0.3s; }
        .dot:nth-child(3) { animation-delay: 0.6s; }

        @keyframes fade {
            0% { opacity: 0.3; }
            50% { opacity: 1; }
            100% { opacity: 0.3; }
        }
    </style>
</head>
<body>
    <div class="logout-container">
        <img src="img/FLORAPP_7.gif" alt="Cerrando sesión...">
        <p class="logout-text" style="color: black;">
            Cerrando sesión
            <span class="dot"></span>
            <span class="dot"></span>
            <span class="dot"></span>
        </p>
    </div>

    <script>
        // Notificar a todas las pestañas que se cerró la sesión
        localStorage.setItem("logoutEvent", Date.now());

        // Redirigir al login después de 3 segundos
        setTimeout(function() {
            window.location.href = "../../index.php";
        }, 3000);
    </script>
</body>
</html>
