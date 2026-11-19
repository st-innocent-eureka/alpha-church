<?php
/**
 * Contact form mail handler for the Alpha Church Hugo theme.
 *
 * Sends the contact form through your organisation's Google Workspace
 * account using PHPMailer. Three authentication modes are supported
 * (set $smtp_mode below):
 *
 *   'relay'        Google Workspace SMTP relay (smtp-relay.gmail.com).
 *                  Authenticates by the server's IP address; no credentials
 *                  are stored in code. A Workspace admin must allow the
 *                  server's IP under Admin console > Apps > Google Workspace
 *                  > Gmail > Routing > SMTP relay service. The server needs
 *                  a static IP. Google's recommended option for websites.
 *
 *   'app_password' smtp.gmail.com with a Google app password. The account
 *                  needs 2-Step Verification enabled, and the Workspace
 *                  admin must allow app passwords. Note: app passwords are
 *                  revoked whenever the account password changes.
 *
 *   'oauth2'       smtp.gmail.com with OAuth2 (XOAUTH2). Requires a Google
 *                  Cloud project and a refresh token for the sending
 *                  account. See SETUP.md ("Contact Form") and the one-time
 *                  helper script contact_oauth_setup.php in exampleSite.
 *
 * Requirements (install with Composer, ideally OUTSIDE the web root):
 *   composer require phpmailer/phpmailer league/oauth2-google
 * (league/oauth2-google is only used in 'oauth2' mode.)
 *
 * SECURITY: keep credentials out of the web root. Put them in a separate
 * config file outside the published site (see $config_file below) rather
 * than editing them into this file, and never commit them to git.
 */

# ---- Basic settings --------------------------------------------------------

# Recipient of the mail:
$to = 'admin@example.org';
# The "From" address. Must be the authenticated Workspace account or a
# verified "send as" alias of it, otherwise Gmail rewrites or rejects it:
$from = 'website@example.org';
$from_name = 'Website contact form';
# Subject of the mail:
$subject = 'Feedback from example.org';
# Forward users to this page when the mail is sent successfully:
$confirm_page = '/contact_thanks';
# If sending fails, forward users to:
$error_page = '/404.html';

# ---- SMTP authentication ---------------------------------------------------

# One of 'relay', 'app_password' or 'oauth2':
$smtp_mode = 'relay';

# For 'app_password' mode:
$smtp_user = 'website@example.org';        # the Workspace account
$smtp_app_password = 'INSERT APP PASSWORD'; # 16-character app password

# For 'oauth2' mode (see contact_oauth_setup.php to obtain the refresh token):
$oauth_client_id = 'INSERT CLIENT ID';
$oauth_client_secret = 'INSERT CLIENT SECRET';
$oauth_refresh_token = 'INSERT REFRESH TOKEN';
# The Workspace account the refresh token was issued for:
$oauth_user = 'website@example.org';

# Path to Composer's autoloader (adjust to your server layout; keeping the
# vendor directory outside the web root is recommended):
$autoload_path = __DIR__ . '/vendor/autoload.php';

# Optional: load settings from a file OUTSIDE the web root. Any variable set
# there overrides the defaults above. Recommended place for credentials:
$config_file = __DIR__ . '/../contact_mail_config.php';
if (is_file($config_file)) {
    require $config_file;
}

# ---- Form handling ---------------------------------------------------------

function fail($page) {
    header('Location: ' . $page);
    exit;
}

if (!isset($_POST['send'])) {
    fail($error_page);
}

$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$message = trim($_POST['message'] ?? '');

# Strip line breaks from the name and validate the address, so neither can
# be used to inject extra mail headers via Reply-To. Cap input lengths.
$name = str_replace(["\r", "\n"], '', $name);
if ($name === '' || mb_strlen($name) > 200 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 ||
    $message === '' || mb_strlen($message) > 10000) {
    fail($error_page);
}

$body = "Name: " . $name . "\n\n"
      . "Email: " . $email . "\n\n"
      . "Message:\n" . $message . "\n";

# ---- Send ------------------------------------------------------------------

try {
    if (!is_file($autoload_path)) {
        throw new RuntimeException('Composer autoloader not found at ' . $autoload_path
            . ' - run: composer require phpmailer/phpmailer league/oauth2-google');
    }
    require $autoload_path;

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->CharSet = PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
    $mail->Host = 'smtp.gmail.com';
    $mail->Port = 587;
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Timeout = 30;

    switch ($smtp_mode) {
        case 'relay':
            $mail->Host = 'smtp-relay.gmail.com';
            $mail->SMTPAuth = false;
            # The relay service expects a fully qualified domain name in the
            # SMTP HELO; use the sender's domain.
            $mail->Helo = substr(strrchr($from, '@'), 1);
            break;

        case 'app_password':
            $mail->SMTPAuth = true;
            $mail->Username = $smtp_user;
            $mail->Password = $smtp_app_password;
            break;

        case 'oauth2':
            $provider = new League\OAuth2\Client\Provider\Google([
                'clientId' => $oauth_client_id,
                'clientSecret' => $oauth_client_secret,
            ]);
            $mail->SMTPAuth = true;
            $mail->AuthType = 'XOAUTH2';
            # PHPMailer fetches and refreshes access tokens as needed:
            $mail->setOAuth(new PHPMailer\PHPMailer\OAuth([
                'provider' => $provider,
                'clientId' => $oauth_client_id,
                'clientSecret' => $oauth_client_secret,
                'refreshToken' => $oauth_refresh_token,
                'userName' => $oauth_user,
            ]));
            break;

        default:
            throw new RuntimeException('Unknown $smtp_mode: ' . $smtp_mode);
    }

    $mail->setFrom($from, $from_name);
    $mail->addAddress($to);
    # Replies go to the visitor, not to the website account:
    $mail->addReplyTo($email, $name);
    $mail->Subject = $subject;
    $mail->Body = $body;

    $mail->send();
    header('Location: ' . $confirm_page);
    exit;
} catch (Throwable $e) {
    error_log('contact_mail.php: ' . $e->getMessage());
    fail($error_page);
}
