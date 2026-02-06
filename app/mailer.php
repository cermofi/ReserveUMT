<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

function mailer_send(string $to, string $subject, string $body): bool {
    $smtp = cfg('smtp');
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $smtp['host'];
            $mail->Port = (int) $smtp['port'];
            $mail->SMTPAuth = true;
            $mail->Username = $smtp['user'];
            $mail->Password = $smtp['pass'];
            $mail->SMTPSecure = $smtp['secure'];
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($smtp['from_email'], $smtp['from_name']);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $body;
            return $mail->send();
        } catch (Throwable $e) {
            debug_log('smtp_error', [
                'type' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    if (!empty($smtp['from_email'])) {
        $headers = "From: " . $smtp['from_name'] . " <" . $smtp['from_email'] . ">\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $ok = @mail($to, $subject, $body, $headers);
        if (!$ok) {
            debug_log('mail_error', [
                'to' => $to,
                'subject' => $subject,
            ]);
        }
        return $ok;
    }

    debug_log('mail_config_missing', [
        'to' => $to,
    ]);
    return false;
}

function send_verification_email(string $email, string $code): bool {
    $subject = 'Ověření rezervace UMT';
    $body = "Váš ověřovací kód: {$code}\n\nPlatnost: 10 minut. Pokud jste žádost nevytvářeli, tento e-mail ignorujte.";
    return mailer_send($email, $subject, $body);
}

function send_manage_links_email(string $email, string $range, string $spaceLabel, string $name, string $bookingLink, string $emailLink): bool {
    $subject = 'Správa rezervace UMT';
    $displayName = $name !== '' ? $name : '-';
    $body = "Rezervace UMT potvrzena.\n\n";
    $body .= "Termín: {$range}\n";
    $body .= "Prostor: {$spaceLabel}\n";
    $body .= "Jméno/tým: {$displayName}\n\n";
    $body .= "Správa této rezervace:\n{$bookingLink}\n\n";
    $body .= "Správa všech rezervací na tento e-mail:\n{$emailLink}\n\n";
    $body .= "Pokud jste rezervaci nevytvářeli, kontaktujte správce.";
    return mailer_send($email, $subject, $body);
}