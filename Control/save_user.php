<?php
require 'config.php';
$db = getDb();

// 1) Recoger y sanitizar datos del formulario
$nombre   = trim($_POST['nombre']   ?? '');
$apellido = trim($_POST['apellido'] ?? '');
$correo   = trim($_POST['correo']   ?? '');
$curso    = trim($_POST['curso']    ?? '');
$curp     = strtoupper(trim($_POST['curp']    ?? ''));

// 2) Validar formato de CURP
if (!preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/', $curp)) {
    echo "<script>
            alert('CURP inválida. Asegúrate de usar 18 caracteres en mayúsculas.');
            window.history.back();
          </script>";
    exit;
}

// 3) Verificar si ese correo ya está inscrito en el mismo curso
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

// 4) Generar username y password aleatoria
$username       = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nombre . $apellido))
                    . rand(100, 999);
$password_plain = bin2hex(random_bytes(4));
$password_hash  = password_hash($password_plain, PASSWORD_DEFAULT);

// 5) Insertar en la tabla de control (incluyendo CURP)
$stmt = $db->prepare(
    'INSERT INTO usuarios
        (nombre, apellido, username, correo, curso, curp, password_hash, password_plain)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $nombre,
    $apellido,
    $username,
    $correo,
    $curso,
    $curp,
    $password_hash,
    $password_plain
]);

// 6) Redirigir al panel de admin para procesar inscripción automática
$id = $db->lastInsertId();
header("Location: procesar_auto.php?id=$id");
exit;
