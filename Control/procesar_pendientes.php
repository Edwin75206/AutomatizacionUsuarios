<?php
require 'config.php';
require 'vendor/autoload.php';   // PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$db         = getDb();
$mailCfg    = require 'mail_config.php';
$cookieFile = __DIR__ . '/edx_cookies.txt';

// ——————————————————————————
// 1) Configuración de endpoints
// ——————————————————————————
$baseUrl   = 'https://app.academusdigital.com';
$rootUrl   = $baseUrl . '/';
$loginApi  = $baseUrl . '/api/user/v1/account/login_session/';
$enrollApi = $baseUrl . '/api/enrollment/v1/enrollment';

// ——————————————————————————
// 2) Función para extraer CSRF de cookies
// ——————————————————————————
function getCsrf(string $cookieFile): string {
    if (!file_exists($cookieFile)) return '';
    foreach (file($cookieFile, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/\tcsrftoken\t([^\t]+)$/', $line, $m)) {
            return $m[1];
        }
    }
    return '';
}

// ——————————————————————————
// 3) Inicializar sesión + CSRF
// ——————————————————————————
@unlink($cookieFile);
$ch = curl_init($rootUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIEJAR      => $cookieFile,
    CURLOPT_COOKIEFILE     => $cookieFile,
]);
curl_exec($ch); curl_close($ch);

$csrf = getCsrf($cookieFile);
if (!$csrf) die('No se encontró CSRF inicial.');

// ——————————————————————————
// 4) Login admin
// ——————————————————————————
$loginQry = http_build_query([
    'email'    => 'admin@academusdigital',
    'password' => 'admin@academus2025',
]);
$ch = curl_init($loginApi);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIEJAR      => $cookieFile,
    CURLOPT_COOKIEFILE     => $cookieFile,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $loginQry,
    CURLOPT_HTTPHEADER     => [
        "X-CSRFToken: $csrf",
        "Referer: $rootUrl",
        'Content-Type: application/x-www-form-urlencoded',
    ],
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($code !== 200) die("Login admin falló: HTTP $code → $resp");

// refrescar CSRF tras login
$ch = curl_init($rootUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIEJAR      => $cookieFile,
    CURLOPT_COOKIEFILE     => $cookieFile,
]);
curl_exec($ch); curl_close($ch);
$csrf = getCsrf($cookieFile);
if (!$csrf) die('No se encontró CSRF tras login.');

// ——————————————————————————
// 5) Acción según formulario
// ——————————————————————————
$act = $_POST['action'] ?? '';

// Helper: enrolar un solo curso
function enrollCourse($username, $courseId, $csrf, $cookieFile, $enrollApi) {
    $payload = json_encode([
        'user'           => $username,
        'course_details' => ['course_id' => $courseId, 'mode' => 'honor'],
    ]);
    $ch = curl_init($enrollApi);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            "X-CSRFToken: $csrf",
            "Referer: $enrollApi",
            'Content-Type: application/json',
        ],
    ]);
    $res = curl_exec($ch);
    $st  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $res];
}

// ——————————————————————————
// 5.1) Alta de usuarios
// ——————————————————————————
if ($act === 'alta' && !empty($_POST['alta_ids'])) {
    foreach ($_POST['alta_ids'] as $id) {
        $u = $db->prepare('SELECT * FROM usuarios WHERE id = ?');
        $u->execute([$id]);
        $usr = $u->fetch(PDO::FETCH_ASSOC);
        $data = [
            'username'         => $usr['username'],
            'password'         => $usr['password_plain'],
            'email'            => $usr['correo'],
            'name'             => $usr['nombre'].' '.$usr['apellido'],
            'country'          => 'MX',
            'honor_code'       => true,
            'terms_of_service' => true,
        ];
        $ch = curl_init($baseUrl.'/api/user/v1/account/registration/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_HTTPHEADER     => [
                "X-CSRFToken: $csrf",
                "Referer: $rootUrl",
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);
        $res = curl_exec($ch);
        $st  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (($st>=200 && $st<300) || ($st===409 && strpos($res,'duplicate-email')!==false)) {
            $db->prepare('UPDATE usuarios SET alta=1 WHERE id=?')->execute([$id]);
        } else {
            $db->prepare('UPDATE usuarios SET error_message=? WHERE id=?')
               ->execute(["HTTP $st → $res",$id]);
        }
    }
}

