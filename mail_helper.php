<?php
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Keep the last SMTP error available to callers while logging the real detail.
 */
function getLastMailError(): string {
    return $GLOBALS['SMART_ROOM_LAST_MAIL_ERROR'] ?? '';
}

/**
 * Send one email through Gmail SMTP. All project email helpers must use this.
 */
function sendSmartRoomEmail(string $toEmail, string $toName, string $subject, string $body, string $altBody = '', bool $isHtml = false): bool {
    $config = require __DIR__ . '/mail_config.php';
    $GLOBALS['SMART_ROOM_LAST_MAIL_ERROR'] = '';

    try {
        // Fail fast when Gmail SMTP credentials have not been configured yet.
        $required = ['host', 'port', 'username', 'password', 'encryption', 'sender_name', 'sender_email'];
        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new Exception("Missing mail_config.php value: {$key}");
            }
        }
        if ($config['username'] === 'your-gmail-address@gmail.com' || $config['password'] === 'your-16-character-app-password') {
            throw new Exception('Gmail SMTP is not configured. Update mail_config.php with your Gmail address and App Password.');
        }

        $mail = new PHPMailer(true);
        $mail->SMTPDebug = 3;
$mail->Debugoutput = function ($str, $level) {
    error_log("[SMTP] $str");
};

$mail->SMTPDebug = 2;
$mail->Debugoutput = function($str, $level) {
    error_log("[SMTP DEBUG] $str");
};
        $mail->isSMTP();
        $mail->Host = $config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['username'];
        $mail->Password = $config['password'];
        $mail->Port = (int)$config['port'];
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 20;

        if (($config['encryption'] ?? 'tls') === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif (($config['encryption'] ?? '') === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }

        // XAMPP localhost setups commonly lack an up-to-date CA bundle.
        // Keep this local-only compatibility setting so Gmail TLS can connect.
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];

        $mail->setFrom($config['sender_email'], $config['sender_name']);
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->isHTML($isHtml);
        if ($isHtml) {
            if ($altBody !== '') $mail->AltBody = $altBody;
        } else {
            $mail->AltBody = $altBody !== '' ? $altBody : $body;
        }

        return $mail->send();
    } catch (Exception $e) {
        $error = $e->getMessage();
        $GLOBALS['SMART_ROOM_LAST_MAIL_ERROR'] = $error;
        error_log('[Smart Room SMTP] Unable to send email to ' . $toEmail . ': ' . $error);
        return false;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $GLOBALS['SMART_ROOM_LAST_MAIL_ERROR'] = $error;
        error_log('[Smart Room SMTP] Mail system error for ' . $toEmail . ': ' . $error);
        return false;
    }
}

/**
 * Send a six-digit login or verification OTP.
 */
function sendSmartRoomOtpEmail(string $toEmail, string $toName, string $otp): bool {
    $subject = 'Smart Room - Your Email Verification Code';
    $body = "Dear {$toName},\r\n\r\n"
          . "Your one-time verification code is:\r\n\r\n"
          . "  {$otp}\r\n\r\n"
          . "This code expires in 5 minutes.\r\n\r\n"
          . "If you did not request this, please contact your administrator.\r\n\r\n"
          . "- Smart Room System";

    return sendSmartRoomEmail($toEmail, $toName, $subject, $body);
}

/**
 * Email a newly approved faculty member their temporary login details.
 */
