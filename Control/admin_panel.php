<?php
require 'config.php';
$db = getDb();

// 1) Todos los usuarios
$all       = $db->query('SELECT * FROM usuarios')->fetchAll(PDO::FETCH_ASSOC);
// 2) Pendientes de alta
$pendAlta  = $db->query('SELECT * FROM usuarios WHERE alta = 0')->fetchAll(PDO::FETCH_ASSOC);
// 3) Pendientes de asignación
$pendAsign = $db->query('SELECT * FROM usuarios WHERE alta = 1 AND asignado = 0')->fetchAll(PDO::FETCH_ASSOC);
// 4) Pendientes de notificación
$pendNotif = $db->query('SELECT * FROM usuarios WHERE alta = 1 AND asignado = 1 AND notificado = 0')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel de Control</title>
    <style>
        :root {
            --primary-color: #5c6bc0;
            --secondary-color: #3949ab;
            --accent-color: #ffca28;
            --bg-light: #f5f5f5;
            --text-dark: #212121;
        }
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: var(--bg-light); color: var(--text-dark); margin:0; }
        header {
            background: var(--primary-color);
            color: white;
            padding: 1rem;
            text-align: center;
        }
        main { padding: 1rem; }
        h2 { margin: 1.5rem 0 0.5rem; color: var(--secondary-color); border-left: 4px solid var(--accent-color); padding-left: 0.5rem; }
        .section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 1rem;
            margin-bottom: 1.5rem;
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
            min-width: 800px;
        }
        th, td {
            padding: 0.5rem;
            text-align: left;
            border: 1px solid #ddd;
            white-space: nowrap;
        }
        th {
            background: var(--secondary-color);
            color: white;
        }
        tr:nth-child(even) {
            background: #fafafa;
        }
        label { display: flex; align-items: center; margin-bottom: 0.5rem; }
        input[type="checkbox"] { margin-right: 0.5rem; transform: scale(1.2); }
        .btn {
            background: var(--secondary-color);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.95rem;
            transition: background 0.2s;
        }
        .btn:hover { background: var(--primary-color); }
        .form-group { margin-top: 1rem; }
    </style>
</head>
<body>
    <header>
        <h1>Panel de Control</h1>
    </header>
    <main>
        <div class="section">
            <h2>1) Todos los usuarios</h2>
            <table>
                <thead>
                    <tr>
                        <?php foreach (array_keys($all[0] ?? []) as $col): ?>
                            <th><?= htmlspecialchars($col) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all as $row): ?>
                        <tr>
                            <?php foreach ($row as $cell): ?>
                                <td><?= htmlspecialchars((string)$cell) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <h2>2) Pendientes de Alta</h2>
            <form method="post" action="procesar_pendientes.php">
                <?php foreach ($pendAlta as $r): ?>
                    <label>
                        <input type="checkbox" name="alta_ids[]" value="<?= $r['id'] ?>">
                        <?= htmlspecialchars($r['nombre'] . ' ' . $r['apellido']) ?>
                    </label>
                <?php endforeach; ?>
                <div class="form-group">
                    <button type="submit" name="action" value="alta" class="btn">Procesar Registro</button>
                </div>
            </form>
        </div>

        <div class="section">
            <h2>3) Pendientes de Asignación</h2>
            <form method="post" action="procesar_pendientes.php">
                <?php foreach ($pendAsign as $r): ?>
                    <label>
                        <input type="checkbox" name="asign_ids[]" value="<?= $r['id'] ?>">
                        <?= htmlspecialchars($r['username']) ?>
                    </label>
                <?php endforeach; ?>
                <div class="form-group">
                    <button type="submit" name="action" value="asign" class="btn">Inscribir en Curso</button>
                </div>
            </form>
        </div>

        <div class="section">
            <h2>4) Pendientes de Notificación</h2>
            <form method="post" action="procesar_pendientes.php">
                <?php foreach ($pendNotif as $r): ?>
                    <label>
                        <input type="checkbox" name="notif_ids[]" value="<?= $r['id'] ?>">
                        <?= htmlspecialchars($r['username']) ?>
                    </label>
                <?php endforeach; ?>
                <div class="form-group">
                    <button type="submit" name="action" value="notif" class="btn">Enviar Credenciales</button>
                </div>
            </form>
        </div>
    </main>
</body>
</html>