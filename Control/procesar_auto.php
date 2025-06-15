<?php
require 'config.php';
require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$db = getDb();
$mailCfg = require 'mail_config.php';
$cookieFile = __DIR__ . '/edx_cookies.txt';

$baseUrl = 'http://local.openedx.io';
$rootUrl = $baseUrl . '/';
$loginApi = $baseUrl . '/api/user/v1/account/login_session/';
$enrollApi = $baseUrl . '/api/enrollment/v1/enrollment';

function getCsrf(string $cookieFile): string
{
    if (!file_exists($cookieFile))
        return '';
    foreach (file($cookieFile, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/\tcsrftoken\t([^\t]+)$/', $line, $m)) {
            return $m[1];
        }
    }
    return '';
}

$id = $_GET['id'] ?? null;
if (!$id)
    exit('ID de usuario no especificado');

// 1. Obtener usuario de la base de datos
$stmt = $db->prepare('SELECT * FROM usuarios WHERE id = ?');
$stmt->execute([$id]);
$usr = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$usr)
    exit('Usuario no encontrado');

$username = $usr['username'];

// 2. GET inicial (cookies + CSRF)
$ch = curl_init($rootUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
]);
curl_exec($ch);
curl_close($ch);
$csrf = getCsrf($cookieFile);
if (!$csrf)
    die('No se encontró CSRF inicial.');

// 3. Registro del usuario en Open edX
$data = [
    'username' => $username,
    'password' => $usr['password_plain'],
    'email' => $usr['correo'],
    'name' => $usr['nombre'] . ' ' . $usr['apellido'],
    'country' => 'MX',
    'honor_code' => true,
    'terms_of_service' => true,
];
$ch = curl_init($baseUrl . '/api/user/v1/account/registration/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($data),
    CURLOPT_HTTPHEADER => [
        "X-CSRFToken: $csrf",
        "Referer: $rootUrl",
        'Content-Type: application/x-www-form-urlencoded',
    ],
]);
$res = curl_exec($ch);
$st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$successAlta = false;
if (($st >= 200 && $st < 300) || ($st === 409 && strpos($res, 'duplicate-email') !== false)) {
    $db->prepare('UPDATE usuarios SET alta=1 WHERE id=?')->execute([$id]);
    $successAlta = true;
} else {
    $db->prepare('UPDATE usuarios SET alta=0, asignado=0, notificado=0, error_message=? WHERE id=?')
        ->execute(["HTTP $st: $res", $id]);
    header('Location: panelparasoporteregistro2025.php');
    exit;
}

// 4. Eliminar cookies y obtener nuevas antes de login admin
unlink($cookieFile);
$ch = curl_init($rootUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
]);
curl_exec($ch);
curl_close($ch);
$csrf = getCsrf($cookieFile);
if (!$csrf)
    die('No se encontró CSRF tras limpiar cookies.');

// 5. LOGIN admin
$loginQry = http_build_query([
    'email' => 'admin@academus.mx',
    'password' => 'Academus2025#',
]);
$ch = curl_init($loginApi);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $loginQry,
    CURLOPT_HTTPHEADER => [
        "X-CSRFToken: $csrf",
        "Referer: $rootUrl",
        'Content-Type: application/x-www-form-urlencoded',
    ],
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($code !== 200)
    die("Login falló: HTTP $code → $resp");

// 6. Refrescar CSRF tras login admin
$ch = curl_init($rootUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
]);
curl_exec($ch);
curl_close($ch);
$csrf = getCsrf($cookieFile);

