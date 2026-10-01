<?php

declare(strict_types=1);

/**
 * Power-Status: einzelner Aussetzer, Bootphase und Fehlschlag beim Schalten
 * (Befunde 2 und 3 des Reviews vom 29.09.2026).
 *
 * Mitschnitte: fixtures/illegal-state (Fehler 7 bei eingeschaltetem TV), fixtures/falscher-psk (HTTP 403).
 * Fehler 7 meldet der TV, solange eine App im Vordergrund ist (fixtures/app-im-vordergrund, wortgleich).
 * Der Aussetzer (curl-Fehler 28) stammt aus demselben Mitschnitt, siehe fixtures/README.md.
 *
 * Aufruf: php tests/check-power-status.php
 */

require_once __DIR__ . '/harness.php';

$fehler7 = mitschnitt('illegal-state', 'avContent_getPlayingContentInfo');

// --- Befund 2: ein einzelner Aussetzer bei laufendem TV ---
$m                                           = konfigurierteInstanz('aktiv');
$m->antworten['avContent/getPlayingContentInfo'] = $fehler7;
$m->UpdateAll();
pruefe($m->werte()['PowerStatus'] === 2, 'Ausgangslage: TV An, getPlayingContentInfo meldet Fehler 7');

$m->antworten['system/getPowerStatus'] = CURLE_OPERATION_TIMEDOUT;
$m->marke();
pruefe($m->UpdateAll() === false, 'Aussetzer: UpdateAll liefert false (Zustand unbekannt)');
pruefe(array_column($m->anfragen, 'method') === ['getPowerStatus', 'getPowerStatus'], 'Aussetzer: getPowerStatus wird einmal wiederholt');
pruefe($m->pausen === [3], 'zwischen den Versuchen 3 s Pause');
pruefe($m->werte()['PowerStatus'] === 2 && $m->instanzStatus() === IS_ACTIVE, 'Aussetzer: TV bleibt An, Instanz bleibt aktiv');
pruefe(!in_array('PowerStatus', array_column($m->writes, 0), true), 'Aussetzer: PowerStatus wird nicht geschrieben');

$m->antworten['system/getPowerStatus'] = mitschnitt('aktiv', 'system_getPowerStatus');
$m->marke();
pruefe($m->UpdateAll() === true && $m->werte()['PowerStatus'] === 2, 'nächste Abfrage: TV sofort wieder An, keine 90 s Wartezeit');

// zwei Aussetzer in Folge gelten als „Aus"
$m->antworten['system/getPowerStatus'] = CURLE_OPERATION_TIMEDOUT;
$m->UpdateAll();
$m->marke();
pruefe($m->UpdateAll() === true, 'zweiter Aussetzer in Folge: Zustand ist ermittelt');
pruefe($m->werte()['PowerStatus'] === 0 && $m->instanzStatus() === 201, 'zweiter Aussetzer in Folge: PowerStatus Aus, Status 201');

// --- Bootphase nach „ganz aus" ---
$m                   = konfigurierteInstanz('aktiv');
SonyTVHarness::$ping = false;
$m->UpdateAll();
pruefe($m->werte()['PowerStatus'] === 0, 'TV vom Netz: PowerStatus Aus');

SonyTVHarness::$ping                         = true;
SonyTVHarness::$uhr                         += 30;
$m->antworten['avContent/getPlayingContentInfo'] = $fehler7;
$m->marke();
pruefe($m->UpdateAll() === false && $m->werte()['PowerStatus'] === 0, '30 s nach dem letzten Fehlschlag, Fehler 7: TV gilt noch als startend');

$m->antworten['avContent/getPlayingContentInfo'] = mitschnitt('aktiv', 'avContent_getPlayingContentInfo');
pruefe($m->UpdateAll() === true && $m->werte()['PowerStatus'] === 2, 'Inhalt verfügbar: TV ist An');

SonyTVHarness::$ping = false;
$m->UpdateAll();
$m->UpdateAll();
SonyTVHarness::$ping                         = true;
SonyTVHarness::$uhr                         += 91;
$m->antworten['avContent/getPlayingContentInfo'] = $fehler7;
pruefe($m->UpdateAll() === true && $m->werte()['PowerStatus'] === 2, '91 s nach dem letzten Fehlschlag: Fehler 7 hält den TV nicht mehr auf');
pruefe($fehler7 === mitschnitt('app-im-vordergrund', 'avContent_getPlayingContentInfo'), 'App im Vordergrund meldet denselben Fehler 7: startet der TV mit einer App, gilt er höchstens 90 s als startend');

// --- Befund 3: Schalten scheitert, der TV läuft weiter ---
$m                                     = konfigurierteInstanz('aktiv');
$m->antworten['system/setPowerStatus'] = ['http' => 403, 'body' => mitschnitt('falscher-psk', 'system_setPowerStatus')];
$m->marke();
$meldung = meldungVon(fn () => $m->RequestAction('PowerStatus', 0));
pruefe(array_column($m->anfragen, 'method') === ['setPowerStatus', 'getPowerStatus'], 'nach dem Fehlschlag wird der Zustand neu gelesen');
pruefe(str_starts_with((string)$meldung, 'Action "PowerStatus" with value 0 failed'), 'TV lehnt ab und bleibt an: RequestAction meldet den Fehlschlag');
pruefe(!in_array(['PowerStatus', 0], $m->writes, true), 'kein falsches „Aus" in der Variablen');
pruefe($m->werte()['PowerStatus'] === 2 && $m->instanzStatus() === 203, 'TV bleibt An, Status 203: die Ablehnung kam mit 403 (Schlüssel)');
pruefe($m->pausen === [], 'nach einem Fehlschlag keine Wartezeit');

// Schalten gelingt: 2 s warten, dann neu lesen
$m->antworten['system/setPowerStatus'] = '{"result":[],"id":1}';
$m->antworten['system/getPowerStatus'] = mitschnitt('standby', 'system_getPowerStatus');
$m->marke();
$m->RequestAction('PowerStatus', 0);
pruefe($m->pausen === [2] && $m->werte()['PowerStatus'] === 1, 'Ausschalten gelingt: 2 s Pause, danach Standby');
pruefe($m->anfragen[0]['params'] === [['status' => false]], 'Ausschalten sendet status false');

ergebnis();
