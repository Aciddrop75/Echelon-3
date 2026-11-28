<?php
/**
 * Send a sample registration e-mail to the logged in user's own address, so the mail setup can be checked.
 * (It sends to the account's address only - never to an address taken from the request - and creates no key.)
 */
$auth_name = 'login';
require '../inc.php';
require '../inc/functions_messenger.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['email-test'])) {
    sendBack('Please do not access that page directly');
}
csrfCheck();

$email = (string) $mem->email;
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendBack('Your account does not have a valid email address to send the test to.');
}

$user_key = 'TEST-KEY';
$host = htmlspecialchars((string) ($_SERVER['SERVER_NAME'] ?? ''), ENT_QUOTES);

$body = '<html><body>';
$body .= '<h2>Echelon Test Email</h2>';
$body .= $config['cosmos']['email_header'] ?? '';
$body .= 'This is what a registration email for Echelon would look like.
    <a href="http://' . $host . PATH . 'register.php?key=' . $user_key . '">Register here</a>.<br />';
$body .= 'Registration Key: ' . $user_key . '<br />';
$body .= $config['cosmos']['email_footer'] ?? '';
$body .= '</body></html>';

$body = str_replace('%ech_name%', (string) ($config['cosmos']['name'] ?? 'Echelon'), $body);
$body = str_replace('%name%', 'new user', $body);

$mgr = new messenger();
$mgr->to($email);
$mgr->replyto($email_config['board_email']);
$mgr->from($email_config['board_email']);
$mgr->subject('Echelon Test Email');
$mgr->msg($body);
$mgr->anti_abuse_headers();
$mgr->setconfigvalues($email_config);
try {
    $mgr->send();
} catch (Throwable $e) {
    sendBack('The test email could not be sent: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES));
}

sendGood('A test email has been sent to ' . htmlspecialchars($email, ENT_QUOTES) . '.');
