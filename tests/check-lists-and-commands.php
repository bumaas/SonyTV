<?php

declare(strict_types=1);

/**
 * Quellen-, App- und Tastenlisten aus den Mitschnitten und die Befehle, die daraus entstehen.
 *
 * Aufruf: php tests/check-lists-and-commands.php
 */

require_once __DIR__ . '/harness.php';

$m = konfigurierteInstanz('standby');

// Quellen: nur physische Eingänge (hdmi, composite, component), keine Bildschirmspiegelung
$quellen = json_decode($m->attribut('SourceList'), true, 512, JSON_THROW_ON_ERROR);
pruefe(array_column($quellen, 'title') === ['HDMI 1', 'HDMI 2', 'HDMI 3/ARC', 'HDMI 4', 'AV 1', 'AV 2/Component'], 'SourceList enthält die sechs physischen Eingänge');

// Apps: vollständig im Attribut
$apps = json_decode($m->attribut('ApplicationList'), true, 512, JSON_THROW_ON_ERROR);
pruefe(count($apps) === 27, 'ApplicationList enthält alle 27 Apps');
pruefe($m->ReadApplicationList() === $m->attribut('ApplicationList'), 'ReadApplicationList liefert dieselbe Liste wie das Attribut');

// Fernbedienung: vollständig im Attribut
$tasten = json_decode($m->attribut('RemoteControllerInfo'), true, 512, JSON_THROW_ON_ERROR);
pruefe(count($tasten) === 146, 'RemoteControllerInfo enthält alle 146 Tasten');

// Eingang umschalten
$m->antworten['avContent/setPlayContent'] = '{"result":[],"id":1}';
$m->marke();
pruefe($m->SetInputSource('HDMI 2') === true, 'SetInputSource(HDMI 2) meldet Erfolg');
pruefe($m->anfragen[0]['method'] === 'setPlayContent' && $m->anfragen[0]['params'] === [['uri' => 'extInput:hdmi?port=2']], 'SetInputSource schickt die URI des Eingangs');

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

$m->antworten['audio/setAudioVolume'] = '{"error":[40005,"Display Is Turned off"],"id":1}';
pruefe($m->SetSpeakerVolume(40) === false && $m->werte()['SpeakerVolume'] === 25, 'bei Fehlerantwort bleibt die Lautstärke-Variable unverändert');

ergebnis();
