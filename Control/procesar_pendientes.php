<?php
require 'config.php';
require 'vendor/autoload.php';   // PHPMailer
$db         = getDb();
$mailCfg = require 'mail_config.php';
$cookieFile = __DIR__ . '/edx_cookies.txt';

// ——————————————————————————
// 1) Configura tu HOST/Scheme EXACTO
// ——————————————————————————
$baseUrl   = 'http://local.openedx.io';
$rootUrl   = $baseUrl . '/';
$loginApi  = $baseUrl . '/api/user/v1/account/login_session/';
$enrollApi = $baseUrl . '/api/enrollment/v1/enrollment';

// ——————————————————————————
// 2) Extrae el CSRF de edx_cookies.txt
// ——————————————————————————
function getCsrf(string $cookieFile): string {
    if (!file_exists($cookieFile)) {
        return '';
    }
    foreach (file($cookieFile, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/\tcsrftoken\t([^\t]+)$/', $line, $m)) {
            return $m[1];
        }
    }
    return '';
}

// ——————————————————————————
// 3) GET inicial para cookies + CSRF inicial
// ——————————————————————————
$ch = curl_init($rootUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIEJAR      => $cookieFile,
    CURLOPT_COOKIEFILE     => $cookieFile,
]);
curl_exec($ch);
curl_close($ch);

$csrf = getCsrf($cookieFile);
if (!$csrf) die('No se encontró CSRF inicial.');

// ——————————————————————————
// 4) LOGIN admin → actualiza sessionid + CSRF
// ——————————————————————————
$loginQry = http_build_query([
    'email'    => 'admin@academus.mx',
    'password' => 'Academus2025#',
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
if ($code !== 200) die("Login falló: HTTP $code → $resp");

// ——————————————————————————
// 5) Refresca CSRF tras login (nuevo GET)
// ——————————————————————————
$ch = curl_init($rootUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIEJAR      => $cookieFile,
    CURLOPT_COOKIEFILE     => $cookieFile,
]);
curl_exec($ch);
curl_close($ch);

$csrf = getCsrf($cookieFile);
if (!$csrf) die('No se encontró CSRF tras login.');

// ——————————————————————————
// 6) Procesar acciones del formulario
// ——————————————————————————
$act = $_POST['action'] ?? '';

// 6.1) Alta de usuarios
if ($act === 'alta' && !empty($_POST['alta_ids'])) {
    foreach ($_POST['alta_ids'] as $id) {
        $stmt = $db->prepare('SELECT * FROM usuarios WHERE id = ?');
        $stmt->execute([$id]);
        $usr = $stmt->fetch(PDO::FETCH_ASSOC);

        $data = [
            'username'         => $usr['username'],
            'password'         => $usr['password_plain'],
            'email'            => $usr['correo'],
            'name'             => $usr['nombre'] . ' ' . $usr['apellido'],
            'country'          => 'MX',
            'honor_code'       => true,
            'terms_of_service' => true,
        ];
        $ch = curl_init($baseUrl . '/api/user/v1/account/registration/');
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

        if (($st >= 200 && $st < 300) || ($st === 409 && strpos($res, 'duplicate-email') !== false)) {
            $db->prepare('UPDATE usuarios SET alta = 1 WHERE id = ?')->execute([$id]);
        } else {
            $db->prepare('UPDATE usuarios SET error_message = ? WHERE id = ?')
               ->execute(["HTTP $st: $res", $id]);
        }
    }
}

// 6.2) Inscripción (JSON payload con course_details)
if ($act === 'asign' && !empty($_POST['asign_ids'])) {
    foreach ($_POST['asign_ids'] as $id) {
        $stmt = $db->prepare('SELECT * FROM usuarios WHERE id = ?');
        $stmt->execute([$id]);
        $usr = $stmt->fetch(PDO::FETCH_ASSOC);

        $enrollData = [
            'user'           => $usr['username'],
            'course_details' => [
                'course_id' => 'course-v1:Preescolar+CAD001+2025_MAR',
                'mode'      => 'honor',
            ],
        ];
        $payload = json_encode($enrollData);

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

        if ($st >= 200 && $st < 300) {
            $db->prepare('UPDATE usuarios SET asignado = 1 WHERE id = ?')->execute([$id]);
        } else {
            $db->prepare('UPDATE usuarios SET error_message = ? WHERE id = ?')
               ->execute(["HTTP $st: $res", $id]);
        }
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
// ——————————————————————————
// 6.3) Notificación por mail con PHPMailer
// ——————————————————————————
if ($act === 'notif' && !empty($_POST['notif_ids'])) {
    foreach ($_POST['notif_ids'] as $id) {
        // 1) Carga datos del usuario
        $stmt = $db->prepare('SELECT * FROM usuarios WHERE id = ?');
        $stmt->execute([$id]);
        $usr = $stmt->fetch(PDO::FETCH_ASSOC);

        // 2) Prepara PHPMailer
        $mail = new PHPMailer(true);
        try {
            // Configuración SMTP de $mailCfg
            $mail->isSMTP();
            $mail->Host       = $mailCfg['smtp']['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $mailCfg['smtp']['username'];
            $mail->Password   = $mailCfg['smtp']['password'];
            $mail->SMTPSecure = $mailCfg['smtp']['encryption'];
            $mail->Port       = $mailCfg['smtp']['port'];

            // From / To
            $mail->setFrom($mailCfg['smtp']['from_email'], $mailCfg['smtp']['from_name']);
            $mail->addAddress($usr['correo'], $usr['nombre'].' '.$usr['apellido']);

            // Contenido
            $mail->isHTML(false);
            $mail->Subject = 'Credenciales Open edX';
            $mail->Body    = "Usuario: {$usr['username']}\nContraseña: {$usr['password_plain']}";

            // Envía
            $mail->send();

            // Marca como notificado
            $db->prepare('UPDATE usuarios SET notificado = 1 WHERE id = ?')
               ->execute([$id]);
        } catch (Exception $e) {
            // Guarda el error en la BD
            $db->prepare('UPDATE usuarios SET error_message = ? WHERE id = ?')
               ->execute([$mail->ErrorInfo, $id]);
        }
    }
} 
// ——————————————————————————
// 7) Redirigir al panel
// ——————————————————————————
header('Location: admin_panel.php');
exit;