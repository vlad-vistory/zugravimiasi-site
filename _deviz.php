<?php
// Motorul de deviz pentru lucrari de ZUGRAVIT (pereti + tavane).
// Tarifele sunt aceleasi cu cele afisate public pe site, ca sa nu iasa doua adevaruri.

const DEVIZ_ADAOS_MATERIAL = 1.25; // materialele se trec cu 25% peste pretul de achizitie

// manopera, lei/mp
function deviz_tarife() {
    return [
        'tapet'         => ['nume' => 'Îndepărtare tapet și curățare perete', 'p' => 25],
        'decopertare'   => ['nume' => 'Decopertare glet',                    'p' => 29],
        'tencuiala'     => ['nume' => 'Tencuială ipsos grosieră, 5–9 mm',    'p' => 19],
        'plasa'         => ['nume' => 'Plasă din fibră de sticlă',           'p' => 4],
        'plasa_acop'    => ['nume' => 'Tencuială ipsos, acoperire plasă',    'p' => 12],
        'rep_grosiere'  => ['nume' => 'Reparații grosiere',                  'p' => 7],
        'rep_finisaj'   => ['nume' => 'Reparații de finisaj',                'p' => 7],
        'slefuire'      => ['nume' => 'Șlefuire',                            'p' => 6],
        'slefuire_mec'  => ['nume' => 'Șlefuire mecanizată',                 'p' => 6],
        'desprafuire'   => ['nume' => 'Desprăfuire',                         'p' => 3],
        'amorsa'        => ['nume' => 'Amorsare',                            'p' => 4],
        'glet_grosier'  => ['nume' => 'Glet grosier, un strat',              'p' => 13],
        'glet_finisaj'  => ['nume' => 'Glet de finisaj, un strat',           'p' => 13],
        'amorsa_vopsire'=> ['nume' => 'Amorsare înainte de vopsire',         'p' => 4],
        'lavabila'      => ['nume' => 'Vopsea lavabilă, un strat',           'p' => 7],
        'lavabila_2c'   => ['nume' => 'Vopsea lavabilă 2 culori, un strat',  'p' => 8.5],
    ];
}

// materiale: pret pachet / cantitate pachet / consum pe mp
function deviz_materiale() {
    return [
        'amorsa'       => ['pachet' => 80,  'cant' => 10, 'um' => 'l',  'consum' => 0.1],
        'glet_grosier' => ['pachet' => 35,  'cant' => 20, 'um' => 'kg', 'consum' => 1.0],
        'glet_finisaj' => ['pachet' => 55,  'cant' => 20, 'um' => 'kg', 'consum' => 1.0],
        'lavabila'     => ['pachet' => 510, 'cant' => 15, 'um' => 'l',  'consum' => 0.13],
        'lavabila_2c'  => ['pachet' => 550, 'cant' => 15, 'um' => 'l',  'consum' => 0.13],
        'ipsos'        => ['pachet' => 40,  'cant' => 25, 'um' => 'kg', 'consum' => 8.0],
        'plasa'        => ['pachet' => 147, 'cant' => 50, 'um' => 'mp', 'consum' => 1.05],
    ];
}

function deviz_pret_material($key) {
    $m = deviz_materiale();
    if (!isset($m[$key])) return [0, 0, ''];
    $x = $m[$key];
    $pret_um = ($x['pachet'] * DEVIZ_ADAOS_MATERIAL) / $x['cant'];
    return [round($pret_um, 2), $x['consum'], $x['um']];
}

function deviz_optiuni_implicite() {
    return [
        'tapet' => 0, 'decopertare' => 0, 'tencuiala' => 0, 'plasa' => 0,
        'reparatii' => 1, 'glet' => 2, 'straturi' => 2, 'culori' => 1,
        'tavan_glet' => 1, 'tavan_straturi' => 2,
    ];
}

/**
 * Construieste liniile devizului.
 * $m = ['pereti' => mp, 'tavane' => mp]
 * $o = optiuni (vezi deviz_optiuni_implicite)
 */