// ——————————————————————————
// 5.2) Inscripción (curso dinámico + biblioteca)
// ——————————————————————————
if ($act==='asign' && !empty($_POST['asign_ids'])) {
    $courseMap = [
    'Pre-Primaria'                  => 'course-v1:Primaria+CPRIAD000+2025_MAR',
    '1° Primaria'                   => 'course-v1:Primaria+CPRIAD001+2025_MAR',
    '2° Primaria'                   => 'course-v1:Primaria+CPRIAD002+2025_MAR',
    '3° Primaria'                   => 'course-v1:Primaria+CPRIAD003+2025_MAR',
    '4° Primaria'                   => 'course-v1:Primaria+CPRIAD004+2025_MAR',
    '5° Primaria'                   => 'course-v1:Primaria+CPRIAD005+2025_MAR',
    '6° Primaria'                   => 'course-v1:Primaria+CPRIAD006+2025_MAR',
    '1° de Secundaria'             => 'course-v1:Secundaria+CPSECD001+2025',
    '2° de Secundaria'             => 'course-v1:Secundaria+CPSECD002+2025',
    '3° de Secundaria'             => 'course-v1:Secundaria+CPSECD003+2025',
    'Preparatoria 1° Semestre'     => 'course-v1:Preparatoria+CPTECS001+2025',
    'Preparatoria 2° Semestre'     => 'course-v1:Preparatoria+CPTECS002+2025',
    'Preparatoria 3° Semestre'     => 'course-v1:Preparatoria+CPTECS003+2025',
    'Preparatoria 4° Semestre'     => 'course-v1:Preparatoria+CPTECS004+2025',
    'Preparatoria 5° Semestre'     => 'course-v1:Preparatoria+CPTECS005+2025',
    'Preparatoria 6° Semestre'     => 'course-v1:Preparatoria+CPTECS006+2025',
];

    $libCourse = 'course-v1:Unimec+CBIBAD001+2025_ABR';
    foreach ($_POST['asign_ids'] as $id) {
        $stmt = $db->prepare('SELECT * FROM usuarios WHERE id=?');
        $stmt->execute([$id]);
        $usr = $stmt->fetch(PDO::FETCH_ASSOC);
        $dyn = $courseMap[$usr['curso']] ?? null;
        if (!$dyn) {
            $db->prepare('UPDATE usuarios SET error_message=? WHERE id=?')
               ->execute(["Curso no reconocido: {$usr['curso']}",$id]);
            continue;
        }
        list($st1,$res1) = enrollCourse($usr['username'],$dyn, $csrf,$cookieFile,$enrollApi);
        list($st2,$res2) = enrollCourse($usr['username'],$libCourse,$csrf,$cookieFile,$enrollApi);
        if ($st1>=200&&$st1<300 && $st2>=200&&$st2<300) {
            $db->prepare('UPDATE usuarios SET asignado=1 WHERE id=?')->execute([$id]);
        } else {
            $err = "Dinámico:$st1/$res1 Biblioteca:$st2/$res2";
            $db->prepare('UPDATE usuarios SET error_message=? WHERE id=?')
               ->execute([$err,$id]);
        }
    }
}

// ——————————————————————————
// 5.3) Notificación por correo (**mismo mensaje que en procesar_auto**)
// ——————————————————————————
if ($act==='notif' && !empty($_POST['notif_ids'])) {
    foreach ($_POST['notif_ids'] as $id) {
        $stmt = $db->prepare('SELECT * FROM usuarios WHERE id=?');
        $stmt->execute([$id]);
        $usr = $stmt->fetch(PDO::FETCH_ASSOC);

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $mailCfg['smtp']['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $mailCfg['smtp']['username'];
            $mail->Password   = $mailCfg['smtp']['password'];
            $mail->SMTPSecure = $mailCfg['smtp']['encryption'];
            $mail->Port       = $mailCfg['smtp']['port'];

            $mail->setFrom($mailCfg['smtp']['from_email'], $mailCfg['smtp']['from_name']);
            $mail->addAddress($usr['correo'], $usr['nombre'].' '.$usr['apellido']);

            $mail->isHTML(true);
            $mail->Subject = 'Bienvenido a la Plataforma de Aprendizaje – Cuenta de acceso';
            $mail->Body    = "
                <p>👋 Estimado(a) <strong>{$usr['nombre']} {$usr['apellido']}</strong>,</p>
                <p>🎓 ¡Bienvenido(a) a nuestra plataforma de aprendizaje en línea <strong>Academus Digital</strong>!</p>
                <p>Nos da mucho gusto que formes parte de esta comunidad comprometida con el crecimiento y la formación continua. 🚀</p>
                <p><strong>🔐 Tus credenciales de acceso:</strong></p>
                <ul>
                  <li>👤 <strong>Usuario:</strong> {$usr['username']}</li>
                  <li>📧 <strong>Correo:</strong> {$usr['correo']}</li>
                  <li>🔑 <strong>Contraseña temporal:</strong> {$usr['password_plain']}</li>
                  <li>🌐 <strong>Enlace:</strong> <a href='{$baseUrl}'>{$baseUrl}</a></li>
                </ul>
                <p>🔁 Te recomendamos cambiar tu contraseña al ingresar por primera vez.</p>
                <p>Un saludo cordial,<br>💙 <strong>Equipo de Academus Digital</strong></p>
            ";

            $mail->send();
            $db->prepare('UPDATE usuarios SET notificado=1 WHERE id=?')->execute([$id]);
        } catch (Exception $e) {
            $db->prepare('UPDATE usuarios SET error_message=? WHERE id=?')
               ->execute([$mail->ErrorInfo,$id]);
        }
    }
}

// ——————————————————————————
// 6) Volver al panel
// ——————————————————————————
header('Location: panelparasoporteregistro2025.php');
exit;