function sendFacultyTemporaryPasswordEmail(string $toEmail, string $toName, string $username, string $tempPass): bool {
    $loginUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
              . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/index.php';
    $subject = 'Faculty Account Created - Smart Room System';
    $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
    $safeUsername = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $safePassword = htmlspecialchars($tempPass, ENT_QUOTES, 'UTF-8');
    $safeLoginUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');

    $body = '
<!DOCTYPE html>
<html>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Segoe UI,Arial,sans-serif;color:#1f2937;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6f9;padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="620" cellspacing="0" cellpadding="0" style="max-width:620px;width:100%;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
          <tr>
            <td style="background:#0d1b2a;color:#ffffff;padding:22px 28px;">
              <h1 style="margin:0;font-size:22px;line-height:1.3;">Smart Room Faculty Account Approved</h1>
              <p style="margin:6px 0 0;color:#cbd5e1;font-size:14px;">Your account is ready for first-time verification.</p>
            </td>
          </tr>
          <tr>
            <td style="padding:28px;">
              <p style="margin:0 0 16px;">Hello <strong>' . $safeName . '</strong>,</p>
              <p style="margin:0 0 20px;line-height:1.6;">Your faculty registration request has been approved. Use the temporary credentials below to sign in.</p>

              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:18px 0;border:1px solid #dbe3ef;">
                <tr>
                  <td style="padding:12px 14px;background:#f8fafc;border-bottom:1px solid #dbe3ef;font-weight:600;width:38%;">Faculty Name</td>
                  <td style="padding:12px 14px;border-bottom:1px solid #dbe3ef;">' . $safeName . '</td>
                </tr>
                <tr>
                  <td style="padding:12px 14px;background:#f8fafc;border-bottom:1px solid #dbe3ef;font-weight:600;">Username</td>
                  <td style="padding:12px 14px;border-bottom:1px solid #dbe3ef;">' . $safeUsername . '</td>
                </tr>
                <tr>
                  <td style="padding:12px 14px;background:#f8fafc;border-bottom:1px solid #dbe3ef;font-weight:600;">Temporary Password</td>
                  <td style="padding:12px 14px;border-bottom:1px solid #dbe3ef;"><strong>' . $safePassword . '</strong></td>
                </tr>
                <tr>
                  <td style="padding:12px 14px;background:#f8fafc;font-weight:600;">Login URL</td>
                  <td style="padding:12px 14px;"><a href="' . $safeLoginUrl . '" style="color:#1a6bcc;">' . $safeLoginUrl . '</a></td>
                </tr>
              </table>

              <div style="background:#eef6ff;border-left:4px solid #1a6bcc;padding:16px 18px;margin:22px 0;">
                <p style="margin:0 0 10px;font-weight:700;">First login instructions</p>
                <ol style="margin:0;padding-left:20px;line-height:1.7;">
                  <li>Log in using the temporary password.</li>
                  <li>Complete Face Verification.</li>
                  <li>Complete Email OTP verification.</li>
                  <li>Change the temporary password.</li>
                  <li>Future logins from this same trusted device will only require Username and Password.</li>
                </ol>
              </div>

              <p style="margin:20px 0 0;color:#64748b;font-size:13px;line-height:1.6;">If you did not expect this email, please contact the system administrator.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';

    return sendSmartRoomEmail($toEmail, $toName, $subject, $body, '', true);
}

/**
 * Email a password reset link that expires after 15 minutes.
 */
function sendPasswordResetEmail(string $toEmail, string $toName, string $resetLink): bool {
    $subject = 'Password Reset Link - Smart Room System';
    $body = "Dear {$toName},\r\n\r\n"
          . "Use the link below to reset your Smart Room password:\r\n\r\n"
          . "{$resetLink}\r\n\r\n"
          . "This link expires in 15 minutes.\r\n\r\n"
          . "If you did not request this, please ignore this email or contact the administrator.\r\n\r\n"
          . "- Smart Room System";

    return sendSmartRoomEmail($toEmail, $toName, $subject, $body);
}

/**
 * Email faculty registration decisions through the same SMTP pipeline.
 */
function sendRegistrationRejectionEmail(string $toEmail, string $toName, string $reason = ''): bool {
    $subject = 'Faculty Registration Request - Update';
    $body = "Dear {$toName},\r\n\r\n"
          . "We regret to inform you that your faculty registration request has been reviewed and could not be approved at this time.\r\n"
          . ($reason ? "\r\nReason: {$reason}\r\n" : '')
          . "\r\nIf you believe this is an error, please contact the system administrator.\r\n\r\n"
          . "- Smart Room System";

    return sendSmartRoomEmail($toEmail, $toName, $subject, $body);
}
