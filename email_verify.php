<?php
/**
 * email_verify.php — Email address change verification handler
 *
 * This page handles the verification link that is emailed to a user's NEW
 * email address when they request an email change on the Account page.
 *
 * Flow:
 *   1. User submits new email on account.php
 *   2. A token is stored in portal_email_tokens and a link is sent to the new address
 *   3. User clicks the link — this page validates the token
 *   4. On valid token: ROBUST UpdateUserAccount is called to set the new email,
 *      a notification is sent to the OLD address, the token is deleted
 *   5. On invalid/expired token: an error is shown
 *
 * This page does NOT require the user to be logged in — the token itself
 * is the authentication proof. This allows verification from a different
 * browser or device than where the change was requested.
 *
 * Token format:  64 hex characters (32 random bytes via random_bytes)
 * Token expiry:  24 hours from creation
 *
 * Database table required (portal-owned):
 *
 *   CREATE TABLE `portal_email_tokens` (
 *     `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
 *     `uuid`        CHAR(36)     NOT NULL,
 *     `old_email`   VARCHAR(254) NOT NULL,
 *     `new_email`   VARCHAR(254) NOT NULL,
 *     `token`       CHAR(64)     NOT NULL,
 *     `expires_at`  DATETIME     NOT NULL,
 *     `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     PRIMARY KEY (`id`),
 *     UNIQUE KEY `token` (`token`),
 *     KEY `uuid` (`uuid`),
 *     KEY `expires_at` (`expires_at`)
 *   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
 *
 * DB permissions: the web portal user needs INSERT, UPDATE, DELETE on this table.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/robust_api.php';
require_once __DIR__ . '/includes/helpers.php';

// Email sending is only loaded if enabled — avoids PHP errors if
// PHPMailer config is incomplete when email is disabled.
if (EMAIL_ENABLED) {
    require_once __DIR__ . '/includes/mailer.php';
}

// ─── Validate token from query string ────────────────────────────────────────

$raw_token = trim($_GET['token'] ?? '');
$result    = 'invalid';  // 'success' | 'expired' | 'invalid' | 'error'
$new_email = '';

if (preg_match('/^[0-9a-f]{64}$/', $raw_token)) {

    try {
        $db = get_db();

        // Look up the token — fetch whether expired too so we can give a better message
        $stmt = $db->prepare("
            SELECT id, uuid, old_email, new_email, expires_at,
                   NOW() > expires_at AS is_expired
            FROM portal_email_tokens
            WHERE token = :token
            LIMIT 1
        ");
        $stmt->execute([':token' => $raw_token]);
        $row = $stmt->fetch();

        if (!$row) {
            $result = 'invalid';  // Token doesn't exist (or was already used)

        } elseif ((int)$row['is_expired'] === 1) {
            // Token exists but has expired — clean it up
            $db->prepare("DELETE FROM portal_email_tokens WHERE id = :id")
               ->execute([':id' => $row['id']]);
            $result = 'expired';

        } else {
            // Valid, unexpired token — apply the change
            $uuid      = $row['uuid'];
            $old_email = $row['old_email'];
            $new_email = $row['new_email'];

            // We need firstname/lastname for the ROBUST call
            $ua = $db->prepare("
                SELECT FirstName, LastName FROM UserAccounts
                WHERE PrincipalID = :uuid LIMIT 1
            ");
            $ua->execute([':uuid' => $uuid]);
            $account = $ua->fetch();

            if (!$account) {
                error_log("email_verify.php: UserAccounts row missing for UUID {$uuid}");
                $result = 'error';
            } else {
                $api_result = robust_set_user_email(
                    $uuid,
                    $new_email
                );

                if (!$api_result['success']) {
                    error_log("email_verify.php: ROBUST call failed for UUID {$uuid}: " .
                              ($api_result['error'] ?? 'unknown'));
                    $result = 'error';
                } else {
                    // ROBUST update succeeded — delete the token (one-time use)
                    $db->prepare("DELETE FROM portal_email_tokens WHERE id = :id")
                       ->execute([':id' => $row['id']]);

                    // Notify the OLD address that the email was changed
                    if (EMAIL_ENABLED && $old_email !== '') {
                        send_notification_to_old_email(
                            $old_email,
                            $new_email,
                            $account['FirstName'] . ' ' . $account['LastName']
                        );
                    }

                    $result = 'success';
                }
            }
        }

    } catch (PDOException $e) {
        error_log('email_verify.php: DB error: ' . $e->getMessage());
        $result = 'error';
    }
}

// ─── Send old-address notification ───────────────────────────────────────────

/**
 * Sends a "your email was changed" security notice to the OLD address.
 * Called only after ROBUST has successfully applied the change.
 */
