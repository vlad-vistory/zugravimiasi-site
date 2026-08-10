<?php
require_once __DIR__ . '/_shell.php';
crm_require_login();

$db = crm_db();

/* ---------------- perioada ---------------- */
$zile = isset($_GET['d']) ? (int)$_GET['d'] : 30;
if (!in_array($zile, [7, 30, 90, 0], true)) $zile = 30;

$pana = date('Y-m-d H:i:s');
if ($zile > 0) {
    $de_la   = date('Y-m-d 00:00:00', strtotime('-' . $zile . ' days'));
    $a_de_la = date('Y-m-d 00:00:00', strtotime('-' . ($zile * 2) . ' days'));
    $a_pana  = $de_la;
} else {
    $de_la   = '0000-01-01 00:00:00';
    $a_de_la = null;
    $a_pana  = null;
}
$per = [$de_la, $pana];
$ant = $zile > 0 ? [$a_de_la, $a_pana] : null;

$luna_de_la = $zile > 0 ? substr($de_la, 0, 7) : '0000-00';
$luna_pana  = substr($pana, 0, 7);

$FILE = ['palnie', 'trafic', 'rezultate', 'cheltuieli'];
$f = (isset($_GET['f']) && in_array($_GET['f'], $FILE, true)) ? $_GET['f'] : 'palnie';

/* ---------------- helperi ---------------- */
function st_canale_spend() { return ['Google Ads', 'Meta Ads', 'TikTok', 'Altele']; }

function st_val($db, $sql, $args) {
    $s = $db->prepare($sql); $s->execute($args); $v = $s->fetchColumn();
    return $v === false ? 0 : $v;
}
function st_rows($db, $sql, $args) {
    $s = $db->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC);
}
function st_row($db, $sql, $args) {
    $s = $db->prepare($sql); $s->execute($args); $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ? $r : [];
}
function st_url($f, $d, $extra = '') {
    return '/crm/statistici.php?f=' . rawurlencode($f) . '&amp;d=' . (int)$d . $extra;
}
function st_pct($n, $baza) { return $baza > 0 ? round($n / $baza * 100) : 0; }
function st_proc($n, $baza, $zecimale = 1) {
    if ($baza <= 0) return '-';
    return number_format($n / $baza * 100, $zecimale, ',', '.') . '%';
}
function st_kpi($titlu, $valoare, $sub = '', $delta = '') {
    echo '<div class="kpi"><div class="kpi-t">' . crm_h($titlu) . '</div>';
    echo '<div class="kpi-v">' . crm_h($valoare) . '</div>';
    if ($sub !== '') echo '<div class="kpi-s">' . crm_h($sub) . '</div>';
    if ($delta !== '') echo $delta;
    echo '</div>';
}
// delta procentuala fata de perioada anterioara (doar acolo unde "mai mult" inseamna "mai bine")
function st_delta($acum, $inainte, $are_anterioara) {
    if (!$are_anterioara) return '';
    $acum = (float)$acum; $inainte = (float)$inainte;
    if ($inainte <= 0) {
        if ($acum <= 0) return '';
        return '<div class="kpi-d up">↑ nou față de perioada trecută</div>';
    }
    $p = (int)round(($acum - $inainte) / $inainte * 100);
    if ($p === 0) return '<div class="kpi-d">la fel ca perioada trecută</div>';
    $cls = $p > 0 ? 'up' : 'down';
    $sag = $p > 0 ? '↑' : '↓';
    $sem = $p > 0 ? '+' : '';
    return '<div class="kpi-d ' . $cls . '">' . $sag . ' ' . $sem . $p . '% față de perioada trecută</div>';
}
function st_fn($eticheta, $n, $baza, $culoare) {
    $p = st_pct($n, $baza);
    echo '<div class="fn"><div class="fn-h"><b>' . crm_h($eticheta) . '</b><span>' . (int)$n . ' · ' . $p . '%</span></div>';
    echo '<div class="fn-bar"><span style="width:' . $p . '%;background:' . $culoare . '"></span></div></div>';
}
function st_drop($de_la_n, $la_n) {
    if ($de_la_n <= 0) return;
    $d = $de_la_n - $la_n;
    if ($d <= 0) return;
    echo '<div class="drop">↓ ' . (int)$d . ' au renunțat aici (' . st_pct($d, $de_la_n) . '%)</div>';
}
function st_dispozitiv($d) {
    $d = mb_strtolower(trim((string)$d), 'UTF-8');
    if ($d === '') return 'Nespecificat';
    if (strpos($d, 'tab') !== false) return 'Tabletă';
    if (strpos($d, 'mob') !== false || strpos($d, 'phone') !== false || strpos($d, 'telefon') !== false) return 'Mobil';
    return 'Desktop';
}
function st_canal($c) {
    $c = trim((string)$c);
    if ($c === '') return 'necunoscut';
    $map = [
        'google-ads' => 'Google Ads', 'googleads' => 'Google Ads', 'gads' => 'Google Ads',
        'meta-ads' => 'Meta Ads', 'facebook-ads' => 'Meta Ads', 'fbads' => 'Meta Ads',
        'tiktok-ads' => 'TikTok Ads', 'tiktok' => 'TikTok',
        'google' => 'Google (căutare)', 'bing' => 'Bing', 'facebook' => 'Facebook',
        'instagram' => 'Instagram', 'direct' => 'Direct', 'intern' => 'Din site',
    ];
    $k = mb_strtolower($c, 'UTF-8');
    return isset($map[$k]) ? $map[$k] : $c;
}
function st_camp($c) {
    $map = [
        'nume' => 'Nume', 'telefon' => 'Telefon', 'email' => 'Email',
        'cand' => 'Când vrea lucrarea', 'deviz' => 'Tip deviz', 'mesaj' => 'Detalii',
        'suprafata' => 'Suprafață', 'adresa' => 'Adresă',
    ];
    $k = mb_strtolower(trim((string)$c), 'UTF-8');
    return isset($map[$k]) ? $map[$k] : $c;
}
function st_fel_click($k) {
    $map = ['whatsapp' => 'WhatsApp', 'wa' => 'WhatsApp', 'email' => 'Email', 'mail' => 'Email', 'telefon' => 'Telefon', 'tel' => 'Telefon'];
    $x = mb_strtolower(trim((string)$k), 'UTF-8');
    return isset($map[$x]) ? $map[$x] : ($x === '' ? 'nespecificat' : $k);
}
function st_luna($l) {
    $luni = ['01' => 'ianuarie', '02' => 'februarie', '03' => 'martie', '04' => 'aprilie', '05' => 'mai', '06' => 'iunie',
             '07' => 'iulie', '08' => 'august', '09' => 'septembrie', '10' => 'octombrie', '11' => 'noiembrie', '12' => 'decembrie'];
    if (!preg_match('/^(\d{4})-(\d{2})$/', (string)$l, $m)) return (string)$l;
    return (isset($luni[$m[2]]) ? $luni[$m[2]] : $m[2]) . ' ' . $m[1];
}
function st_minute($m) {
    $m = (int)round($m);
    if ($m < 60) return $m . ' min';
    if ($m < 1440) return round($m / 60, 1) . ' ore';
    return round($m / 1440, 1) . ' zile';
}
function st_tabel_gol($text) { echo '<div class="empty">' . crm_h($text) . '</div>'; }

