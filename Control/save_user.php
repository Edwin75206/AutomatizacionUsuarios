<?php
require 'config.php';
$db = getDb();

// Recoger y sanitizar datos del formulario
define('MIN_COURSE', 1);
$nombre   = trim($_POST['nombre']   ?? '');
$apellido = trim($_POST['apellido'] ?? '');
$correo   = trim($_POST['correo']   ?? '');
$curso    = intval($_POST['curso']  ?? MIN_COURSE);

// Generar username y password aleatoria
$username        = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nombre . $apellido)) . rand(100, 999);
$password_plain  = bin2hex(random_bytes(4));
$password_hash   = password_hash($password_plain, PASSWORD_DEFAULT);

// Insertar en la tabla de control, incluyendo contraseña plana
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

// Redirigir al panel de administración\header('Location: admin_panel.php');
$id = $db->lastInsertId();
header("Location: procesar_auto.php?id=$id");
exit;