// 7. Inscripción al curso
$successAsign = false;
if ($successAlta) {
    $mapCursos = [
        '1° Primaria' => 'course-v1:Preescolar+CAD001+2025_MAR',
        '2° Primaria' => 'course-v1:Unimec+CAD001+2025_MAR',
    ];
    $nombreCurso = $usr['curso'] ?? '';
    $course_id = $mapCursos[$nombreCurso] ?? null;

    if (!$course_id) {
        $db->prepare('UPDATE usuarios SET asignado=0, notificado=0, error_message=? WHERE id=?')
            ->execute(["Curso no reconocido: $nombreCurso", $id]);
        header('Location: panelparasoporteregistro2025.php');
        exit;
    }

    $enrollData = [
        'user' => $username,
        'course_details' => [
            'course_id' => $course_id,
            'mode' => 'honor',
        ],
    ];
    $payload = json_encode($enrollData);

    $ch = curl_init($enrollApi);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            "X-CSRFToken: $csrf",
            "Referer: $enrollApi",
            'Content-Type: application/json',
        ],
    ]);
    $res = curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($st >= 200 && $st < 300) {
        $db->prepare('UPDATE usuarios SET asignado=1 WHERE id=?')->execute([$id]);
        $successAsign = true;
    } else {
        $db->prepare('UPDATE usuarios SET asignado=0, notificado=0, error_message=? WHERE id=?')
            ->execute(["HTTP $st: $res", $id]);
    }
}

// 8. Notificación por correo
if ($successAsign) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $mailCfg['smtp']['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $mailCfg['smtp']['username'];
        $mail->Password = $mailCfg['smtp']['password'];
        $mail->SMTPSecure = $mailCfg['smtp']['encryption'];
        $mail->Port = $mailCfg['smtp']['port'];

        $mail->setFrom($mailCfg['smtp']['from_email'], $mailCfg['smtp']['from_name']);
        $mail->addAddress($usr['correo'], $usr['nombre'] . ' ' . $usr['apellido']);
        $mail->isHTML(true); // Ahora enviará correo con formato HTML
        $mail->Subject = 'Bienvenido a la Plataforma de Aprendizaje – Cuenta de acceso';

        $nombreCompleto = $usr['nombre'] . ' ' . $usr['apellido'];
        $correoUsuario = $usr['correo'];
        $contrasena = $usr['password_plain'];
        $username = $usr['username'];

        $mail->Body = "
    <p>👋 Estimado(a) <strong>$nombreCompleto</strong>,</p>

    <p>🎓 ¡Bienvenido(a) a nuestra plataforma de aprendizaje en línea <strong>Academus Digital</strong>!</p>

    <p>Nos da mucho gusto que formes parte de esta comunidad comprometida con el crecimiento y la formación continua. 
    A partir de ahora, tendrás acceso a contenidos diseñados para potenciar tu desarrollo y alcanzar tus metas de aprendizaje. 🚀</p>

    <p><strong>🔐 A continuación, te compartimos tus datos de acceso:</strong></p>
    <ul>
        <li>👤 <strong>Usuario:</strong> $username</li>
        <li>📧 <strong>Correo:</strong> $correoUsuario</li>
        <li>🔑 <strong>Contraseña temporal:</strong> $contrasena</li>
        <li>🌐 <strong>Enlace de acceso:</strong> <a href='https://app.academusdigital.com'>https://app.academusdigital.com</a></li>
    </ul>

    <p>ℹ️ <strong>Recuerda que puedes iniciar sesión usando tu <u>nombre de usuario</u> o <u>correo electrónico</u>.</strong></p>

    <p>📄 <strong>Guía de acceso:</strong> (inserta aquí el enlace real a la guía)</p>

    <p>🔁 Por favor, te recomendamos cambiar tu contraseña al ingresar por primera vez. 
    Si tienes alguna duda o dificultad técnica, no dudes en escribirnos. 💬</p>

    <p>Un saludo cordial,<br>
    💙 <strong>Equipo de Academus Digital</strong></p>
";

        $mail->send();

        $db->prepare('UPDATE usuarios SET notificado=1 WHERE id=?')->execute([$id]);
    } catch (Exception $e) {
        $db->prepare('UPDATE usuarios SET notificado=0, error_message=? WHERE id=?')
            ->execute([$mail->ErrorInfo, $id]);
    }
}

// 9. Redirigir
header('Location: registro_exitoso.php');
exit;
