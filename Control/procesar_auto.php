<?php
require 'config.php';
require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$db = getDb();
$mailCfg = require 'mail_config.php';
$cookieFile = __DIR__ . '/edx_cookies.txt';

$baseUrl = 'https://app.academusdigital.com';
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

// *** AQUI INICIA LA LOGICA DE HERMANOS (correo duplicado pero diferente nombre o curso) ***

// Buscar si el correo ya está registrado para otro usuario distinto con diferente nombre o curso
$stmtCheck = $db->prepare('SELECT * FROM usuarios WHERE correo = ? ORDER BY id ASC');
$stmtCheck->execute([$usr['correo']]);
$usuariosMismoCorreo = $stmtCheck->fetchAll(PDO::FETCH_ASSOC);

$esDuplicado = false;
$primerRegistro = null;
$cont = 1;

foreach ($usuariosMismoCorreo as $reg) {
    if (
        $reg['id'] != $id &&
        ($reg['nombre'] != $usr['nombre'] || $reg['curso'] != $usr['curso'])
    ) {
        $esDuplicado = true;
        $primerRegistro = $reg; // El primer usuario con ese correo
        break;
    }
}

if ($esDuplicado) {
    // 1. Generar nuevo correo con +1, +2, etc
    $correoParts = explode('@', $usr['correo']);
    $nuevoCorreo = $correoParts[0] . '+' . $cont . '@' . $correoParts[1];
    while (true) {
        $stmtTmp = $db->prepare('SELECT COUNT(*) FROM usuarios WHERE correo = ?');
        $stmtTmp->execute([$nuevoCorreo]);
        $cuantos = $stmtTmp->fetchColumn();
        if ($cuantos == 0)
            break;
        $cont++;
        $nuevoCorreo = $correoParts[0] . '+' . $cont . '@' . $correoParts[1];
    }
    // 2. Actualizar correo en BD de control
    $db->prepare('UPDATE usuarios SET correo = ? WHERE id = ?')->execute([$nuevoCorreo, $id]);
    // 3. Tomar datos del primer registro (usuario ya existente)
    $usernameOriginal = $primerRegistro['username'];
    $correoOriginal = $primerRegistro['correo'];
    $contrasenaOriginal = $primerRegistro['password_plain'];

    // 4. LOGIN admin para obtener CSRF limpio 
    unlink($cookieFile);

    // Primera llamada para generar cookies
    $ch = curl_init($rootUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_HTTPHEADER => [
            'Referer: ' . $rootUrl,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);

    // Segunda llamada por si la primera no genera el CSRF
    $ch = curl_init($rootUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_HTTPHEADER => [
            'Referer: ' . $rootUrl,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);

    // Obtener el CSRF token después de limpiar cookies y hacer doble solicitud
    $csrf = getCsrf($cookieFile);
    if (!$csrf) {
        die('No se encontró CSRF tras limpiar cookies.');
    }

    // 1) Arma el array con username (no email) y contraseña
    $loginData = [
        'username' => 'sysadmin',
        'password' => 'admin@academus2025',
    ];

    // 2) JSON-encode y envíalo como JSON
    $payload = json_encode($loginData);
    curl_setopt_array($ch, [
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            "X-CSRFToken: $csrf",
            "Referer: $rootUrl",
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

    // 7. Enrolar al curso SOLO usando el usuario original
    $mapCursos = [
        '1° Primaria' => 'course-v1:Primaria+CPRIAD001+2025_MAR',
        '2° Primaria' => 'course-v1:Primaria+CPRIAD002+2025_MAR',
        // ... agregar todos los cursos 
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
        'user' => $usernameOriginal,
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

    $successAsign = false;
    if ($st >= 200 && $st < 300) {
        $db->prepare('UPDATE usuarios SET asignado=1 WHERE id=?')->execute([$id]);
        $successAsign = true;
    } else {
        $db->prepare('UPDATE usuarios SET asignado=0, notificado=0, error_message=? WHERE id=?')
            ->execute(["HTTP $st: $res", $id]);
    }

    // 8. Notifica con los DATOS DEL USUARIO ORIGINAL
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
            $mail->addAddress($nuevoCorreo, $usr['nombre'] . ' ' . $usr['apellido']); // al nuevo correo generado
            $mail->isHTML(true);
            $mail->Subject = 'Bienvenido a la Plataforma de Aprendizaje – Cuenta de acceso';

            $nombreCompleto = $usr['nombre'] . ' ' . $usr['apellido'];
            $mail->Body = "
        <p>👋 Estimado(a) <strong>$nombreCompleto</strong>,</p>

        <p>🎓 ¡Bienvenido(a) a nuestra plataforma de aprendizaje en línea <strong>Academus Digital</strong>!</p>

        <p>Nos da mucho gusto que formes parte de esta comunidad comprometida con el crecimiento y la formación continua. 
        A partir de ahora, tendrás acceso a contenidos diseñados para potenciar tu desarrollo y alcanzar tus metas de aprendizaje. 🚀</p>

        <p><strong>🔐 A continuación, te compartimos tus datos de acceso:</strong></p>
        <ul>
            <li>👤 <strong>Usuario:</strong> $usernameOriginal</li>
            <li>📧 <strong>Correo:</strong> $correoOriginal</li>
            <li>🔑 <strong>Contraseña temporal:</strong> $contrasenaOriginal</li>
            <li>🌐 <strong>Enlace de acceso:</strong> <a href='https://app.academusdigital.com'>https://app.academusdigital.com</a></li>
        </ul>

        <p>ℹ️ <strong>Recuerda que puedes iniciar sesión usando tu <u>nombre de usuario</u> o <u>correo electrónico</u>.</strong></p>
        <p>📄 <strong>Guía de acceso:</strong></p>
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
} // FIN DEL IF DUPLICADO

// ----- SI NO ES DUPLICADO, PROCESO NORMAL -----

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

// 1) Arma el array con username (no email) y contraseña
$loginData = [
    'username' => 'sysadmin',
    'password' => 'admin@academus2025',
];

// 2) JSON-encode y envíalo como JSON
$payload = json_encode($loginData);
curl_setopt_array($ch, [
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        "X-CSRFToken: $csrf",
        "Referer: $rootUrl",
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
        '1° Primaria' => 'course-v1:Primaria+CPRIAD001+2025_MAR',
        '2° Primaria' => 'course-v1:Primaria+CPRIAD002+2025_MAR',
        // ... agrega los demás cursos aquí
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
?>