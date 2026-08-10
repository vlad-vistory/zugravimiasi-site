<?php
require_once __DIR__ . '/_shell.php';
require_once dirname(__DIR__) . '/_deviz.php';
crm_require_login();

$db = crm_db();

const DEVIZ_STARI    = ['ciornă', 'trimis', 'acceptat', 'refuzat'];
const DEVIZ_UM       = ['mp', 'ml', 'buc'];
const DEVIZ_GRUPURI  = ['Pereți', 'Tavane'];

/* ============================ helperi ============================ */

function dz_num($v) {
    $v = str_replace(' ', '', trim((string)$v));
    if ($v === '') return 0.0;
    if (strpos($v, ',') !== false && strpos($v, '.') !== false) $v = str_replace('.', '', $v);
    $v = str_replace(',', '.', $v);
    return is_numeric($v) ? (float)$v : 0.0;
}
// valoare pentru input: punct ca separator, fara zerouri inutile
function dz_inp($v) {
    $s = number_format((float)$v, 2, '.', '');
    if (strpos($s, '.') !== false) $s = rtrim(rtrim($s, '0'), '.');
    return $s === '' ? '0' : $s;
}
function dz_bani($v) { return number_format(round((float)$v, 2), 2, ',', '.'); }

function dz_deviz($db, $id) {
    $q = $db->prepare("SELECT * FROM deviz WHERE id=?");
    $q->execute([(int)$id]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ? $r : null;
}
function dz_linii($db, $id) {
    $q = $db->prepare("SELECT * FROM deviz_linii WHERE deviz_id=? ORDER BY pozitie, id");
    $q->execute([(int)$id]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function dz_json($s, $fallback = []) {
    $x = json_decode((string)$s, true);
    return is_array($x) ? $x : $fallback;
}
function dz_masuratori($d) {
    $m = dz_json($d['masuratori'] ?? '');
    return [
        'mod'    => (isset($m['mod']) && $m['mod'] === 'camere') ? 'camere' : 'mp',
        'pereti' => (float)($m['pereti'] ?? 0),
        'tavane' => (float)($m['tavane'] ?? 0),
        'camere' => (isset($m['camere']) && is_array($m['camere'])) ? $m['camere'] : [],
    ];
}
function dz_optiuni($d) {
    return array_merge(deviz_optiuni_implicite(), ['tavan_decopertare' => 0], dz_json($d['optiuni'] ?? ''));
}
function dz_grupuri($linii) {
    $byg = [];
    foreach ($linii as $l) { $byg[$l['grup']][] = $l; }
    $ordine = [];
    foreach (DEVIZ_GRUPURI as $g) { if (isset($byg[$g])) $ordine[] = $g; }
    foreach ($byg as $g => $x) { if (!in_array($g, $ordine, true)) $ordine[] = $g; }
    $out = [];
    foreach ($ordine as $g) { $out[$g] = $byg[$g]; }
    return $out;
}
function dz_touch($db, $id) {
    $db->prepare("UPDATE deviz SET updated=? WHERE id=?")->execute([date('Y-m-d H:i:s'), (int)$id]);
}
// totalul devizului merge automat in fisa lead-ului
function dz_sync_lead($db, $id) {
    $d = dz_deviz($db, $id);
    if (!$d || empty($d['lead_id'])) return;
    $t = deviz_totaluri(dz_linii($db, $id));
    $db->prepare("UPDATE leads SET quote_price=? WHERE id=?")->execute([(int)round($t['total']), (int)$d['lead_id']]);
}
function dz_scrie_linii($db, $id, $linii, $manual = 0, $poz0 = 0) {
    $st = $db->prepare("INSERT INTO deviz_linii (deviz_id,grup,nume,um,cantitate,pret_um,material_cant,material_pret,pozitie,manual) VALUES (?,?,?,?,?,?,?,?,?,?)");
    foreach ($linii as $i => $l) {
        $st->execute([
            (int)$id, $l['grup'], $l['nume'], $l['um'],
            (float)$l['cantitate'], (float)$l['pret_um'],
            (float)($l['material_cant'] ?? 0), (float)($l['material_pret'] ?? 0),
            $poz0 + (isset($l['pozitie']) ? (int)$l['pozitie'] : $i), (int)$manual,
        ]);
    }
}

/* citeste masuratorile din formular (mp direct sau pe camere) */
function dz_post_masuratori() {
    $mod = (isset($_POST['mod']) && $_POST['mod'] === 'camere') ? 'camere' : 'mp';
    $camere = []; $pereti = 0.0; $tavane = 0.0;
    if ($mod === 'camere') {
        $nume = (isset($_POST['cam_nume']) && is_array($_POST['cam_nume'])) ? $_POST['cam_nume'] : [];
        $ll   = (isset($_POST['cam_l']) && is_array($_POST['cam_l'])) ? $_POST['cam_l'] : [];
        $ww   = (isset($_POST['cam_w']) && is_array($_POST['cam_w'])) ? $_POST['cam_w'] : [];
        $hh   = (isset($_POST['cam_h']) && is_array($_POST['cam_h'])) ? $_POST['cam_h'] : [];
        foreach ($ll as $i => $x) {
            $l = dz_num($x);
            $w = dz_num($ww[$i] ?? 0);
            $h = dz_num($hh[$i] ?? 0);
            if ($h <= 0) $h = 2.5;
            if ($l <= 0 || $w <= 0) continue;
            $c = deviz_din_camera($l, $w, $h);
            $camere[] = [
                'nume' => trim((string)($nume[$i] ?? '')),
                'l' => $l, 'w' => $w, 'h' => $h,
                'pereti' => $c['pereti'], 'tavane' => $c['tavane'],
            ];
            $pereti += $c['pereti'];
            $tavane += $c['tavane'];
        }
    } else {
        $pereti = dz_num($_POST['pereti'] ?? 0);
        $tavane = dz_num($_POST['tavane'] ?? 0);
    }
    return ['mod' => $mod, 'pereti' => round($pereti, 2), 'tavane' => round($tavane, 2), 'camere' => $camere];
}
function dz_post_optiuni() {
    $o = array_merge(deviz_optiuni_implicite(), ['tavan_decopertare' => 0]);
    foreach (['tapet', 'decopertare', 'tencuiala', 'plasa', 'reparatii', 'tavan_decopertare'] as $k) {
        $o[$k] = empty($_POST[$k]) ? 0 : 1;
    }
    $o['glet']            = max(0, min(2, (int)($_POST['glet'] ?? 0)));
    $o['straturi']        = max(0, min(2, (int)($_POST['straturi'] ?? 0)));
    $o['culori']          = ((int)($_POST['culori'] ?? 1)) >= 2 ? 2 : 1;
    $o['tavan_glet']      = max(0, min(2, (int)($_POST['tavan_glet'] ?? 0)));
    $o['tavan_straturi']  = max(0, min(2, (int)($_POST['tavan_straturi'] ?? 0)));
    if (!$o['tencuiala']) $o['plasa'] = 0;
    return $o;
}

/* optiunile in text, pentru oferta tiparita */
function dz_text_optiuni($o) {
    $p = [];
    if (!empty($o['tapet']))       $p[] = 'îndepărtarea tapetului';
    if (!empty($o['decopertare'])) $p[] = 'decopertare glet';
    if (!empty($o['tencuiala']))   $p[] = !empty($o['plasa']) ? 'tencuială de ipsos cu plasă din fibră de sticlă' : 'tencuială de ipsos';
    if (!empty($o['reparatii']))   $p[] = 'reparații grosiere și de finisaj';
    $g = (int)$o['glet'];
    $p[] = $g >= 2 ? 'glet în două mâini' : ($g === 1 ? 'glet într-o mână' : 'fără glet');
    $s = (int)$o['straturi'];
    if ($s >= 1) {
        $p[] = $s >= 2 ? 'vopsit în două straturi' : 'vopsit într-un strat';
        $p[] = ((int)$o['culori']) >= 2 ? 'două culori' : 'o culoare';
    } else {
        $p[] = 'fără vopsit';
    }
    $t = [];
    if (!empty($o['tavan_decopertare'])) $t[] = 'decopertare';
    $tg = (int)$o['tavan_glet'];
    $t[] = $tg >= 2 ? 'glet în două mâini' : ($tg === 1 ? 'glet într-o mână' : 'fără glet');
    $ts = (int)$o['tavan_straturi'];
    $t[] = $ts >= 2 ? 'vopsit în două straturi' : ($ts === 1 ? 'vopsit într-un strat' : 'fără vopsit');
    return ['pereti' => implode(', ', $p), 'tavane' => implode(', ', $t)];
}

/* ============================ actiuni ============================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = isset($_POST['act']) ? $_POST['act'] : '';
    $id  = (int)($_POST['id'] ?? 0);

    if ($act === 'creaza') {
        $m = dz_post_masuratori();
        $o = dz_post_optiuni();
        $lead_id = (int)($_POST['lead_id'] ?? 0);
        $nr = (int)$db->query("SELECT COALESCE(MAX(numar),0) FROM deviz")->fetchColumn() + 1;
        $now = date('Y-m-d H:i:s');
        $titlu = trim((string)($_POST['titlu'] ?? ''));
        if ($titlu === '') $titlu = 'Lucrare de zugrăvit';
        $st = $db->prepare("INSERT INTO deviz (numar,lead_id,client_nume,client_telefon,client_email,adresa,titlu,status,masuratori,optiuni,note,created,updated)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([
            $nr,
            $lead_id > 0 ? $lead_id : null,
            trim((string)($_POST['client_nume'] ?? '')),
            trim((string)($_POST['client_telefon'] ?? '')),
            trim((string)($_POST['client_email'] ?? '')),
            trim((string)($_POST['adresa'] ?? '')),
            $titlu,
            'ciornă',
            json_encode($m, JSON_UNESCAPED_UNICODE),
            json_encode($o, JSON_UNESCAPED_UNICODE),
            '', $now, $now,
        ]);
        $id = (int)$db->lastInsertId();
        dz_scrie_linii($db, $id, deviz_linii($m, $o), 0, 0);
        dz_sync_lead($db, $id);
        header('Location: /crm/deviz.php?a=edit&id=' . $id . '&ok=creat');
        exit;
    }

    $d = $id ? dz_deviz($db, $id) : null;
    if (!$d) { header('Location: /crm/deviz.php'); exit; }

    if (isset($_POST['sterge_linie'])) {
        $db->prepare("DELETE FROM deviz_linii WHERE id=? AND deviz_id=?")->execute([(int)$_POST['sterge_linie'], $id]);
        dz_touch($db, $id);
        dz_sync_lead($db, $id);
        header('Location: /crm/deviz.php?a=edit&id=' . $id . '&ok=stersa');
        exit;
    }

    if ($act === 'client') {
        $titlu = trim((string)($_POST['titlu'] ?? ''));
        if ($titlu === '') $titlu = 'Lucrare de zugrăvit';
        $db->prepare("UPDATE deviz SET client_nume=?, client_telefon=?, client_email=?, adresa=?, titlu=?, updated=? WHERE id=?")
           ->execute([
               trim((string)($_POST['client_nume'] ?? '')),
               trim((string)($_POST['client_telefon'] ?? '')),
               trim((string)($_POST['client_email'] ?? '')),
               trim((string)($_POST['adresa'] ?? '')),
               $titlu, date('Y-m-d H:i:s'), $id,
           ]);
        header('Location: /crm/deviz.php?a=edit&id=' . $id . '&ok=client');
        exit;
    }

    if ($act === 'status') {
        $nou = (string)($_POST['status'] ?? '');
        if (in_array($nou, DEVIZ_STARI, true) && $nou !== $d['status']) {
            $db->prepare("UPDATE deviz SET status=?, updated=? WHERE id=?")->execute([$nou, date('Y-m-d H:i:s'), $id]);
            if ($nou === 'trimis' && !empty($d['lead_id']) && !crm_get($db, 'deviz_trimis_' . $id)) {
                $t = deviz_totaluri(dz_linii($db, $id));
                crm_note($db, (int)$d['lead_id'], 'Deviz nr. ' . (int)$d['numar'] . ' trimis clientului: ' . crm_lei($t['total']) . '.');
                crm_set($db, 'deviz_trimis_' . $id, date('Y-m-d H:i:s'));
            }
            dz_sync_lead($db, $id);
        }
        header('Location: /crm/deviz.php?a=edit&id=' . $id . '&ok=status');
        exit;
    }

    if ($act === 'recalc') {
        $m = dz_post_masuratori();
        $o = dz_post_optiuni();
        $db->prepare("UPDATE deviz SET masuratori=?, optiuni=?, updated=? WHERE id=?")
           ->execute([json_encode($m, JSON_UNESCAPED_UNICODE), json_encode($o, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), $id]);
        $db->prepare("DELETE FROM deviz_linii WHERE deviz_id=? AND manual=0")->execute([$id]);
        dz_scrie_linii($db, $id, deviz_linii($m, $o), 0, 0);
        dz_sync_lead($db, $id);
        header('Location: /crm/deviz.php?a=edit&id=' . $id . '&ok=recalc');
        exit;
    }

    if ($act === 'linii') {
        $cant = (isset($_POST['cant']) && is_array($_POST['cant'])) ? $_POST['cant'] : [];
        $pret = (isset($_POST['pret']) && is_array($_POST['pret'])) ? $_POST['pret'] : [];
        $st = $db->prepare("UPDATE deviz_linii SET cantitate=?, pret_um=?, manual=? WHERE id=? AND deviz_id=?");
        foreach (dz_linii($db, $id) as $l) {
            $lid = (int)$l['id'];
            if (!isset($cant[$lid]) && !isset($pret[$lid])) continue;
            $c = isset($cant[$lid]) ? dz_num($cant[$lid]) : (float)$l['cantitate'];
            $p = isset($pret[$lid]) ? dz_num($pret[$lid]) : (float)$l['pret_um'];
            $manual = (int)$l['manual'];
            // pretul schimbat de om ramane asa si dupa recalculare
            if (abs($p - (float)$l['pret_um']) > 0.004) $manual = 1;
            $st->execute([$c, $p, $manual, $lid, $id]);
        }
        dz_touch($db, $id);
        dz_sync_lead($db, $id);
        header('Location: /crm/deviz.php?a=edit&id=' . $id . '&ok=linii');
        exit;
    }

    if ($act === 'adauga') {
        $nume = trim((string)($_POST['m_nume'] ?? ''));
        if ($nume !== '') {
            $grup = trim((string)($_POST['m_grup'] ?? ''));
            if ($grup === '') $grup = 'Alte lucrări';
            $um = (string)($_POST['m_um'] ?? 'mp');
            if (!in_array($um, DEVIZ_UM, true)) $um = 'mp';
            $q = $db->prepare("SELECT COUNT(*) FROM deviz_linii WHERE deviz_id=? AND manual=1");
            $q->execute([$id]);
            $poz = 1000 + (int)$q->fetchColumn();
            $db->prepare("INSERT INTO deviz_linii (deviz_id,grup,nume,um,cantitate,pret_um,material_cant,material_pret,pozitie,manual) VALUES (?,?,?,?,?,?,0,0,?,1)")
               ->execute([$id, $grup, $nume, $um, dz_num($_POST['m_cant'] ?? 0), dz_num($_POST['m_pret'] ?? 0), $poz]);
            dz_touch($db, $id);
            dz_sync_lead($db, $id);
        }
        header('Location: /crm/deviz.php?a=edit&id=' . $id . '&ok=adaugat');
        exit;
    }

    if ($act === 'nota') {
        $db->prepare("UPDATE deviz SET note=?, updated=? WHERE id=?")
           ->execute([trim((string)($_POST['note'] ?? '')), date('Y-m-d H:i:s'), $id]);
        header('Location: /crm/deviz.php?a=edit&id=' . $id . '&ok=nota');
        exit;
    }

    if ($act === 'sterge') {
        $db->prepare("DELETE FROM deviz_linii WHERE deviz_id=?")->execute([$id]);
        $db->prepare("DELETE FROM deviz WHERE id=?")->execute([$id]);
        header('Location: /crm/deviz.php?ok=sters');
        exit;
    }

    header('Location: /crm/deviz.php');
    exit;
}

/* ============================ bucati de formular ============================ */

function dz_camp($eticheta, $nume, $val, $tip = 'text', $ph = '') {
    echo '<div class="fld"><label class="fl">' . crm_h($eticheta) . '</label>';
    echo '<input type="' . crm_h($tip) . '" name="' . crm_h($nume) . '" value="' . crm_h($val) . '"';
    if ($ph !== '') echo ' placeholder="' . crm_h($ph) . '"';
    echo '></div>';
}
function dz_select($eticheta, $nume, $val, $optiuni) {
    echo '<div class="fld"><label class="fl">' . crm_h($eticheta) . '</label><select name="' . crm_h($nume) . '">';
    foreach ($optiuni as $k => $txt) {
        echo '<option value="' . crm_h($k) . '"' . ((string)$k === (string)$val ? ' selected' : '') . '>' . crm_h($txt) . '</option>';
    }
    echo '</select></div>';
}
function dz_bifa($eticheta, $nume, $on, $id = '', $extra = '') {
    echo '<label class="' . ($on ? 'on' : '') . '"><input type="checkbox" name="' . crm_h($nume) . '" value="1"';
    if ($id !== '') echo ' id="' . crm_h($id) . '"';
    if ($on) echo ' checked';
    echo ' onchange="dzOpt(this);' . $extra . '"> ' . crm_h($eticheta) . '</label>';
}

/* masuratorile + optiunile, folosite si la deviz nou si la recalculare */
function dz_form_dimensiuni($m, $o) {
    $mod = $m['mod'];
    $mp_on = ($mod === 'mp');
    ?>
    <input type="hidden" name="mod" id="dz-mod" value="<?php echo crm_h($mod); ?>">
    <div class="tabs">
      <button type="button" class="tab<?php echo $mp_on ? ' on' : ''; ?>" id="tab-mp" style="font:inherit;font-weight:600;cursor:pointer" onclick="dzMod('mp')">Direct în mp</button>
      <button type="button" class="tab<?php echo $mp_on ? '' : ' on'; ?>" id="tab-cam" style="font:inherit;font-weight:600;cursor:pointer" onclick="dzMod('camere')">Pe camere</button>
    </div>

    <div id="box-mp"<?php echo $mp_on ? '' : ' style="display:none"'; ?>>
      <div class="grid2">
        <?php dz_camp('Pereți (mp)', 'pereti', dz_inp($m['pereti']), 'text', 'ex. 62'); ?>
        <?php dz_camp('Tavane (mp)', 'tavane', dz_inp($m['tavane']), 'text', 'ex. 24'); ?>
      </div>
    </div>

    <div id="box-cam"<?php echo $mp_on ? ' style="display:none"' : ''; ?>>
      <p class="muted">Lungime, lățime și înălțime în metri. Pereții se calculează 2 x (L + l) x h, tavanul L x l.</p>
      <div id="dz-cam">
        <?php
        $camere = $m['camere'];
        if (!$camere) $camere = [['nume' => '', 'l' => '', 'w' => '', 'h' => 2.5]];
        foreach ($camere as $c) {
            $cl = (isset($c['l']) && $c['l'] !== '') ? dz_inp($c['l']) : '';
            $cw = (isset($c['w']) && $c['w'] !== '') ? dz_inp($c['w']) : '';
            $ch = (isset($c['h']) && $c['h'] !== '') ? dz_inp($c['h']) : '2.5';
            echo '<div class="inline" style="margin-bottom:8px">';
            echo '<input type="text" name="cam_nume[]" placeholder="Cameră" value="' . crm_h($c['nume'] ?? '') . '">';
            echo '<input type="text" name="cam_l[]" placeholder="Lungime" oninput="dzCalc()" value="' . crm_h($cl) . '">';
            echo '<input type="text" name="cam_w[]" placeholder="Lățime" oninput="dzCalc()" value="' . crm_h($cw) . '">';
            echo '<input type="text" name="cam_h[]" placeholder="Înălțime" oninput="dzCalc()" value="' . crm_h($ch) . '">';
            echo '<button type="button" class="btn ghost sm" onclick="dzSterge(this)">Scoate</button>';
            echo '</div>';
        }
        ?>
      </div>
      <button type="button" class="btn ghost sm" onclick="dzRand()">+ Adaugă cameră</button>
      <p class="muted" id="dz-sum" style="margin-top:10px"></p>
    </div>

    <h3 style="margin-top:24px">Pereți</h3>
    <div class="opt">
      <?php
      dz_bifa('Are tapet de scos (25 lei/mp)', 'tapet', !empty($o['tapet']));
      dz_bifa('Decopertare glet', 'decopertare', !empty($o['decopertare']));
      dz_bifa('Tencuială ipsos', 'tencuiala', !empty($o['tencuiala']), 'o-tencuiala', 'dzPlasa();');
      dz_bifa('Plasă fibră de sticlă', 'plasa', !empty($o['plasa']), 'o-plasa');
      dz_bifa('Reparații (grosiere + finisaj)', 'reparatii', !empty($o['reparatii']));
      ?>
    </div>
    <div class="grid3" style="margin-top:14px">
      <?php
      dz_select('Glet', 'glet', (int)$o['glet'], [0 => 'Fără glet', 1 => 'O mână', 2 => 'Două mâini']);
      dz_select('Vopsit', 'straturi', (int)$o['straturi'], [0 => 'Fără vopsit', 1 => 'Un strat', 2 => 'Două straturi']);
      dz_select('Culori', 'culori', (int)$o['culori'], [1 => 'O culoare', 2 => 'Două culori']);
      ?>
    </div>

    <h3 style="margin-top:24px">Tavane</h3>
    <div class="opt">
      <?php dz_bifa('Decopertare', 'tavan_decopertare', !empty($o['tavan_decopertare'])); ?>
    </div>
    <div class="grid2" style="margin-top:14px">
      <?php
      dz_select('Glet tavan', 'tavan_glet', (int)$o['tavan_glet'], [0 => 'Fără glet', 1 => 'O mână', 2 => 'Două mâini']);
      dz_select('Vopsit tavan', 'tavan_straturi', (int)$o['tavan_straturi'], [0 => 'Fără vopsit', 1 => 'Un strat', 2 => 'Două straturi']);
      ?>
    </div>
    <?php
}

function dz_form_js() { ?>
<script>
function dzMod(m){
  document.getElementById('dz-mod').value = m;
  document.getElementById('box-mp').style.display  = (m === 'mp') ? '' : 'none';
  document.getElementById('box-cam').style.display = (m === 'camere') ? '' : 'none';
  document.getElementById('tab-mp').className  = 'tab' + (m === 'mp' ? ' on' : '');
  document.getElementById('tab-cam').className = 'tab' + (m === 'camere' ? ' on' : '');
  dzCalc();
}
function dzRand(){
  var box = document.getElementById('dz-cam');
  var d = document.createElement('div');
  d.className = 'inline';
  d.style.marginBottom = '8px';
  d.innerHTML = '<input type="text" name="cam_nume[]" placeholder="Cameră">'
    + '<input type="text" name="cam_l[]" placeholder="Lungime" oninput="dzCalc()">'
    + '<input type="text" name="cam_w[]" placeholder="Lățime" oninput="dzCalc()">'
    + '<input type="text" name="cam_h[]" placeholder="Înălțime" value="2.5" oninput="dzCalc()">'
    + '<button type="button" class="btn ghost sm" onclick="dzSterge(this)">Scoate</button>';
  box.appendChild(d);
  dzCalc();
}
function dzSterge(b){ b.parentNode.parentNode.removeChild(b.parentNode); dzCalc(); }
function dzN(x){ x = (x || '').toString().replace(',', '.'); var n = parseFloat(x); return isNaN(n) ? 0 : n; }
function dzCalc(){
  var box = document.getElementById('dz-cam');
  if (!box) return;
  var L = box.querySelectorAll('input[name="cam_l[]"]');
  var W = box.querySelectorAll('input[name="cam_w[]"]');
  var H = box.querySelectorAll('input[name="cam_h[]"]');
  var p = 0, t = 0, n = 0;
  for (var i = 0; i < L.length; i++){
    var l = dzN(L[i].value), w = dzN(W[i].value), h = dzN(H[i].value);
    if (h <= 0) h = 2.5;
    if (l <= 0 || w <= 0) continue;
    p += 2 * (l + w) * h; t += l * w; n++;
  }
  var s = document.getElementById('dz-sum');
  if (s) s.innerHTML = n ? ('Total din ' + n + (n === 1 ? ' cameră' : ' camere') + ': pereți ' + p.toFixed(2) + ' mp, tavane ' + t.toFixed(2) + ' mp.') : 'Completează dimensiunile ca să vezi totalul.';
}
function dzOpt(el){ el.parentNode.className = el.checked ? 'on' : ''; }
function dzPlasa(){
  var t = document.getElementById('o-tencuiala'), p = document.getElementById('o-plasa');
  if (!t || !p) return;
  p.disabled = !t.checked;
  if (!t.checked) p.checked = false;
  p.parentNode.className = p.checked ? 'on' : '';
  p.parentNode.style.opacity = t.checked ? '1' : '.5';
}
dzPlasa(); dzCalc();
</script>
<?php }

/* ============================ ecrane ============================ */

$a = isset($_GET['a']) ? $_GET['a'] : 'lista';

/* ---------------------------- PRINT ---------------------------- */
if ($a === 'print') {
    $d = dz_deviz($db, (int)($_GET['id'] ?? 0));
    if (!$d) { crm_head('deviz', 'Ofertă'); echo '<div class="empty">Devizul nu există.</div>'; crm_foot(); exit; }
    $linii  = dz_linii($db, (int)$d['id']);
    $tot    = deviz_totaluri($linii);
    $m      = dz_masuratori($d);
    $o      = dz_optiuni($d);
    $txt    = dz_text_optiuni($o);
    crm_head('deviz', 'Ofertă nr. ' . (int)$d['numar']);
    ?>
    <div class="no-print" style="display:flex;gap:10px;margin-bottom:16px">
      <a class="back" href="/crm/deviz.php?a=edit&id=<?php echo (int)$d['id']; ?>" style="margin:0;align-self:center">← Înapoi la deviz</a>
      <button class="no-print btn" onclick="window.print()">Tipărește</button>
    </div>
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap">
        <div>
          <h1>Zugrav Iași</h1>
          <div class="muted">Ofertă de preț</div>
        </div>
        <div style="text-align:right">
          <div><b>Nr. <?php echo (int)$d['numar']; ?></b></div>
          <div class="muted"><?php echo crm_h(date('d.m.Y', strtotime($d['created']))); ?></div>
        </div>
      </div>

      <h3 style="margin-top:26px">Beneficiar</h3>
      <table class="kv">
        <tr><td class="k">Nume</td><td><?php echo crm_h($d['client_nume'] !== '' ? $d['client_nume'] : '-'); ?></td></tr>
        <tr><td class="k">Telefon</td><td><?php echo crm_h($d['client_telefon'] !== '' ? $d['client_telefon'] : '-'); ?></td></tr>
        <tr><td class="k">Adresa</td><td><?php echo crm_h($d['adresa'] !== '' ? $d['adresa'] : '-'); ?></td></tr>
        <tr><td class="k">Lucrarea</td><td><?php echo crm_h($d['titlu']); ?></td></tr>
      </table>

      <h3 style="margin-top:26px">Dimensiunile lucrării</h3>
      <table class="kv">
        <?php if ($m['pereti'] > 0) { ?>
        <tr><td class="k">Pereți</td><td><?php echo crm_h(deviz_nr($m['pereti'])); ?> mp: <?php echo crm_h($txt['pereti']); ?></td></tr>
        <?php } if ($m['tavane'] > 0) { ?>
        <tr><td class="k">Tavane</td><td><?php echo crm_h(deviz_nr($m['tavane'])); ?> mp: <?php echo crm_h($txt['tavane']); ?></td></tr>
        <?php } if ($m['camere']) { ?>
        <tr><td class="k">Camere</td><td><?php
            $bits = [];
            foreach ($m['camere'] as $c) {
                $bits[] = ($c['nume'] !== '' ? $c['nume'] . ' ' : '') . deviz_nr($c['l']) . ' x ' . deviz_nr($c['w']) . ' x ' . deviz_nr($c['h']) . ' m';
            }
            echo crm_h(implode('; ', $bits));
        ?></td></tr>
        <?php } ?>
      </table>

      <?php foreach (dz_grupuri($linii) as $grup => $ln) {
        $gman = 0; $gmat = 0; ?>
        <h3 style="margin-top:26px"><?php echo crm_h($grup); ?></h3>
        <table class="dz">
          <tr><th>Etapă</th><th class="n">Cantitate</th><th>U.M.</th><th class="n">Preț u.m.</th><th class="n">Manoperă</th><th class="n">Cant. material</th><th class="n">Preț material</th><th class="n">Total</th></tr>
          <?php foreach ($ln as $l) {
            $man = (float)$l['cantitate'] * (float)$l['pret_um'];
            $mat = (float)$l['material_cant'] * (float)$l['material_pret'];
            $gman += $man; $gmat += $mat; ?>
            <tr>
              <td><?php echo crm_h($l['nume']); ?></td>
              <td class="n"><?php echo crm_h(deviz_nr($l['cantitate'])); ?></td>
              <td><?php echo crm_h($l['um']); ?></td>
              <td class="n"><?php echo dz_bani($l['pret_um']); ?></td>
              <td class="n"><?php echo dz_bani($man); ?></td>
              <td class="n"><?php echo $l['material_cant'] > 0 ? crm_h(deviz_nr($l['material_cant'])) : '-'; ?></td>
              <td class="n"><?php echo $mat > 0 ? dz_bani($mat) : '-'; ?></td>
              <td class="n"><?php echo dz_bani($man + $mat); ?></td>
            </tr>
          <?php } ?>
          <tr class="tot"><td colspan="4">Total <?php echo crm_h($grup); ?></td><td class="n"><?php echo dz_bani($gman); ?></td><td></td><td class="n"><?php echo dz_bani($gmat); ?></td><td class="n"><?php echo dz_bani($gman + $gmat); ?></td></tr>
        </table>
      <?php } ?>

      <h3 style="margin-top:26px">Recapitulare</h3>
      <table class="kv">
        <tr><td class="k">Manoperă</td><td><?php echo dz_bani($tot['manopera']); ?> lei</td></tr>
        <tr><td class="k">Materiale</td><td><?php echo dz_bani($tot['material']); ?> lei</td></tr>
        <tr><td class="k"><b>TOTAL</b></td><td><b><?php echo dz_bani($tot['total']); ?> lei</b></td></tr>
      </table>

      <h3 style="margin-top:26px">Ce include oferta</h3>
      <ul class="muted" style="line-height:1.7">
        <li>Manopera pentru etapele de mai sus și materialele de bază trecute în tabel.</li>
        <li>Materialele decorative alese de client, cum ar fi vopselele speciale sau tapetul, se pot lua separat.</li>
        <li>Estimarea este gratuită. Devizul exact, măsurat la fața locului, costă 200 lei și se scad integral din prețul final al lucrării.</li>
        <li>Garanție scrisă la finalul lucrării.</li>
        <li>Protejăm mobila și pardoseala cu folie, iar la final facem curat.</li>
      </ul>

      <p class="muted" style="margin-top:26px;border-top:1px solid #eef0f2;padding-top:14px">
        contact@zugravimiasi.ro · zugravimiasi.ro
      </p>
    </div>
    <?php
    crm_foot();
    exit;
}

/* ---------------------------- NOU ---------------------------- */
if ($a === 'nou') {
    $lead = null;
    $lead_id = (int)($_GET['lead'] ?? 0);
    if ($lead_id > 0) {
        $q = $db->prepare("SELECT * FROM leads WHERE id=?");
        $q->execute([$lead_id]);
        $lead = $q->fetch(PDO::FETCH_ASSOC);
    }
    if (!$lead) $lead_id = 0;
    $m = ['mod' => 'mp', 'pereti' => 0, 'tavane' => 0, 'camere' => []];
    $o = array_merge(deviz_optiuni_implicite(), ['tavan_decopertare' => 0]);

    crm_head('deviz', 'Deviz nou');
    echo '<a class="back" href="/crm/deviz.php">← Toate devizele</a>';
    echo '<h1>Deviz nou</h1>';
    if ($lead) {
        echo '<p class="muted">Pornit din lead-ul <a href="/crm/?p=lead&id=' . (int)$lead['id'] . '" style="color:#ec5e0c;font-weight:600">'
            . crm_h($lead['nume'] !== '' ? $lead['nume'] : $lead['telefon']) . '</a>.';
        if (!empty($lead['suprafata'])) echo ' În formular a scris: ' . crm_h($lead['suprafata']) . '.';
        echo '</p>';
        $alt = $db->prepare("SELECT COUNT(*) FROM deviz WHERE lead_id=?");
        $alt->execute([(int)$lead['id']]);
        if ((int)$alt->fetchColumn() > 0) echo '<div class="msg warn">Acest lead are deja un deviz. Verifică lista înainte să faci încă unul.</div>';
    }
    ?>
    <form method="post" action="/crm/deviz.php">
      <input type="hidden" name="act" value="creaza">
      <input type="hidden" name="lead_id" value="<?php echo (int)$lead_id; ?>">

      <div class="card">
        <h3>Clientul</h3>
        <div class="grid3">
          <?php
          dz_camp('Nume', 'client_nume', $lead ? $lead['nume'] : '');
          dz_camp('Telefon', 'client_telefon', $lead ? $lead['telefon'] : '');
          dz_camp('Email', 'client_email', $lead ? $lead['email'] : '');
          ?>
        </div>
        <div class="grid2">
          <?php
          dz_camp('Adresa lucrării', 'adresa', '', 'text', 'strada, bloc, apartament');
          dz_camp('Titlul lucrării', 'titlu', 'Lucrare de zugrăvit');
          ?>
        </div>
      </div>

      <div class="card">
        <h3>Măsurători</h3>
        <?php dz_form_dimensiuni($m, $o); ?>
      </div>

      <button class="btn full" type="submit">Creează devizul</button>
    </form>
    <?php
    dz_form_js();
    crm_foot();
    exit;
}

/* ---------------------------- EDIT ---------------------------- */
if ($a === 'edit') {
    $d = dz_deviz($db, (int)($_GET['id'] ?? 0));
    if (!$d) { crm_head('deviz', 'Deviz'); echo '<div class="empty">Devizul nu există.</div>'; crm_foot(); exit; }
    $id     = (int)$d['id'];
    $linii  = dz_linii($db, $id);
    $tot    = deviz_totaluri($linii);
    $m      = dz_masuratori($d);
    $o      = dz_optiuni($d);
    $lead   = null;
    if (!empty($d['lead_id'])) {
        $q = $db->prepare("SELECT id, nume, telefon FROM leads WHERE id=?");
        $q->execute([(int)$d['lead_id']]);
        $lead = $q->fetch(PDO::FETCH_ASSOC);
    }
    $mesaje = [
        'creat'   => 'Devizul a fost creat.',
        'client'  => 'Datele clientului au fost salvate.',
        'status'  => 'Starea devizului a fost schimbată.',
        'recalc'  => 'Devizul a fost recalculat din opțiuni.',
        'linii'   => 'Modificările au fost salvate.',
        'adaugat' => 'Etapa a fost adăugată.',
        'stersa'  => 'Etapa a fost ștearsă.',
        'nota'    => 'Nota a fost salvată.',
    ];
    $ok = isset($_GET['ok']) ? (string)$_GET['ok'] : '';

    crm_head('deviz', 'Deviz nr. ' . (int)$d['numar']);
    echo '<a class="back" href="/crm/deviz.php">← Toate devizele</a>';
    if (isset($mesaje[$ok])) echo '<div class="msg ok">' . crm_h($mesaje[$ok]) . '</div>';
    ?>
    <div class="an-top">
      <div>
        <h1>Deviz nr. <?php echo (int)$d['numar']; ?></h1>
        <div class="muted">
          <?php echo crm_h($d['titlu']); ?> · creat <?php echo crm_h(date('d.m.Y', strtotime($d['created']))); ?>
          <?php if (!empty($d['updated'])) echo ' · modificat acum ' . crm_h(crm_ago($d['updated'])); ?>
          <?php if ($lead) { ?>
            · <a href="/crm/?p=lead&id=<?php echo (int)$lead['id']; ?>" style="color:#ec5e0c;font-weight:600">fișa lead-ului</a>
          <?php } ?>
        </div>
      </div>
      <div class="dfilt">
        <form method="post" action="/crm/deviz.php" style="min-width:170px">
          <input type="hidden" name="act" value="status">
          <input type="hidden" name="id" value="<?php echo $id; ?>">
          <select name="status" onchange="this.form.submit()">
            <?php foreach (DEVIZ_STARI as $s) { ?>
              <option value="<?php echo crm_h($s); ?>"<?php echo $s === $d['status'] ? ' selected' : ''; ?>><?php echo crm_h(ucfirst($s)); ?></option>
            <?php } ?>
          </select>
        </form>
        <a class="btn ghost" href="/crm/deviz.php?a=print&id=<?php echo $id; ?>">Vezi ca ofertă</a>
      </div>
    </div>

    <div class="card">
      <h3>Clientul</h3>
      <form method="post" action="/crm/deviz.php">
        <input type="hidden" name="act" value="client">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <div class="grid3">
          <?php
          dz_camp('Nume', 'client_nume', $d['client_nume']);
          dz_camp('Telefon', 'client_telefon', $d['client_telefon']);
          dz_camp('Email', 'client_email', $d['client_email']);
          ?>
        </div>
        <div class="grid2">
          <?php
          dz_camp('Adresa lucrării', 'adresa', $d['adresa']);
          dz_camp('Titlul lucrării', 'titlu', $d['titlu']);
          ?>
        </div>
        <button class="btn sm" type="submit" style="margin-top:14px">Salvează</button>
      </form>
    </div>

    <div class="card">
      <h3>Dimensiunile lucrării</h3>
      <p class="muted" style="margin-top:-8px">Acum: pereți <?php echo crm_h(deviz_nr($m['pereti'])); ?> mp, tavane <?php echo crm_h(deviz_nr($m['tavane'])); ?> mp.</p>
      <form method="post" action="/crm/deviz.php">
        <input type="hidden" name="act" value="recalc">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <?php dz_form_dimensiuni($m, $o); ?>
        <div class="msg warn" style="margin-top:18px">Recalcularea șterge liniile generate automat și le face din nou. Liniile adăugate manual și prețurile schimbate de tine rămân pe loc.</div>
        <button class="btn" type="submit" onclick="return confirm('Refac liniile automate din opțiuni. Continui?')">Recalculează din opțiuni</button>
      </form>
    </div>

    <div class="card">
      <h3>Devizul</h3>
      <?php if (!$linii) { ?>
        <div class="empty">Devizul nu are nicio linie. Recalculează din opțiuni sau adaugă o etapă manual.</div>
      <?php } else { ?>
      <form method="post" action="/crm/deviz.php">
        <input type="hidden" name="act" value="linii">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <div style="overflow-x:auto">
        <table class="dz">
          <tr>
            <th>Etapă</th><th class="n">Cantitate</th><th>U.M.</th><th class="n">Preț u.m.</th>
            <th class="n">Manoperă</th><th class="n">Cant. material</th><th class="n">Preț material</th><th class="n">Total</th><th></th>
          </tr>
          <?php foreach (dz_grupuri($linii) as $grup => $ln) {
            $gman = 0; $gmat = 0; ?>
            <tr class="grp"><td colspan="9"><?php echo crm_h($grup); ?></td></tr>
            <?php foreach ($ln as $l) {
              $lid = (int)$l['id'];
              $man = (float)$l['cantitate'] * (float)$l['pret_um'];
              $mat = (float)$l['material_cant'] * (float)$l['material_pret'];
              $gman += $man; $gmat += $mat; ?>
              <tr>
                <td><?php echo crm_h($l['nume']); ?><?php echo ((int)$l['manual'] === 1) ? ' <span class="badge">manual</span>' : ''; ?></td>
                <td class="n"><input type="text" name="cant[<?php echo $lid; ?>]" value="<?php echo crm_h(dz_inp($l['cantitate'])); ?>" style="text-align:right"></td>
                <td><?php echo crm_h($l['um']); ?></td>
                <td class="n"><input type="text" name="pret[<?php echo $lid; ?>]" value="<?php echo crm_h(dz_inp($l['pret_um'])); ?>" style="text-align:right"></td>
                <td class="n"><?php echo dz_bani($man); ?></td>
                <td class="n"><?php echo $l['material_cant'] > 0 ? crm_h(deviz_nr($l['material_cant'])) : '-'; ?></td>
                <td class="n"><?php echo $l['material_pret'] > 0 ? dz_bani($mat) : '-'; ?></td>
                <td class="n"><?php echo dz_bani($man + $mat); ?></td>
                <td class="n"><button class="btn del sm" type="submit" name="sterge_linie" value="<?php echo $lid; ?>" onclick="return confirm('Scot linia din deviz?')">x</button></td>
              </tr>
            <?php } ?>
            <tr class="tot">
              <td colspan="4">Total <?php echo crm_h($grup); ?></td>
              <td class="n"><?php echo dz_bani($gman); ?></td>
              <td></td>
              <td class="n"><?php echo dz_bani($gmat); ?></td>
              <td class="n"><?php echo dz_bani($gman + $gmat); ?></td>
              <td></td>
            </tr>
          <?php } ?>
          <tr class="tot"><td colspan="7">MANOPERĂ</td><td class="n"><?php echo dz_bani($tot['manopera']); ?></td><td></td></tr>
          <tr class="tot"><td colspan="7">MATERIALE</td><td class="n"><?php echo dz_bani($tot['material']); ?></td><td></td></tr>
          <tr class="tot"><td colspan="7">TOTAL GENERAL</td><td class="n"><?php echo dz_bani($tot['total']); ?></td><td></td></tr>
        </table>
        </div>
        <button class="btn" type="submit" style="margin-top:16px">Salvează modificările</button>
      </form>
      <?php } ?>
    </div>

    <div class="card">
      <h3>Adaugă etapă manuală</h3>
      <form method="post" action="/crm/deviz.php">
        <input type="hidden" name="act" value="adauga">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <div class="grid3">
          <div class="fld">
            <label class="fl">Grup</label>
            <input type="text" name="m_grup" list="dz-grupuri" value="Alte lucrări">
            <datalist id="dz-grupuri">
              <?php
              $gr = ['Pereți', 'Tavane', 'Alte lucrări'];
              foreach (dz_grupuri($linii) as $g => $x) { if (!in_array($g, $gr, true)) $gr[] = $g; }
              foreach ($gr as $g) echo '<option value="' . crm_h($g) . '"></option>';
              ?>
            </datalist>
          </div>
          <?php
          dz_camp('Denumirea etapei', 'm_nume', '', 'text', 'ex. Montaj colțar de protecție');
          dz_select('U.M.', 'm_um', 'mp', ['mp' => 'mp', 'ml' => 'ml', 'buc' => 'buc']);
          ?>
        </div>
        <div class="grid2">
          <?php
          dz_camp('Cantitate', 'm_cant', '', 'text', 'ex. 12');
          dz_camp('Preț pe u.m. (lei)', 'm_pret', '', 'text', 'ex. 18');
          ?>
        </div>
        <button class="btn sm" type="submit" style="margin-top:14px">Adaugă etapa</button>
      </form>
    </div>

    <div class="card">
      <h3>Notă internă</h3>
      <form method="post" action="/crm/deviz.php">
        <input type="hidden" name="act" value="nota">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <textarea name="note" rows="4" placeholder="Ce ai vorbit cu clientul, ce trebuie verificat la fața locului."><?php echo crm_h($d['note']); ?></textarea>
        <button class="btn sm" type="submit" style="margin-top:12px">Salvează nota</button>
      </form>
    </div>

    <div class="card">
      <form method="post" action="/crm/deviz.php">
        <input type="hidden" name="act" value="sterge">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <button class="btn del" type="submit" onclick="return confirm('Șterg devizul și toate liniile lui. Continui?')">Șterge devizul</button>
      </form>
    </div>
    <?php
    dz_form_js();
    crm_foot();
    exit;
}

/* ---------------------------- LISTA ---------------------------- */
$f = isset($_GET['f']) ? (string)$_GET['f'] : '';
if (!in_array($f, DEVIZ_STARI, true)) $f = '';

$cnt = [];
foreach ($db->query("SELECT status, COUNT(*) c FROM deviz GROUP BY status") as $r) $cnt[$r['status']] = (int)$r['c'];

$totaluri = [];
foreach ($db->query("SELECT deviz_id, SUM(cantitate*pret_um + material_cant*material_pret) t FROM deviz_linii GROUP BY deviz_id") as $r) {
    $totaluri[(int)$r['deviz_id']] = (float)$r['t'];
}

if ($f !== '') {
    $q = $db->prepare("SELECT * FROM deviz WHERE status=? ORDER BY id DESC");
    $q->execute([$f]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
} else {
    $rows = $db->query("SELECT * FROM deviz ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
}

crm_head('deviz', 'Devize');
?>
<div class="an-top">
  <h1>Devize</h1>
  <a class="btn" href="/crm/deviz.php?a=nou">Deviz nou</a>
</div>
<?php if (isset($_GET['ok']) && $_GET['ok'] === 'sters') echo '<div class="msg ok">Devizul a fost șters.</div>'; ?>
<div class="tabs">
  <a class="tab <?php echo $f === '' ? 'on' : ''; ?>" href="/crm/deviz.php">Toate <span><?php echo array_sum($cnt); ?></span></a>
  <?php foreach (DEVIZ_STARI as $s) { ?>
    <a class="tab <?php echo $f === $s ? 'on' : ''; ?>" href="/crm/deviz.php?f=<?php echo urlencode($s); ?>"><?php echo crm_h(ucfirst($s)); ?> <span><?php echo (int)($cnt[$s] ?? 0); ?></span></a>
  <?php } ?>
</div>

<?php if (!$rows) { ?>
  <div class="empty">Niciun deviz aici încă. Apasă pe „Deviz nou” ca să faci primul.</div>
<?php } else { ?>
<div class="card">
  <div style="overflow-x:auto">
  <table class="an">
    <tr>
      <th>Nr.</th><th>Client</th><th>Lucrarea</th><th>Stare</th><th class="n">Total</th><th>Făcut</th><th>Modificat</th>
    </tr>
    <?php foreach ($rows as $r) {
      $rid = (int)$r['id'];
      $t = isset($totaluri[$rid]) ? $totaluri[$rid] : 0; ?>
      <tr style="cursor:pointer" onclick="location.href='/crm/deviz.php?a=edit&id=<?php echo $rid; ?>'">
        <td><a href="/crm/deviz.php?a=edit&id=<?php echo $rid; ?>"><b><?php echo (int)$r['numar']; ?></b></a></td>
        <td><?php echo crm_h($r['client_nume'] !== '' ? $r['client_nume'] : ($r['client_telefon'] !== '' ? $r['client_telefon'] : '(fără nume)')); ?></td>
        <td><?php echo crm_h($r['titlu']); ?></td>
        <td><span class="badge s-<?php echo crm_h(crm_slug($r['status'])); ?>"><?php echo crm_h(ucfirst($r['status'])); ?></span></td>
        <td class="n"><?php echo crm_h(crm_lei($t)); ?></td>
        <td><?php echo crm_h(date('d.m.Y', strtotime($r['created']))); ?></td>
        <td><?php echo crm_h($r['updated'] ? crm_ago($r['updated']) : '-'); ?></td>
      </tr>
    <?php } ?>
  </table>
  </div>
</div>
<?php }
crm_foot();
