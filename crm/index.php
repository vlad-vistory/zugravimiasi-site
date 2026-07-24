<?php
require_once dirname(__DIR__) . '/_crm.php';
session_start();
$db = crm_db();
$hash = crm_get($db, 'pass');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---- setare parola la prima accesare ---- */
if (!$hash) {
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['newpass'])) {
        $p = (string)$_POST['newpass'];
        if (strlen($p) >= 6) { crm_set($db,'pass',password_hash($p,PASSWORD_DEFAULT)); header('Location: ?'); exit; }
        $err = 'Parola trebuie sa aiba minim 6 caractere.';
    }
    render_shell('Setează parola CRM', '<form method="post" class="auth"><h1>Prima accesare</h1><p>Setează o parolă pentru CRM.</p>'
        .(isset($err)?'<div class="msg err">'.h($err).'</div>':'')
        .'<input type="password" name="newpass" placeholder="Parolă nouă (min. 6 caractere)" required autofocus>'
        .'<button class="btn">Salvează parola</button></form>');
    exit;
}

/* ---- login ---- */
if (empty($_SESSION['crm_ok'])) {
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['pass'])) {
        if (password_verify((string)$_POST['pass'], $hash)) { $_SESSION['crm_ok']=1; header('Location: ?'); exit; }
        $err = 'Parolă greșită.';
    }
    render_shell('Autentificare CRM', '<form method="post" class="auth"><h1>CRM Zugrav Iași</h1>'
        .(isset($err)?'<div class="msg err">'.h($err).'</div>':'')
        .'<input type="password" name="pass" placeholder="Parolă" required autofocus>'
        .'<button class="btn">Intră</button></form>');
    exit;
}

if (isset($_GET['logout'])) { session_destroy(); header('Location: ?'); exit; }

$STATUSES = ['Nou','Contactat','Ofertat','Câștigat','Pierdut'];

/* ---- actiuni (update status / nota) ---- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    if (isset($_POST['status']) && in_array($_POST['status'],$STATUSES,true)) {
        $db->prepare("UPDATE leads SET status=? WHERE id=?")->execute([$_POST['status'],$id]);
    }
    if (isset($_POST['note'])) {
        $db->prepare("UPDATE leads SET note=? WHERE id=?")->execute([trim((string)$_POST['note']),$id]);
    }
    if (isset($_POST['delete'])) {
        $db->prepare("DELETE FROM leads WHERE id=?")->execute([$id]);
    }
    header('Location: ?'.(isset($_GET['f'])?'f='.urlencode($_GET['f']):'')); exit;
}

/* ---- export CSV ---- */
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="lead-uri-zugravimiasi.csv"');
    echo "\xEF\xBB\xBF"; // BOM pentru Excel
    $out = fopen('php://output','w');
    fputcsv($out, ['Data','Nume','Telefon','Email','Cand','Deviz','Mesaj','Sursa','Status','Nota']);
    foreach ($db->query("SELECT * FROM leads ORDER BY id DESC") as $r) {
        fputcsv($out, [$r['created'],$r['nume'],$r['telefon'],$r['email'],$r['cand'],$r['deviz'],$r['mesaj'],$r['sursa'],$r['status'],$r['note']]);
    }
    fclose($out); exit;
}

