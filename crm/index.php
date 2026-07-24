<?php
require_once dirname(__DIR__) . '/_crm.php';
session_start();
$db = crm_db();
$hash = crm_get($db, 'pass');
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ago($ts){ $d=time()-strtotime($ts); if($d<60)return 'acum'; if($d<3600)return floor($d/60).' min'; if($d<86400)return floor($d/3600).' h'; return floor($d/86400).' zile'; }

/* ---------- setare parola (prima accesare) ---------- */
if (!$hash) {
    $err='';
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['newpass'])) {
        if (strlen((string)$_POST['newpass'])>=6){ crm_set($db,'pass',password_hash($_POST['newpass'],PASSWORD_DEFAULT)); header('Location: ?'); exit; }
        $err='Parola trebuie să aibă minim 6 caractere.';
    }
    shell_auth('Setează parola CRM','Prima accesare','Setează o parolă pentru CRM.','newpass','Parolă nouă (min. 6 caractere)','Salvează parola',$err); exit;
}
/* ---------- login ---------- */
if (empty($_SESSION['crm_ok'])) {
    $err='';
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['pass'])) {
        if (password_verify((string)$_POST['pass'],$hash)){ $_SESSION['crm_ok']=1; header('Location: ?'); exit; }
        $err='Parolă greșită.';
    }
    shell_auth('Autentificare CRM','CRM Zugrav Iași','','pass','Parolă','Intră',$err); exit;
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: ?'); exit; }

$STAT = ['Nou','Contactat','Ofertat','Câștigat','Pierdut'];

/* ---------- actiuni POST ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $id = (int)($_POST['id'] ?? 0);
    if (isset($_POST['setpass']) && strlen((string)$_POST['setpass'])>=6){ crm_set($db,'pass',password_hash($_POST['setpass'],PASSWORD_DEFAULT)); header('Location: ?p=setari&ok=1'); exit; }
    if ($id) {
        $lead = $db->prepare("SELECT * FROM leads WHERE id=?"); $lead->execute([$id]); $lead=$lead->fetch(PDO::FETCH_ASSOC);
        if ($lead) {
            if (isset($_POST['status']) && in_array($_POST['status'],$STAT,true) && $_POST['status']!==$lead['status']){
                $db->prepare("UPDATE leads SET status=? WHERE id=?")->execute([$_POST['status'],$id]);
                crm_note($db,$id,'Stare schimbată în „'.$_POST['status'].'”.');
            }
            if (isset($_POST['value'])) { $db->prepare("UPDATE leads SET value=? WHERE id=?")->execute([max(0,(int)$_POST['value']),$id]); }
            if (isset($_POST['note']) && trim($_POST['note'])!=='') { crm_note($db,$id,trim($_POST['note'])); }
            if (isset($_POST['trash'])) { $db->prepare("UPDATE leads SET trashed=1 WHERE id=?")->execute([$id]); header('Location: ?p=palnie'); exit; }
            if (isset($_POST['restore'])) { $db->prepare("UPDATE leads SET trashed=0 WHERE id=?")->execute([$id]); header('Location: ?p=cos'); exit; }
            if (isset($_POST['delete'])) { $db->prepare("DELETE FROM leads WHERE id=?")->execute([$id]); $db->prepare("DELETE FROM notes WHERE lead_id=?")->execute([$id]); header('Location: ?p=cos'); exit; }
        }
    }
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '?')); exit;
}

/* ---------- export CSV ---------- */
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="lead-uri-zugravimiasi.csv"');
    echo "\xEF\xBB\xBF"; $o=fopen('php://output','w');
    fputcsv($o,['Data','Nume','Telefon','Email','Cand','Deviz','Mesaj','Status','Valoare','Sursa','Pagina intrare','Buton','Dispozitiv']);
    foreach ($db->query("SELECT * FROM leads WHERE trashed=0 ORDER BY id DESC") as $r)
        fputcsv($o,[$r['created'],$r['nume'],$r['telefon'],$r['email'],$r['cand'],$r['deviz'],$r['mesaj'],$r['status'],$r['value'],$r['source'],$r['entry_page'],$r['button'],$r['device']]);
    fclose($o); exit;
}

