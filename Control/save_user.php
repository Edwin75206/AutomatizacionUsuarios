<?php
require 'config.php';
$db = getDb();

// 1) Recoger y sanitizar datos del formulario
$nombre   = trim($_POST['nombre']   ?? '');
$apellido = trim($_POST['apellido'] ?? '');
$correo   = trim($_POST['correo']   ?? '');
$curso    = trim($_POST['curso']    ?? '');

// 2) Verificar si ese correo ya está inscrito en el mismo curso
$stmt = $db->prepare("
    SELECT COUNT(*) 
      FROM usuarios
     WHERE correo = ?
       AND curso  = ?
");
$stmt->execute([$correo, $curso]);
if ($stmt->fetchColumn() > 0) {
    echo "<script>
            alert('Este correo ya está inscrito en este curso.');
            window.history.back();
          </script>";
    exit;
}

// 3) Generar username y password aleatoria
$username       = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nombre . $apellido))
                    . rand(100, 999);
$password_plain = bin2hex(random_bytes(4));
$password_hash  = password_hash($password_plain, PASSWORD_DEFAULT);

// 4) Insertar en la tabla de control
$stmt = $db->prepare(
    'INSERT INTO usuarios
        (nombre, apellido, username, correo, curso, password_hash, password_plain)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $nombre,
    $apellido,
    $username,
    $correo,
    $curso,
    $password_hash,
    $password_plain
]);

// 5) Redirigir al panel de admin
$id = $db->lastInsertId();
header("Location: procesar_auto.php?id=$id");
exit;
