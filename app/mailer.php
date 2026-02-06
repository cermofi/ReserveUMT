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
            return false;
        }
    }

    if (!empty($smtp['from_email'])) {
        $headers = "From: " . $smtp['from_name'] . " <" . $smtp['from_email'] . ">\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        return @mail($to, $subject, $body, $headers);
    }

    return false;
}

function send_verification_email(string $email, string $code): bool {
    $subject = 'OvÄ›Ĺ™enĂ­ rezervace UMT';
    $body = "VĂˇĹˇ ovÄ›Ĺ™ovacĂ­ kĂłd: {$code}\n\nPlatnost: 10 minut. Pokud jste ĹľĂˇdost nevytvĂˇĹ™eli, tento e-mail ignorujte.";
    return mailer_send($email, $subject, $body);
}
