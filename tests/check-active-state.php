<?php

declare(strict_types=1);

/**
 * TV eingeschaltet: UpdateAll liest Lautstärke, Stummschaltung und laufenden Eingang;
 * Bootphase (getPowerStatus meldet fälschlich „active").
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
pruefe($werte['InputSource'] === 2, 'InputSource = 2 (HDMI 3/ARC, Index in der SourceList)');
pruefe(array_column($m->anfragen, 'method') === ['getPowerStatus', 'getVolumeInformation', 'getPlayingContentInfo'], 'UpdateAll fragt Power-Status, Lautstärke und Eingang ab');

// Laufender Inhalt ist kein physischer Eingang (z. B. eine App) -> keine Änderung am Eingang
$m->antworten['avContent/getPlayingContentInfo'] = '{"result":[{"uri":"extInput:widi?port=1","source":"extInput:widi","title":"Bildschirm spiegeln"}],"id":1}';
$m->UpdateAll();
pruefe($m->werte()['InputSource'] === 2, 'unbekannter Inhalt ändert InputSource nicht');

// getPlayingContentInfo mit Fehler 7 (Illegal State, z. B. während eine App läuft) -> Eingang -1
$m->antworten['avContent/getPlayingContentInfo'] = '{"error":[7,"Illegal State"],"id":1}';
$m->UpdateAll();
pruefe($m->werte()['InputSource'] === -1, 'Illegal State: InputSource = -1');

// Bootphase: nach einem Fehlschlag meldet getPowerStatus „active", Content-Info ist noch nicht bereit
$m = konfigurierteInstanz('aktiv');
$m->antworten['system/getPowerStatus'] = CURLE_OPERATION_TIMEDOUT;
$m->UpdateAll(); // setzt den Zeitstempel des Fehlschlags
$m->antworten['system/getPowerStatus']         = (string)file_get_contents(__DIR__ . '/fixtures/aktiv/system_getPowerStatus.json');
$m->antworten['avContent/getPlayingContentInfo'] = '{"error":[7,"Illegal State"],"id":1}';
$m->marke();
pruefe($m->UpdateAll() === false, 'Bootphase: UpdateAll liefert false');
pruefe(!in_array(['PowerStatus', 2], $m->writes, true), 'Bootphase: PowerStatus wird nicht auf Eingeschaltet gesetzt');

$m->antworten['avContent/getPlayingContentInfo'] = (string)file_get_contents(__DIR__ . '/fixtures/aktiv/avContent_getPlayingContentInfo.json');
pruefe($m->UpdateAll() === true && $m->werte()['PowerStatus'] === 2, 'nach der Bootphase: PowerStatus = 2');

ergebnis();
