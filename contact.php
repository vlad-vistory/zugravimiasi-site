<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /'); exit; }

$to = 'contact@zugravimiasi.ro';

// honeypot: botii completeaza campul ascuns "website"
if (!empty($_POST['website'])) { header('Location: /?ok=1#contact'); exit; }

$clean = function ($s) { return trim(str_replace(array("\r", "\n"), ' ', (string)$s)); };
$nume  = $clean($_POST['nume'] ?? '');
$tel   = $clean($_POST['telefon'] ?? '');
$email = $clean($_POST['email'] ?? '');
$cand  = $clean($_POST['cand'] ?? '');
$deviz = $clean($_POST['deviz'] ?? '');
$mesaj = trim((string)($_POST['mesaj'] ?? ''));

if ($nume === '' || $tel === '') { header('Location: /?err=1#contact'); exit; }

// salveaza lead-ul in CRM (SQLite, in afara public_html); nu blocheaza formularul daca DB pica
try {
    require_once __DIR__ . '/_crm.php';
    $db = crm_db();
    $sursa = ($_POST['sursa'] ?? '') !== '' ? $clean($_POST['sursa']) : $clean($_SERVER['HTTP_REFERER'] ?? '');
    $st = $db->prepare("INSERT INTO leads (created,nume,telefon,email,cand,deviz,mesaj,sursa,ip) VALUES (?,?,?,?,?,?,?,?,?)");
    $st->execute([date('Y-m-d H:i:s'), $nume, $tel, $email, $cand, $deviz, $mesaj, $sursa, $_SERVER['REMOTE_ADDR'] ?? '']);
} catch (Exception $e) { /* ignora erorile de DB */ }

$subject = 'Cerere oferta de pe zugravimiasi.ro';
$body  = "Nume: $nume\n";
$body .= "Telefon: $tel\n";
$body .= 'Email: ' . ($email !== '' ? $email : '-') . "\n";
$body .= 'Cand vrea lucrarea: ' . ($cand !== '' ? $cand : '-') . "\n";
$body .= 'Tip deviz: ' . ($deviz !== '' ? $deviz : '-') . "\n\n";
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
