<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

$mail = new PHPMailer(true);

try {
    // Server settings
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com'; // e.g. Gmail
    $mail->SMTPAuth = true;
    $mail->Username = 'your-email@gmail.com'; // your Gmail
    $mail->Password = 'your-email-password'; // or App Password if 2FA
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    // Recipients
    $mail->setFrom('your-email@gmail.com', 'LSPU Admin');
    $mail->addAddress('recipient@example.com', 'New User');

    // Content
    $mail->isHTML(true);
    $mail->Subject = 'LSPU Registration Approved';
    $mail->Body    = 'Hello, your registration has been approved.';

    $mail->send();
    echo 'Message has been sent';
} catch (Exception $e) {
    echo "Mailer Error: {$mail->ErrorInfo}";
}
