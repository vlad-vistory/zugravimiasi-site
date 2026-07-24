<?php
// Baza de date a CRM-ului (SQLite, in afara public_html ca sa supravietuiasca la deploy)
function crm_dir() {
    $root = !empty($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : dirname(__DIR__);
    return dirname($root) . '/crm-data';
}
function crm_db() {
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
    foreach (['session_id TEXT','entry_page TEXT','submit_page TEXT','button TEXT','source TEXT','device TEXT',
              "value INTEGER NOT NULL DEFAULT 0","trashed INTEGER NOT NULL DEFAULT 0"] as $col) {
        try { $db->exec("ALTER TABLE leads ADD COLUMN $col"); } catch (Exception $e) {}
    }
    $db->exec("CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id TEXT, type TEXT, page TEXT, created TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS notes (id INTEGER PRIMARY KEY AUTOINCREMENT, lead_id INTEGER, text TEXT, created TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT)");
    return $db;
}
function crm_get($db, $k) { $s = $db->prepare("SELECT v FROM settings WHERE k=?"); $s->execute([$k]); $r = $s->fetch(PDO::FETCH_ASSOC); return $r ? $r['v'] : null; }
function crm_set($db, $k, $v) { $s = $db->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v"); $s->execute([$k, $v]); }
function crm_note($db, $lead_id, $text) { $db->prepare("INSERT INTO notes (lead_id,text,created) VALUES (?,?,?)")->execute([$lead_id, $text, date('Y-m-d H:i:s')]); }
