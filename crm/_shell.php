<?php
// Invelisul comun al CRM-ului: autentificare, meniu, stiluri. Folosit de toate ecranele.
require_once dirname(__DIR__) . '/_crm.php';

function crm_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function crm_ago($ts) {
    if (!$ts) return '';
    $d = time() - strtotime($ts);
    if ($d < 60) return 'acum';
    if ($d < 3600) return floor($d / 60) . ' min';
    if ($d < 86400) return floor($d / 3600) . ' h';
    return floor($d / 86400) . ' zile';
}
function crm_slug($s) { return strtr(mb_strtolower($s, 'UTF-8'), ['ă'=>'a','â'=>'a','î'=>'i','ș'=>'s','ț'=>'t',' '=>'-']); }
function crm_lei($v) { return number_format(round((float)$v), 0, ',', '.') . ' lei'; }

// cere sesiune valida; daca nu, trimite la login
function crm_require_login() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['crm_ok'])) { header('Location: /crm/'); exit; }
}

const CRM_STATUSES = ['Nou', 'Contactat', 'Ofertat', 'Câștigat', 'Pierdut'];
const CRM_INTERES  = ['Interesat', 'Vrea doar un preț', 'Neinteresat'];
const CRM_MOTIVE   = ['Preț prea mare', 'A ales altă firmă', 'A amânat lucrarea', 'Nu răspunde', 'Nu era în zonă', 'Alt motiv'];
const CRM_SURSE_OFFLINE = ['Recomandare', 'Telefon', 'WhatsApp', 'Facebook', 'OLX / Publi24', 'Client vechi', 'Flyer', 'Altă sursă'];

function crm_nav_items() {
    return [
        ['/crm/?p=panou',   'Panou',   'panou'],
        ['/crm/?p=palnie',  'Pâlnie',  'palnie'],
        ['/crm/deviz.php',  'Devize',  'deviz'],
        ['/crm/statistici.php', 'Analiză', 'statistici'],
        ['/crm/?p=cos',     'Coș',     'cos'],
        ['/crm/?p=setari',  'Setări',  'setari'],
    ];
}

