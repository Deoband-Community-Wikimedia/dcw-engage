<?php
// includes/mailer.php

// Note: Ensure `composer install` has been run for PHPMailer.
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

class Mailer {
    /**
     * Sends a Magic Link to the applicant.
     * Uses plain text for now. Will be swapped with Gauri's HTML templates later.
     */
    public static function sendMagicLink($email, $applicantName, $token) {
        $config = require __DIR__ . '/config.php';
        $mailConfig = $config['mail'];
        
        // If PHPMailer is not installed (dev environment), just log and return true
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            error_log("DEV MODE: Magic link token generated for $email: $token");
            return true;
        }

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $mailConfig['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $mailConfig['user'];
            $mail->Password   = $mailConfig['pass'];
            $mail->Port       = $mailConfig['port'];
            // Encryption: port 465 uses implicit SSL, otherwise STARTTLS (e.g. 587).
            // Set 'secure' in the mail config to override.
            $secure = $mailConfig['secure'] ?? ((int)$mailConfig['port'] === 465 ? 'ssl' : 'tls');
            if (!empty($secure)) {
                $mail->SMTPSecure = $secure;
            }

            $mail->setFrom($mailConfig['user'], 'DCW Engage');
            $mail->addAddress($email, $applicantName);
            
            $mail->isHTML(true);
            $mail->Subject = 'Your DCW Application - Magic Link';
            
            // Generate link based on site URL
            $appUrl = $config['app']['url'] . '/resume/' . $token;
            
            $htmlBody = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #f4f6f8; margin: 0; padding: 0; color: #1e293b; }
                    .email-container { max-width: 600px; margin: 40px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
                    .header { background-color: #106b9a; padding: 30px 20px; text-align: center; color: #ffffff; }
                    .header h1 { margin: 0; font-size: 24px; font-weight: 600; letter-spacing: -0.5px; }
                    .body-content { padding: 40px 30px; }
                    .body-content p { font-size: 16px; line-height: 1.6; margin-bottom: 20px; }
                    .btn-wrapper { text-align: center; margin: 30px 0; }
                    .btn { display: inline-block; background-color: #106b9a; color: #ffffff !important; text-decoration: none; padding: 14px 28px; border-radius: 6px; font-size: 16px; font-weight: 600; }
                    .footer { background-color: #f8fafc; padding: 20px; text-align: center; font-size: 13px; color: #64748b; border-top: 1px solid #e2e8f0; }
                </style>
            </head>
            <body>
                <div class='email-container'>
                    <div class='header'>
                        <h1>DCW Engage</h1>
                    </div>
                    <div class='body-content'>
                        <p>Hello <strong>$applicantName</strong>,</p>
                        <p>Your application has been successfully saved to our system. We have generated a secure magic link for you so that you can return and edit your application at any time before the deadline.</p>
                        <div class='btn-wrapper'>
                            <a href='$appUrl' class='btn'>Access My Application</a>
                        </div>
                        <p>If the button doesn't work, you can copy and paste this link into your browser:<br><br><a href='$appUrl' style='color: #106b9a; word-break: break-all;'>$appUrl</a></p>
                        <p style='margin-bottom:0;'>For security reasons, this link will expire automatically. Please do not share this link with anyone.</p>
                    </div>
                    <div class='footer'>
                        &copy; " . date('Y') . " Deoband Community Wikimedia. All rights reserved.<br>
                        This is an automated message, please do not reply.
                    </div>
                </div>
            </body>
            </html>
            ";

            $mail->Body    = $htmlBody;
            $mail->AltBody = "Hello $applicantName,\n\nYour application was successfully saved! Here is your magic link to access it:\n$appUrl\n\nThis link will expire soon for security reasons.\n\nBest,\nDeoband Community Wikimedia";
            
            // Prevent actual sending if using dummy dev credentials
            if ($mailConfig['host'] !== 'smtp.example.com') {
                $mail->send();
            }
            return true;
        } catch (Exception $e) {
            error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
            return false;
        }
    }
    /**
     * Sends an alert email to the organizer(s) in charge of a form
     * whenever a new application is submitted.
     * Supports multiple recipients via a comma-separated notify_emails column.
     */
    public static function sendOrganizerAlert($form, $applicantEmail, $applicantName, $appId) {
        $config = require __DIR__ . '/config.php';
        $mailConfig = $config['mail'];

        $notifyEmails = trim($form['notify_emails'] ?? '');
        if (empty($notifyEmails)) {
            // No organizer configured for this form yet — nothing to notify.
            return true;
        }

        $recipients = array_filter(array_map('trim', explode(',', $notifyEmails)));
        if (empty($recipients)) {
            return true;
        }

        // If PHPMailer is not installed (dev environment), just log and return true
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            error_log("DEV MODE: Organizer alert for form '{$form['form_type']}' (App #$appId) would be sent to: " . implode(', ', $recipients));
            return true;
        }

        $trackingId = 'DCW-' . str_pad($appId, 5, '0', STR_PAD_LEFT);
        $formTitle = $form['title'] ?? $form['form_type'];
        $manageUrl = $config['app']['url'] . '/admin/form_manager?id=' . $form['id'];

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $mailConfig['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $mailConfig['user'];
            $mail->Password   = $mailConfig['pass'];
            $mail->Port       = $mailConfig['port'];
            // Encryption: port 465 uses implicit SSL, otherwise STARTTLS (e.g. 587).
            // Set 'secure' in the mail config to override.
            $secure = $mailConfig['secure'] ?? ((int)$mailConfig['port'] === 465 ? 'ssl' : 'tls');
            if (!empty($secure)) {
                $mail->SMTPSecure = $secure;
            }

            $mail->setFrom($mailConfig['user'], 'DCW Engage');
            foreach ($recipients as $recipientEmail) {
                $mail->addAddress($recipientEmail);
            }

            $mail->isHTML(true);
            $mail->Subject = "New Application: $formTitle ($trackingId)";

            $htmlBody = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #f4f6f8; margin: 0; padding: 0; color: #1e293b; }
                    .email-container { max-width: 600px; margin: 40px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
                    .header { background-color: #106b9a; padding: 30px 20px; text-align: center; color: #ffffff; }
                    .header h1 { margin: 0; font-size: 24px; font-weight: 600; letter-spacing: -0.5px; }
                    .body-content { padding: 40px 30px; }
                    .body-content p { font-size: 16px; line-height: 1.6; margin-bottom: 20px; }
                    .btn-wrapper { text-align: center; margin: 30px 0; }
                    .btn { display: inline-block; background-color: #106b9a; color: #ffffff !important; text-decoration: none; padding: 14px 28px; border-radius: 6px; font-size: 16px; font-weight: 600; }
                    .footer { background-color: #f8fafc; padding: 20px; text-align: center; font-size: 13px; color: #64748b; border-top: 1px solid #e2e8f0; }
                </style>
            </head>
            <body>
                <div class='email-container'>
                    <div class='header'>
                        <h1>DCW Engage</h1>
                    </div>
                    <div class='body-content'>
                        <p>Hello,</p>
                        <p>A new application has been submitted for <strong>$formTitle</strong>.</p>
                        <p><strong>Tracking ID:</strong> $trackingId<br>
                        <strong>Applicant:</strong> " . htmlspecialchars($applicantName) . "<br>
                        <strong>Email:</strong> " . htmlspecialchars($applicantEmail) . "</p>
                        <div class='btn-wrapper'>
                            <a href='$manageUrl' class='btn'>Review Application</a>
                        </div>
                    </div>
                    <div class='footer'>
                        &copy; " . date('Y') . " Deoband Community Wikimedia. All rights reserved.<br>
                        This is an automated message, please do not reply.
                    </div>
                </div>
            </body>
            </html>
            ";

            $mail->Body    = $htmlBody;
            $mail->AltBody = "New application submitted for $formTitle.\nTracking ID: $trackingId\nApplicant: $applicantName ($applicantEmail)\n\nReview it here: $manageUrl";

            if ($mailConfig['host'] !== 'smtp.example.com') {
                $mail->send();
            }
            return true;
        } catch (Exception $e) {
            error_log("Organizer alert could not be sent. Mailer Error: {$mail->ErrorInfo}");
            return false;
        }
    }
}
