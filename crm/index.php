<?php
session_start();
require_once __DIR__ . '/_shell.php';

$db   = crm_db();
$hash = crm_get($db, 'pass');

/* ---------- setare parola (prima accesare) ---------- */
if (!$hash) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['newpass'])) {
        if (strlen((string)$_POST['newpass']) >= 6) {
            crm_set($db, 'pass', password_hash($_POST['newpass'], PASSWORD_DEFAULT));
            header('Location: ?'); exit;
        }
        $err = 'Parola trebuie să aibă minim 6 caractere.';
    }
    shell_auth('Setează parola CRM', 'Prima accesare', 'Setează o parolă pentru CRM.', 'newpass', 'Parolă nouă (min. 6 caractere)', 'Salvează parola', $err);
    exit;
}

/* ---------- login ---------- */
if (empty($_SESSION['crm_ok'])) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pass'])) {
        if (password_verify((string)$_POST['pass'], $hash)) { $_SESSION['crm_ok'] = 1; header('Location: ?'); exit; }
        $err = 'Parolă greșită.';
    }
    shell_auth('Autentificare CRM', 'CRM Zugrav Iași', '', 'pass', 'Parolă', 'Intră', $err);
    exit;
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: ?'); exit; }

$ACUM = date('Y-m-d H:i:s');

