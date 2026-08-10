<?php
// Endpoint pentru urmarirea first-party: palnia formularului, vizite, click-uri de contact
require_once __DIR__ . '/_crm.php';

// max ~60 evenimente pe minut de la acelasi IP, tinut in tabela settings
function track_sub_limita($db, $ip, $limita = 60) {
    if ($ip === '') return true;
    $min = date('YmdHi');
    $k = 'rl:' . $min . ':' . substr(hash('sha256', $ip), 0, 16);
    $db->prepare("INSERT INTO settings (k,v) VALUES (?,'1') ON CONFLICT(k) DO UPDATE SET v = CAST(v AS INTEGER) + 1")
       ->execute([$k]);
    $n = (int)crm_get($db, $k);
    if (mt_rand(1, 50) === 1) { // curata cheile din minutele trecute
        $db->prepare("DELETE FROM settings WHERE k LIKE 'rl:%' AND k NOT LIKE ?")->execute(['rl:' . $min . ':%']);
    }
    return $n <= $limita;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(204); exit; }

$camp = function ($k) { $v = $_POST[$k] ?? ''; return is_string($v) ? $v : ''; };

$sid   = substr(preg_replace('/[^a-zA-Z0-9]/', '', $camp('sid')), 0, 40);
$type  = in_array($camp('type'), ['form_view', 'form_start', 'form_field', 'page_view', 'contact_click', 'cta_view'], true) ? $camp('type') : '';
$page  = trim(substr(str_replace(["\r", "\n", "\t"], ' ', $camp('page')), 0, 200));
$chan  = substr(preg_replace('/[^a-zA-Z0-9._:\/-]/', '', $camp('ch')), 0, 60);
$dev   = in_array($camp('dev'), ['mobil', 'desktop'], true) ? $camp('dev') : '';
$kind  = in_array($camp('kind'), ['whatsapp', 'email'], true) ? $camp('kind') : '';
$field = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $camp('field')), 0, 40);

if ($page === '') $page = '/';
if ($page[0] !== '/') $page = '/' . $page;

if ($sid !== '' && $type !== '' && !crm_is_bot()) {
    try {
        $db  = crm_db();
        $now = date('Y-m-d H:i:s');
        if (track_sub_limita($db, (string)($_SERVER['REMOTE_ADDR'] ?? ''))) {
            if ($type === 'page_view') {
                $db->prepare("INSERT INTO page_views (created,page,visitor,device,channel) VALUES (?,?,?,?,?)")
                   ->execute([$now, $page, crm_visitor_hash(), $dev, $chan]);

            } elseif ($type === 'contact_click') {
                if ($kind !== '') {
                    $db->prepare("INSERT INTO contact_clicks (created,kind,page,session_id,channel) VALUES (?,?,?,?,?)")
                       ->execute([$now, $kind, $page, $sid, $chan]);
                }

            } elseif ($type === 'cta_view') {
                $c = $db->prepare("SELECT COUNT(*) FROM cta_views WHERE session_id=? AND page=?");
                $c->execute([$sid, $page]);
                if ((int)$c->fetchColumn() === 0) {
                    $db->prepare("INSERT INTO cta_views (created,page,session_id) VALUES (?,?,?)")
                       ->execute([$now, $page, $sid]);
                }

            } else { // form_view / form_start / form_field
                if ($type === 'form_field') {
                    $c = $db->prepare("SELECT COUNT(*) FROM events WHERE session_id=? AND type=? AND field=?");
                    $c->execute([$sid, $type, $field]);
                } else {
                    $c = $db->prepare("SELECT COUNT(*) FROM events WHERE session_id=? AND type=?");
                    $c->execute([$sid, $type]);
                }
                if ((int)$c->fetchColumn() === 0) {
                    $db->prepare("INSERT INTO events (session_id,type,page,created,field,channel,device) VALUES (?,?,?,?,?,?,?)")
                       ->execute([$sid, $type, $page, $now, $field, $chan, $dev]);
                }
            }
        }
    } catch (Exception $e) {}
}

http_response_code(204);
