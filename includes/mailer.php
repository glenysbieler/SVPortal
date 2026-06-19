<?php
/**
 * mailer.php — Portal email sending wrapper
 *
 * A thin layer over PHPMailer that reads the portal's email configuration
 * and provides a single send_mail() function for the rest of the codebase.
 *
 * Usage:
 *   $ok = send_mail(
 *       to:           'user@example.com',
 *       subject:      'Your email address was changed',
 *       body_html:    '<p>Hello...</p>',
 *       body_text:    'Hello...'
 *   );
 *
 * Returns true on success, false on failure (errors are written to the PHP
 * error log — never shown to the user).
 *
 * This file should only be included when EMAIL_ENABLED is true. Callers
 * should check EMAIL_ENABLED before calling send_mail().
 *
 * PHPMailer is bundled in includes/phpmailer/ — no Composer required.
 * Source: https://github.com/PHPMailer/PHPMailer (MIT licence)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Sends an email via the transport configured in config.php.
 *
 * @param  string      $to         Recipient email address
 * @param  string      $subject    Email subject line
 * @param  string      $body_html  HTML version of the email body
 * @param  string      $body_text  Plain-text version of the email body (fallback)
 * @param  string|null $to_name    Optional recipient display name
 * @return bool  true on success, false on failure
 */
function send_mail(
    string $to,
    string $subject,
    string $body_html,
    string $body_text,
    ?string $to_name = null
): bool {
    try {
        $mail = new PHPMailer(true);  // true = throw exceptions

        // ── Transport ─────────────────────────────────────────────────────────
        if (EMAIL_TRANSPORT === 'smtp') {
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->Port       = SMTP_PORT;
            $mail->SMTPAuth   = (SMTP_USERNAME !== '');
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;

            switch (SMTP_ENCRYPTION) {
                case 'ssl':
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    break;
                case 'tls':
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    break;
                default:
                    $mail->SMTPSecure = '';
                    $mail->SMTPAutoTLS = false;
                    break;
            }
        } else {
            // 'php' transport — uses the server's local MTA via mail()
            $mail->isMail();
        }

        // ── From ──────────────────────────────────────────────────────────────
        $mail->setFrom(EMAIL_FROM_ADDRESS, EMAIL_FROM_NAME);
        $mail->addReplyTo(EMAIL_FROM_ADDRESS, EMAIL_FROM_NAME);

        // ── To ────────────────────────────────────────────────────────────────
        $mail->addAddress($to, $to_name ?? '');

        // ── Content ───────────────────────────────────────────────────────────
        $mail->isHTML(true);
        $mail->CharSet  = 'UTF-8';
        $mail->Subject  = $subject;
        $mail->Body     = $body_html;
        $mail->AltBody  = $body_text;

        $mail->send();
        return true;

    } catch (PHPMailerException $e) {
        error_log('send_mail() failed to ' . $to . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Builds a simple, consistently styled HTML email body for the portal.
 *
 * All outgoing emails use the same basic template so they look consistent
 * and are clearly from the grid. Caller provides the inner content as HTML.
 *
 * @param  string $content_html  The inner HTML content (paragraphs, links etc.)
 * @return string                A complete HTML email document
 */
function build_email_html(string $content_html): string
{
    $grid_name = htmlspecialchars(GRID_NAME, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$grid_name}</title>
</head>
<body style="margin:0;padding:0;background:#f5f4f8;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f4f8;padding:32px 16px;">
    <tr><td align="center">
      <table width="560" cellpadding="0" cellspacing="0"
             style="background:#ffffff;border-radius:10px;overflow:hidden;
                    box-shadow:0 2px 8px rgba(0,0,0,0.08);max-width:560px;width:100%;">

        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,#6b46c1 0%,#8b68c4 100%);
                     padding:24px 32px;text-align:center;">
            <span style="font-family:Georgia,serif;font-size:22px;
                         font-weight:normal;color:#ffffff;letter-spacing:0.02em;">
              {$grid_name}
            </span>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:32px;color:#374151;font-size:15px;line-height:1.6;">
            {$content_html}
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#f9f8fc;padding:18px 32px;
                     border-top:1px solid #ede9f6;text-align:center;
                     font-size:12px;color:#9ca3af;">
            This is an automated message from {$grid_name}.<br>
            Please do not reply to this email.
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}
