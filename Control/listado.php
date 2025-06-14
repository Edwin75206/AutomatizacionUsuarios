<?php
// listado.php
require_once './config.php';

try {
    $pdo = getDb();
    $stmt = $pdo->query("SELECT nombre, apellido, correo, curso, created_at FROM usuarios ORDER BY created_at DESC");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Error al consultar la base de datos: ' . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Listado de Registros</title>
  <link rel="icon" href="./logo.png" type="image/png">
  <style>
    * {
      box-sizing: border-box;
    }
    body {
      font-family: 'Segoe UI', sans-serif;
      margin: 0;
      padding: 0;
      background-color: #f4f4f4;
    }
    header {
      background-color: #009688;
      color: white;
      text-align: center;
      padding: 1rem 0 0.5rem;
    }
    .banner {
      width: 90px;
      height: auto;
      display: block;
      margin: 0 auto 0.5rem;
    }
    h1 {
      margin: 0;
      font-size: 2rem;
      padding-bottom: 1rem;
    }
    .container {
      padding: 2rem;
      width: 100%;
      max-width: 1800px;
      margin: 0 auto;
    }
    .actions {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1rem;
    }
    .actions a {
      background-color: #00796B;
      color: white;
      text-decoration: none;
      padding: 0.6rem 1.2rem;
      border-radius: 5px;
      font-weight: bold;
      transition: background 0.3s;
    }
    .actions a:hover {
      background-color: #005f50;
    }
    .search-box input {
      padding: 0.5rem;
      width: 300px;
      border-radius: 5px;
      border: 1px solid #ccc;
      font-size: 1rem;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      background-color: white;
      box-shadow: 0 4px 12px rgba(0,0,0,0.1);
      border-radius: 10px;
      overflow: hidden;
    }
    th, td {
      padding: 1rem;
      border-bottom: 1px solid #ddd;
      text-align: left;
    }
    th {
      background-color: #00796B;
      color: white;
      text-transform: uppercase;
      font-size: 0.9rem;
      cursor: pointer;
    }
    tr:hover {
      background-color: #f1f1f1;
    }
  </style>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const searchInput = document.getElementById('searchInput');
      searchInput.addEventListener('input', function () {
        const filter = searchInput.value.toLowerCase();
        const rows = document.querySelectorAll('tbody tr');
        rows.forEach(row => {
          const cells = row.querySelectorAll('td');
          const match = Array.from(cells).some(td => td.textContent.toLowerCase().includes(filter));
          row.style.display = match ? '' : 'none';
        });
      });

      // Ordenamiento asc/desc al hacer clic en los headers
      const getCellValue = (tr, idx) => tr.children[idx].innerText || tr.children[idx].textContent;
      const comparer = (idx, asc) => (a, b) => ((v1, v2) => 
        v1 !== '' && v2 !== '' && !isNaN(v1) && !isNaN(v2) ? v1 - v2 : v1.toString().localeCompare(v2)
      )(getCellValue(asc ? a : b, idx), getCellValue(asc ? b : a, idx));

      document.querySelectorAll('th').forEach((th, idx) => {
        let asc = true;
        th.addEventListener('click', () => {
          const table = th.closest('table');
          Array.from(table.querySelectorAll('tbody tr'))
            .sort(comparer(idx, asc = !asc))
            .forEach(tr => table.querySelector('tbody').appendChild(tr));
        });
      });
    });
  </script>
</head>
<body>
  <header>
    <img class="banner" src="./logo.png" alt="Logo">
    <h1>Listado de Registros</h1>
  </header>

  <div class="container">
    <div class="actions">
      <div class="search-box">
        <input type="text" id="searchInput" placeholder="Buscar por nombre, correo o curso...">
      </div>
      <a href="./index.php">+ Nuevo registro</a>
    </div>
    <table>
      <thead>
        <tr>
          <th>Nombre completo</th>
          <th>Correo</th>
          <th>Curso</th>
          <th>Fecha de Registro</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($usuarios as $u): ?>
          <tr>
            <td><?= htmlspecialchars($u['nombre'] . ' ' . $u['apellido']) ?></td>
            <td><?= htmlspecialchars($u['correo']) ?></td>
            <td><?= htmlspecialchars($u['curso']) ?></td>
            <td><?= date('d/m/Y H:i', strtotime($u['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</body>
</html>