/* ================= ACTIUNI POST ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // parola noua, din Setari
    if (isset($_POST['setpass'])) {
        if (strlen((string)$_POST['setpass']) >= 6) {
            crm_set($db, 'pass', password_hash($_POST['setpass'], PASSWORD_DEFAULT));
            header('Location: ?p=setari&ok=1'); exit;
        }
        header('Location: ?p=setari&scurt=1'); exit;
    }

    // lead adaugat manual (venit pe telefon, recomandare, etc.)
    if (isset($_POST['lead_nou'])) {
        $nume  = trim((string)($_POST['nume'] ?? ''));
        $tel   = trim((string)($_POST['telefon'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $mesaj = trim((string)($_POST['mesaj'] ?? ''));
        $snote = trim((string)($_POST['source_note'] ?? ''));
        $sursa = (string)($_POST['sursa'] ?? '');
        if (!in_array($sursa, CRM_SURSE_OFFLINE, true)) $sursa = 'Altă sursă';
        if ($nume === '' && $tel === '') { header('Location: ?p=nou&e=1'); exit; }
        $ins = $db->prepare("INSERT INTO leads
            (created,session_id,nume,telefon,email,cand,deviz,mesaj,entry_page,submit_page,button,source,device,ip,status,value,trashed,channel,source_note,quote_price)
            VALUES (?,'',?,?,?,'','',?,'','','',?,'','','Nou',0,0,'offline',?,0)");
        $ins->execute([$ACUM, $nume, $tel, $email, $mesaj, $sursa, $snote]);
        $nid = (int)$db->lastInsertId();
        crm_note($db, $nid, 'Lead adăugat manual.');
        header('Location: ?p=lead&id=' . $nid); exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $q = $db->prepare("SELECT * FROM leads WHERE id=?"); $q->execute([$id]);
        $lead = $q->fetch(PDO::FETCH_ASSOC);
        if ($lead) {

            // cos: mutare, restaurare, stergere definitiva
            if (isset($_POST['trash']))   { $db->prepare("UPDATE leads SET trashed=1 WHERE id=?")->execute([$id]); header('Location: ?p=palnie'); exit; }
            if (isset($_POST['restore'])) { $db->prepare("UPDATE leads SET trashed=0 WHERE id=?")->execute([$id]); header('Location: ?p=cos'); exit; }
            if (isset($_POST['delete']))  {
                $db->prepare("DELETE FROM leads WHERE id=?")->execute([$id]);
                $db->prepare("DELETE FROM notes WHERE lead_id=?")->execute([$id]);
                header('Location: ?p=cos'); exit;
            }

            // stare
            if (isset($_POST['status']) && in_array($_POST['status'], CRM_STATUSES, true)) {
                $ns = (string)$_POST['status'];
                if ($ns !== (string)$lead['status']) {
                    $db->prepare("UPDATE leads SET status=? WHERE id=?")->execute([$ns, $id]);
                    crm_note($db, $id, 'Stare schimbată în „' . $ns . '”.');
                }
                // prima iesire din "Nou" = momentul in care a fost luat in lucru
                if ($ns !== 'Nou' && trim((string)$lead['contacted_at']) === '') {
                    $db->prepare("UPDATE leads SET contacted_at=? WHERE id=?")->execute([$ACUM, $id]);
                }
            }

            // valoare estimata
            if (isset($_POST['value'])) {
                $nv = max(0, (int)$_POST['value']);
                if ($nv !== (int)$lead['value']) {
                    $db->prepare("UPDATE leads SET value=? WHERE id=?")->execute([$nv, $id]);
                    crm_note($db, $id, 'Valoare estimată: ' . crm_lei($nv) . '.');
                }
            }

            // notita libera
            if (isset($_POST['note']) && trim((string)$_POST['note']) !== '') {
                crm_note($db, $id, trim((string)$_POST['note']));
            }

            // urmarire (interes, vizionare, pret deviz, start lucrare, motiv pierdere)
            if (isset($_POST['followup'])) {
                $interes = (string)($_POST['interest'] ?? '');
                if ($interes !== '' && !in_array($interes, CRM_INTERES, true)) $interes = '';
                if ($interes !== (string)$lead['interest']) {
                    $db->prepare("UPDATE leads SET interest=? WHERE id=?")->execute([$interes, $id]);
                    crm_note($db, $id, $interes === '' ? 'Interesul a fost șters.' : 'Interes: ' . $interes . '.');
                }

                $vd = (string)($_POST['visit_at'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $vd)) $vd = '';
                $vt = (string)($_POST['visit_time'] ?? '');
                if (!preg_match('/^\d{2}:\d{2}$/', $vt)) $vt = '';
                if ($vd === '') $vt = '';
                if ($vd !== (string)$lead['visit_at'] || $vt !== (string)$lead['visit_time']) {
                    $db->prepare("UPDATE leads SET visit_at=?, visit_time=? WHERE id=?")->execute([$vd, $vt, $id]);
                    crm_note($db, $id, $vd === '' ? 'Vizionarea a fost ștearsă.' : 'Vizionare programată: ' . data_ro($vd, $vt) . '.');
                }

                if (isset($_POST['quote_price'])) {
                    $qp = max(0, (int)$_POST['quote_price']);
                    if ($qp !== (int)$lead['quote_price']) {
                        $db->prepare("UPDATE leads SET quote_price=? WHERE id=?")->execute([$qp, $id]);
                        crm_note($db, $id, $qp === 0 ? 'Prețul devizului a fost șters.' : 'Preț deviz: ' . crm_lei($qp) . '.');
                    }
                }

                $wd = (string)($_POST['work_starts_at'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $wd)) $wd = '';
                if ($wd !== (string)$lead['work_starts_at']) {
                    $db->prepare("UPDATE leads SET work_starts_at=? WHERE id=?")->execute([$wd, $id]);
                    crm_note($db, $id, $wd === '' ? 'Data de start a fost ștearsă.' : 'Start lucrare: ' . data_ro($wd) . '.');
                }

                if (isset($_POST['lost_reason'])) {
                    $lr = (string)$_POST['lost_reason'];
                    if ($lr !== '' && !in_array($lr, CRM_MOTIVE, true)) $lr = '';
                    if ($lr !== (string)$lead['lost_reason']) {
                        $db->prepare("UPDATE leads SET lost_reason=? WHERE id=?")->execute([$lr, $id]);
                        if ($lr !== '') crm_note($db, $id, 'Motivul pierderii: ' . $lr . '.');
                    }
                }
            }
        }
        header('Location: ?p=lead&id=' . $id); exit;
    }
    header('Location: ?'); exit;
}

/* ---------- export CSV ---------- */
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="lead-uri-zugravimiasi.csv"');
    echo "\xEF\xBB\xBF";
    $o = fopen('php://output', 'w');
    fputcsv($o, ['Data','Nume','Telefon','Email','Când','Deviz cerut','Mesaj','Stare','Interes','Valoare','Preț deviz','Vizionare','Start lucrare','Motiv pierdere','Sursă','Notă sursă','Canal','Pagina intrare','Buton','Dispozitiv']);
    foreach ($db->query("SELECT * FROM leads WHERE trashed=0 ORDER BY id DESC") as $r) {
        fputcsv($o, [
            $r['created'], $r['nume'], $r['telefon'], $r['email'], $r['cand'], $r['deviz'], $r['mesaj'],
            $r['status'], $r['interest'], $r['value'], $r['quote_price'],
            trim($r['visit_at'] . ' ' . $r['visit_time']), $r['work_starts_at'], $r['lost_reason'],
            $r['source'], $r['source_note'], $r['channel'], $r['entry_page'], $r['button'], $r['device'],
        ]);
    }
    fclose($o); exit;
}

