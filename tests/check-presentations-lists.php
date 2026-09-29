<?php

declare(strict_types=1);

/**
 * Darstellungen statt Variablenprofile: Quellen, Apps und Fernbedienungstasten stehen als
 * Optionen einer Aufzählung an der jeweiligen Variable - je Instanz, ohne Obergrenze von 128,
 * ohne globale Profile STV.*. Die Auswahl in der Visualisierung schaltet über den Index der Liste.
 *
 * Aufruf: php tests/check-presentations-lists.php
 */

require_once __DIR__ . '/harness.php';

const ALTE_PROFILE = ['STV.Applications', 'STV.PowerStatus', 'STV.Volume', 'STV.RemoteKey', 'STV.Sources'];

/** Optionen der Aufzählung einer Variable (Wert => Beschriftung). */
function optionen(SonyTVHarness $m, string $ident): array
{
    $darstellung = IPS_GetVariable(IPS_GetObjectIDByIdent($ident, $m->id()))['VariablePresentation'];
    if (($darstellung['PRESENTATION'] ?? '') !== VARIABLE_PRESENTATION_ENUMERATION) {
        return [];
    }
    return array_column(json_decode($darstellung['OPTIONS'], true, 512, JSON_THROW_ON_ERROR), 'Caption', 'Value');
}

function darstellung(SonyTVHarness $m, string $ident): array
{
    return IPS_GetVariable(IPS_GetObjectIDByIdent($ident, $m->id()))['VariablePresentation'];
}

// Ein nicht mehr benutztes Altprofil wird aufgeräumt, ein von fremder Seite benutztes bleibt
IPS_CreateVariableProfile('STV.Sources', VARIABLETYPE_INTEGER);
IPS_CreateVariableProfile('STV.RemoteKey', VARIABLETYPE_INTEGER);
$fremd = IPS_CreateVariable(VARIABLETYPE_INTEGER);
IPS_SetVariableCustomProfile($fremd, 'STV.RemoteKey');

$m = konfigurierteInstanz('aktiv');

pruefe(!IPS_VariableProfileExists('STV.Sources'), 'unbenutztes Altprofil STV.Sources wird gelöscht');
pruefe(IPS_VariableProfileExists('STV.RemoteKey'), 'Altprofil STV.RemoteKey bleibt, solange eine fremde Variable es nutzt');
foreach (['STV.Applications', 'STV.PowerStatus', 'STV.Volume'] as $profil) {
    pruefe(!IPS_VariableProfileExists($profil), "kein neues Profil $profil");
}

// Feste Darstellungen
pruefe(optionen($m, 'PowerStatus') === [0 => 'Off', 1 => 'Standby', 2 => 'On'], 'PowerStatus: Aufzählung Off/Standby/On');
pruefe(darstellung($m, 'AudioMute')['PRESENTATION'] === VARIABLE_PRESENTATION_SWITCH, 'AudioMute: Schalter');
foreach (['SpeakerVolume', 'HeadphoneVolume'] as $ident) {
    $d = darstellung($m, $ident);
    pruefe($d['PRESENTATION'] === VARIABLE_PRESENTATION_SLIDER && $d['MIN'] === 0 && $d['MAX'] === 100, "$ident: Schieberegler 0..100");
}

// Listen als Optionen, vollständig
$quellen = optionen($m, 'InputSource');
pruefe($quellen === [-1 => '-', 0 => 'HDMI 1', 1 => 'HDMI 2', 2 => 'HDMI 3/ARC', 3 => 'HDMI 4', 4 => 'AV 1', 5 => 'AV 2/Component'], 'InputSource: Optionen aus der Quellenliste');
$tasten = json_decode($m->attribut('RemoteControllerInfo'), true, 512, JSON_THROW_ON_ERROR);
pruefe(count(optionen($m, 'SendRemoteKey')) === 147, 'SendRemoteKey: alle 146 Tasten plus „-“ (keine Grenze bei 128)');
$apps = json_decode($m->attribut('ApplicationList'), true, 512, JSON_THROW_ON_ERROR);
pruefe(count(optionen($m, 'Application')) === 28, 'Application: alle 27 Apps plus „-“');

// Auswahl schaltet über den Index
$m->antworten['IRCC/X_SendIRCC']          = '';
$m->antworten['avContent/setPlayContent'] = '{"result":[],"id":1}';
$m->antworten['appControl/setActiveApp']  = '{"result":[],"id":1}';

$m->marke();
$m->RequestAction('SendRemoteKey', 140);
pruefe(str_contains($m->anfragen[0]['data'] ?? '', '<IRCCCode>' . $tasten[140]['value'] . '</IRCCCode>'), 'Taste 140 (jenseits der alten Grenze) wird gesendet: ' . $tasten[140]['name']);

$m->marke();
$m->RequestAction('InputSource', 1);
pruefe(($m->anfragen[0]['params'] ?? null) === [['uri' => 'extInput:hdmi?port=2']], 'Auswahl InputSource 1 schaltet auf HDMI 2');

$m->marke();
$m->RequestAction('Application', 0);
pruefe(($m->anfragen[0]['params'] ?? null) === [['uri' => $apps[0]['uri']]], 'Auswahl Application 0 startet ' . $apps[0]['title']);

// Zwei Fernseher: jede Instanz behält ihre eigene Liste
$zweiter = neueInstanz();
$zweiter->antwortenAus('aktiv');
$liste                                                     = json_decode($zweiter->antworten['avContent/getCurrentExternalInputsStatus'], true, 512, JSON_THROW_ON_ERROR);
$liste['result'][0]                                        = array_values(array_filter($liste['result'][0], fn ($q) => $q['uri'] === 'extInput:hdmi?port=1'));
$zweiter->antworten['avContent/getCurrentExternalInputsStatus'] = json_encode($liste, JSON_THROW_ON_ERROR);
IPS_SetProperty($zweiter->id(), 'Host', '192.168.178.22');
IPS_ApplyChanges($zweiter->id());
pruefe(optionen($zweiter, 'InputSource') === [-1 => '-', 0 => 'HDMI 1'], 'zweiter TV: eigene Quellenliste');
pruefe(optionen($m, 'InputSource') === $quellen, 'erster TV: Quellenliste unverändert');

// Aktualisierte App-Liste landet in den Optionen
$liste                                        = json_decode($m->antworten['appControl/getApplicationList'], true, 512, JSON_THROW_ON_ERROR);
$liste['result'][0]                           = array_slice($liste['result'][0], 0, 3);
$m->antworten['appControl/getApplicationList'] = json_encode($liste, JSON_THROW_ON_ERROR);
$m->RequestAction('UpdateApplicationList', 0);
pruefe(count(optionen($m, 'Application')) === 4, 'nach UpdateApplicationList: Optionen aus der neuen Liste');

ergebnis();
