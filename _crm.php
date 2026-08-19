<?php
// Baza de date a CRM-ului (SQLite, in afara public_html ca sa supravietuiasca la deploy)
function crm_dir() {
    $root = !empty($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : dirname(__DIR__);
    return dirname($root) . '/crm-data';
}
function crm_db() {
    static $db = null;
    if ($db !== null) return $db;
    $dir = crm_dir();
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    $db = new PDO('sqlite:' . $dir . '/leads.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS leads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created TEXT NOT NULL,
        session_id TEXT, nume TEXT, telefon TEXT, email TEXT,
        cand TEXT, deviz TEXT, mesaj TEXT,
        entry_page TEXT, submit_page TEXT, button TEXT, source TEXT, device TEXT, ip TEXT,
        status TEXT NOT NULL DEFAULT 'Nou',
        value INTEGER NOT NULL DEFAULT 0,
        trashed INTEGER NOT NULL DEFAULT 0
    )");
    // migrare pentru baze de date mai vechi (adauga coloane lipsa)
    foreach ([
        'session_id TEXT','entry_page TEXT','submit_page TEXT','button TEXT','source TEXT','device TEXT',
        "value INTEGER NOT NULL DEFAULT 0","trashed INTEGER NOT NULL DEFAULT 0",
        // urmarire si follow-up (dupa modelul CRM-ului zugravescu)
        'channel TEXT',            // google-ads / meta-ads / google / facebook / direct ...
        'source_note TEXT',        // "de la Ion Popescu" pentru lead-uri offline
        'interest TEXT',           // Interesat / Vrea doar un pret / Neinteresat
        'visit_at TEXT',           // data vizionarii
        'visit_time TEXT',         // ora vizionarii
        'work_starts_at TEXT',     // data de start a lucrarii
        'lost_reason TEXT',        // motivul pierderii
        'contacted_at TEXT',       // cand a iesit prima data din "Nou" (viteza de reactie)
        'viewed_at TEXT',          // cand a fost deschisa prima data fisa (necitit)
        'quote_price INTEGER NOT NULL DEFAULT 0', // totalul devizului, sincronizat automat
        'suprafata TEXT',          // suprafata declarata in formular, daca exista
        'oras TEXT',               // orasul clientului
        'adresa TEXT',             // adresa lucrarii
        'tip_imobil TEXT'          // Apartament / Casa / Spatiu comercial
    ] as $col) {
        try { $db->exec("ALTER TABLE leads ADD COLUMN $col"); } catch (Exception $e) {}
    }
    $db->exec("CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id TEXT, type TEXT, page TEXT, created TEXT)");
    foreach (['field TEXT','channel TEXT','device TEXT'] as $col) {
        try { $db->exec("ALTER TABLE events ADD COLUMN $col"); } catch (Exception $e) {}
    }
    $db->exec("CREATE TABLE IF NOT EXISTS notes (id INTEGER PRIMARY KEY AUTOINCREMENT, lead_id INTEGER, text TEXT, created TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT)");

    // click-uri pe WhatsApp / email (conversii care nu trec prin formular)
    $db->exec("CREATE TABLE IF NOT EXISTS contact_clicks (
        id INTEGER PRIMARY KEY AUTOINCREMENT, created TEXT, kind TEXT, page TEXT, session_id TEXT, channel TEXT
    )");
    // cati au VAZUT butonul de ofertă (nu doar cati au apasat)
    $db->exec("CREATE TABLE IF NOT EXISTS cta_views (
        id INTEGER PRIMARY KEY AUTOINCREMENT, created TEXT, page TEXT, session_id TEXT
    )");
    // vizite pe pagini, cu vizitator anonim (hash zilnic)
    $db->exec("CREATE TABLE IF NOT EXISTS page_views (
        id INTEGER PRIMARY KEY AUTOINCREMENT, created TEXT, page TEXT, visitor TEXT, device TEXT, channel TEXT
    )");
    // cheltuieli de promovare, introduse manual -> cost pe lead
    $db->exec("CREATE TABLE IF NOT EXISTS ad_spend (
        id INTEGER PRIMARY KEY AUTOINCREMENT, created TEXT, luna TEXT, channel TEXT, suma INTEGER
    )");

    // ---- deviz (doar partea de zugravit) ----
    $db->exec("CREATE TABLE IF NOT EXISTS deviz (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        numar INTEGER,
        lead_id INTEGER,
        client_nume TEXT, client_telefon TEXT, client_email TEXT, adresa TEXT,
        titlu TEXT,
        status TEXT NOT NULL DEFAULT 'ciornă',
        masuratori TEXT,   -- json: pereti, tavane, camere
        optiuni TEXT,      -- json: tapet, decopertare, maini glet, culori...
        note TEXT,
        created TEXT, updated TEXT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS deviz_linii (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        deviz_id INTEGER,
        grup TEXT, nume TEXT, um TEXT,
        cantitate REAL, pret_um REAL,
        material_cant REAL, material_pret REAL,
        pozitie INTEGER NOT NULL DEFAULT 0,
        manual INTEGER NOT NULL DEFAULT 0
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_linii_deviz ON deviz_linii(deviz_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_pv_created ON page_views(created)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_leads_created ON leads(created)");
    return $db;
}
function crm_get($db, $k) { $s = $db->prepare("SELECT v FROM settings WHERE k=?"); $s->execute([$k]); $r = $s->fetch(PDO::FETCH_ASSOC); return $r ? $r['v'] : null; }
function crm_set($db, $k, $v) { $s = $db->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v"); $s->execute([$k, $v]); }
function crm_note($db, $lead_id, $text) { $db->prepare("INSERT INTO notes (lead_id,text,created) VALUES (?,?,?)")->execute([$lead_id, $text, date('Y-m-d H:i:s')]); }

// hash zilnic de vizitator: fara cookie, fara consimtamant, "unic" = unic intr-o zi
function crm_visitor_hash() {
    $db = crm_db();
    $salt = crm_get($db, 'salt');
    if (!$salt) { $salt = bin2hex(random_bytes(16)); crm_set($db, 'salt', $salt); }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return substr(hash('sha256', $salt . '|' . date('Y-m-d') . '|' . $ip . '|' . $ua), 0, 32);
}
function crm_is_bot() {
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') return true;
    foreach (['bot','crawl','spider','slurp','headless','preview','monitor','curl','wget','python','facebookexternalhit'] as $b) {
        if (strpos($ua, $b) !== false) return true;
    }
    return false;
}
