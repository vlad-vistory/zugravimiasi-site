<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /'); exit; }

$to = 'contact@zugravimiasi.ro';

// honeypot: botii completeaza campul ascuns "website"
if (!empty($_POST['website'])) { header('Location: /?ok=1#contact'); exit; }

// canal de proveniență, dedus din adresa paginii de pe care s-a trimis formularul
function contact_canal_din_referer($ref) {
    if (!is_string($ref) || $ref === '') return 'direct';
    $p = array();
    $q = (string)parse_url($ref, PHP_URL_QUERY);
    if ($q !== '') { parse_str($q, $p); }
    $src = strtolower(trim((string)(isset($p['utm_source']) && is_string($p['utm_source']) ? $p['utm_source'] : '')));
    $med = strtolower(trim((string)(isset($p['utm_medium']) && is_string($p['utm_medium']) ? $p['utm_medium'] : '')));
    $platit = (bool)preg_match('/cpc|ppc|paid|cpm|display|retarget|ads/', $med);
    if (!empty($p['gclid']) || !empty($p['gbraid']) || !empty($p['wbraid']) || (strpos($src, 'google') !== false && $platit)) return 'google-ads';
    if (!empty($p['fbclid']) || ((strpos($src, 'facebook') !== false || strpos($src, 'instagram') !== false || $src === 'meta' || $src === 'fb' || $src === 'ig') && $platit)) return 'meta-ads';
    if (!empty($p['ttclid'])) return 'tiktok-ads';
    if ($src !== '') return 'utm:' . substr(preg_replace('/[^a-z0-9._-]/', '', $src), 0, 30);
    $h = preg_replace('/^www\./', '', strtolower((string)parse_url($ref, PHP_URL_HOST)));
    if ($h === '' || preg_match('/(^|\.)zugravimiasi\.ro$/', $h)) return 'direct';
    if (strpos($h, 'google.') !== false) return 'google';
    if (strpos($h, 'facebook') !== false || strpos($h, 'instagram') !== false) return 'facebook';
    if (strpos($h, 'bing.') !== false) return 'bing';
    if (strpos($h, 'tiktok') !== false) return 'tiktok';
    if (strpos($h, 'olx.') !== false || strpos($h, 'publi24') !== false) return 'anunturi';
    return substr($h, 0, 50);
}

$clean = function ($s) { return trim(str_replace(array("\r", "\n"), ' ', is_string($s) ? $s : '')); };
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
    $sid    = substr(preg_replace('/[^a-zA-Z0-9]/', '', $_POST['f_sid'] ?? ''), 0, 40);
    $entry  = $clean($_POST['f_entry'] ?? '');
    $submit = $clean($_POST['f_submit'] ?? '');
    if ($submit === '') { $submit = $clean((string)parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH)); }
    $button = $clean($_POST['f_btn'] ?? '');
    $source = $clean($_POST['f_src'] ?? '');
    $device = $clean($_POST['f_dev'] ?? '');
    $channel = substr(preg_replace('/[^a-zA-Z0-9._:\/-]/', '', $clean($_POST['f_channel'] ?? '')), 0, 60);
    if ($channel === '') { $channel = contact_canal_din_referer($_SERVER['HTTP_REFERER'] ?? ''); }
    $suprafata = substr($clean($_POST['suprafata'] ?? ''), 0, 60);
    $st = $db->prepare("INSERT INTO leads (created,session_id,nume,telefon,email,cand,deviz,mesaj,entry_page,submit_page,button,source,device,ip,channel,suprafata) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([date('Y-m-d H:i:s'), $sid, $nume, $tel, $email, $cand, $deviz, $mesaj, $entry, $submit, $button, $source, $device, $_SERVER['REMOTE_ADDR'] ?? '', $channel, $suprafata]);
    crm_note($db, (int)$db->lastInsertId(), 'Lead primit din formular.');
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
