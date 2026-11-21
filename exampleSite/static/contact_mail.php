<?php
/**
 * Simple contact form mail handler for the Alpha Church Hugo theme.
 *
 * Sends the form with PHP's built-in mail() function — no dependencies.
 * This works wherever the server's mail setup can deliver to the
 * recipient (many shared hosts can; cloud VMs often need authenticated
 * SMTP instead).
 *
 * For authenticated SMTP (e.g. Google Workspace) and ALTCHA captcha
 * support, use contact_mail_smtp.php instead and point the form at it:
 *
 *   [params.contact]
 *     service = "php"
 *     php_script = "/contact_mail_smtp.php"
 *
 * SECURITY: this script cannot verify ALTCHA captcha payloads. If one is
 * submitted (captcha enabled in hugo.toml while this script is selected),
 * the request is rejected rather than silently skipping verification.
 */

# Edit the following variables to fit your needs!

# Recipient of the mail:
$to = 'admin@example.org';
# The "From" address of the mail. This should match the domain of the server:
$from = 'website@example.org';
# Forward users to this page if the mail is successfully sent:
$confirm_page = '/contact_thanks';
# If something goes wrong and sending the mail fails, forward users to:
$error_page = '/404.html';
# Specify the subject of the mail:
$subject = 'Feedback from example.org';

# Optional: load settings from a file OUTSIDE the web root. Any variable set
# there overrides the defaults above (the same file contact_mail_smtp.php
# and altcha_challenge.php load). Never commit secrets to git.
$config_file = __DIR__ . '/../contact_mail_config.php';
if (is_file($config_file)) {
    require $config_file;
}

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

# Honeypot: the hidden "website" field must stay empty (bots fill it in).
if (!empty($_POST['website'])) {
    fail($error_page);
}

# ALTCHA payloads cannot be verified here; reject them (fail closed) and
# point the admin at the SMTP script, which supports the captcha.
if (!empty($_POST['altcha'])) {
    error_log('contact_mail.php: ALTCHA payload submitted but this script cannot verify it; use contact_mail_smtp.php');
    fail($error_page);
}

$body = "Name: " . $name . "\n\n"
      . "Email: " . $email . "\n\n"
      . "Message:\n" . $message . "\n";

# Replies go to the visitor (the validated address), not the website account.
$headers = "From: " . $from . "\r\n"
         . "Reply-To: " . $email . "\r\n"
         . "Content-Type: text/plain; charset=utf-8\r\n";

if (mail($to, $subject, $body, $headers)) {
    header('Location: ' . $confirm_page);
    exit;
}
error_log('contact_mail.php: mail() failed');
fail($error_page);
