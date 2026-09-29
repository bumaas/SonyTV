<?php

declare(strict_types=1);

/**
 * Die Statusvariablen einer neuen Instanz: Ident, Typ, Position, Darstellung, Aktion und Startwert.
 *
 * Aufruf: php tests/check-variable-registration.php
 */

require_once __DIR__ . '/harness.php';

$m = neueInstanz();

$erwartet = [
    // Ident => [Typ, Position, Darstellung, Startwert]
    'PowerStatus'     => [VARIABLETYPE_INTEGER, 10, VARIABLE_PRESENTATION_ENUMERATION, 0],
    'AudioMute'       => [VARIABLETYPE_BOOLEAN, 20, VARIABLE_PRESENTATION_SWITCH, false],
    'SpeakerVolume'   => [VARIABLETYPE_INTEGER, 30, VARIABLE_PRESENTATION_SLIDER, 0],
    'HeadphoneVolume' => [VARIABLETYPE_INTEGER, 40, VARIABLE_PRESENTATION_SLIDER, 0],
    'SendRemoteKey'   => [VARIABLETYPE_INTEGER, 50, VARIABLE_PRESENTATION_ENUMERATION, -1],
    'InputSource'     => [VARIABLETYPE_INTEGER, 60, VARIABLE_PRESENTATION_ENUMERATION, -1],
    'Application'     => [VARIABLETYPE_INTEGER, 70, VARIABLE_PRESENTATION_ENUMERATION, -1],
];

$vorhanden = [];
foreach (IPS_GetChildrenIDs($m->id()) as $id) {
    $objekt = IPS_GetObject($id);
    if ($objekt['ObjectType'] === 2 /* Variable */) {
        $vorhanden[$objekt['ObjectIdent']] = $id;
    }
}
pruefe(array_keys($erwartet) == array_keys(array_intersect_key($erwartet, $vorhanden)) && count($vorhanden) === count($erwartet), 'genau die sieben erwarteten Variablen: ' . implode(', ', array_keys($vorhanden)));

foreach ($erwartet as $ident => [$typ, $position, $darstellung, $startwert]) {
    $id = $vorhanden[$ident] ?? 0;
    if ($id === 0) {
        pruefe(false, "$ident fehlt");
        continue;
    }
    $variable = IPS_GetVariable($id);
    pruefe($variable['VariableType'] === $typ, "$ident: Typ $typ");
    pruefe(IPS_GetObject($id)['ObjectPosition'] === $position, "$ident: Position $position");
    pruefe(($variable['VariablePresentation']['PRESENTATION'] ?? '') === $darstellung, "$ident: Darstellung");
    pruefe($variable['VariableAction'] === $m->id(), "$ident: schaltbar");
    pruefe(GetValue($id) === $startwert, "$ident: Startwert " . var_export($startwert, true));
}

// Auswahllisten ohne gelesene Liste bieten nur „keine Auswahl"
foreach (['SendRemoteKey', 'InputSource', 'Application'] as $ident) {
    pruefe($m->optionen($ident) === [-1 => '-'], "$ident: nur die Option „-“");
}

// erneutes Übernehmen legt nichts doppelt an und setzt keine Werte zurück
$tv = konfigurierteInstanz('aktiv');
$tv->UpdateAll();
$werte = $tv->werte();
IPS_ApplyChanges($tv->id());
pruefe(count(IPS_GetChildrenIDs($tv->id())) === 7, 'nach erneutem Übernehmen weiterhin sieben Variablen');
pruefe($tv->werte()['InputSource'] === $werte['InputSource'] && $tv->werte()['SpeakerVolume'] === $werte['SpeakerVolume'], 'erneutes Übernehmen setzt die Werte nicht zurück');

ergebnis();
