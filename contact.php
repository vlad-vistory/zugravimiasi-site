<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /'); exit; }

$to = 'contact@zugravimiasi.ro';

// honeypot: botii completeaza campul ascuns "website"
if (!empty($_POST['website'])) { header('Location: /?ok=1#contact'); exit; }

$clean = function ($s) { return trim(str_replace(array("\r", "\n"), ' ', (string)$s)); };
$nume  = $clean($_POST['nume'] ?? '');
$tel   = $clean($_POST['telefon'] ?? '');
$email = $clean($_POST['email'] ?? '');
$mesaj = trim((string)($_POST['mesaj'] ?? ''));

if ($nume === '' || $tel === '') { header('Location: /?err=1#contact'); exit; }

$subject = 'Cerere oferta de pe zugravimiasi.ro';
$body  = "Nume: $nume\n";
$body .= "Telefon: $tel\n";
$body .= 'Email: ' . ($email !== '' ? $email : '-') . "\n\n";
$body .= "Mesaj:\n" . ($mesaj !== '' ? $mesaj : '-') . "\n";

$headers  = 'From: Zugrav Iasi <contact@zugravimiasi.ro>' . "\r\n";
$headers .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $headers .= 'Reply-To: ' . $email . "\r\n";
}

$enc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
$ok = @mail($to, $enc, $body, $headers);

header('Location: /?' . ($ok ? 'ok=1' : 'err=1') . '#contact');
exit;
