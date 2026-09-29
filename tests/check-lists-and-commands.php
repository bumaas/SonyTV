<?php

declare(strict_types=1);

/**
 * Quellen-, App- und Tastenlisten aus den Mitschnitten und die Befehle, die daraus entstehen.
 * Dazu die Befunde 7, 9, 11 und 12 des Reviews vom 29.09.2026: Namen mit Sonderzeichen, Schreiben erst
 * nach Erfolg, feste Werte je Listeneintrag, Werte fremden Typs in RequestAction.
 *
 * Aufruf: php tests/check-lists-and-commands.php
 */

require_once __DIR__ . '/harness.php';

$m = konfigurierteInstanz('standby');

// Quellen: nur physische Eingänge (hdmi, composite, component), keine Bildschirmspiegelung
$quellen = json_decode($m->attribut('SourceList'), true, 512, JSON_THROW_ON_ERROR);
pruefe(array_column($quellen, 'title') === ['HDMI 1', 'HDMI 2', 'HDMI 3/ARC', 'HDMI 4', 'AV 1', 'AV 2/Component'], 'SourceList enthält die sechs physischen Eingänge');

// Apps und Tasten: vollständig im Attribut
$apps = json_decode($m->attribut('ApplicationList'), true, 512, JSON_THROW_ON_ERROR);
pruefe(count($apps) === 27, 'ApplicationList enthält alle 27 Apps');
pruefe($m->ReadApplicationList() === $m->attribut('ApplicationList'), 'ReadApplicationList liefert dieselbe Liste wie das Attribut');
$tasten = json_decode($m->attribut('RemoteControllerInfo'), true, 512, JSON_THROW_ON_ERROR);
pruefe(count($tasten) === 146, 'RemoteControllerInfo enthält alle 146 Tasten');

// Eingang umschalten (Antwort des TV aus dem Mitschnitt tuner)
$m->antworten['avContent/setPlayContent'] = mitschnitt('tuner', 'avContent_setPlayContent');
$m->marke();
pruefe($m->SetInputSource('HDMI 2') === true, 'SetInputSource(HDMI 2) meldet Erfolg');
pruefe($m->anfragen[0]['method'] === 'setPlayContent' && $m->anfragen[0]['params'] === [['uri' => 'extInput:hdmi?port=2']], 'SetInputSource schickt die URI des Eingangs');
pruefe($m->writes === [['InputSource', $m->wertVon('InputSource', 'HDMI 2')]], 'nach Erfolg zeigt die Variable den Eingang');

// App starten
$m->antworten['appControl/setActiveApp'] = '{"result":[],"id":1}';
$m->marke();
pruefe($m->StartApplication('Play Store') === true, 'StartApplication(Play Store) meldet Erfolg');
pruefe($m->anfragen[0]['params'] === [['uri' => $apps[0]['uri']]], 'StartApplication schickt die URI der App');

// Fernbedienungstaste
$m->antworten['IRCC/X_SendIRCC'] = '';
$m->marke();
pruefe($m->SendRemoteKey('Num1') === true, 'SendRemoteKey(Num1) meldet Erfolg');
pruefe(str_contains($m->anfragen[0]['data'], '<IRCCCode>' . $tasten[0]['value'] . '</IRCCCode>'), 'SendRemoteKey schickt den IRCC-Code der Taste');
pruefe(in_array('SOAPAction: "urn:schemas-sony-com:service:IRCC:1#X_SendIRCC"', $m->anfragen[0]['headers'], true), 'SendRemoteKey setzt die SOAPAction');

// Lautstärke und Stummschaltung setzen die Variable erst nach Bestätigung durch den TV
$m->antworten['audio/setAudioVolume'] = '{"result":[0],"id":1}';
$m->antworten['audio/setAudioMute']   = '{"result":[0],"id":1}';
pruefe($m->SetSpeakerVolume(25) === true && $m->werte()['SpeakerVolume'] === 25, 'SetSpeakerVolume(25) setzt die Variable');
pruefe($m->anfragen[count($m->anfragen) - 1]['params'] === [['target' => 'speaker', 'volume' => '25']], 'SetSpeakerVolume schickt die Lautstärke als String');
pruefe($m->SetAudioMute(true) === true && $m->werte()['AudioMute'] === true, 'SetAudioMute(true) setzt die Variable');

// Mitschnitt standby: der TV lehnt mit 40005 „Display Is Turned off" ab
$m->antworten['audio/setAudioVolume'] = mitschnitt('standby', 'audio_getVolumeInformation');
pruefe($m->SetSpeakerVolume(40) === false && $m->werte()['SpeakerVolume'] === 25, 'bei Fehlerantwort bleibt die Lautstärke-Variable unverändert');