/* ---------------- cheltuieli: adaugare / stergere ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inapoi = '/crm/statistici.php?f=cheltuieli&d=' . $zile;
    if (isset($_POST['adauga_spend'])) {
        $luna = trim((string)($_POST['luna'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $luna)) $luna = date('Y-m');
        $canal = trim((string)($_POST['canal'] ?? ''));
        if (!in_array($canal, st_canale_spend(), true)) $canal = 'Altele';
        $suma = (int)round((float)str_replace(',', '.', (string)($_POST['suma'] ?? '0')));
        if ($suma > 0) {
            $db->prepare("INSERT INTO ad_spend (created,luna,channel,suma) VALUES (?,?,?,?)")
               ->execute([date('Y-m-d H:i:s'), $luna, $canal, $suma]);
            $inapoi .= '&ok=1';
        } else {
            $inapoi .= '&err=1';
        }
    } elseif (isset($_POST['sterge_spend'])) {
        $sid = (int)$_POST['sterge_spend'];
        if ($sid > 0) {
            $db->prepare("DELETE FROM ad_spend WHERE id=?")->execute([$sid]);
            $inapoi .= '&sters=1';
        }
    }
    header('Location: ' . $inapoi);
    exit;
}

/* ---------------- export CSV ---------------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = st_rows($db, "SELECT * FROM leads WHERE trashed=0 AND created>=? AND created<=? ORDER BY id DESC", $per);
    $nume_fis = 'lead-uri-zugravimiasi-' . ($zile > 0 ? $zile . 'zile' : 'tot') . '-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nume_fis . '"');
    echo "\xEF\xBB\xBF";
    $o = fopen('php://output', 'w');
    fputcsv($o, ['Data', 'Nume', 'Telefon', 'Email', 'Cand', 'Deviz', 'Status', 'Interes', 'Valoare deviz',
                 'Sursa', 'Canal', 'Pagina intrare', 'Buton', 'Dispozitiv', 'Motiv pierdere', 'Contactat in (minute)'], ';');
    foreach ($rows as $r) {
        $val = ((int)($r['quote_price'] ?? 0) > 0) ? (int)$r['quote_price'] : (int)($r['value'] ?? 0);
        $min = '';
        if (!empty($r['contacted_at'])) {
            $d = strtotime($r['contacted_at']) - strtotime($r['created']);
            if ($d >= 0) $min = (int)round($d / 60);
        }
        fputcsv($o, [
            $r['created'], $r['nume'], $r['telefon'], $r['email'], $r['cand'], $r['deviz'],
            $r['status'], $r['interest'] ?? '', $val,
            $r['source'], $r['channel'] ?? '', $r['entry_page'], $r['button'], $r['device'],
            $r['lost_reason'] ?? '', $min,
        ], ';');
    }
    fclose($o);
    exit;
}

/* ---------------- antet ---------------- */
$titluri = ['palnie' => 'Pâlnia formularului', 'trafic' => 'Trafic și canale', 'rezultate' => 'Rezultate și bani', 'cheltuieli' => 'Cheltuieli de promovare'];
crm_head('statistici', 'Analiză · CRM Zugrav Iași');

$perioade = [7 => '7 zile', 30 => '30 zile', 90 => '90 zile', 0 => 'Tot timpul'];
echo '<div class="an-top"><div class="dfilt">';
foreach ($perioade as $k => $lab) {
    echo '<a class="tab' . ($zile === $k ? ' on' : '') . '" href="' . st_url($f, $k) . '">' . crm_h($lab) . '</a>';
}
echo '</div><a class="btn ghost sm" href="' . st_url($f, $zile, '&amp;export=csv') . '">Descarcă CSV</a></div>';

echo '<div class="tabs">';
foreach ($FILE as $x) {
    echo '<a class="tab' . ($f === $x ? ' on' : '') . '" href="' . st_url($x, $zile) . '">' . crm_h($titluri[$x]) . '</a>';
}
echo '</div>';

$are_ant = ($ant !== null);

/* ================================================================
   1. PALNIA FORMULARULUI
   ================================================================ */
