<?php

declare(strict_types=1);

/**
 * getPowerStatus antwortet mit Fehler 404 (MCP-Test vom 01.10.2026, Befund 5). Bis 2.2 build 37 ging die Antwort
 * als Rohmeldung „Notice: Unexpected return: {"error":[404,"Not Found"]}" ins Log, ohne Wiederholung und ohne
 * dass der Aussetzer gezählt wurde.
 *
 * Fixture fixtures/fehler-404: die Antwort des KD-75XE9405 am nuc, 01.10.2026 18:18:30, aus der Logzeile des
 * Moduls übernommen, das sie unverändert ausgegeben hat. In welchem Zustand der TV dabei war, ist nicht bekannt.
 *
 * Aufruf: php tests/check-power-status-404.php
 */

require_once __DIR__ . '/harness.php';

$fehler404 = mitschnitt('fehler-404', 'system_getPowerStatus');

$m = konfigurierteInstanz('aktiv');
pruefe($m->werte()['PowerStatus'] === 2 && $m->instanzStatus() === IS_ACTIVE, 'Ausgangslage: TV An, Instanz aktiv');

// erste 404: wie ein Aussetzer – einmal wiederholen, Zustand unbekannt, nichts ändern
$m->antworten['system/getPowerStatus'] = $fehler404;
$m->marke();
$ergebnis = null;
$meldung  = meldungVon(function () use ($m, &$ergebnis) {
    $ergebnis = $m->UpdateAll();
});
pruefe($meldung === null, 'Antwort 404: keine Notice' . ($meldung === null ? '' : ' (' . substr($meldung, 0, 70) . ')'));
pruefe($ergebnis === false, 'Antwort 404: UpdateAll liefert false (Zustand unbekannt)');
pruefe(array_column($m->anfragen, 'method') === ['getPowerStatus', 'getPowerStatus'] && $m->pausen === [3], 'Antwort 404: nach 3 s einmal wiederholt');
pruefe($m->werte()['PowerStatus'] === 2 && $m->instanzStatus() === IS_ACTIVE, 'Antwort 404 einmal: TV bleibt An, Instanz aktiv');
$roh = array_filter(array_column($m->logsSeitMarke(), 'Message'), static fn (string $s): bool => str_contains($s, 'Unexpected return'));
pruefe($roh === [], 'keine Rohmeldung „Unexpected return" im Log');

// zweite 404 in Folge: wie zwei Aussetzer in Folge
$m->marke();
meldungVon(fn () => $m->UpdateAll());
pruefe($m->werte()['PowerStatus'] === 0 && $m->instanzStatus() === 201, 'Antwort 404 zweimal in Folge: wie zwei Aussetzer, PowerStatus Aus, Status 201');

// danach wieder eine reguläre Antwort
$m->antworten['system/getPowerStatus'] = mitschnitt('aktiv', 'system_getPowerStatus');
pruefe($m->UpdateAll() === true && $m->werte()['PowerStatus'] === 2 && $m->instanzStatus() === IS_ACTIVE, 'reguläre Antwort: TV An, Instanz aktiv');

ergebnis();