// --- Befund 7: Namen mit Sonderzeichen ---
pruefe($apps[11]['title'] === 'Play Filme &amp; Serien', 'Mitschnitt: der TV liefert den App-Namen mit &amp;');
pruefe(in_array('Play Filme & Serien', $m->optionen('Application'), true), 'die Auswahlliste zeigt „Play Filme & Serien"');
foreach (['Play Filme & Serien', 'Play Filme &amp; Serien'] as $name) {
    $m->marke();
    pruefe($m->StartApplication($name) === true && ($m->anfragen[0]['params'] ?? null) === [['uri' => $apps[11]['uri']]], "StartApplication('$name') findet die URI");
}
foreach ([['StartApplication', 'Gibt es nicht', 'Unknown application: Gibt es nicht'], ['SetInputSource', 'HDMI 9', 'Unknown input source: HDMI 9']] as [$funktion, $name, $erwartet]) {
    $m->marke();
    $meldung = meldungVon(fn () => $m->$funktion($name));
    pruefe($meldung === $erwartet && $m->anfragen === [], "$funktion('$name'): Meldung, kein Befehl mit leerer URI");
}

// --- Befund 9: Auswahl schreibt erst nach Erfolg ---
$m->antworten['avContent/setPlayContent'] = mitschnitt('standby', 'audio_getVolumeInformation');
$vorher                                   = $m->werte()['InputSource'];
$m->RequestAction('InputSource', $m->wertVon('InputSource', 'HDMI 4'));
pruefe($m->werte()['InputSource'] === $vorher, 'Eingang gewählt, TV lehnt ab (40005): Variable bleibt unverändert');
$m->antworten['appControl/setActiveApp'] = mitschnitt('standby', 'audio_getVolumeInformation');
$vorher                                  = $m->werte()['Application'];
$m->RequestAction('Application', $m->wertVon('Application', 'Netflix'));
pruefe($m->werte()['Application'] === $vorher, 'App gewählt, TV lehnt ab (40005): Variable bleibt unverändert');

// --- Befund 11: feste Werte je Eintrag ---
$m->antworten['appControl/setActiveApp'] = '{"result":[],"id":1}';
$netflix                                 = $m->wertVon('Application', 'Netflix');
pruefe($netflix === 12, 'Erstbelegung: der Wert ist die bisherige Position (Netflix = 12)');

// der TV liefert die Liste später mit einer zusätzlichen App an erster Stelle und ohne den Play Store
$liste    = json_decode($m->antworten['appControl/getApplicationList'], true, 512, JSON_THROW_ON_ERROR);
$original = $liste['result'][0];
array_shift($liste['result'][0]);
array_unshift($liste['result'][0], ['title' => 'Neue App', 'uri' => 'com.sony.dtv.neu', 'icon' => '']);
$m->antworten['appControl/getApplicationList'] = json_encode($liste, JSON_THROW_ON_ERROR);
$m->RequestAction('UpdateApplicationList', 0);
pruefe($m->wertVon('Application', 'Netflix') === 12, 'Liste verschoben: Netflix behält den Wert 12');
pruefe($m->wertVon('Application', 'Neue App') === 27, 'neue App bekommt den nächsten freien Wert (27)');
pruefe(!in_array('Play Store', $m->optionen('Application'), true), 'entfallene App steht nicht mehr in der Auswahl');
$m->marke();
$m->RequestAction('Application', 12);
pruefe(($m->anfragen[0]['params'] ?? null) === [['uri' => $original[12]['uri']]], 'gespeicherter Wert 12 startet weiterhin Netflix');
$m->marke();
$m->RequestAction('Application', 0);
pruefe($m->anfragen === [], 'Wert der entfallenen App löst nichts aus');

// die entfallene App kommt zurück und bekommt ihren alten Wert
$liste['result'][0][]                          = $original[0];
$m->antworten['appControl/getApplicationList'] = json_encode($liste, JSON_THROW_ON_ERROR);
$m->RequestAction('UpdateApplicationList', 0);
pruefe($m->wertVon('Application', 'Play Store') === 0, 'wieder installierte App bekommt ihren alten Wert (0)');

// --- Befund 12: Werte fremden Typs ---
$m->antworten['system/setPowerStatus'] = '{"result":[],"id":1}';
foreach ([['2', true], [2, true], [true, true], ['0', false], [1, false], [false, false]] as [$wert, $erwartet]) {
    $m->marke();
    $m->RequestAction('PowerStatus', $wert);
    pruefe(($m->anfragen[0]['params'] ?? null) === [['status' => $erwartet]], 'PowerStatus ' . var_export($wert, true) . ' schaltet ' . ($erwartet ? 'ein' : 'aus'));
}
$m->antworten['audio/setAudioVolume'] = '{"result":[0],"id":1}';
$meldung                              = meldungVon(fn () => $m->RequestAction('SpeakerVolume', 30.0));
pruefe($meldung === null && $m->werte()['SpeakerVolume'] === 30, 'SpeakerVolume als Gleitkommazahl wird angenommen');
$meldung = meldungVon(fn () => $m->RequestAction('AudioMute', 'false'));
pruefe($meldung === null && $m->werte()['AudioMute'] === false, "AudioMute 'false' schaltet die Stummschaltung aus");
$m->marke();
$m->RequestAction('Application', '12');
pruefe(($m->anfragen[0]['params'] ?? null) === [['uri' => $original[12]['uri']]], "Application '12' als Text startet Netflix");

ergebnis();
