<?php
// send_mail.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Подключаем файлы библиотеки вручную
require __DIR__ . '/libs/PHPMailer/src/Exception.php';
require __DIR__ . '/libs/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/libs/PHPMailer/src/SMTP.php';

/**
 * Функция отправки почты через Gmail SMTP
 */
function sendEmail(string $to, string $subject, string $bodyHtml): array {
    // === НАСТРОЙКИ GMAIL ===
    $smtpUsername = 'rusminecraft74@gmail.com'; // Твой логин Gmail
    $smtpPassword = 'ngfr hlpz jbsa idfe';  // Тот самый 16-значный пароль приложения (без пробелов)
    $fromName     = 'Fair of Contradictions'; // Имя отправителя
    // =======================

    $mail = new PHPMailer(true);

    try {
        // Настройки сервера
        $mail->isSMTP();                                            
        $mail->Host       = 'smtp.gmail.com';                     
        $mail->SMTPAuth   = true;                                   
        $mail->Username   = $smtpUsername;                     
        $mail->Password   = $smtpPassword;                               
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // Или ENCRYPTION_SMTPS (для 465 порта)
        $mail->Port       = 587;                            // 587 для TLS, 465 для SSL
        $mail->CharSet    = 'UTF-8';

        // От кого и кому
        $mail->setFrom($smtpUsername, $fromName);
        $mail->addAddress($to);     

        // Контент
        $mail->isHTML(true);                                  
        $mail->Subject = $subject;
        $mail->Body    = $bodyHtml;
        // Версия для текстовых клиентов (strip_tags убирает html теги)
        $mail->AltBody = strip_tags($bodyHtml); 

        $mail->send();
        return ['success' => true, 'message' => 'Письмо отправлено'];
    } catch (Exception $e) {
        // Логируем ошибку или возвращаем её
        return ['success' => false, 'message' => "Ошибка отправки: {$mail->ErrorInfo}"];
    }
}