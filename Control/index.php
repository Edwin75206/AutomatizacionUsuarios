<?php
// index.php (validación genérica de email)
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Registro de Usuarios</title>
  <style>
    :root {
      --primary-color: #5c6bc0;
      --secondary-color: #3949ab;
      --error-color: #e53935;
      --success-color: #4caf50;
      --bg-light: #f5f5f5;
      --text-dark: #212121;
      --input-bg: #ffffff;
      --input-border: #ccc;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Segoe UI', sans-serif; background: var(--bg-light); color: var(--text-dark); }
    .container { max-width: 400px; margin: 2rem auto; padding: 1.5rem; background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); text-align: center; }
    h2 { color: var(--primary-color); margin-bottom: 1rem; }
    .alert-error, .alert-success {
      padding: 0.75rem; border-radius: 4px; margin-bottom: 1rem; font-weight: 500;
    }
    .alert-error { background: var(--error-color); color: #fff; }
    .alert-success { background: var(--success-color); color: #fff; }
    form { display: flex; flex-direction: column; text-align: left; }
    label { margin-bottom: 0.75rem; font-weight: 500; }
    input, select {
      width: 100%; padding: 0.5rem; margin-top: 0.25rem;
      border: 1px solid var(--input-border); border-radius: 4px; background: var(--input-bg);
    }
    button {
      margin-top: 1rem; padding: 0.75rem; background: var(--secondary-color);
      color: #fff; border: none; border-radius: 4px; cursor: pointer; transition: background 0.2s;
    }
    button:hover { background: var(--primary-color); }
  </style>
</head>
<body>
  <div class="container">
    <img src="logo.png" alt="Logo" style="max-width:150px; margin-bottom:1rem;">
    <h2>Registrar Usuario</h2>

    <!-- Mensaje de éxito/error -->
    <?php if (!empty($_GET['success'])): ?>
      <div class="alert-success">
        Usuario creado con éxito. Credenciales enviadas al correo registrado.
      </div>
    <?php elseif (!empty($_GET['error']) && $_GET['error'] === 'invalid'): ?>
      <div class="alert-error">
        El correo ingresado no tiene un formato válido.
      </div>
    <?php elseif (!empty($_GET['error']) && $_GET['error'] === 'duplicate'): ?>
      <div class="alert-error">
        Ese usuario ya existe.
      </div>
    <?php endif; ?>

    <form id="registerForm" action="save_user.php" method="post">
      <label>Nombre:
        <input type="text" name="nombre" required>
      </label>
      <label>Apellido:
        <input type="text" name="apellido" required>
      </label>
      <label>Correo:
        <input
          type="email"
          name="correo"
          required
          pattern="^[^\s@]+@[^\s@]+\.[^\s@]+$"
          title="Introduce un correo con formato usuario@dominio.ext"
        >
      </label>
      <label>Curso:
        <select name="curso">
  <option value="1° Primaria">1° Primaria</option>
<option value="2° Primaria">2° Primaria</option>
</select>
      </label>
      <button type="submit">Guardar en Control</button>
    </form>
  </div>

  <script>
    // Refuerzo de validación en JavaScript
    document.getElementById('registerForm').addEventListener('submit', function(e) {
      const email = this.correo.value.trim();
      const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!regex.test(email)) {
        e.preventDefault();
        alert('Correo inválido. Usa el formato usuario@dominio.ext');
      }
    });
  </script>
</body>
</html>
