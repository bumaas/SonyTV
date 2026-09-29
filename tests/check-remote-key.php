<?php

declare(strict_types=1);

/**
 * Fernbedienungstasten: HTTP-Status der Antwort, Nachladen der Tastenliste, Schreiben erst nach Erfolg
 * (Befunde 4, 8 und 9 des Reviews vom 29.09.2026).
 *
 * Mitschnitt: /sony/IRCC antwortet bei falschem Pre-Shared Key mit HTTP 403 und leerem Inhalt.
 *
 * Aufruf: php tests/check-remote-key.php
 */

require_once __DIR__ . '/harness.php';

// --- Befund 4: der HTTP-Status entscheidet ---
$m                               = konfigurierteInstanz('aktiv');
$m->antworten['IRCC/X_SendIRCC'] = ['http' => 403, 'body' => ''];
$m->marke();
pruefe($m->SendRemoteKey('Home') === false, 'HTTP 403: SendRemoteKey liefert false');
pruefe(!in_array('SendRemoteKey', array_column($m->writes, 0), true), 'HTTP 403: Variable wird nicht geschrieben');
pruefe(count(array_filter($m->logsSeitMarke(), fn (array $e): bool => str_contains($e['Message'], 'HTTP status 403'))) === 1, 'HTTP 403 steht im Log');

$m->antworten['IRCC/X_SendIRCC'] = ['http' => 200, 'body' => ''];
$m->marke();
pruefe($m->SendRemoteKey('Home') === true, 'HTTP 200: SendRemoteKey liefert true');
pruefe($m->writes === [['SendRemoteKey', $m->wertVon('SendRemoteKey', 'Home')]], 'HTTP 200: Variable zeigt die gesendete Taste');

// --- Befund 9: Auswahl in der Visualisierung schreibt erst nach Erfolg ---
$m->antworten['IRCC/X_SendIRCC'] = ['http' => 403, 'body' => ''];
$vorher                          = $m->werte()['SendRemoteKey'];
$m->RequestAction('SendRemoteKey', $m->wertVon('SendRemoteKey', 'VolumeUp'));
pruefe($m->werte()['SendRemoteKey'] === $vorher, 'Auswahl ohne Erfolg: Variable bleibt unverändert');

// unbekannte Taste geht nicht raus
$m->marke();
$meldung = meldungVon(fn () => $m->SendRemoteKey('GibtEsNicht'));
pruefe($meldung === 'Invalid RemoteKey: GibtEsNicht' && $m->anfragen === [], 'unbekannte Taste: Meldung, keine Anfrage');

// --- Befund 8: TV war beim Übernehmen aus ---
SonyTVHarness::$ping = false;
$m                   = konfigurierteInstanz('aktiv');
pruefe($m->optionen('SendRemoteKey') === [-1 => '-'] && $m->werte()['SendRemoteKey'] === -1, 'TV aus: Tastenliste leer, Variable auf „keine Auswahl"');
$meldung = meldungVon(fn () => $m->SendRemoteKey('Home'));
pruefe($meldung === 'Remote key list not yet read. Please update the remote key list.', 'ohne Tastenliste: Meldung nennt den Knopf, nicht die Registrierung');

// Knopf im Formular
SonyTVHarness::$ping = true;
$m->RequestAction('UpdateRemoteKeyList', 0);
pruefe(count($m->optionen('SendRemoteKey')) === 147, 'Knopf „Update remote key list" liest die 146 Tasten');
$formular = json_decode((string)file_get_contents(dirname(__DIR__) . '/Sony TV/form.json'), true, 512, JSON_THROW_ON_ERROR);
$onClicks = array_column($formular['actions'], 'onClick');
pruefe(in_array("IPS_RequestAction(\$id, 'UpdateRemoteKeyList', 0);", $onClicks, true), 'form.json hat den Knopf');

// Listen werden nachgeholt, sobald der TV erreichbar ist
SonyTVHarness::$ping = false;
$m                   = konfigurierteInstanz('aktiv');
SonyTVHarness::$ping = true;
$m->marke();
$m->UpdateAll();
pruefe(count($m->optionen('SendRemoteKey')) === 147 && count($m->optionen('InputSource')) === 7 && count($m->optionen('Application')) === 28, 'TV wird erreichbar: fehlende Listen werden nachgeladen');
SonyTVHarness::$uhr += 100; // Bootphase vorbei
$m->marke();
$m->UpdateAll();
pruefe(array_column($m->anfragen, 'method') === ['getPowerStatus', 'getVolumeInformation', 'getPlayingContentInfo'], 'danach fragt UpdateAll die Listen nicht erneut ab');

// Robustheit gegen eine fehlerhafte Antwort (kein Mitschnitt): error-Feld, das keine Liste ist
$m->antworten['system/getInterfaceInformation'] = '{"error":"kaputt","id":1}';
$meldung                                        = meldungVon(fn () => $m->SendRestAPIRequest('system', 'getInterfaceInformation', '[]', '1.0'));
pruefe($meldung === null, 'error-Feld ohne Liste bricht nicht ab' . ($meldung === null ? '' : ': ' . substr($meldung, 0, 60)));

ergebnis();
