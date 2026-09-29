<?php

declare(strict_types=1);

/**
 * TV eingeschaltet: UpdateAll liest Lautstärke, Stummschaltung und laufenden Eingang
 * (dazu Befund 10 des Reviews vom 29.09.2026: läuft kein physischer Eingang, steht die Variable auf -1).
 *
 * Mitschnitte: fixtures/aktiv (HDMI 3 läuft), fixtures/illegal-state (getPlayingContentInfo meldet Fehler 7),
 * fixtures/tuner, fixtures/spiegelung und fixtures/app-im-vordergrund (Netflix, meldet ebenfalls Fehler 7).
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

// --- Befund 10: es läuft kein physischer Eingang ---
// HDMI 3 läuft (Mitschnitt aktiv), dann wird am TV auf Tuner bzw. Bildschirmspiegelung umgeschaltet
foreach (['tuner' => 'TV-Tuner', 'spiegelung' => 'Bildschirmspiegelung'] as $zustand => $name) {
    $m = konfigurierteInstanz('aktiv');
    $m->UpdateAll();
    pruefe($m->werte()['InputSource'] === $m->wertVon('InputSource', 'HDMI 3/ARC'), "$name, Ausgangslage: Variable zeigt HDMI 3/ARC");
    $m->antworten['avContent/getPlayingContentInfo'] = mitschnitt($zustand, 'avContent_getPlayingContentInfo');
    $m->marke();
    pruefe($m->UpdateAll() === true && $m->werte()['PowerStatus'] === 2, "$name: UpdateAll liefert true, TV bleibt An");
    pruefe($m->werte()['InputSource'] === -1, "$name: InputSource = -1 statt des alten Werts");
    pruefe($m->werte()['Application'] === -1, "$name: Application = -1");
    pruefe($m->logsSeitMarke() === [], "$name erzeugt keinen Logeintrag");
}

// --- App im Vordergrund (Mitschnitt: Netflix über das Modul gestartet, der TV meldet dauerhaft Fehler 7) ---
$m = konfigurierteInstanz('aktiv');
$m->UpdateAll();
// die Antwort auf setActiveApp ist nicht mitgeschnitten; so antwortet der TV auf setPlayContent
$m->antworten['appControl/setActiveApp'] = mitschnitt('tuner', 'avContent_setPlayContent');
pruefe($m->StartApplication('Netflix') === true, 'App: Netflix gestartet');
$m->antworten['avContent/getPlayingContentInfo'] = mitschnitt('app-im-vordergrund', 'avContent_getPlayingContentInfo');
$m->marke();
pruefe($m->UpdateAll() === true && $m->werte()['PowerStatus'] === 2, 'App: UpdateAll liefert true, TV bleibt An');
pruefe($m->werte()['InputSource'] === -1, 'App: InputSource = -1 statt HDMI 3/ARC');
pruefe($m->werte()['Application'] === $m->wertVon('Application', 'Netflix'), 'App: Application zeigt weiter Netflix');
pruefe($m->logsSeitMarke() === [], 'App im Vordergrund erzeugt keinen Logeintrag');

// zurück auf HDMI 3: die App ist nicht mehr im Vordergrund
$m->antworten['avContent/getPlayingContentInfo'] = mitschnitt('aktiv', 'avContent_getPlayingContentInfo');
$m->UpdateAll();
pruefe($m->werte()['InputSource'] === $m->wertVon('InputSource', 'HDMI 3/ARC') && $m->werte()['Application'] === -1, 'wieder HDMI 3: InputSource zeigt den Eingang, Application = -1');

// Attribut aus einer früheren Version ('' statt JSON) bricht nicht ab
$m->attributSetzen('SourceList', '');
$m->attributSetzen('RemoteControllerInfo', '');
$meldung = meldungVon(fn () => $m->UpdateAll());
pruefe($meldung === null, 'leeres Attribut SourceList: UpdateAll läuft durch' . ($meldung === null ? '' : ': ' . substr($meldung, 0, 60)));
$meldung = meldungVon(fn () => $m->SendRemoteKey('Home'));
pruefe($meldung === 'Remote key list not yet read. Please update the remote key list.', 'leeres Attribut RemoteControllerInfo: Meldung statt Absturz');

ergebnis();
