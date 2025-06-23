<?php
// config.php — Parámetros de conexión a MySQL (Servidor remoto)
define('DB_HOST', 'mysql.academusdigital.com');
define('DB_NAME', 'academcontrol');  
define('DB_USER', 'academusdigitalc');
define('DB_PASS', 'yc3?L**7');
define('DB_PORT', 3306);

/**
 * Obtiene una conexión PDO con el modo de errores en excepciones.
 *
 * @return PDO
 */
function getDb(): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_PORT,
        DB_NAME
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        die('Error al conectar con la base de datos: ' . $e->getMessage());
    }
}
