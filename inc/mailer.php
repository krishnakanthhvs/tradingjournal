<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';
require_once __DIR__ . '/phpmailer/Exception.php';

/**
 * Send email using Gmail SMTP
 */
function send_smtp_mail(string $to, string $subject, string $html): bool
{
    $mail = new PHPMailer(true);

    try {
        // SMTP settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'janupandu1606@gmail.com';   // 🔴 CHANGE
        $mail->Password   = 'mdzr lxcg odgc zhsb';         // 🔴 CHANGE
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Mail headers
        $mail->setFrom('janupandu1606@gmail.com', 'MyDailyDiary');
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log('SMTP Mail Error: ' . $mail->ErrorInfo);
        return false;
    }
}