function send_notification_to_old_email(string $old_email, string $new_email, string $display_name): void
{
    $grid     = htmlspecialchars(GRID_NAME,  ENT_QUOTES, 'UTF-8');
    $safe_new = htmlspecialchars($new_email, ENT_QUOTES, 'UTF-8');
    $safe_name = htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8');
    $portal   = htmlspecialchars(PORTAL_BASE_URL, ENT_QUOTES, 'UTF-8');

    $html = build_email_html("
        <p>Hello {$safe_name},</p>
        <p>This is a security notice to let you know that the email address
           on your <strong>{$grid}</strong> account has been changed.</p>
        <p><strong>New email address:</strong> {$safe_new}</p>
        <p>If you made this change yourself, no action is needed.</p>
        <p>If you did <strong>not</strong> make this change, please contact
           a grid administrator as soon as possible via the portal:<br>
           <a href=\"{$portal}\" style=\"color:#6b46c1;\">{$portal}</a></p>
    ");

    $text = "Hello {$display_name},\n\n"
          . "This is a security notice. The email address on your {$grid} account "
          . "has been changed to: {$new_email}\n\n"
          . "If you did not make this change, please contact a grid administrator via {$portal}\n";

    send_mail(
        to:        $old_email,
        subject:   '[' . GRID_NAME . '] Your email address has been changed',
        body_html: $html,
        body_text: $text,
        to_name:   $display_name
    );
}

// ─── Page output ──────────────────────────────────────────────────────────────
// No session required — this page is designed to be opened from an email link.

$bg_enabled = ($_COOKIE['portal_bg'] ?? '1') !== '0';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification — <?= htmlspecialchars(GRID_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;1,400&family=Inter:wght@300;400;500;600&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">

    <?php render_shared_css(); ?>

    <style>
        .page-wrap {
            position: relative;
            z-index: 1;
            max-width: 560px;
            margin: 60px auto;
            padding: 0 20px;
        }
        .verify-card {
            background: var(--clr-surface);
            border: 1px solid var(--clr-border-light);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-card);
            padding: 40px 36px;
            text-align: center;
        }
        .verify-icon {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        .icon-success { background: #e6f5ee; color: #2e7d52; }
        .icon-error   { background: var(--clr-rose-soft); color: #a3243d; }
        .icon-warning { background: #fef3e2; color: #9a6200; }

        .verify-heading {
            font-family: var(--font-display);
            font-size: 1.5rem;
            font-weight: 400;
            color: var(--clr-text-primary);
            margin-bottom: 12px;
        }
        .verify-body {
            font-size: 0.9rem;
            color: var(--clr-text-secondary);
            line-height: 1.65;
            margin-bottom: 24px;
        }
        .verify-body strong {
            color: var(--clr-text-primary);
        }
        .btn-primary {
            display: inline-block;
            font-family: var(--font-body);
            font-size: 0.875rem;
            font-weight: 500;
            color: #fff;
            background: var(--clr-lilac-deep);
            border: none;
            border-radius: var(--radius-md);
            padding: 10px 24px;
            text-decoration: none;
            transition: background 0.15s;
        }
        .btn-primary:hover { background: var(--clr-lilac-text); }
    </style>
</head>
<body<?= $bg_enabled ? '' : ' class="no-bg"' ?>>

<?php render_bg_layer(); ?>

<main class="page-wrap" id="main-content">
    <div class="verify-card">

        <?php if ($result === 'success'): ?>

            <div class="verify-icon icon-success">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            </div>
            <h1 class="verify-heading">Email address confirmed</h1>
            <p class="verify-body">
                Your email address has been updated to
                <strong><?= htmlspecialchars($new_email) ?></strong>.<br>
                A notification has been sent to your previous email address.
            </p>
            <a href="account.php" class="btn-primary">Go to My Account</a>

        <?php elseif ($result === 'expired'): ?>

            <div class="verify-icon icon-warning">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
            </div>
            <h1 class="verify-heading">Link has expired</h1>
            <p class="verify-body">
                This verification link has expired. Links are valid for 24 hours.<br>
                Please return to your account and request the email change again.
            </p>
            <a href="account.php" class="btn-primary">Go to My Account</a>

        <?php elseif ($result === 'error'): ?>

            <div class="verify-icon icon-error">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="15" y1="9" x2="9" y2="15"/>
                    <line x1="9" y1="9" x2="15" y2="15"/>
                </svg>
            </div>
            <h1 class="verify-heading">Something went wrong</h1>
            <p class="verify-body">
                The grid service was unable to apply this change right now.<br>
                Please try again in a few minutes. If the problem continues,
                contact a grid administrator.
            </p>
            <a href="account.php" class="btn-primary">Go to My Account</a>

        <?php else: /* invalid */ ?>

            <div class="verify-icon icon-error">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="15" y1="9" x2="9" y2="15"/>
                    <line x1="9" y1="9" x2="15" y2="15"/>
                </svg>
            </div>
            <h1 class="verify-heading">Invalid link</h1>
            <p class="verify-body">
                This verification link is not valid or has already been used.<br>
                If you need to change your email address, please submit
                the request again from your account page.
            </p>
            <a href="account.php" class="btn-primary">Go to My Account</a>

        <?php endif; ?>

    </div>
</main>

<?php render_shared_js(); ?>

</body>
</html>
