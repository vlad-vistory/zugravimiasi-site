<?php
// Endpoint pentru evenimentele de palnie (a vazut formularul / a inceput sa completeze)
require_once __DIR__ . '/_crm.php';
$sid  = substr(preg_replace('/[^a-zA-Z0-9]/', '', $_POST['sid'] ?? ''), 0, 40);
$type = in_array(($_POST['type'] ?? ''), ['form_view', 'form_start'], true) ? $_POST['type'] : '';
$page = substr((string)($_POST['page'] ?? ''), 0, 200);
if ($sid && $type) {
    try {
        $db = crm_db();
        $c = $db->prepare("SELECT COUNT(*) FROM events WHERE session_id=? AND type=?");
        $c->execute([$sid, $type]);
        if ((int)$c->fetchColumn() === 0) {
            $db->prepare("INSERT INTO events (session_id,type,page,created) VALUES (?,?,?,?)")
               ->execute([$sid, $type, $page, date('Y-m-d H:i:s')]);
        }
    } catch (Exception $e) {}
}
http_response_code(204);