function deviz_linii($m, $o) {
    $t = deviz_tarife();
    $o = array_merge(deviz_optiuni_implicite(), $o);
    $pereti = max(0, (float)($m['pereti'] ?? 0));
    $tavane = max(0, (float)($m['tavane'] ?? 0));
    $linii = [];

    $add = function ($grup, $key, $mp, $mat = null, $nume = null, $pret = null) use (&$linii, $t) {
        if ($mp <= 0) return;
        $tar = $t[$key] ?? ['nume' => $key, 'p' => 0];
        $mc = 0; $mp_pret = 0;
        if ($mat) { list($pu, $consum, $um) = deviz_pret_material($mat); $mc = $consum * $mp; $mp_pret = $pu; }
        $linii[] = [
            'grup' => $grup,
            'nume' => $nume ?: $tar['nume'],
            'um' => 'mp',
            'cantitate' => round($mp, 2),
            'pret_um' => $pret !== null ? $pret : $tar['p'],
            'material_cant' => round($mc, 2),
            'material_pret' => $mp_pret,
        ];
    };

    // ---------------- PERETI ----------------
    if ($pereti > 0) {
        $g = 'Pereți';
        if (!empty($o['tapet']))       $add($g, 'tapet', $pereti);
        if (!empty($o['decopertare'])) $add($g, 'decopertare', $pereti);
        if (!empty($o['tencuiala'])) {
            $add($g, 'tencuiala', $pereti, 'ipsos');
            if (!empty($o['plasa'])) { $add($g, 'plasa', $pereti, 'plasa'); $add($g, 'plasa_acop', $pereti, 'ipsos'); }
        }
        if (!empty($o['reparatii'])) { $add($g, 'rep_grosiere', $pereti); $add($g, 'rep_finisaj', $pereti); }

        $glet = (int)$o['glet'];
        if ($glet >= 1) {
            // pe perete curatat de tapet sau decopertat, gletul are nevoie de amorsa dedesubt
            if (!empty($o['tapet']) || !empty($o['decopertare'])) $add($g, 'amorsa', $pereti, 'amorsa');
            $add($g, 'glet_grosier', $pereti, 'glet_grosier', $glet >= 2 ? 'Glet, mâna 1' : 'Glet, un strat');
            if ($glet >= 2) $add($g, 'glet_finisaj', $pereti, 'glet_finisaj', 'Glet, mâna 2');
            $add($g, 'slefuire_mec', $pereti);
            $add($g, 'desprafuire', $pereti);
        } else {
            $add($g, 'slefuire', $pereti);
            $add($g, 'desprafuire', $pereti);
        }

        $str = (int)$o['straturi'];
        if ($str >= 1) {
            $add($g, 'amorsa_vopsire', $pereti, 'amorsa');
            $doua = ((int)$o['culori']) >= 2;
            $key = $doua ? 'lavabila_2c' : 'lavabila';
            $mat = $doua ? 'lavabila_2c' : 'lavabila';
            for ($i = 1; $i <= $str; $i++) {
                $add($g, $key, $pereti, $mat, ($doua ? 'Vopsea lavabilă 2 culori' : 'Vopsea lavabilă') . ', stratul ' . $i);
            }
        }
    }

    // ---------------- TAVANE ----------------
    if ($tavane > 0) {
        $g = 'Tavane';
        if (!empty($o['tavan_decopertare'])) $add($g, 'decopertare', $tavane);
        $tg = (int)$o['tavan_glet'];
        if ($tg >= 1) {
            $add($g, 'glet_grosier', $tavane, 'glet_grosier', $tg >= 2 ? 'Glet, mâna 1' : 'Glet, un strat');
            if ($tg >= 2) $add($g, 'glet_finisaj', $tavane, 'glet_finisaj', 'Glet, mâna 2');
            $add($g, 'slefuire_mec', $tavane);
            $add($g, 'desprafuire', $tavane);
        } else {
            $add($g, 'slefuire', $tavane);
            $add($g, 'desprafuire', $tavane);
        }
        $ts = (int)$o['tavan_straturi'];
        if ($ts >= 1) {
            $add($g, 'amorsa_vopsire', $tavane, 'amorsa');
            for ($i = 1; $i <= $ts; $i++) $add($g, 'lavabila', $tavane, 'lavabila', 'Vopsea lavabilă, stratul ' . $i);
        }
    }

    foreach ($linii as $i => $l) { $linii[$i]['pozitie'] = $i; }
    return $linii;
}

function deviz_totaluri($linii) {
    $t = ['manopera' => 0, 'material' => 0, 'total' => 0, 'grupuri' => []];
    foreach ($linii as $l) {
        $man = (float)$l['cantitate'] * (float)$l['pret_um'];
        $mat = (float)$l['material_cant'] * (float)$l['material_pret'];
        $g = $l['grup'];
        if (!isset($t['grupuri'][$g])) $t['grupuri'][$g] = ['manopera' => 0, 'material' => 0];
        $t['grupuri'][$g]['manopera'] += $man;
        $t['grupuri'][$g]['material'] += $mat;
        $t['manopera'] += $man;
        $t['material'] += $mat;
    }
    $t['total'] = $t['manopera'] + $t['material'];
    return $t;
}

// suprafete pornind de la dimensiunile camerei
function deviz_din_camera($lungime, $latime, $inaltime = 2.5) {
    $l = (float)$lungime; $w = (float)$latime; $h = (float)$inaltime;
    return ['pereti' => round(2 * ($l + $w) * $h, 2), 'tavane' => round($l * $w, 2)];
}

function deviz_lei($v) { return number_format(round($v, 2), 2, ',', '.') . ' lei'; }
function deviz_nr($v)  { return rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ','); }