/* ---- date ---- */
$counts = [];
foreach ($db->query("SELECT status, COUNT(*) c FROM leads GROUP BY status") as $r) { $counts[$r['status']] = (int)$r['c']; }
$total = array_sum($counts);
$f = $_GET['f'] ?? '';
if ($f && in_array($f,$STATUSES,true)) {
    $q = $db->prepare("SELECT * FROM leads WHERE status=? ORDER BY id DESC"); $q->execute([$f]); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
} else {
    $rows = $db->query("SELECT * FROM leads ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
}

/* ---- render lista ---- */
ob_start(); ?>
<div class="top">
  <div><b>CRM Zugrav Iași</b> · lead-uri din formular</div>
  <div><a href="?export=csv" class="lnk">Export CSV</a> · <a href="?logout" class="lnk">Ieși</a></div>
</div>
<div class="tabs">
  <a class="tab <?php echo $f===''?'on':''; ?>" href="?">Toate <span><?php echo $total; ?></span></a>
  <?php foreach ($STATUSES as $s): ?>
    <a class="tab <?php echo $f===$s?'on':''; ?>" href="?f=<?php echo urlencode($s); ?>"><?php echo h($s); ?> <span><?php echo (int)($counts[$s]??0); ?></span></a>
  <?php endforeach; ?>
</div>
<?php if (!$rows): ?>
  <div class="empty">Niciun lead <?php echo $f?('cu statusul „'.h($f).'”'):'încă'; ?>.</div>
<?php else: foreach ($rows as $r): ?>
  <div class="lead st-<?php echo h(strtolower(str_replace(['ă','â','î','ș','ț',' '],['a','a','i','s','t','-'],mb_strtolower($r['status'],'UTF-8')))); ?>">
    <div class="lead-h">
      <div>
        <div class="nm"><?php echo h($r['nume']); ?></div>
        <div class="meta"><?php echo h(date('d.m.Y H:i', strtotime($r['created']))); ?> · <span class="src"><?php echo h($r['sursa']?:'—'); ?></span></div>
      </div>
      <div class="contact">
        <a href="tel:<?php echo h(preg_replace('/\s+/','',$r['telefon'])); ?>"><?php echo h($r['telefon']); ?></a>
        <?php if ($r['email']): ?><a href="mailto:<?php echo h($r['email']); ?>"><?php echo h($r['email']); ?></a><?php endif; ?>
      </div>
    </div>
    <div class="chips">
      <?php if ($r['cand']): ?><span class="chip"><i>Când:</i> <?php echo h($r['cand']); ?></span><?php endif; ?>
      <?php if ($r['deviz']): ?><span class="chip"><i>Deviz:</i> <?php echo h($r['deviz']); ?></span><?php endif; ?>
    </div>
    <?php if (trim($r['mesaj'])!==''): ?><div class="msgtxt"><?php echo nl2br(h($r['mesaj'])); ?></div><?php endif; ?>
    <form method="post" class="row">
      <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
      <select name="status" onchange="this.form.submit()">
        <?php foreach ($STATUSES as $s): ?><option <?php echo $r['status']===$s?'selected':''; ?>><?php echo h($s); ?></option><?php endforeach; ?>
      </select>
      <input type="text" name="note" value="<?php echo h($r['note']); ?>" placeholder="Notă (ex: sunat, revin marți)">
      <button class="btn sm">Salvează nota</button>
      <button class="btn sm del" name="delete" value="1" onclick="return confirm('Ștergi acest lead?')">Șterge</button>
    </form>
  </div>
<?php endforeach; endif;
$content = ob_get_clean();
render_shell('CRM Zugrav Iași', $content);

/* ---- layout ---- */
function render_shell($title, $body) { ?>
<!doctype html><html lang="ro"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo h($title); ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f4f5f7;color:#1f2937;font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;font-size:15px}
a{color:#ec5e0c;text-decoration:none}a:hover{text-decoration:underline}
.wrap{max-width:920px;margin:0 auto;padding:20px 16px 60px}
.top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:16px 0;border-bottom:2px solid #f2681c;margin-bottom:18px}
.top b{color:#111}
.lnk{font-weight:600}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}
.tab{background:#fff;border:1px solid #e4e4e8;border-radius:999px;padding:7px 14px;color:#374151;font-weight:600}
.tab.on{background:#f2681c;border-color:#f2681c;color:#fff}
.tab span{opacity:.7;font-weight:700}
.lead{background:#fff;border:1px solid #e4e4e8;border-left:4px solid #9ca3af;border-radius:12px;padding:16px 18px;margin-bottom:12px}
.lead.st-nou{border-left-color:#f2681c}
.lead.st-contactat{border-left-color:#3b82f6}
.lead.st-ofertat{border-left-color:#a855f7}
.lead.st-castigat{border-left-color:#16a34a}
.lead.st-pierdut{border-left-color:#9ca3af;opacity:.72}
.lead-h{display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap}
.nm{font-weight:800;font-size:1.05rem;color:#111}
.meta{font-size:.82rem;color:#6b7280;margin-top:2px}
.src{color:#9ca3af}
.contact{text-align:right;display:flex;flex-direction:column;gap:2px}
.contact a{font-weight:600}
.chips{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 0}
.chip{background:#f4f5f7;border:1px solid #e4e4e8;border-radius:999px;padding:4px 11px;font-size:.82rem}
.chip i{color:#9ca3af;font-style:normal}
.msgtxt{margin-top:10px;padding:10px 12px;background:#f9fafb;border:1px solid #eef0f2;border-radius:8px;font-size:.92rem;color:#374151}
.row{display:flex;gap:8px;align-items:center;margin-top:12px;flex-wrap:wrap}
.row select,.row input[type=text]{padding:9px 11px;border:1px solid #d1d5db;border-radius:8px;font:inherit;font-size:.9rem}
.row input[type=text]{flex:1;min-width:160px}
.row select{cursor:pointer;font-weight:600}
.btn{background:#ec5e0c;color:#fff;border:0;border-radius:8px;padding:10px 16px;font:inherit;font-weight:700;cursor:pointer}
.btn:hover{background:#d45309}
.btn.sm{padding:9px 12px;font-size:.86rem}
.btn.del{background:#fff;color:#b91c1c;border:1px solid #e5c1c1}
.btn.del:hover{background:#fee2e2}
.empty{background:#fff;border:1px dashed #d1d5db;border-radius:12px;padding:40px;text-align:center;color:#6b7280}
.auth{max-width:360px;margin:9vh auto 0;background:#fff;border:1px solid #e4e4e8;border-radius:16px;padding:32px 28px;box-shadow:0 20px 50px -30px rgba(0,0,0,.3)}
.auth h1{font-size:1.3rem;margin:0 0 6px}
.auth p{color:#6b7280;margin:0 0 18px;font-size:.92rem}
.auth input{width:100%;padding:12px 14px;border:1.5px solid #d1d5db;border-radius:10px;font:inherit;margin-bottom:12px}
.auth input:focus{outline:none;border-color:#f2681c}
.auth .btn{width:100%}
.msg{padding:10px 12px;border-radius:8px;font-size:.9rem;margin-bottom:12px}
.msg.err{background:#fee2e2;color:#b91c1c}
</style></head><body><div class="wrap"><?php echo $body; ?></div></body></html>
<?php }
