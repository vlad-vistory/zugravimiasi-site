<?php
// Conexiune la baza de date a CRM-ului (SQLite, stocata IN AFARA public_html ca sa supravietuiasca la deploy)
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
        nume TEXT, telefon TEXT, email TEXT,
        cand TEXT, deviz TEXT, mesaj TEXT, sursa TEXT, ip TEXT,
        status TEXT NOT NULL DEFAULT 'Nou',
        note TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT)");
    return $db;
}
function crm_get($db, $k) {
    $s = $db->prepare("SELECT v FROM settings WHERE k=?"); $s->execute([$k]);
    $r = $s->fetch(PDO::FETCH_ASSOC); return $r ? $r['v'] : null;
}
function crm_set($db, $k, $v) {
    $s = $db->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v");
    $s->execute([$k, $v]);
}
