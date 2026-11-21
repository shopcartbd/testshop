<?php

declare(strict_types=1);:

final class Mailer
{
    public static function configured(): bool
    {
        return getenv('MAIL_HOST') !== false && (string) getenv('MAIL_HOST') !== ''
            && getenv('MAIL_USERNAME') !== false && (string) getenv('MAIL_USERNAME') !== ''
            && getenv('MAIL_PASSWORD') !== false && (string) getenv('MAIL_PASSWORD') !== ''
            && filter_var((string) getenv('MAIL_FROM_ADDRESS'), FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function send(string $to, string $subject, string $text): bool
    {
        if (!self::configured() || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) return false;
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = (string) getenv('MAIL_HOST');
            $mail->Port = (int) (getenv('MAIL_PORT') ?: 587);
            $mail->SMTPAuth = true;
            $mail->Username = (string) getenv('MAIL_USERNAME');
            $mail->Password = (string) getenv('MAIL_PASSWORD');
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Timeout = 8;
            $mail->CharSet = 'UTF-8';
            $mail->setFrom((string) getenv('MAIL_FROM_ADDRESS'), (string) (getenv('MAIL_FROM_NAME') ?: app_name()));
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $text;
            $mail->isHTML(false);
            $mail->send();
            return true;
        } catch (Throwable $error) {
            error_log('Email delivery failed.');
            return false;
        }
    }
}
