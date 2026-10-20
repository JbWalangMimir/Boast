<?php
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

const SMTP_HOST = 'smtp.gmail.com';
const SMTP_USER = 'siriuslyunfortunate@gmail.com';
const SMTP_PASS = 'ndgj fpvu jyod qize';
const SMTP_PORT = 587;
const MAIL_FROM = 'siriuslyunfortunate@gmail.com';

function sendMailMessage(string $to, string $name, string $subject, string $html, string $alt): bool
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host        = SMTP_HOST;
        $mail->SMTPAuth    = true;
        $mail->Username    = SMTP_USER;
        $mail->Password    = str_replace(' ', '', SMTP_PASS);
        $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port        = SMTP_PORT;
        $mail->Timeout     = 20;
        $mail->CharSet     = 'UTF-8';
        $mail->SMTPDebug   = 0;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];

        $mail->setFrom(MAIL_FROM, 'Boast');
        $mail->addAddress($to, $name);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $alt;

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        $GLOBALS['mail_error'] = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
        return false;
    }
}

function sendVerificationCode(string $to, string $name, string $code): bool
{
    return sendMailMessage(
        $to,
        $name,
        'Your Boast verification code',
        'Hi ' . htmlspecialchars($name) . ',<br><br>Your verification code is:<br><br>'
            . '<h2 style="letter-spacing:4px;">' . $code . '</h2>It expires in 15 minutes.',
        "Your verification code is: $code (expires in 15 minutes)"
    );
}

function sendResetCode(string $to, string $name, string $code): bool
{
    return sendMailMessage(
        $to,
        $name,
        'Your Boast password reset code',
        'Hi ' . htmlspecialchars($name) . ',<br><br>Your password reset code is:<br><br>'
            . '<h2 style="letter-spacing:4px;">' . $code . '</h2>It expires in 15 minutes. If you did not request this, you can ignore this email.',
        "Your password reset code is: $code (expires in 15 minutes)"
    );
}