if ($f === 'palnie') {

    $vazut  = (int)st_val($db, "SELECT COUNT(DISTINCT session_id) FROM events WHERE type=? AND created>=? AND created<=?", array_merge(['form_view'], $per));
    $inceput = (int)st_val($db, "SELECT COUNT(DISTINCT session_id) FROM events WHERE type=? AND created>=? AND created<=?", array_merge(['form_start'], $per));
    $trimis = (int)st_val($db, "SELECT COUNT(*) FROM leads WHERE trashed=0 AND created>=? AND created<=?", $per);

    $a_vazut = $a_inceput = $a_trimis = 0;
    if ($are_ant) {
        $a_vazut   = (int)st_val($db, "SELECT COUNT(DISTINCT session_id) FROM events WHERE type=? AND created>=? AND created<=?", array_merge(['form_view'], $ant));
        $a_inceput = (int)st_val($db, "SELECT COUNT(DISTINCT session_id) FROM events WHERE type=? AND created>=? AND created<=?", array_merge(['form_start'], $ant));
        $a_trimis  = (int)st_val($db, "SELECT COUNT(*) FROM leads WHERE trashed=0 AND created>=? AND created<=?", $ant);
    }

    echo '<div class="kpis" style="grid-template-columns:repeat(4,1fr)">';
    st_kpi('A văzut formularul', $vazut, 'vizitatori diferiți', st_delta($vazut, $a_vazut, $are_ant));
    st_kpi('A început să scrie', $inceput, 'a atins primul câmp', st_delta($inceput, $a_inceput, $are_ant));
    st_kpi('A trimis cererea', $trimis, 'lead-uri intrate', st_delta($trimis, $a_trimis, $are_ant));
    st_kpi('Din văzut în trimis', st_pct($trimis, $vazut) . '%', 'rata formularului', st_delta(st_pct($trimis, $vazut), st_pct($a_trimis, $a_vazut), $are_ant));
    echo '</div>';

    $baza = max($vazut, $inceput, $trimis, 1);
    echo '<div class="card"><h3>Pâlnia formularului</h3>';
    echo '<p class="muted" style="margin-top:-8px">Câți au ajuns la formular, câți au început să completeze, câți au apăsat pe trimite.</p>';
    if ($vazut === 0 && $inceput === 0 && $trimis === 0) {
        st_tabel_gol('Nu sunt date în perioada asta. Pâlnia se umple pe măsură ce oamenii ajung pe pagina cu formularul.');
    } else {
        st_fn('1. A văzut formularul', $vazut, $baza, '#f9c9a8');
        st_drop($vazut, $inceput);
        st_fn('2. A început să completeze', $inceput, $baza, '#f2861c');
        st_drop($inceput, $trimis);
        st_fn('3. A trimis cererea', $trimis, $baza, '#15803d');
    }
    echo '</div>';

    /* --- defalcare pe dispozitiv --- */
    $dv = []; $ds = []; $dl = []; $chei = [];
    foreach (st_rows($db, "SELECT device d, COUNT(DISTINCT session_id) c FROM events WHERE type=? AND created>=? AND created<=? GROUP BY d", array_merge(['form_view'], $per)) as $r) {
        $k = st_dispozitiv($r['d']); $dv[$k] = (isset($dv[$k]) ? $dv[$k] : 0) + (int)$r['c']; $chei[$k] = 1;
    }
    foreach (st_rows($db, "SELECT device d, COUNT(DISTINCT session_id) c FROM events WHERE type=? AND created>=? AND created<=? GROUP BY d", array_merge(['form_start'], $per)) as $r) {
        $k = st_dispozitiv($r['d']); $ds[$k] = (isset($ds[$k]) ? $ds[$k] : 0) + (int)$r['c']; $chei[$k] = 1;
    }
    foreach (st_rows($db, "SELECT device d, COUNT(*) c FROM leads WHERE trashed=0 AND created>=? AND created<=? GROUP BY d", $per) as $r) {
        $k = st_dispozitiv($r['d']); $dl[$k] = (isset($dl[$k]) ? $dl[$k] : 0) + (int)$r['c']; $chei[$k] = 1;
    }

    echo '<div class="card"><h3>Mobil față de desktop</h3>';
    echo '<p class="muted" style="margin-top:-8px">Dacă pe mobil rata e mult mai mică, formularul e greu de completat cu degetul, nu lipsesc oamenii.</p>';
    if (!$chei) {
        st_tabel_gol('Încă nu sunt vizite înregistrate pe dispozitive în perioada asta.');
    } else {
        $ordine = array_keys($chei);
        usort($ordine, function ($a, $b) use ($dv, $dl) {
            $ta = (isset($dv[$a]) ? $dv[$a] : 0) + (isset($dl[$a]) ? $dl[$a] : 0);
            $tb = (isset($dv[$b]) ? $dv[$b] : 0) + (isset($dl[$b]) ? $dl[$b] : 0);
            return $tb - $ta;
        });
        echo '<table class="an"><tr><th>Dispozitiv</th><th class="n">A văzut</th><th class="n">A început</th><th class="n">A trimis</th><th class="n">Rată</th></tr>';
        foreach ($ordine as $k) {
            $v = isset($dv[$k]) ? $dv[$k] : 0;
            $s = isset($ds[$k]) ? $ds[$k] : 0;
            $l = isset($dl[$k]) ? $dl[$k] : 0;
            echo '<tr><td>' . crm_h($k) . '</td>';
            echo '<td class="n">' . ($v ? $v : '-') . '</td>';
            echo '<td class="n">' . ($s ? $s : '-') . '</td>';
            echo '<td class="n">' . ($l ? $l : '-') . '</td>';
            echo '<td class="n">' . ($v > 0 ? st_proc($l, $v) : '-') . '</td></tr>';
        }
        echo '</table>';
        if (count($dv) === 1 && isset($dv['Nespecificat'])) {
            echo '<p class="muted" style="margin-top:12px">Pașii dinaintea trimiterii nu au încă dispozitivul salvat, așa că apar la „Nespecificat”. Se completează de la sine pe măsură ce vin vizitatori noi.</p>';
        }
    }
    echo '</div>';

    /* --- unde se opresc oamenii --- */
    $campuri = st_rows($db, "SELECT field f, COUNT(DISTINCT session_id) c FROM events WHERE field IS NOT NULL AND field<>'' AND created>=? AND created<=? GROUP BY f ORDER BY c DESC LIMIT 10", $per);
    echo '<div class="card"><h3>Unde se opresc oamenii</h3>';
    echo '<p class="muted" style="margin-top:-8px">Ultimul câmp atins înainte de a pleca. Câmpul care apare cel mai des e cel de scos sau de simplificat.</p>';
    if (!$campuri) {
        echo '<div class="empty">Nu sunt încă date pe câmpuri. Urmărirea pe câmpuri se populează pe măsură ce vin vizitatori și ating formularul.</div>';
    } else {
        $tot_c = 0;
        foreach ($campuri as $r) $tot_c += (int)$r['c'];
        echo '<table class="an"><tr><th>Câmp</th><th class="n">Persoane</th><th class="n">Din total</th></tr>';
        foreach ($campuri as $r) {
            echo '<tr><td>' . crm_h(st_camp($r['f'])) . '</td><td class="n">' . (int)$r['c'] . '</td><td class="n">' . st_proc((int)$r['c'], $tot_c, 0) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';
}

/* ================================================================
   2. TRAFIC SI CANALE
   ================================================================ */
elseif ($f === 'trafic') {

    $t = st_row($db, "SELECT COUNT(*) vizite, COUNT(DISTINCT visitor) unici FROM page_views WHERE created>=? AND created<=?", $per);
    $vizite = (int)(isset($t['vizite']) ? $t['vizite'] : 0);
    $unici  = (int)(isset($t['unici']) ? $t['unici'] : 0);
    $lead_tot = (int)st_val($db, "SELECT COUNT(*) FROM leads WHERE trashed=0 AND created>=? AND created<=?", $per);

    $a_vizite = $a_unici = $a_lead = 0;
    if ($are_ant) {
        $ta = st_row($db, "SELECT COUNT(*) vizite, COUNT(DISTINCT visitor) unici FROM page_views WHERE created>=? AND created<=?", $ant);
        $a_vizite = (int)(isset($ta['vizite']) ? $ta['vizite'] : 0);
        $a_unici  = (int)(isset($ta['unici']) ? $ta['unici'] : 0);
        $a_lead   = (int)st_val($db, "SELECT COUNT(*) FROM leads WHERE trashed=0 AND created>=? AND created<=?", $ant);
    }

    echo '<div class="kpis" style="grid-template-columns:repeat(4,1fr)">';
    st_kpi('Vizite', $vizite, 'pagini deschise', st_delta($vizite, $a_vizite, $are_ant));
    st_kpi('Vizitatori', $unici, 'oameni diferiți pe zi', st_delta($unici, $a_unici, $are_ant));
    st_kpi('Lead-uri', $lead_tot, 'cereri din formular', st_delta($lead_tot, $a_lead, $are_ant));
    st_kpi('Rata de conversie', st_proc($lead_tot, $unici), 'din vizitatori în cereri', st_delta(st_pct($lead_tot, $unici), st_pct($a_lead, $a_unici), $are_ant));
    echo '</div>';

    if ($vizite === 0) {
        echo '<div class="card"><h3>Trafic</h3><div class="empty">Nu sunt vizite înregistrate în perioada asta. Numărătoarea pornește când scriptul de urmărire rulează pe paginile site-ului.</div></div>';
    }

    /* --- canale --- */
    $pv_can = []; $lead_can = []; $chei = [];
    foreach (st_rows($db, "SELECT channel c, COUNT(*) vizite, COUNT(DISTINCT visitor) unici FROM page_views WHERE created>=? AND created<=? GROUP BY c", $per) as $r) {
        $k = trim((string)$r['c']);
        $pv_can[$k] = ['vizite' => (int)$r['vizite'], 'unici' => (int)$r['unici']];
        $chei[$k] = 1;
    }
    foreach (st_rows($db, "SELECT channel c, COUNT(*) n FROM leads WHERE trashed=0 AND created>=? AND created<=? GROUP BY c", $per) as $r) {
        $k = trim((string)$r['c']);
        $lead_can[$k] = (isset($lead_can[$k]) ? $lead_can[$k] : 0) + (int)$r['n'];
        $chei[$k] = 1;
    }
    echo '<div class="card"><h3>Canale</h3>';
    echo '<p class="muted" style="margin-top:-8px">Unde se transformă traficul în cereri. Un canal cu mulți vizitatori și zero cereri costă bani degeaba.</p>';
    if (!$chei) {
        st_tabel_gol('Încă nu sunt canale înregistrate în perioada asta.');
    } else {
        $ordine = array_keys($chei);
        usort($ordine, function ($a, $b) use ($pv_can, $lead_can) {
            $ua = isset($pv_can[$a]['unici']) ? $pv_can[$a]['unici'] : 0;
            $ub = isset($pv_can[$b]['unici']) ? $pv_can[$b]['unici'] : 0;
            if ($ua === $ub) {
                return (isset($lead_can[$b]) ? $lead_can[$b] : 0) - (isset($lead_can[$a]) ? $lead_can[$a] : 0);
            }
            return $ub - $ua;
        });
        echo '<table class="an"><tr><th>Canal</th><th class="n">Vizitatori</th><th class="n">Lead-uri</th><th class="n">Conversie</th></tr>';
        foreach ($ordine as $k) {
            $u = isset($pv_can[$k]['unici']) ? $pv_can[$k]['unici'] : 0;
            $l = isset($lead_can[$k]) ? $lead_can[$k] : 0;
            echo '<tr><td>' . crm_h(st_canal($k)) . '</td>';
            echo '<td class="n">' . ($u ? $u : '-') . '</td>';
            echo '<td class="n">' . $l . '</td>';
            echo '<td class="n">' . ($u > 0 ? st_proc($l, $u) : '-') . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    echo '<div class="grid2">';

    /* --- dispozitiv --- */
    echo '<div class="card"><h3>Trafic pe dispozitiv</h3>';
    $pv_dev = []; $tot_pv_dev = 0;
    foreach (st_rows($db, "SELECT device d, COUNT(*) vizite, COUNT(DISTINCT visitor) unici FROM page_views WHERE created>=? AND created<=? GROUP BY d", $per) as $r) {
        $k = st_dispozitiv($r['d']);
        if (!isset($pv_dev[$k])) $pv_dev[$k] = ['vizite' => 0, 'unici' => 0];
        $pv_dev[$k]['vizite'] += (int)$r['vizite'];
        $pv_dev[$k]['unici']  += (int)$r['unici'];
        $tot_pv_dev += (int)$r['vizite'];
    }
    if (!$pv_dev) {
        st_tabel_gol('Nimic de arătat încă.');
    } else {
        echo '<table class="an"><tr><th>Dispozitiv</th><th class="n">Vizite</th><th class="n">Vizitatori</th><th class="n">Din trafic</th></tr>';
        foreach ($pv_dev as $k => $v) {
            echo '<tr><td>' . crm_h($k) . '</td><td class="n">' . $v['vizite'] . '</td><td class="n">' . $v['unici'] . '</td><td class="n">' . st_proc($v['vizite'], $tot_pv_dev, 0) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    /* --- pagini de intrare care aduc lead-uri --- */
    echo '<div class="card"><h3>Pagini care aduc cereri</h3>';
    $intrari = st_rows($db, "SELECT COALESCE(NULLIF(entry_page,''),'necunoscută') p, COUNT(*) c FROM leads WHERE trashed=0 AND created>=? AND created<=? GROUP BY p ORDER BY c DESC LIMIT 10", $per);
    if (!$intrari) {
        st_tabel_gol('Niciun lead în perioada asta.');
    } else {
        echo '<table class="an"><tr><th>Pagina de intrare</th><th class="n">Lead-uri</th></tr>';
        foreach ($intrari as $r) echo '<tr><td>' . crm_h($r['p']) . '</td><td class="n">' . (int)$r['c'] . '</td></tr>';
        echo '</table>';
    }
    echo '</div>';
    echo '</div>';

    /* --- top pagini --- */
    echo '<div class="card"><h3>Cele mai vizitate pagini</h3>';
    $pagini = st_rows($db, "SELECT COALESCE(NULLIF(page,''),'/') p, COUNT(*) vizite, COUNT(DISTINCT visitor) unici FROM page_views WHERE created>=? AND created<=? GROUP BY p ORDER BY vizite DESC LIMIT 15", $per);
    if (!$pagini) {
        st_tabel_gol('Nu sunt vizite înregistrate în perioada asta.');
    } else {
        echo '<table class="an"><tr><th>Pagină</th><th class="n">Vizite</th><th class="n">Vizitatori</th></tr>';
        foreach ($pagini as $r) {
            echo '<tr><td>' . crm_h($r['p']) . '</td><td class="n">' . (int)$r['vizite'] . '</td><td class="n">' . (int)$r['unici'] . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    /* --- click-uri pe WhatsApp / email --- */
    echo '<div class="card"><h3>Click-uri pe WhatsApp, email și telefon</h3>';
    echo '<p class="muted" style="margin-top:-8px">Oamenii ăștia nu au trecut prin formular, deci nu intră în rata de conversie a formularului. Sunt cereri separate, care ajung direct pe telefon.</p>';
    $cl_fel = st_rows($db, "SELECT kind k, COUNT(*) c FROM contact_clicks WHERE created>=? AND created<=? GROUP BY k ORDER BY c DESC", $per);
    if (!$cl_fel) {
        st_tabel_gol('Niciun click înregistrat în perioada asta.');
    } else {
        echo '<table class="an"><tr><th>Fel</th><th class="n">Click-uri</th></tr>';
        foreach ($cl_fel as $r) echo '<tr><td>' . crm_h(st_fel_click($r['k'])) . '</td><td class="n">' . (int)$r['c'] . '</td></tr>';
        echo '</table>';
        $cl_pag = st_rows($db, "SELECT COALESCE(NULLIF(page,''),'/') p, kind k, COUNT(*) c FROM contact_clicks WHERE created>=? AND created<=? GROUP BY p, k ORDER BY c DESC LIMIT 12", $per);
        if ($cl_pag) {
            echo '<h3 style="margin-top:22px">Pe ce pagini se apasă</h3><table class="an"><tr><th>Pagină</th><th>Fel</th><th class="n">Click-uri</th></tr>';
            foreach ($cl_pag as $r) {
                echo '<tr><td>' . crm_h($r['p']) . '</td><td>' . crm_h(st_fel_click($r['k'])) . '</td><td class="n">' . (int)$r['c'] . '</td></tr>';
            }
            echo '</table>';
        }
    }
    echo '</div>';

    /* --- butoane vazute vs apasate --- */
    echo '<div class="card"><h3>Butonul de ofertă: văzut față de folosit</h3>';
    echo '<p class="muted" style="margin-top:-8px">Dacă butonul e văzut de mulți și cererile sunt puține, problema e la buton sau la ofertă, nu la trafic. Dacă e văzut de puțini, problema e că e prea jos în pagină.</p>';
    $cta = st_rows($db, "SELECT COALESCE(NULLIF(page,''),'/') p, COUNT(DISTINCT session_id) c FROM cta_views WHERE created>=? AND created<=? GROUP BY p ORDER BY c DESC LIMIT 12", $per);
    if (!$cta) {
        st_tabel_gol('Încă nu s-a înregistrat nicio afișare de buton în perioada asta.');
    } else {
        $lead_pag = [];
        foreach (st_rows($db, "SELECT COALESCE(NULLIF(entry_page,''),'/') p, COUNT(*) c FROM leads WHERE trashed=0 AND created>=? AND created<=? GROUP BY p", $per) as $r) {
            $lead_pag[$r['p']] = (int)$r['c'];
        }
        echo '<table class="an"><tr><th>Pagină</th><th class="n">Au văzut butonul</th><th class="n">Lead-uri de aici</th><th class="n">Rată</th></tr>';
        foreach ($cta as $r) {
            $p = $r['p'];
            $v = (int)$r['c'];
            $l = isset($lead_pag[$p]) ? $lead_pag[$p] : 0;
            echo '<tr><td>' . crm_h($p) . '</td><td class="n">' . $v . '</td><td class="n">' . $l . '</td><td class="n">' . st_proc($l, $v) . '</td></tr>';
        }
        echo '</table>';
        echo '<p class="muted" style="margin-top:12px">Lead-urile sunt numărate după pagina pe care a intrat omul, deci cifrele se citesc ca tendință, nu la virgulă.</p>';
    }
    echo '</div>';
}

/* ================================================================
   3. REZULTATE SI BANI
   ================================================================ */
elseif ($f === 'rezultate') {

    $sql_sumar = "SELECT COUNT(*) n,
        SUM(CASE WHEN status='Câștigat' THEN 1 ELSE 0 END) castigate,
        SUM(CASE WHEN status='Pierdut' THEN 1 ELSE 0 END) pierdute,
        SUM(CASE WHEN status IN ('Ofertat','Câștigat','Pierdut') THEN (CASE WHEN quote_price>0 THEN quote_price ELSE value END) ELSE 0 END) val_ofertata,
        SUM(CASE WHEN status='Câștigat' THEN (CASE WHEN quote_price>0 THEN quote_price ELSE value END) ELSE 0 END) val_castigata
        FROM leads WHERE trashed=0 AND created>=? AND created<=?";
    $s = st_row($db, $sql_sumar, $per);
    $n_lead   = (int)(isset($s['n']) ? $s['n'] : 0);
    $castigate = (int)(isset($s['castigate']) ? $s['castigate'] : 0);
    $pierdute  = (int)(isset($s['pierdute']) ? $s['pierdute'] : 0);
    $val_of    = (float)(isset($s['val_ofertata']) ? $s['val_ofertata'] : 0);
    $val_cas   = (float)(isset($s['val_castigata']) ? $s['val_castigata'] : 0);
    $decise    = $castigate + $pierdute;
    $medie     = $castigate > 0 ? $val_cas / $castigate : 0;

    $a_n = $a_cas = $a_pier = 0; $a_val_cas = 0;
    if ($are_ant) {
        $sa = st_row($db, $sql_sumar, $ant);
        $a_n       = (int)(isset($sa['n']) ? $sa['n'] : 0);
        $a_cas     = (int)(isset($sa['castigate']) ? $sa['castigate'] : 0);
        $a_pier    = (int)(isset($sa['pierdute']) ? $sa['pierdute'] : 0);
        $a_val_cas = (float)(isset($sa['val_castigata']) ? $sa['val_castigata'] : 0);
    }
    $a_decise = $a_cas + $a_pier;

    echo '<div class="kpis">';
    st_kpi('Lead-uri', $n_lead, 'cereri intrate', st_delta($n_lead, $a_n, $are_ant));
    st_kpi('Rata de câștig', st_pct($castigate, $decise) . '%', $castigate . ' din ' . $decise . ' decise', st_delta(st_pct($castigate, $decise), st_pct($a_cas, $a_decise), $are_ant));
    st_kpi('Valoare ofertată', crm_lei($val_of), 'devize trimise');
    st_kpi('Valoare câștigată', crm_lei($val_cas), $castigate . ' lucrări', st_delta($val_cas, $a_val_cas, $are_ant));
    st_kpi('Media pe lucrare', crm_lei($medie), 'la lucrările câștigate');
    echo '</div>';

    /* --- viteza de reactie --- */
    $vr = st_row($db, "SELECT COUNT(*) n,
            AVG((julianday(contacted_at)-julianday(created))*1440) medie,
            SUM(CASE WHEN (julianday(contacted_at)-julianday(created))*1440 <= 60 THEN 1 ELSE 0 END) sub_ora
            FROM leads WHERE trashed=0 AND contacted_at IS NOT NULL AND contacted_at<>'' AND created>=? AND created<=?", $per);
    $vr_n = (int)(isset($vr['n']) ? $vr['n'] : 0);

    echo '<div class="card"><h3>Viteza de reacție</h3>';
    echo '<p class="muted" style="margin-top:-8px">Cât trece de la cererea primită până la primul contact. E cel mai bun semn dinainte că lucrarea se ia sau se pierde: cine sună primul, de obicei ia lucrarea.</p>';
    if ($vr_n === 0) {
        st_tabel_gol('Niciun lead contactat în perioada asta, sau ora contactului nu a fost salvată. Se completează singură când muți lead-ul din „Nou” în „Contactat”.');
    } else {
        $medie_min = (float)(isset($vr['medie']) ? $vr['medie'] : 0);
        $sub_ora   = (int)(isset($vr['sub_ora']) ? $vr['sub_ora'] : 0);
        echo '<table class="an"><tr><th>Lead-uri contactate</th><td class="n">' . $vr_n . ' din ' . $n_lead . '</td></tr>';
        echo '<tr><th>Timp mediu până la primul contact</th><td class="n">' . crm_h(st_minute($medie_min)) . '</td></tr>';
        echo '<tr><th>Contactate în mai puțin de o oră</th><td class="n">' . $sub_ora . ' (' . st_proc($sub_ora, $vr_n, 0) . ')</td></tr></table>';
    }
    echo '</div>';

    /* --- benzi de pret --- */
    $benzi = [
        ['Sub 3.000 lei', 0, 3000],
        ['3.000 - 8.000 lei', 3000, 8000],
        ['8.000 - 15.000 lei', 8000, 15000],
        ['Peste 15.000 lei', 15000, 0],
    ];
    $cos = []; $fara_pret = 0;
    foreach ($benzi as $i => $b) $cos[$i] = ['castigate' => 0, 'pierdute' => 0];
    $decizii = st_rows($db, "SELECT status, (CASE WHEN quote_price>0 THEN quote_price ELSE value END) p
        FROM leads WHERE trashed=0 AND status IN ('Câștigat','Pierdut') AND created>=? AND created<=?", $per);
    foreach ($decizii as $r) {
        $p = (float)$r['p'];
        if ($p <= 0) { $fara_pret++; continue; }
        foreach ($benzi as $i => $b) {
            if ($p >= $b[1] && ($b[2] === 0 || $p < $b[2])) {
                if ($r['status'] === 'Câștigat') $cos[$i]['castigate']++; else $cos[$i]['pierdute']++;
                break;
            }
        }
    }
    $are_benzi = false;
    foreach ($cos as $c) if ($c['castigate'] + $c['pierdute'] > 0) $are_benzi = true;

    echo '<div class="card"><h3>Pe ce bandă de preț se câștigă și pe care se pierde</h3>';
    echo '<p class="muted" style="margin-top:-8px">Numai lucrările decise, câștigate sau pierdute. Dacă rata cade brusc peste o sumă, acolo e pragul de la care clientul se sperie de preț.</p>';
    if (!$are_benzi) {
        st_tabel_gol('Nu sunt încă lucrări decise cu preț trecut în perioada asta.');
    } else {
        echo '<table class="an"><tr><th>Bandă de preț</th><th class="n">Decise</th><th class="n">Câștigate</th><th class="n">Pierdute</th><th class="n">Rată de câștig</th></tr>';
        foreach ($benzi as $i => $b) {
            $c = $cos[$i]; $tot = $c['castigate'] + $c['pierdute'];
            echo '<tr><td>' . crm_h($b[0]) . '</td><td class="n">' . $tot . '</td><td class="n">' . $c['castigate'] . '</td><td class="n">' . $c['pierdute'] . '</td>';
            echo '<td class="n">' . ($tot > 0 ? st_proc($c['castigate'], $tot, 0) : '-') . '</td></tr>';
        }
        echo '</table>';
        if ($fara_pret > 0) {
            echo '<p class="muted" style="margin-top:12px">' . (int)$fara_pret . ' lucrări decise nu au niciun preț trecut, așa că nu apar în tabel.</p>';
        }
    }
    echo '</div>';

    echo '<div class="grid2">';

    /* --- motive de pierdere --- */
    echo '<div class="card"><h3>De ce s-au pierdut</h3>';
    $motive = st_rows($db, "SELECT COALESCE(NULLIF(lost_reason,''),'Nemenționat') m, COUNT(*) c
        FROM leads WHERE trashed=0 AND status=? AND created>=? AND created<=? GROUP BY m ORDER BY c DESC", array_merge(['Pierdut'], $per));
    if (!$motive) {
        st_tabel_gol('Nicio lucrare pierdută în perioada asta.');
    } else {
        $tot_m = 0;
        foreach ($motive as $r) $tot_m += (int)$r['c'];
        echo '<table class="an"><tr><th>Motiv</th><th class="n">Cazuri</th><th class="n">Din total</th></tr>';
        foreach ($motive as $r) {
            echo '<tr><td>' . crm_h($r['m']) . '</td><td class="n">' . (int)$r['c'] . '</td><td class="n">' . st_proc((int)$r['c'], $tot_m, 0) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    /* --- interes --- */
    echo '<div class="card"><h3>Cât de serioși sunt cei care sună</h3>';
    $interes = st_rows($db, "SELECT COALESCE(NULLIF(interest,''),'Netrecut') i, COUNT(*) c
        FROM leads WHERE trashed=0 AND created>=? AND created<=? GROUP BY i ORDER BY c DESC", $per);
    if (!$interes) {
        st_tabel_gol('Niciun lead în perioada asta.');
    } else {
        $tot_i = 0;
        foreach ($interes as $r) $tot_i += (int)$r['c'];
        echo '<table class="an"><tr><th>Interes</th><th class="n">Lead-uri</th><th class="n">Din total</th></tr>';
        foreach ($interes as $r) {
            echo '<tr><td>' . crm_h($r['i']) . '</td><td class="n">' . (int)$r['c'] . '</td><td class="n">' . st_proc((int)$r['c'], $tot_i, 0) . '</td></tr>';
        }
        echo '</table>';
        echo '<p class="muted" style="margin-top:12px">Dacă mulți vin doar după un preț, fie reclama atrage oameni care compară, fie prețul nu e explicat destul pe site.</p>';
    }
    echo '</div>';
    echo '</div>';

    /* --- cost pe lead --- */
    $spend = (float)st_val($db, "SELECT COALESCE(SUM(suma),0) FROM ad_spend WHERE luna>=? AND luna<=?", [$luna_de_la, $luna_pana]);
    echo '<div class="card"><h3>Cât costă un client</h3>';
    if ($spend <= 0) {
        echo '<div class="empty">Nu ai trecut nicio cheltuială de promovare pentru perioada asta. <a class="more" href="' . st_url('cheltuieli', $zile) . '">Adaugă cheltuielile</a></div>';
    } else {
        echo '<table class="an"><tr><th>Cheltuit pe reclame</th><td class="n">' . crm_h(crm_lei($spend)) . '</td></tr>';
        echo '<tr><th>Cost pe lead</th><td class="n">' . ($n_lead > 0 ? crm_h(crm_lei($spend / $n_lead)) : '-') . '</td></tr>';
        echo '<tr><th>Cost pe lucrare câștigată</th><td class="n">' . ($castigate > 0 ? crm_h(crm_lei($spend / $castigate)) : '-') . '</td></tr>';
        echo '<tr><th>Din valoarea câștigată</th><td class="n">' . ($val_cas > 0 ? st_proc($spend, $val_cas, 0) : '-') . '</td></tr></table>';
        echo '<p class="muted" style="margin-top:12px">Cheltuielile se trec pe luni întregi, așa că pe o perioadă scurtă cifra e orientativă.</p>';
    }
    echo '</div>';
}

/* ================================================================
   4. CHELTUIELI DE PROMOVARE
   ================================================================ */
elseif ($f === 'cheltuieli') {

    if (isset($_GET['ok']))    echo '<div class="msg ok">Cheltuiala a fost adăugată.</div>';
    if (isset($_GET['sters'])) echo '<div class="msg ok">Cheltuiala a fost ștearsă.</div>';
    if (isset($_GET['err']))   echo '<div class="msg warn">Trece o sumă mai mare decât zero.</div>';

    $luni = [];
    for ($i = 0; $i < 18; $i++) $luni[] = date('Y-m', strtotime('-' . $i . ' months'));

    echo '<div class="card"><h3>Adaugă o cheltuială</h3>';
    echo '<p class="muted" style="margin-top:-8px">Trece aici cât ai dat pe reclame în fiecare lună, ca să vezi cât te costă un client.</p>';
    echo '<form method="post"><div class="grid3">';
    echo '<div><label class="fl">Luna</label><select name="luna">';
    foreach ($luni as $l) echo '<option value="' . crm_h($l) . '">' . crm_h(st_luna($l)) . '</option>';
    echo '</select></div>';
    echo '<div><label class="fl">Canal</label><select name="canal">';
    foreach (st_canale_spend() as $c) echo '<option value="' . crm_h($c) . '">' . crm_h($c) . '</option>';
    echo '</select></div>';
    echo '<div><label class="fl">Sumă (lei)</label><input type="number" name="suma" min="1" step="1" placeholder="ex. 1200" required></div>';
    echo '</div><button class="btn" name="adauga_spend" value="1" style="margin-top:16px">Salvează cheltuiala</button></form></div>';

    $rows = st_rows($db, "SELECT * FROM ad_spend ORDER BY luna DESC, id DESC LIMIT 200", []);
    echo '<div class="card"><h3>Cheltuieli trecute</h3>';
    if (!$rows) {
        st_tabel_gol('Nicio cheltuială trecută încă.');
    } else {
        echo '<table class="dz"><tr><th>Luna</th><th>Canal</th><th class="n">Sumă</th><th></th></tr>';
        foreach ($rows as $r) {
            echo '<tr><td>' . crm_h(st_luna($r['luna'])) . '</td><td>' . crm_h($r['channel']) . '</td>';
            echo '<td class="n">' . crm_h(crm_lei($r['suma'])) . '</td><td class="n">';
            echo '<form method="post" onsubmit="return confirm(\'Ștergi cheltuiala asta?\')">';
            echo '<button class="btn sm del" name="sterge_spend" value="' . (int)$r['id'] . '">Șterge</button></form></td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    $spend = (float)st_val($db, "SELECT COALESCE(SUM(suma),0) FROM ad_spend WHERE luna>=? AND luna<=?", [$luna_de_la, $luna_pana]);
    $n_lead = (int)st_val($db, "SELECT COUNT(*) FROM leads WHERE trashed=0 AND created>=? AND created<=?", $per);
    $castigate = (int)st_val($db, "SELECT COUNT(*) FROM leads WHERE trashed=0 AND status=? AND created>=? AND created<=?", array_merge(['Câștigat'], $per));

    echo '<div class="card"><h3>Pe perioada aleasă</h3>';
    echo '<table class="an"><tr><th>Cheltuit</th><td class="n">' . crm_h(crm_lei($spend)) . '</td></tr>';
    echo '<tr><th>Lead-uri</th><td class="n">' . $n_lead . '</td></tr>';
    echo '<tr><th>Cost pe lead</th><td class="n">' . ($spend > 0 && $n_lead > 0 ? crm_h(crm_lei($spend / $n_lead)) : '-') . '</td></tr>';
    echo '<tr><th>Cost pe lucrare câștigată</th><td class="n">' . ($spend > 0 && $castigate > 0 ? crm_h(crm_lei($spend / $castigate)) : '-') . '</td></tr></table>';
    echo '<p class="muted" style="margin-top:12px">Suma se ia pe luni întregi, din lunile atinse de perioada aleasă.</p>';
    echo '</div>';

    $pe_canal = st_rows($db, "SELECT channel c, COALESCE(SUM(suma),0) s FROM ad_spend WHERE luna>=? AND luna<=? GROUP BY c ORDER BY s DESC", [$luna_de_la, $luna_pana]);
    if ($pe_canal) {
        echo '<div class="card"><h3>Pe canale, în perioada aleasă</h3><table class="an"><tr><th>Canal</th><th class="n">Cheltuit</th><th class="n">Din total</th></tr>';
        foreach ($pe_canal as $r) {
            echo '<tr><td>' . crm_h($r['c']) . '</td><td class="n">' . crm_h(crm_lei($r['s'])) . '</td><td class="n">' . st_proc((float)$r['s'], $spend, 0) . '</td></tr>';
        }
        echo '</table></div>';
    }
}

crm_foot();