function crm_head($active, $title = 'CRM Zugrav Iași') { ?>
<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?php echo crm_h($title); ?></title><style>
*{box-sizing:border-box}body{margin:0;background:#f4f5f7;color:#1f2937;font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;font-size:15px}
a{text-decoration:none;color:inherit}
.nav{background:#18181b;color:#fff;position:sticky;top:0;z-index:5}
.nav .in{max-width:1120px;margin:0 auto;display:flex;align-items:center;gap:6px;padding:0 16px;height:56px}
.nav .brand{font-weight:800;margin-right:14px;white-space:nowrap}.nav .brand span{color:#f2681c}
.nav a.n{padding:8px 13px;border-radius:8px;color:#cbd0d6;font-weight:600;font-size:.95rem;white-space:nowrap}
.nav a.n.on{background:#f2681c;color:#fff}.nav a.n:hover{color:#fff}
.nav .sp{flex:1}.nav .ext{color:#cbd0d6;font-size:.9rem}.nav .out{border:1px solid #3a3a40;padding:6px 12px;border-radius:8px;color:#fff;font-size:.9rem}
.wrap{max-width:1120px;margin:0 auto;padding:22px 16px 70px}
h1{font-size:1.6rem;margin:0}h3{margin:0 0 14px;font-size:1.05rem}
.card{background:#fff;border:1px solid #e4e4e8;border-radius:14px;padding:20px 22px;margin-bottom:16px}
.muted{color:#6b7280;font-size:.9rem}
.kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:18px}
.kpi{background:#fff;border:1px solid #e4e4e8;border-radius:14px;padding:16px 18px}
.kpi-t{color:#6b7280;font-size:.8rem;font-weight:600}.kpi-v{font-size:1.7rem;font-weight:800;color:#111;margin-top:4px}.kpi-s{color:#9ca3af;font-size:.8rem;margin-top:2px}
.kpi-d{font-size:.8rem;font-weight:700;margin-top:3px}.kpi-d.up{color:#15803d}.kpi-d.down{color:#dc2626}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.lrow{display:flex;align-items:center;gap:10px;padding:11px 0;border-bottom:1px solid #eef0f2}.lrow:last-of-type{border:0}
.lrow b{flex:1}.rmeta{color:#9ca3af;font-size:.82rem}
.more{display:inline-block;margin-top:10px;color:#ec5e0c;font-weight:600}
.brow{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid #eef0f2}.brow:last-child{border:0}
.badge{font-size:.72rem;font-weight:800;padding:4px 10px;border-radius:999px;background:#eef0f2;color:#374151;white-space:nowrap}
.s-nou{background:#fde3d3;color:#c2410c}.s-contactat{background:#dbeafe;color:#1d4ed8}.s-ofertat{background:#f3e8ff;color:#7e22ce}.s-castigat{background:#dcfce7;color:#15803d}.s-pierdut{background:#f3f4f6;color:#6b7280}
.s-ciorna{background:#f3f4f6;color:#6b7280}.s-trimis{background:#dbeafe;color:#1d4ed8}.s-acceptat{background:#dcfce7;color:#15803d}.s-refuzat{background:#fee2e2;color:#b91c1c}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.tab{background:#fff;border:1px solid #e4e4e8;border-radius:999px;padding:7px 14px;color:#374151;font-weight:600;font-size:.92rem}
.tab.on{background:#f2681c;border-color:#f2681c;color:#fff}.tab span{opacity:.7}
.lead{display:flex;justify-content:space-between;align-items:center;gap:12px;background:#fff;border:1px solid #e4e4e8;border-left:4px solid #9ca3af;border-radius:12px;padding:14px 18px;margin-bottom:10px}
.lead.s-nou{border-left-color:#f2681c}.lead.s-contactat{border-left-color:#3b82f6}.lead.s-ofertat{border-left-color:#a855f7}.lead.s-castigat{border-left-color:#16a34a}.lead.s-pierdut{border-left-color:#9ca3af;opacity:.72}
.lead.nou-necitit{box-shadow:0 0 0 2px #16a34a}
.lead-l{display:flex;align-items:center;gap:12px}.nm{font-weight:800;color:#111}.meta{color:#6b7280;font-size:.85rem;margin-top:2px}
.lead-r{text-align:right}.lead-r b{display:block;color:#15803d}
.dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#16a34a;margin-right:6px}
.empty{background:#fff;border:1px dashed #d1d5db;border-radius:12px;padding:40px;text-align:center;color:#6b7280}
.back{display:inline-block;margin-bottom:14px;color:#6b7280;font-weight:600}
.kv{width:100%;border-collapse:collapse}.kv td{padding:9px 0;border-bottom:1px solid #eef0f2;vertical-align:top}.kv td.k{color:#6b7280;width:150px}.kv tr:last-child td{border:0}
.fl{display:block;font-weight:700;font-size:.85rem;margin:16px 0 6px}
select,input[type=number],input[type=text],input[type=password],input[type=date],input[type=time],input[type=email],input[type=tel],textarea{font:inherit;padding:10px 12px;border:1px solid #d1d5db;border-radius:9px;width:100%;background:#fff}
select{cursor:pointer;font-weight:600}
.inline{display:flex;gap:8px;align-items:center}.inline input{flex:1}.inline span{color:#6b7280}
.btn{background:#ec5e0c;color:#fff;border:0;border-radius:9px;padding:11px 18px;font:inherit;font-weight:700;cursor:pointer;display:inline-block;text-align:center}.btn:hover{background:#d45309}
.btn.sm{padding:9px 13px;font-size:.88rem}.btn.ghost{background:#fff;color:#374151;border:1px solid #d1d5db}.btn.ghost:hover{background:#f9fafb}
.btn.full{width:100%;margin-top:16px}.btn.del{background:#fff;color:#b91c1c;border:1px solid #e5c1c1}.btn.del:hover{background:#fee2e2}
.timeline{margin:12px 0}.tl{border-left:2px solid #e4e4e8;padding:0 0 14px 14px;position:relative}.tl:before{content:'';position:absolute;left:-5px;top:4px;width:8px;height:8px;border-radius:50%;background:#f2681c}
.tl-t{font-size:.8rem;color:#9ca3af}.tl-x{margin-top:2px}
.an-top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px}.dfilt{display:flex;gap:8px;flex-wrap:wrap}
.fn{margin:14px 0}.fn-h{display:flex;justify-content:space-between;font-size:.92rem;margin-bottom:6px}.fn-bar{background:#eef0f2;border-radius:8px;overflow:hidden;height:22px}.fn-bar span{display:block;height:100%}
.drop{color:#dc2626;font-size:.82rem;margin:-6px 0 6px}
.an{width:100%;border-collapse:collapse}.an td,.an th{padding:9px 0;border-bottom:1px solid #eef0f2;text-align:left}.an td.n,.an th.n{text-align:right;font-weight:800}
.msg.ok{background:#dcfce7;color:#15803d;padding:10px 14px;border-radius:8px;margin-bottom:14px}
.msg.warn{background:#fef3c7;color:#92400e;padding:10px 14px;border-radius:8px;margin-bottom:14px}
.fld{margin-bottom:12px}
.dz{width:100%;border-collapse:collapse;font-size:.92rem}
.dz th{background:#18181b;color:#fff;padding:9px 10px;text-align:left;font-size:.8rem}
.dz th.n,.dz td.n{text-align:right}
.dz td{padding:8px 10px;border-bottom:1px solid #eef0f2}
.dz tr.grp td{background:#f4f5f7;font-weight:800}
.dz tr.tot td{font-weight:800;border-top:2px solid #18181b}
.dz input{padding:6px 8px;font-size:.9rem}
.opt{display:flex;flex-wrap:wrap;gap:10px;margin-top:8px}
.opt label{display:flex;align-items:center;gap:7px;background:#fff;border:1px solid #d1d5db;border-radius:9px;padding:9px 13px;cursor:pointer;font-weight:600;font-size:.92rem}
.opt input{width:auto}
.opt label.on{border-color:#f2681c;background:#fff7ed}
@media(max-width:920px){.kpis{grid-template-columns:repeat(2,1fr)}.grid2,.grid3{grid-template-columns:1fr}.nav .ext{display:none}.nav .in{overflow-x:auto}}
@media print{.nav,.no-print{display:none!important}.wrap{max-width:none;padding:0}.card{border:0;padding:0}}
</style></head><body>
<div class="nav no-print"><div class="in"><span class="brand">Zugrav <span>Iași</span> CRM</span>
<?php foreach (crm_nav_items() as $n) {
    $on = ($active === $n[2]) ? ' on' : '';
    echo '<a class="n' . $on . '" href="' . $n[0] . '">' . crm_h($n[1]) . '</a>';
} ?>
<span class="sp"></span><a class="ext" href="https://zugravimiasi.ro/" target="_blank">Vezi site-ul ↗</a><a class="out" href="/crm/?logout=1">Ieși</a></div></div>
<div class="wrap">
<?php }

function crm_foot() { echo "</div></body></html>"; }
