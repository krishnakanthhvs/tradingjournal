<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

function getMailer(): PHPMailer
{
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;

    // ⚠️ CHANGE THESE
    $mail->Username   = 'janupandu1606@gmail.com';
    $mail->Password   = 'mdzr lxcg odgc zhsb';

    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom('janupandu1606@gmail.com', 'MyDailyDiary');
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';

    return $mail;
}