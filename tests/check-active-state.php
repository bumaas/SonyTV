<?php

declare(strict_types=1);

/**
 * TV eingeschaltet: UpdateAll liest Lautstärke, Stummschaltung und laufenden Eingang
 * (dazu Befund 10 des Reviews vom 29.09.2026: läuft kein physischer Eingang, steht die Variable auf -1).
 *
 * Mitschnitte: fixtures/aktiv (HDMI 3 läuft), fixtures/illegal-state (getPlayingContentInfo meldet Fehler 7).
 * Für Tuner, Bildschirmspiegelung und App im Vordergrund gibt es noch keine Mitschnitte; was der TV dann
 * meldet, prüft dieser Test nicht.
 *
 * Aufruf: php tests/check-active-state.php
 */

require_once __DIR__ . '/harness.php';

$m = konfigurierteInstanz('aktiv');
pruefe($m->instanzStatus() === IS_ACTIVE, 'TV eingeschaltet: Instanz aktiv');

// Quellenliste: physische Eingänge, CEC-Geräte und Bildschirmspiegelung bleiben draußen
$quellen = json_decode($m->attribut('SourceList'), true, 512, JSON_THROW_ON_ERROR);
pruefe(array_column($quellen, 'title') === ['HDMI 1', 'HDMI 2', 'HDMI 3/ARC', 'HDMI 4', 'AV 1', 'AV 2/Component'], 'SourceList: sechs physische Eingänge, keine CEC-Geräte');

$m->marke();
pruefe($m->UpdateAll() === true, 'UpdateAll liefert true');
$werte = $m->werte();
pruefe($werte['PowerStatus'] === 2, 'PowerStatus = 2 (Eingeschaltet)');
pruefe($werte['SpeakerVolume'] === 40, 'SpeakerVolume = 40 aus getVolumeInformation');
pruefe($werte['AudioMute'] === false, 'AudioMute = false');
pruefe($werte['InputSource'] === $m->wertVon('InputSource', 'HDMI 3/ARC'), 'InputSource zeigt HDMI 3/ARC');
pruefe($werte['Application'] === -1, 'läuft ein Eingang, steht Application auf „keine Auswahl"');
pruefe(array_column($m->anfragen, 'method') === ['getPowerStatus', 'getVolumeInformation', 'getPlayingContentInfo'], 'UpdateAll fragt Power-Status, Lautstärke und Eingang ab');

// getPlayingContentInfo meldet Fehler 7 (Mitschnitt illegal-state): kein Eingang
$m->antworten['avContent/getPlayingContentInfo'] = mitschnitt('illegal-state', 'avContent_getPlayingContentInfo');
$m->marke();
$m->UpdateAll();
pruefe($m->werte()['InputSource'] === -1, 'Fehler 7: InputSource = -1');
pruefe($m->logsSeitMarke() === [], 'Fehler 7 erzeugt keinen Logeintrag');

// --- Befund 10: der laufende Inhalt ist keiner der Eingänge aus der Liste ---
// HDMI 3 läuft (Mitschnitt aktiv), die Quellenliste dieser Instanz kennt aber nur HDMI 1
$m = neueInstanz();
$m->antwortenAus('aktiv');
$eingaenge                                                 = json_decode($m->antworten['avContent/getCurrentExternalInputsStatus'], true, 512, JSON_THROW_ON_ERROR);
$eingaenge['result'][0]                                    = array_values(array_filter($eingaenge['result'][0], fn (array $e): bool => $e['uri'] === 'extInput:hdmi?port=1'));
$m->antworten['avContent/getCurrentExternalInputsStatus'] = json_encode($eingaenge, JSON_THROW_ON_ERROR);
$m->antworten['avContent/setPlayContent']                 = '{"result":[],"id":1}';
IPS_SetProperty($m->id(), 'Host', '192.168.178.21');
IPS_ApplyChanges($m->id());
$m->RequestAction('InputSource', 0);
pruefe($m->werte()['InputSource'] === 0, 'Ausgangslage: Variable zeigt HDMI 1');
$m->marke();
$m->UpdateAll();
pruefe($m->werte()['InputSource'] === -1, 'laufender Inhalt passt zu keinem Eingang der Liste: InputSource = -1 statt des alten Werts');

// Attribut aus einer früheren Version ('' statt JSON) bricht nicht ab
$m->attributSetzen('SourceList', '');
$m->attributSetzen('RemoteControllerInfo', '');
$meldung = meldungVon(fn () => $m->UpdateAll());
pruefe($meldung === null, 'leeres Attribut SourceList: UpdateAll läuft durch' . ($meldung === null ? '' : ': ' . substr($meldung, 0, 60)));
$meldung = meldungVon(fn () => $m->SendRemoteKey('Home'));
pruefe($meldung === 'Remote key list not yet read. Please update the remote key list.', 'leeres Attribut RemoteControllerInfo: Meldung statt Absturz');

ergebnis();