$p = $_GET['p'] ?? 'panou';
// analiza s-a mutat in pagina de statistici
if ($p === 'analiza') { header('Location: /crm/statistici.php'); exit; }

$activ = ($p === 'lead' || $p === 'nou') ? 'palnie' : $p;
$titlu = 'CRM Zugrav Iași';
ob_start();

/* ================= PANOU ================= */
if ($p === 'panou') {
    $cnt = [];
    foreach ($db->query("SELECT status,COUNT(*) c FROM leads WHERE trashed=0 GROUP BY status") as $r) $cnt[$r['status']] = (int)$r['c'];

    $q = $db->prepare("SELECT COUNT(*) FROM leads WHERE trashed=0 AND created>=?");
    $q->execute([date('Y-m-01') . ' 00:00:00']);
    $luna = (int)$q->fetchColumn();

    $inlucru = ($cnt['Contactat'] ?? 0) + ($cnt['Ofertat'] ?? 0);
    $castig  = $cnt['Câștigat'] ?? 0;
    $pierdut = $cnt['Pierdut'] ?? 0;
    $rata    = ($castig + $pierdut) > 0 ? round($castig / ($castig + $pierdut) * 100) : 0;
    $valoare = (int)$db->query("SELECT COALESCE(SUM(value),0) FROM leads WHERE trashed=0 AND status='Câștigat'")->fetchColumn();
    $neatinse = (int)$db->query("SELECT COUNT(*) FROM leads WHERE trashed=0 AND status='Nou' AND (viewed_at IS NULL OR viewed_at='')")->fetchColumn();

    echo '<div class="kpis">';
    kpi('Lead-uri luna asta', $luna, '');
    kpi('Neatinse', $neatinse, 'nedeschise încă');
    kpi('În lucru', $inlucru, 'din ' . array_sum($cnt) . ' total');
    kpi('Rata de câștig', $rata . '%', $castig . ' câștigate, ' . $pierdut . ' pierdute');
    kpi('Valoare câștigată', crm_lei($valoare), '');
    echo '</div>';

    // viteza de reactie: de la primire pana la prima atingere, ultimele 30 de zile
    $q = $db->prepare("SELECT COUNT(*) n,
            AVG((julianday(contacted_at)-julianday(created))*1440) med,
            SUM(CASE WHEN (julianday(contacted_at)-julianday(created))*1440<=60 THEN 1 ELSE 0 END) sub
        FROM leads
        WHERE trashed=0 AND contacted_at IS NOT NULL AND contacted_at<>'' AND created>=?");
    $q->execute([date('Y-m-d 00:00:00', strtotime('-30 days'))]);
    $vr = $q->fetch(PDO::FETCH_ASSOC);
    $vn = (int)($vr['n'] ?? 0);

    // valoare in lucru: devizele trimise si neinchise
    $vl  = (int)$db->query("SELECT COALESCE(SUM(quote_price),0) FROM leads WHERE trashed=0 AND status='Ofertat'")->fetchColumn();
    $nof = (int)($cnt['Ofertat'] ?? 0);

    echo '<div class="grid2">';

    // ce urmeaza in urmatoarele 14 zile
    $azi = date('Y-m-d'); $lim = date('Y-m-d', strtotime('+14 days'));
    $q = $db->prepare("SELECT * FROM leads WHERE trashed=0 AND (
            (COALESCE(visit_at,'')<>'' AND visit_at>=? AND visit_at<=?)
            OR (COALESCE(work_starts_at,'')<>'' AND work_starts_at>=? AND work_starts_at<=?))");
    $q->execute([$azi, $lim, $azi, $lim]);
    $prog = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $nume = nume_lead($r);
        if (trim((string)$r['visit_at']) !== '' && $r['visit_at'] >= $azi && $r['visit_at'] <= $lim) {
            $prog[] = ['k' => $r['visit_at'] . ' ' . ($r['visit_time'] ?: '00:00'), 'id' => (int)$r['id'], 'nume' => $nume,
                       'ce' => 'Vizionare', 'cand' => data_ro($r['visit_at'], $r['visit_time']), 'st' => $r['status']];
        }
        if (trim((string)$r['work_starts_at']) !== '' && $r['work_starts_at'] >= $azi && $r['work_starts_at'] <= $lim) {
            $prog[] = ['k' => $r['work_starts_at'] . ' 00:00', 'id' => (int)$r['id'], 'nume' => $nume,
                       'ce' => 'Start lucrare', 'cand' => data_ro($r['work_starts_at']), 'st' => $r['status']];
        }
    }
    usort($prog, 'cmp_prog');
    echo '<div class="card"><h3>Urmează</h3><p class="muted" style="margin-top:-6px">Vizionări și lucrări programate în următoarele 14 zile.</p>';
    if (!$prog) echo '<p class="muted">Nimic programat deocamdată.</p>';
    foreach ($prog as $e) {
        echo '<a class="lrow" href="?p=lead&id=' . $e['id'] . '"><span class="badge s-' . crm_slug($e['st']) . '">' . crm_h($e['ce']) . '</span><b>' . crm_h($e['nume']) . '</b><span class="rmeta">' . crm_h($e['cand']) . '</span></a>';
    }
    echo '</div>';

    echo '<div>';
    echo '<div class="card"><h3>Viteza de reacție</h3>';
    if ($vn > 0) {
        $med = (float)$vr['med'];
        $pctsub = round(((int)$vr['sub']) / $vn * 100);
        echo '<div class="kpi-v">' . crm_h(minute_txt($med)) . '</div>';
        echo '<div class="kpi-s">' . $pctsub . '% dintre lead-uri au fost luate sub o oră</div>';
        echo '<p class="muted" style="margin:10px 0 0">Media pe ultimele 30 de zile, din ' . $vn . ' lead-uri luate în lucru.</p>';
    } else {
        echo '<div class="kpi-v">fără date</div><p class="muted" style="margin:10px 0 0">Se calculează din momentul în care scoți un lead din starea „Nou”.</p>';
    }
    echo '</div>';
    echo '<div class="card"><h3>Valoare în lucru</h3><div class="kpi-v">' . crm_h(crm_lei($vl)) . '</div>';
    echo '<div class="kpi-s">' . $nof . ' ' . ($nof === 1 ? 'ofertă trimisă' : 'oferte trimise') . '</div>';
    echo '<p class="muted" style="margin:10px 0 0">Suma devizelor de la lead-urile aflate în starea „Ofertat”.</p></div>';
    echo '</div></div>';

    echo '<div class="grid2">';
    echo '<div class="card"><h3>Ultimele cereri</h3>';
    $last = $db->query("SELECT * FROM leads WHERE trashed=0 ORDER BY id DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
    if (!$last) echo '<p class="muted">Niciun lead încă.</p>';
    foreach ($last as $r) {
        $nou = trim((string)$r['viewed_at']) === '' ? '<span class="dot"></span>' : '';
        echo '<a class="lrow" href="?p=lead&id=' . (int)$r['id'] . '"><span class="badge s-' . crm_slug($r['status']) . '">' . crm_h($r['status']) . '</span><b>' . $nou . crm_h(nume_lead($r)) . '</b><span class="rmeta">' . crm_h(de_unde($r)) . ' · ' . crm_h(crm_ago($r['created'])) . '</span></a>';
    }
    echo '<a class="more" href="?p=palnie">Vezi toată pâlnia →</a></div>';

    echo '<div class="card"><h3>Ce buton convertește</h3><p class="muted" style="margin-top:-6px">Din ce loc au pornit oamenii spre formular.</p>';
    $btns = $db->query("SELECT COALESCE(NULLIF(button,''),'A intrat direct pe formular') b, COUNT(*) c
        FROM leads WHERE trashed=0 AND COALESCE(channel,'')<>'offline' GROUP BY b ORDER BY c DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    if (!$btns) echo '<p class="muted">Încă nimic.</p>';
    foreach ($btns as $r) echo '<div class="brow"><span>' . crm_h($r['b']) . '</span><b>' . (int)$r['c'] . '</b></div>';
    echo '</div></div>';
}

/* ================= PALNIE ================= */
elseif ($p === 'palnie') {
    $titlu = 'Pâlnie · CRM Zugrav Iași';
    $f = $_GET['f'] ?? '';
    $cnt = [];
    foreach ($db->query("SELECT status,COUNT(*) c FROM leads WHERE trashed=0 GROUP BY status") as $r) $cnt[$r['status']] = (int)$r['c'];
    $tot = array_sum($cnt);

    echo '<div class="an-top"><div class="tabs" style="margin-bottom:0">';
    echo '<a class="tab ' . ($f === '' ? 'on' : '') . '" href="?p=palnie">Toate <span>' . $tot . '</span></a>';
    foreach (CRM_STATUSES as $s) echo '<a class="tab ' . ($f === $s ? 'on' : '') . '" href="?p=palnie&f=' . urlencode($s) . '">' . crm_h($s) . ' <span>' . (int)($cnt[$s] ?? 0) . '</span></a>';
    echo '</div><div style="display:flex;gap:8px"><a class="btn ghost sm" href="?export=csv">Descarcă CSV</a><a class="btn sm" href="?p=nou">+ Lead nou</a></div></div>';

    if ($f && in_array($f, CRM_STATUSES, true)) {
        $q = $db->prepare("SELECT * FROM leads WHERE trashed=0 AND status=? ORDER BY id DESC");
        $q->execute([$f]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = $db->query("SELECT * FROM leads WHERE trashed=0 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    }
    if (!$rows) echo '<div class="empty">Niciun lead aici încă.</div>';
    foreach ($rows as $r) {
        $necitit = trim((string)$r['viewed_at']) === '';
        $pret = (int)$r['quote_price'] > 0 ? (int)$r['quote_price'] : (int)$r['value'];
        echo '<a class="lead s-' . crm_slug($r['status']) . ($necitit ? ' nou-necitit' : '') . '" href="?p=lead&id=' . (int)$r['id'] . '">';
        echo '<div class="lead-l"><span class="badge s-' . crm_slug($r['status']) . '">' . crm_h($r['status']) . '</span><div>';
        echo '<div class="nm">' . ($necitit ? '<span class="dot"></span>' : '') . crm_h($r['nume'] ?: '(fără nume)') . '</div>';
        $sub = trim((string)$r['telefon']);
        if (trim((string)$r['cand']) !== '') $sub .= ($sub !== '' ? ' · ' : '') . $r['cand'];
        if (trim((string)$r['visit_at']) !== '') $sub .= ($sub !== '' ? ' · ' : '') . 'vizionare ' . data_ro($r['visit_at'], $r['visit_time']);
        echo '<div class="meta">' . crm_h($sub) . '</div></div></div>';
        echo '<div class="lead-r">' . ($pret > 0 ? '<b>' . crm_h(crm_lei($pret)) . '</b>' : '') . '<span class="rmeta">' . crm_h(de_unde($r)) . ' · ' . crm_h(crm_ago($r['created'])) . '</span></div></a>';
    }
}

/* ================= LEAD NOU (manual) ================= */
elseif ($p === 'nou') {
    $titlu = 'Lead nou · CRM Zugrav Iași';
    echo '<a class="back" href="?p=palnie">← Înapoi la pâlnie</a>';
    if (isset($_GET['e'])) echo '<div class="msg warn">Scrie măcar numele sau numărul de telefon.</div>';
    echo '<div class="card" style="max-width:560px"><h3>Lead nou</h3>';
    echo '<p class="muted" style="margin-top:-8px">Pentru cererile care nu vin de pe site: telefon, recomandare, mesaj pe Facebook.</p>';
    echo '<form method="post"><input type="hidden" name="lead_nou" value="1">';
    echo '<label class="fl">Nume</label><input type="text" name="nume" autofocus>';
    echo '<label class="fl">Telefon</label><input type="text" name="telefon">';
    echo '<label class="fl">Email</label><input type="email" name="email">';
    echo '<label class="fl">Ce vrea</label><textarea name="mesaj" style="min-height:90px" placeholder="Apartament 2 camere, zugrăvit complet, vrea în septembrie."></textarea>';
    echo '<label class="fl">De unde a venit</label><select name="sursa">';
    foreach (CRM_SURSE_OFFLINE as $s) echo '<option>' . crm_h($s) . '</option>';
    echo '</select>';
    echo '<label class="fl">Notă despre sursă</label><input type="text" name="source_note" placeholder="Recomandat de Ion Popescu">';
    echo '<button class="btn full">Salvează lead-ul</button></form></div>';
}

/* ================= FISA LEAD ================= */
elseif ($p === 'lead') {
    $id = (int)($_GET['id'] ?? 0);
    $q = $db->prepare("SELECT * FROM leads WHERE id=?"); $q->execute([$id]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        echo '<div class="empty">Lead inexistent.</div>';
    } else {
        // prima deschidere a fisei
        if (trim((string)$r['viewed_at']) === '') {
            $db->prepare("UPDATE leads SET viewed_at=? WHERE id=?")->execute([$ACUM, $id]);
            $r['viewed_at'] = $ACUM;
        }
        $nume  = nume_lead($r);
        $titlu = $nume . ' · CRM Zugrav Iași';
        $wa = preg_replace('/[^0-9]/', '', (string)$r['telefon']);
        if (strlen($wa) === 10 && $wa[0] === '0') $wa = '4' . $wa;

        $notes = $db->prepare("SELECT * FROM notes WHERE lead_id=? ORDER BY id DESC");
        $notes->execute([$id]);
        $notes = $notes->fetchAll(PDO::FETCH_ASSOC);

        $dv = $db->prepare("SELECT * FROM deviz WHERE lead_id=? ORDER BY id DESC LIMIT 1");
        $dv->execute([$id]);
        $dv = $dv->fetch(PDO::FETCH_ASSOC);
        $dvtot = 0;
        if ($dv) {
            $t = $db->prepare("SELECT COALESCE(SUM(cantitate*pret_um + material_cant*material_pret),0) FROM deviz_linii WHERE deviz_id=?");
            $t->execute([(int)$dv['id']]);
            $dvtot = (float)$t->fetchColumn();
        }

        echo '<a class="back" href="?p=palnie">← Înapoi la pâlnie</a>';
        echo '<div class="an-top"><div><h1>' . crm_h($nume) . '</h1><div class="muted" style="margin-top:4px">Etapa curentă: <b>' . crm_h($r['status']) . '</b>' . ($r['interest'] ? ' · ' . crm_h($r['interest']) : '') . '</div></div>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap">';
        if ($wa !== '') echo '<a class="btn" style="background:#25d366" href="https://api.whatsapp.com/send?phone=' . crm_h($wa) . '" target="_blank" rel="noopener">WhatsApp</a>';
        if (trim((string)$r['email']) !== '') echo '<a class="btn ghost" href="mailto:' . crm_h($r['email']) . '">Email</a>';
        echo '</div></div>';

        echo '<div class="grid2"><div>';

        // ---- contact ----
        echo '<div class="card"><h3>Contact</h3><table class="kv">';
        rnd('Telefon', '<b>' . crm_h($r['telefon'] ?: 'nu a lăsat număr') . '</b>');
        if (trim((string)$r['email']) !== '') rnd('Email', '<a href="mailto:' . crm_h($r['email']) . '">' . crm_h($r['email']) . '</a>');
        if (trim((string)$r['cand']) !== '') rnd('Când vrea lucrarea', crm_h($r['cand']));
        if (trim((string)$r['suprafata']) !== '') rnd('Suprafață', crm_h($r['suprafata']));
        if (trim((string)$r['deviz']) !== '') rnd('Deviz cerut', crm_h($r['deviz']));
        if (trim((string)$r['mesaj']) !== '') rnd('Detalii', nl2br(crm_h($r['mesaj'])));
        rnd('Primit', crm_h(date('d.m.Y H:i', strtotime($r['created']))) . ' (' . crm_h(crm_ago($r['created'])) . ')');
        if (trim((string)$r['contacted_at']) !== '') rnd('Luat în lucru', crm_h(date('d.m.Y H:i', strtotime($r['contacted_at']))));
        echo '</table></div>';

        // ---- urmarire ----
        echo '<div class="card"><h3>Urmărire</h3><form method="post"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="followup" value="1">';
        echo '<label class="fl">Interes</label><select name="interest"><option value="">(nespecificat)</option>';
        foreach (CRM_INTERES as $i) echo '<option' . ((string)$r['interest'] === $i ? ' selected' : '') . '>' . crm_h($i) . '</option>';
        echo '</select>';
        echo '<label class="fl">Programare vizionare</label><div class="inline"><input type="date" name="visit_at" value="' . crm_h($r['visit_at']) . '"><input type="time" name="visit_time" value="' . crm_h($r['visit_time']) . '"></div>';
        echo '<label class="fl">Preț deviz</label><div class="inline"><input type="number" name="quote_price" min="0" step="1" value="' . (int)$r['quote_price'] . '"><span>lei</span></div>';
        echo '<p class="muted" style="margin:6px 0 0">Se completează singur când faci devizul din modulul Devize.</p>';
        echo '<label class="fl">Start lucrare</label><input type="date" name="work_starts_at" value="' . crm_h($r['work_starts_at']) . '">';
        if ($r['status'] === 'Pierdut') {
            echo '<label class="fl">Motivul pierderii</label><select name="lost_reason"><option value="">(nespecificat)</option>';
            foreach (CRM_MOTIVE as $m) echo '<option' . ((string)$r['lost_reason'] === $m ? ' selected' : '') . '>' . crm_h($m) . '</option>';
            echo '</select>';
        }
        echo '<button class="btn full">Salvează urmărirea</button></form></div>';

        // ---- deviz ----
        echo '<div class="card"><h3>Deviz</h3>';
        if ($dv) {
            echo '<table class="kv">';
            rnd('Număr', '<b>' . crm_h($dv['numar'] ? '#' . (int)$dv['numar'] : '#' . (int)$dv['id']) . '</b>' . ($dv['titlu'] ? ' · ' . crm_h($dv['titlu']) : ''));
            rnd('Stare', '<span class="badge s-' . crm_slug($dv['status']) . '">' . crm_h($dv['status']) . '</span>');
            rnd('Total', '<b>' . crm_h(crm_lei($dvtot)) . '</b>');
            echo '</table><a class="btn sm ghost full" href="/crm/deviz.php?a=edit&id=' . (int)$dv['id'] . '">Deschide devizul</a>';
        } else {
            echo '<p class="muted" style="margin-top:-6px">Nu are încă niciun deviz.</p>';
            echo '<a class="btn full" href="/crm/deviz.php?a=nou&lead=' . $id . '">Fă un deviz pentru acest lead</a>';
        }
        echo '</div>';

        echo '</div><div>';

        // ---- gestionare + notite ----
        echo '<div class="card"><h3>Gestionare</h3><form method="post"><input type="hidden" name="id" value="' . $id . '">';
        echo '<label class="fl">Stare</label><select name="status" onchange="this.form.submit()">';
        foreach (CRM_STATUSES as $s) echo '<option' . ($r['status'] === $s ? ' selected' : '') . '>' . crm_h($s) . '</option>';
        echo '</select>';
        echo '<label class="fl">Valoare estimată</label><div class="inline"><input type="number" name="value" min="0" value="' . (int)$r['value'] . '"><span>lei</span><button class="btn sm">Salvează</button></div></form>';
        echo '<label class="fl">Istoric și notițe</label><form method="post"><input type="hidden" name="id" value="' . $id . '">';
        echo '<textarea name="note" style="min-height:70px" placeholder="Ce ai discutat, ce urmează..."></textarea>';
        echo '<button class="btn sm" style="margin-top:8px">Adaugă notiță</button></form>';
        echo '<div class="timeline">';
        foreach ($notes as $n) echo '<div class="tl"><div class="tl-t">' . crm_h(date('d.m.Y H:i', strtotime($n['created']))) . '</div><div class="tl-x">' . nl2br(crm_h($n['text'])) . '</div></div>';
        echo '</div>';
        echo '<form method="post" onsubmit="return confirm(\'Muți lead-ul în coș?\')"><input type="hidden" name="id" value="' . $id . '"><button class="btn ghost full" name="trash" value="1">Mută în coș</button></form>';
        echo '</div>';

        // ---- atribuire ----
        echo '<div class="card"><h3>Cum a ajuns aici</h3><table class="kv">';
        rnd('Pagina de intrare', '<b>' . crm_h($r['entry_page'] ?: 'necunoscută') . '</b>');
        rnd('A trimis de pe', crm_h($r['submit_page'] ?: 'necunoscută'));
        rnd('De unde a apăsat', crm_h($r['button'] ?: 'a intrat direct pe formular'));
        rnd('Sursă', crm_h($r['source'] ?: 'direct'));
        rnd('Canal', crm_h($r['channel'] ?: 'necunoscut'));
        if (trim((string)$r['source_note']) !== '') rnd('Notă sursă', crm_h($r['source_note']));
        rnd('Dispozitiv', crm_h($r['device'] ?: 'necunoscut'));
        echo '</table></div>';

        echo '</div></div>';
    }
}

/* ================= COS ================= */
elseif ($p === 'cos') {
    $titlu = 'Coș · CRM Zugrav Iași';
    $rows = $db->query("SELECT * FROM leads WHERE trashed=1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) echo '<div class="empty">Coșul e gol.</div>';
    foreach ($rows as $r) {
        echo '<div class="lead"><div class="lead-l"><div><div class="nm">' . crm_h($r['nume'] ?: $r['telefon']) . '</div><div class="meta">' . crm_h($r['telefon']) . ' · ' . crm_h(crm_ago($r['created'])) . '</div></div></div>';
        echo '<form method="post" style="display:flex;gap:8px"><input type="hidden" name="id" value="' . (int)$r['id'] . '">';
        echo '<button class="btn sm" name="restore" value="1">Restaurează</button>';
        echo '<button class="btn sm del" name="delete" value="1" onclick="return confirm(\'Ștergi definitiv?\')">Șterge definitiv</button></form></div>';
    }
}

/* ================= SETARI ================= */
elseif ($p === 'setari') {
    $titlu = 'Setări · CRM Zugrav Iași';
    if (isset($_GET['ok'])) echo '<div class="msg ok">Parola a fost schimbată.</div>';
    if (isset($_GET['scurt'])) echo '<div class="msg warn">Parola trebuie să aibă minim 6 caractere.</div>';
    echo '<div class="card" style="max-width:420px"><h3>Schimbă parola</h3><form method="post">';
    echo '<input type="password" name="setpass" placeholder="Parolă nouă (min. 6 caractere)" required class="fld">';
    echo '<button class="btn">Salvează</button></form></div>';
    echo '<div class="card" style="max-width:420px"><h3>Datele tale</h3>';
    echo '<p class="muted" style="margin-top:-6px">Toate lead-urile, în format pentru Excel.</p>';
    echo '<a class="btn ghost" href="?export=csv">Descarcă CSV</a></div>';
}

else {
    echo '<div class="empty">Pagină inexistentă.</div>';
}

$content = ob_get_clean();
crm_head($activ, $titlu);
echo $content;
crm_foot();

/* ---------- helperi de afisare ---------- */
function kpi($t, $v, $s) {
    echo '<div class="kpi"><div class="kpi-t">' . crm_h($t) . '</div><div class="kpi-v">' . crm_h($v) . '</div>' . ($s ? '<div class="kpi-s">' . crm_h($s) . '</div>' : '') . '</div>';
}
function rnd($k, $v) { echo '<tr><td class="k">' . crm_h($k) . '</td><td>' . $v . '</td></tr>'; }
function data_ro($d, $t = '') {
    $d = trim((string)$d);
    if ($d === '') return '';
    $x = date('d.m.Y', strtotime($d));
    $t = trim((string)$t);
    return $t !== '' ? $x . ', ora ' . $t : $x;
}
function minute_txt($m) {
    $m = (int)round((float)$m);
    if ($m < 1) return 'sub un minut';
    if ($m < 90) return $m . ' min';
    $h = $m / 60;
    if ($h < 48) return round($h) . ' h';
    return round($h / 24) . ' zile';
}
function nume_lead($r) {
    if (trim((string)$r['nume']) !== '') return trim((string)$r['nume']);
    if (trim((string)$r['telefon']) !== '') return trim((string)$r['telefon']);
    return '(fără nume)';
}
function de_unde($r) {
    if (trim((string)$r['entry_page']) !== '') return $r['entry_page'];
    if (trim((string)$r['source']) !== '') return $r['source'];
    return 'necunoscut';
}
function cmp_prog($a, $b) { return strcmp($a['k'], $b['k']); }

/* ---------- ecranul de autentificare (singurul stil local) ---------- */
function shell_auth($title, $h1, $sub, $field, $ph, $btn, $err) { ?>
<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?php echo crm_h($title); ?></title><style>
body{margin:0;background:#f4f5f7;font-family:system-ui,-apple-system,'Segoe UI',Arial,sans-serif}
.box{max-width:360px;margin:10vh auto 0;background:#fff;border:1px solid #e4e4e8;border-radius:16px;padding:32px 28px;box-shadow:0 20px 50px -30px rgba(0,0,0,.3)}
h1{font-size:1.3rem;margin:0 0 6px}p{color:#6b7280;margin:0 0 18px;font-size:.92rem}
input{width:100%;padding:12px 14px;border:1.5px solid #d1d5db;border-radius:10px;font:inherit;margin-bottom:12px}input:focus{outline:none;border-color:#f2681c}
.btn{width:100%;background:#ec5e0c;color:#fff;border:0;border-radius:10px;padding:12px;font:inherit;font-weight:700;cursor:pointer}
.err{background:#fee2e2;color:#b91c1c;padding:10px 12px;border-radius:8px;font-size:.9rem;margin-bottom:12px}
.brand{font-weight:800;text-align:center;margin-bottom:16px}.brand span{color:#f2681c}
</style></head><body><div class="box"><div class="brand">Zugrav <span>Iași</span> CRM</div><h1><?php echo crm_h($h1); ?></h1>
<?php if ($sub) echo '<p>' . crm_h($sub) . '</p>'; if ($err) echo '<div class="err">' . crm_h($err) . '</div>'; ?>
<form method="post"><input type="password" name="<?php echo crm_h($field); ?>" placeholder="<?php echo crm_h($ph); ?>" required autofocus><button class="btn"><?php echo crm_h($btn); ?></button></form></div></body></html>
<?php }
