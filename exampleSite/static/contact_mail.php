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
 * Captcha: set $captcha_service = 'altcha' to require a solved ALTCHA
 * proof-of-work challenge (self-hosted, no external service). This needs
 *   composer require altcha-org/altcha
 * and the altcha_challenge.php endpoint published alongside this script,
 * with matching $altcha_hmac_secret / $altcha_key_secret secrets.
 * A hidden honeypot field is always checked as a baseline.
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

# ---- Captcha ---------------------------------------------------------------

# '' (disabled) or 'altcha' (self-hosted ALTCHA proof-of-work captcha):
$captcha_service = '';
# Secrets shared with altcha_challenge.php. MUST be long random strings,
# set in the external config file (see $config_file below):
$altcha_hmac_secret = '';
$altcha_key_secret = '';

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

/**
 * Single-use guard for ALTCHA payloads: remember solved payloads until they
 * expire so a captured payload cannot be replayed. Returns true if this is
 * the first use. Uses a small JSON file in the system temp directory.
 */
function altcha_claim_payload($payload_b64, $expires_at) {
    $file = sys_get_temp_dir() . '/altcha_used_' . md5(__DIR__) . '.json';
    $hash = hash('sha256', $payload_b64);
    $now = time();
    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        # Availability over strictness: if the store is unwritable, log and
        # accept (the challenge signature and expiry are still enforced).
        error_log('contact_mail.php: ALTCHA replay store unwritable: ' . $file);
        return true;
    }
    flock($fp, LOCK_EX);
    $used = json_decode(stream_get_contents($fp) ?: '[]', true) ?: [];
    $used = array_filter($used, function ($exp) use ($now) { return $exp > $now; });
    $fresh = !isset($used[$hash]);
    if ($fresh) {
        $used[$hash] = $expires_at;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($used));
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $fresh;
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

# Honeypot: the hidden "website" field must stay empty (bots fill it in).
if (!empty($_POST['website'])) {
    fail($error_page);
}

# ---- Send ------------------------------------------------------------------

try {
    if (!is_file($autoload_path)) {
        throw new RuntimeException('Composer autoloader not found at ' . $autoload_path
            . ' - run: composer require phpmailer/phpmailer league/oauth2-google');
    }
    require $autoload_path;

    # ALTCHA captcha: verify the solved proof-of-work challenge before sending.
    if ($captcha_service === 'altcha') {
        $payload_b64 = $_POST['altcha'] ?? '';
        if (!is_string($payload_b64) || $payload_b64 === '') {
            throw new RuntimeException('ALTCHA payload missing');
        }
        if ($altcha_hmac_secret === '') {
            throw new RuntimeException('$altcha_hmac_secret is not configured');
        }
        $altcha = new AltchaOrg\Altcha\Altcha(
            hmacSignatureSecret: $altcha_hmac_secret,
            hmacKeySignatureSecret: $altcha_key_secret !== '' ? $altcha_key_secret : null,
        );
        $verify_options = new AltchaOrg\Altcha\VerifySolutionOptions(
            $payload_b64,
            new AltchaOrg\Altcha\Algorithm\Pbkdf2(),
        );
        $result = $altcha->verifySolution($verify_options);
        if (!$result->verified) {
            throw new RuntimeException('ALTCHA verification failed'
                . ($result->expired ? ' (challenge expired)' : ''));
        }
        $expires_at = (int) ($verify_options->payload->challenge->parameters->expiresAt ?? time() + 300);
        if (!altcha_claim_payload($payload_b64, $expires_at)) {
            throw new RuntimeException('ALTCHA payload already used (replay)');
        }
    }

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