$p = $_GET['p'] ?? 'panou';
ob_start();

/* ================= PANOU ================= */
if ($p==='panou') {
    $cnt=[]; foreach($db->query("SELECT status,COUNT(*) c FROM leads WHERE trashed=0 GROUP BY status") as $r)$cnt[$r['status']]=(int)$r['c'];
    $luna=(int)$db->query("SELECT COUNT(*) FROM leads WHERE trashed=0 AND created>='".date('Y-m-01')."'")->fetchColumn();
    $inlucru=($cnt['Contactat']??0)+($cnt['Ofertat']??0);
    $castig=$cnt['Câștigat']??0; $pierdut=$cnt['Pierdut']??0;
    $rata=($castig+$pierdut)>0?round($castig/($castig+$pierdut)*100):0;
    $valoare=(int)$db->query("SELECT COALESCE(SUM(value),0) FROM leads WHERE trashed=0 AND status='Câștigat'")->fetchColumn();
    echo '<div class="kpis">';
    kpi('Lead-uri luna asta',$luna,'');
    kpi('Neatinse',$cnt['Nou']??0,'de contactat');
    kpi('În lucru',$inlucru,'din '.array_sum($cnt).' total');
    kpi('Rata de câștig',$rata.'%','');
    kpi('Valoare câștigată',number_format($valoare,0,',','.').' lei','');
    echo '</div><div class="grid2">';
    echo '<div class="card"><h3>Ultimele cereri</h3>';
    $last=$db->query("SELECT * FROM leads WHERE trashed=0 ORDER BY id DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
    if(!$last) echo '<p class="muted">Niciun lead încă.</p>';
    foreach($last as $r) echo '<a class="lrow" href="?p=lead&id='.(int)$r['id'].'"><span class="badge s-'.slug($r['status']).'">'.h($r['status']).'</span><b>'.h($r['nume']?:$r['telefon']).'</b><span class="rmeta">'.h($r['entry_page']?:'—').' · '.ago($r['created']).'</span></a>';
    echo '<a class="more" href="?p=palnie">Vezi toată pâlnia →</a></div>';
    echo '<div class="card"><h3>Ce buton convertește</h3><p class="muted" style="margin-top:-6px">Din ce loc au pornit oamenii spre formular.</p>';
    $btns=$db->query("SELECT COALESCE(NULLIF(button,''),'A intrat direct pe formular') b, COUNT(*) c FROM leads WHERE trashed=0 GROUP BY b ORDER BY c DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    if(!$btns) echo '<p class="muted">Încă nimic.</p>';
    foreach($btns as $r) echo '<div class="brow"><span>'.h($r['b']).'</span><b>'.(int)$r['c'].'</b></div>';
    echo '</div></div>';
}

/* ================= PALNIE (lista pe stadii) ================= */
elseif ($p==='palnie') {
    $f=$_GET['f']??'';
    $cnt=[]; foreach($db->query("SELECT status,COUNT(*) c FROM leads WHERE trashed=0 GROUP BY status") as $r)$cnt[$r['status']]=(int)$r['c'];
    $tot=array_sum($cnt);
    echo '<div class="tabs"><a class="tab '.($f===''?'on':'').'" href="?p=palnie">Toate <span>'.$tot.'</span></a>';
    foreach($STAT as $s) echo '<a class="tab '.($f===$s?'on':'').'" href="?p=palnie&f='.urlencode($s).'">'.h($s).' <span>'.(int)($cnt[$s]??0).'</span></a>';
    echo '</div>';
    if($f&&in_array($f,$STAT,true)){ $q=$db->prepare("SELECT * FROM leads WHERE trashed=0 AND status=? ORDER BY id DESC"); $q->execute([$f]); $rows=$q->fetchAll(PDO::FETCH_ASSOC); }
    else $rows=$db->query("SELECT * FROM leads WHERE trashed=0 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) echo '<div class="empty">Niciun lead aici încă.</div>';
    foreach($rows as $r){
        echo '<a class="lead s-'.slug($r['status']).'" href="?p=lead&id='.(int)$r['id'].'">';
        echo '<div class="lead-l"><span class="badge s-'.slug($r['status']).'">'.h($r['status']).'</span><div><div class="nm">'.h($r['nume']?:'(fără nume)').'</div><div class="meta">'.h($r['telefon']).($r['cand']?' · '.h($r['cand']):'').'</div></div></div>';
        echo '<div class="lead-r">'.($r['value']>0?'<b>'.number_format($r['value'],0,',','.').' lei</b>':'').'<span class="rmeta">'.h($r['entry_page']?:'—').' · '.ago($r['created']).'</span></div></a>';
    }
}

/* ================= FISA LEAD ================= */
elseif ($p==='lead') {
    $id=(int)($_GET['id']??0); $q=$db->prepare("SELECT * FROM leads WHERE id=?"); $q->execute([$id]); $r=$q->fetch(PDO::FETCH_ASSOC);
    if(!$r){ echo '<div class="empty">Lead inexistent.</div>'; }
    else {
        $tel=preg_replace('/[^0-9+]/','',$r['telefon']); $wa=preg_replace('/[^0-9]/','',$r['telefon']); if(strlen($wa)===10&&$wa[0]==='0')$wa='4'.$wa;
        $notes=$db->prepare("SELECT * FROM notes WHERE lead_id=? ORDER BY id DESC"); $notes->execute([$id]); $notes=$notes->fetchAll(PDO::FETCH_ASSOC);
        echo '<a class="back" href="?p=palnie">← Înapoi la pâlnie</a>';
        echo '<div class="lead-head"><div><h1>'.h($r['nume']?:$r['telefon']).'</h1><div class="sub">Etapa curentă: <b>'.h($r['status']).'</b></div></div>';
        echo '<div class="acts"><a class="wa" href="https://api.whatsapp.com/send?phone='.h($wa).'" target="_blank" rel="noopener">WhatsApp</a><a class="call" href="tel:'.h($tel).'">Sună</a></div></div>';
        echo '<div class="grid2">';
        // stanga: contact + atribuire
        echo '<div class="card"><h3>Contact</h3><table class="kv">';
        row('Telefon','<a href="tel:'.h($tel).'">'.h($r['telefon']).'</a>');
        if($r['email']) row('Email','<a href="mailto:'.h($r['email']).'">'.h($r['email']).'</a>');
        if($r['cand']) row('Când vrea lucrarea',h($r['cand']));
        if($r['deviz']) row('Deviz cerut',h($r['deviz']));
        if(trim($r['mesaj'])!=='') row('Detalii',nl2br(h($r['mesaj'])));
        row('Primit',h(date('d.m.Y H:i',strtotime($r['created']))).' ('.ago($r['created']).')');
        echo '</table></div>';
        echo '<div class="card"><h3>Gestionare</h3><form method="post"><input type="hidden" name="id" value="'.$id.'">';
        echo '<label class="fl">Stare</label><select name="status" onchange="this.form.submit()">';
        foreach($STAT as $s) echo '<option '.($r['status']===$s?'selected':'').'>'.h($s).'</option>'; echo '</select>';
        echo '<label class="fl">Valoare estimată</label><div class="inline"><input type="number" name="value" value="'.(int)$r['value'].'" min="0"><span>lei</span><button class="btn sm">Salvează</button></div></form>';
        echo '<label class="fl">Istoric și notițe</label><form method="post" class="notef"><input type="hidden" name="id" value="'.$id.'"><textarea name="note" placeholder="Ce ai discutat, ce urmează..."></textarea><button class="btn sm">Adaugă</button></form>';
        echo '<div class="timeline">';
        foreach($notes as $n) echo '<div class="tl"><div class="tl-t">'.h(date('d.m.Y H:i',strtotime($n['created']))).'</div><div class="tl-x">'.nl2br(h($n['text'])).'</div></div>';
        echo '</div>';
        echo '<form method="post" onsubmit="return confirm(\'Muți lead-ul în coș?\')"><input type="hidden" name="id" value="'.$id.'"><button class="btn ghost full" name="trash" value="1">Mută în coș</button></form>';
        echo '</div></div>';
        // atribuire jos
        echo '<div class="card" style="margin-top:16px"><h3>Cum a ajuns aici</h3><table class="kv">';
        row('Pagina de intrare','<b>'.h($r['entry_page']?:'—').'</b>');
        row('A trimis de pe',h($r['submit_page']?:'—'));
        row('De unde a apăsat',h($r['button']?:'a intrat direct pe formular'));
        row('Sursă',h($r['source']?:'direct'));
        row('Dispozitiv',h($r['device']?:'—'));
        echo '</table></div>';
    }
}

/* ================= ANALIZA ================= */
elseif ($p==='analiza') {
    $days=isset($_GET['d'])?(int)$_GET['d']:30; $range=$days>0?" AND created>='".date('Y-m-d 00:00:00',strtotime("-$days days"))."'":'';
    $view=(int)$db->query("SELECT COUNT(DISTINCT session_id) FROM events WHERE type='form_view'".($days>0?" AND created>='".date('Y-m-d 00:00:00',strtotime("-$days days"))."'":''))->fetchColumn();
    $start=(int)$db->query("SELECT COUNT(DISTINCT session_id) FROM events WHERE type='form_start'".($days>0?" AND created>='".date('Y-m-d 00:00:00',strtotime("-$days days"))."'":''))->fetchColumn();
    $sent=(int)$db->query("SELECT COUNT(*) FROM leads WHERE trashed=0$range")->fetchColumn();
    $base=max($view,$start,$sent,1);
    echo '<div class="an-top"><div class="dfilt">';
    foreach([7=>'7 zile',30=>'30 zile',90=>'90 zile',0=>'Tot timpul'] as $k=>$lab) echo '<a class="tab '.($days==$k?'on':'').'" href="?p=analiza&d='.$k.'">'.$lab.'</a>';
    echo '</div><a class="btn ghost sm" href="?export=csv">Descarcă CSV</a></div>';
    echo '<div class="card"><h3>Pâlnia formularului</h3><p class="muted" style="margin-top:-6px">Câți au ajuns la formular, câți au început, câți au trimis.</p>';
    funnel('1. A văzut formularul',$view,$base,'#c7d84a');
    if($view>0) echo '<div class="drop">↓ '.max(0,$view-$start).' au renunțat aici ('.($view?round(($view-$start)/$view*100):0).'%)</div>';
    funnel('2. A început să completeze',$start,$base,'#8db516');
    funnel('3. A trimis',$sent,$base,'#2f7d1f');
    echo '</div>';
    echo '<div class="grid2">';
    echo '<div class="card"><h3>Pagina prin care au intrat</h3><table class="an">';
    foreach($db->query("SELECT COALESCE(NULLIF(entry_page,''),'—') p, COUNT(*) c FROM leads WHERE trashed=0$range GROUP BY p ORDER BY c DESC LIMIT 10") as $r) echo '<tr><td>'.h($r['p']).'</td><td class="n">'.(int)$r['c'].'</td></tr>';
    echo '</table></div>';
    echo '<div class="card"><h3>Conversie după sursă</h3><table class="an">';
    foreach($db->query("SELECT COALESCE(NULLIF(source,''),'direct') s, COUNT(*) c FROM leads WHERE trashed=0$range GROUP BY s ORDER BY c DESC") as $r) echo '<tr><td>'.h($r['s']).'</td><td class="n">'.(int)$r['c'].'</td></tr>';
    echo '</table></div></div>';
}

/* ================= COS ================= */
elseif ($p==='cos') {
    $rows=$db->query("SELECT * FROM leads WHERE trashed=1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) echo '<div class="empty">Coșul e gol.</div>';
    foreach($rows as $r){
        echo '<div class="lead"><div class="lead-l"><div><div class="nm">'.h($r['nume']?:$r['telefon']).'</div><div class="meta">'.h($r['telefon']).' · '.ago($r['created']).'</div></div></div>';
        echo '<form method="post" class="cosf"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn sm" name="restore" value="1">Restaurează</button><button class="btn sm del" name="delete" value="1" onclick="return confirm(\'Ștergi definitiv?\')">Șterge definitiv</button></form></div>';
    }
}

/* ================= SETARI ================= */
elseif ($p==='setari') {
    if(isset($_GET['ok'])) echo '<div class="msg ok">Parola a fost schimbată.</div>';
    echo '<div class="card" style="max-width:420px"><h3>Schimbă parola</h3><form method="post"><input type="password" name="setpass" placeholder="Parolă nouă (min. 6 caractere)" required class="fld"><button class="btn">Salvează</button></form></div>';
}

$content=ob_get_clean();
render_shell($p, $content);

/* ---------- helpers de output ---------- */
function slug($s){ return strtr(mb_strtolower($s,'UTF-8'),['ă'=>'a','â'=>'a','î'=>'i','ș'=>'s','ț'=>'t',' '=>'-']); }
function kpi($t,$v,$s){ echo '<div class="kpi"><div class="kpi-t">'.h($t).'</div><div class="kpi-v">'.h($v).'</div>'.($s?'<div class="kpi-s">'.h($s).'</div>':'').'</div>'; }
function row($k,$v){ echo '<tr><td class="k">'.h($k).'</td><td>'.$v.'</td></tr>'; }
function funnel($lab,$n,$base,$col){ $pct=$base?round($n/$base*100):0; echo '<div class="fn"><div class="fn-h"><b>'.h($lab).'</b><span>'.$n.' · '.$pct.'%</span></div><div class="fn-bar"><span style="width:'.$pct.'%;background:'.$col.'"></span></div></div>'; }

function render_shell($p,$body){ $nav=[['panou','Panou'],['palnie','Pâlnie'],['analiza','Analiză'],['cos','Coș'],['setari','Setări']]; ?>
<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>CRM Zugrav Iași</title><style>
*{box-sizing:border-box}body{margin:0;background:#f4f5f7;color:#1f2937;font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;font-size:15px}
a{text-decoration:none;color:inherit}
.nav{background:#18181b;color:#fff;position:sticky;top:0;z-index:5}
.nav .in{max-width:1040px;margin:0 auto;display:flex;align-items:center;gap:6px;padding:0 16px;height:56px}
.nav .brand{font-weight:800;margin-right:14px}.nav .brand span{color:#f2681c}
.nav a.n{padding:8px 13px;border-radius:8px;color:#cbd0d6;font-weight:600;font-size:.95rem}
.nav a.n.on{background:#f2681c;color:#fff}.nav a.n:hover{color:#fff}
.nav .sp{flex:1}.nav .ext{color:#cbd0d6;font-size:.9rem}.nav .out{border:1px solid #3a3a40;padding:6px 12px;border-radius:8px;color:#fff;font-size:.9rem}
.wrap{max-width:1040px;margin:0 auto;padding:22px 16px 70px}
h1{font-size:1.6rem;margin:0}h3{margin:0 0 14px;font-size:1.05rem}
.card{background:#fff;border:1px solid #e4e4e8;border-radius:14px;padding:20px 22px;margin-bottom:16px}
.muted{color:#6b7280;font-size:.9rem}
.kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:18px}
.kpi{background:#fff;border:1px solid #e4e4e8;border-radius:14px;padding:16px 18px}
.kpi-t{color:#6b7280;font-size:.8rem;font-weight:600}.kpi-v{font-size:1.7rem;font-weight:800;color:#111;margin-top:4px}.kpi-s{color:#9ca3af;font-size:.8rem;margin-top:2px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.lrow{display:flex;align-items:center;gap:10px;padding:11px 0;border-bottom:1px solid #eef0f2}.lrow:last-of-type{border:0}
.lrow b{flex:1}.rmeta{color:#9ca3af;font-size:.82rem}
.more{display:inline-block;margin-top:10px;color:#ec5e0c;font-weight:600}
.brow{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid #eef0f2}.brow:last-child{border:0}
.badge{font-size:.72rem;font-weight:800;padding:4px 10px;border-radius:999px;background:#eef0f2;color:#374151;white-space:nowrap}
.s-nou{background:#fde3d3;color:#c2410c}.s-contactat{background:#dbeafe;color:#1d4ed8}.s-ofertat{background:#f3e8ff;color:#7e22ce}.s-castigat{background:#dcfce7;color:#15803d}.s-pierdut{background:#f3f4f6;color:#6b7280}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.tab{background:#fff;border:1px solid #e4e4e8;border-radius:999px;padding:7px 14px;color:#374151;font-weight:600;font-size:.92rem}
.tab.on{background:#f2681c;border-color:#f2681c;color:#fff}.tab span{opacity:.7}
.lead{display:flex;justify-content:space-between;align-items:center;gap:12px;background:#fff;border:1px solid #e4e4e8;border-left:4px solid #9ca3af;border-radius:12px;padding:14px 18px;margin-bottom:10px}
.lead.s-nou{border-left-color:#f2681c}.lead.s-contactat{border-left-color:#3b82f6}.lead.s-ofertat{border-left-color:#a855f7}.lead.s-castigat{border-left-color:#16a34a}.lead.s-pierdut{border-left-color:#9ca3af;opacity:.72}
.lead-l{display:flex;align-items:center;gap:12px}.nm{font-weight:800;color:#111}.meta{color:#6b7280;font-size:.85rem;margin-top:2px}
.lead-r{text-align:right}.lead-r b{display:block;color:#15803d}
.empty{background:#fff;border:1px dashed #d1d5db;border-radius:12px;padding:40px;text-align:center;color:#6b7280}
.back{display:inline-block;margin-bottom:14px;color:#6b7280;font-weight:600}
.lead-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:16px}
.lead-head .sub{color:#6b7280;margin-top:4px}
.acts{display:flex;gap:8px}.acts a{padding:10px 16px;border-radius:9px;font-weight:700;color:#fff}.wa{background:#25d366}.call{background:#8db516}
.kv{width:100%;border-collapse:collapse}.kv td{padding:9px 0;border-bottom:1px solid #eef0f2;vertical-align:top}.kv td.k{color:#6b7280;width:150px}.kv tr:last-child td{border:0}
.fl{display:block;font-weight:700;font-size:.85rem;margin:16px 0 6px}
select,input[type=number],input[type=text],input[type=password],textarea{font:inherit;padding:10px 12px;border:1px solid #d1d5db;border-radius:9px;width:100%}
select{cursor:pointer;font-weight:600}
.inline{display:flex;gap:8px;align-items:center}.inline input{flex:1}.inline span{color:#6b7280}
.notef{display:flex;gap:8px;align-items:flex-start}.notef textarea{flex:1;min-height:60px;resize:vertical}
.btn{background:#ec5e0c;color:#fff;border:0;border-radius:9px;padding:11px 18px;font:inherit;font-weight:700;cursor:pointer}.btn:hover{background:#d45309}
.btn.sm{padding:9px 13px;font-size:.88rem}.btn.ghost{background:#fff;color:#374151;border:1px solid #d1d5db}.btn.ghost:hover{background:#f9fafb}
.btn.full{width:100%;margin-top:16px}.btn.del{background:#fff;color:#b91c1c;border:1px solid #e5c1c1}.btn.del:hover{background:#fee2e2}
.timeline{margin:12px 0}.tl{border-left:2px solid #e4e4e8;padding:0 0 14px 14px;position:relative}.tl:before{content:'';position:absolute;left:-5px;top:4px;width:8px;height:8px;border-radius:50%;background:#f2681c}
.tl-t{font-size:.8rem;color:#9ca3af}.tl-x{margin-top:2px}
.an-top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px}.dfilt{display:flex;gap:8px;flex-wrap:wrap}
.fn{margin:14px 0}.fn-h{display:flex;justify-content:space-between;font-size:.92rem;margin-bottom:6px}.fn-bar{background:#eef0f2;border-radius:8px;overflow:hidden;height:22px}.fn-bar span{display:block;height:100%}
.drop{color:#dc2626;font-size:.82rem;margin:-6px 0 6px}
.an{width:100%;border-collapse:collapse}.an td{padding:9px 0;border-bottom:1px solid #eef0f2}.an td.n{text-align:right;font-weight:800}
.cosf{display:flex;gap:8px}.notef .btn,.inline .btn{white-space:nowrap}
.msg.ok{background:#dcfce7;color:#15803d;padding:10px 14px;border-radius:8px;margin-bottom:14px}
.fld{margin-bottom:12px}
@media(max-width:820px){.kpis{grid-template-columns:repeat(2,1fr)}.grid2{grid-template-columns:1fr}.nav .ext{display:none}}
</style></head><body>
<div class="nav"><div class="in"><span class="brand">Zugrav <span>Iași</span> CRM</span>
<?php foreach($nav as $n) echo '<a class="n '.($p===$n[0]||($p==='lead'&&$n[0]==='palnie')?'on':'').'" href="?p='.$n[0].'">'.h($n[1]).'</a>'; ?>
<span class="sp"></span><a class="ext" href="https://zugravimiasi.ro/" target="_blank">Vezi site-ul ↗</a><a class="out" href="?logout">Ieși</a></div></div>
<div class="wrap"><?php echo $body; ?></div></body></html>
<?php }

function shell_auth($title,$h1,$sub,$field,$ph,$btn,$err){ ?>
<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?php echo h($title); ?></title><style>
body{margin:0;background:#f4f5f7;font-family:system-ui,-apple-system,'Segoe UI',Arial,sans-serif}
.box{max-width:360px;margin:10vh auto 0;background:#fff;border:1px solid #e4e4e8;border-radius:16px;padding:32px 28px;box-shadow:0 20px 50px -30px rgba(0,0,0,.3)}
h1{font-size:1.3rem;margin:0 0 6px}p{color:#6b7280;margin:0 0 18px;font-size:.92rem}
input{width:100%;padding:12px 14px;border:1.5px solid #d1d5db;border-radius:10px;font:inherit;margin-bottom:12px}input:focus{outline:none;border-color:#f2681c}
.btn{width:100%;background:#ec5e0c;color:#fff;border:0;border-radius:10px;padding:12px;font:inherit;font-weight:700;cursor:pointer}
.err{background:#fee2e2;color:#b91c1c;padding:10px 12px;border-radius:8px;font-size:.9rem;margin-bottom:12px}
.brand{font-weight:800;text-align:center;margin-bottom:16px}.brand span{color:#f2681c}
</style></head><body><div class="box"><div class="brand">Zugrav <span>Iași</span> CRM</div><h1><?php echo h($h1); ?></h1><?php if($sub)echo '<p>'.h($sub).'</p>'; if($err)echo '<div class="err">'.h($err).'</div>'; ?>
<form method="post"><input type="password" name="<?php echo h($field); ?>" placeholder="<?php echo h($ph); ?>" required autofocus><button class="btn"><?php echo h($btn); ?></button></form></div></body></html>
<?php }
