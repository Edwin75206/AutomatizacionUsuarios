<?php
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Registro exitoso</title>
  <style>
    body {
      font-family: 'Segoe UI', sans-serif;
      background-image: url('./bg_academus.png');
      background-size: cover;
      background-position: center;
      background-repeat: no-repeat;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 8rem;
    }
    h1 {
      color: #009688;
      font-size: 2.5rem;
      margin-bottom: 1rem;
    }
    p {
      color: #00796B;
      font-size: 1.2rem;
      margin-bottom: 2rem;
    }
    .btn {
      border: none;
      border-radius: 2rem;
      padding: 0.8rem 1.5rem;
      font-weight: bold;
      cursor: pointer;
      margin: 0.5rem;
      width: 200px;
    }
    .nuevo {
      background-color: #00695C;
      color: white;
    }
    .listado {
      background-color: #9C27B0;
      color: white;
    }
    footer {
      margin-top: 4rem;
      color: #616161;
    }
  </style>
</head>
<body>
  <h1>Registro exitoso</h1>
  <p>Se ha enviado correo con información</p>

  <form action="index.php" method="get" style="margin-bottom: 1rem;">
    <button class="btn nuevo" type="submit">NUEVO REGISTRO</button>
  </form>

  <form action="listado.php" method="get">
    <button class="btn listado" type="submit">VER LISTADO</button>
  </form>

  <footer>
    <p>academusdigital.com</p>
    <p>Ciclo 2025 - 2026</p>
  </footer>
</body>
</html>
