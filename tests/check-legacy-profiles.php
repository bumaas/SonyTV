<?php

declare(strict_types=1);

/**
 * Aufräumen der Variablenprofile STV.* früherer Versionen: gelöscht wird nur, was sicher unbenutzt ist
 * (Befund 6 des Reviews vom 29.09.2026).
 *
 * Aufruf: php tests/check-legacy-profiles.php
 */

require_once __DIR__ . '/harness.php';

const ALTPROFILE = ['STV.Applications', 'STV.PowerStatus', 'STV.Volume', 'STV.RemoteKey', 'STV.Sources'];

function altprofileAnlegen(): void
{
    foreach (ALTPROFILE as $profil) {
        if (!IPS_VariableProfileExists($profil)) {
            IPS_CreateVariableProfile($profil, VARIABLETYPE_INTEGER);
        }
    }
}

function vorhanden(): array
{
    return array_values(array_filter(ALTPROFILE, fn (string $p): bool => IPS_VariableProfileExists($p)));
}

/** Aufbau wie ein echtes Diagramm (am nuc abgelesen an #10080): axes[].profile, base64-kodiert. */
function diagramm(string $profil): string
{
    return base64_encode(json_encode(['datasets' => [], 'type' => 'line', 'axes' => [['profile' => $profil, 'side' => 'left']]], JSON_THROW_ON_ERROR));
}

// Alles unbenutzt: alle fünf werden gelöscht
altprofileAnlegen();
konfigurierteInstanz('aktiv');
pruefe(vorhanden() === [], 'unbenutzte Altprofile werden gelöscht');

// In Benutzung: eigenes Profil, Darstellung „Legacy Profil", lesbares Diagramm
altprofileAnlegen();
$eigen = IPS_CreateVariable(VARIABLETYPE_INTEGER);
IPS_SetVariableCustomProfile($eigen, 'STV.RemoteKey');
$legacy = IPS_CreateVariable(VARIABLETYPE_INTEGER);
IPS_SetVariableCustomPresentation($legacy, ['PRESENTATION' => VARIABLE_PRESENTATION_LEGACY, 'PROFILE' => 'STV.Sources']);
SonyTVHarness::$diagramme = [diagramm('~Temperature'), diagramm('STV.Volume')];
$m                        = konfigurierteInstanz('aktiv');
pruefe(vorhanden() === ['STV.Volume', 'STV.RemoteKey', 'STV.Sources'], 'benutzt von Variable, Legacy-Darstellung oder Diagramm: bleibt; der Rest wird gelöscht');

// Befund 6: ein Diagramm ist nicht lesbar - dann wird nichts gelöscht
altprofileAnlegen();
SonyTVHarness::$diagramme = [diagramm('~Temperature'), false];
IPS_ApplyChanges($m->id());
pruefe(vorhanden() === ALTPROFILE, 'ein unlesbares Diagramm: kein Altprofil wird gelöscht');

// sobald alle Diagramme lesbar sind, wird aufgeräumt
SonyTVHarness::$diagramme = [diagramm('~Temperature')];
IPS_ApplyChanges($m->id());
pruefe(vorhanden() === ['STV.RemoteKey', 'STV.Sources'], 'alle Diagramme lesbar: unbenutzte Altprofile werden gelöscht');

// vor KR_READY wird nicht aufgeräumt
altprofileAnlegen();
SonyTVHarness::$runlevel = KR_INIT;
IPS_ApplyChanges($m->id());
pruefe(vorhanden() === ALTPROFILE, 'vor KR_READY bleibt alles stehen');
SonyTVHarness::$runlevel = KR_READY;

ergebnis();
