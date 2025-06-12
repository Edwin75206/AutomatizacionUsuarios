<?php
// Parámetros de conexión a MySQL (XAMPP)
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'control_db');
define('DB_USER', 'root');
define('DB_PASS', '');

/**
 * Obtiene una conexión PDO con el modo de errores en excepciones.
 *
 * @return PDO
 */
function getDb(): PDO
{
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        die('Error al conectar con la base de datos: ' . $e->getMessage());
    }
}
?>
