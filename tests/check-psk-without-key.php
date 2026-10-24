<?php

declare(strict_types=1);

/**
 * Methoden ohne Schlüssel heben Status 203 nicht auf. Sonys Referenz nennt für getPowerStatus, getVolumeInformation,
 * getCurrentExternalInputsStatus, getRemoteControllerInfo und getPictureQualitySettings die Authentifizierungsstufe
 * „none“. Bis 2.3 build 47 galt nur getPowerStatus so: Bei falschem Schlüssel und eingeschaltetem TV wechselte der
 * Status dann in jedem Zyklus zwischen 203 und 102 (getVolumeInformation hob ihn auf, getPlayingContentInfo setzte
 * ihn wieder), mit den Bildeinstellungen stand er am Ende jedes Zyklus fälschlich auf 102.
 *
 * Fixtures fixtures/falscher-psk: Antworten des KD-75XE9405 vom 24.10.2026 mit dem Schlüssel „falsch-test“, TV an,
 * HDMI 3. Die Methoden ohne Schlüssel antworten mit HTTP 200 wie mit richtigem Schlüssel, getApplicationList und
 * getSystemInformation mit HTTP 403 (wie getPlayingContentInfo und setPowerStatus vom 29.09.2026).
 *
 * Aufruf: php tests/check-psk-without-key.php
 */

require_once __DIR__ . '/harness.php';

const OHNE_SCHLUESSEL = [
    'audio/getVolumeInformation'               => 'audio_getVolumeInformation',
    'avContent/getCurrentExternalInputsStatus' => 'avContent_getCurrentExternalInputsStatus',
    'system/getRemoteControllerInfo'           => 'system_getRemoteControllerInfo',
    'video/getPictureQualitySettings'          => 'video_getPictureQualitySettings',
];
const MIT_SCHLUESSEL = [
    'avContent/getPlayingContentInfo' => 'avContent_getPlayingContentInfo',
    'appControl/getApplicationList'   => 'appControl_getApplicationList',
    'system/getSystemInformation'     => 'system_getSystemInformation',
];

/** Antworten mit falschem Schlüssel über die eines Zustands legen; getPowerStatus antwortet ohnehin. */
function falscherSchluessel(SonyTVHarness $m): void
{
    foreach (OHNE_SCHLUESSEL as $schluessel => $datei) {
        $m->antworten[$schluessel] = mitschnitt('falscher-psk', $datei);
    }
    foreach (MIT_SCHLUESSEL as $schluessel => $datei) {
        $m->antworten[$schluessel] = ['http' => 403, 'body' => mitschnitt('falscher-psk', $datei)];
    }
}

/** Meldungen seit der letzten Marke, die einen Text enthalten. */
function zeilenMit(SonyTVHarness $m, string $text): array
{
    return array_values(array_filter(array_column($m->logsSeitMarke(), 'Message'), static fn (string $s): bool => str_contains($s, $text)));
}

// --- TV an, falscher Schlüssel, Bildeinstellungen an ---
$m = neueInstanz();
$m->antwortenAus('aktiv');
falscherSchluessel($m);
IPS_SetProperty($m->id(), 'Host', '192.168.178.21');
IPS_SetProperty($m->id(), 'ShowPictureSettings', true);
$m->marke();
IPS_ApplyChanges($m->id());
pruefe($m->instanzStatus() === 203, 'Übernehmen, TV an, falscher Schlüssel: Status 203 (ist ' . $m->instanzStatus() . ')');
pruefe(count(zeilenMit($m, 'Pre-Shared Key accepted')) === 0, 'Übernehmen: keine Meldung „Pre-Shared Key accepted“');

foreach ([1, 2, 3] as $zyklus) {
    $m->marke();
    $m->UpdateAll();
    pruefe($m->instanzStatus() === 203, "Zyklus $zyklus: Status bleibt 203");
    pruefe(array_diff($m->status, [203]) === [], "Zyklus $zyklus: kein Wechsel über 102 (SetStatus: " . implode(', ', $m->status) . ')');
    pruefe(zeilenMit($m, 'Pre-Shared Key') === [], "Zyklus $zyklus: keine Logzeile zum Schlüssel");
}

// jede Methode ohne Schlüssel einzeln: ihre Antwort lässt 203 stehen
foreach (['UpdateRemoteKeyList', 'GetSourceListInfo'] as $aktion) {
    $m->marke();
    meldungVon(fn () => $m->RequestAction($aktion, 0));
    pruefe($m->instanzStatus() === 203, "$aktion (ohne Schlüssel): Status bleibt 203");
}

// Schlüssel stimmt wieder: die nächste Antwort einer Methode mit Schlüssel hebt 203 auf, einmal im Log
$m->antwortenAus('aktiv');
$m->marke();
$m->UpdateAll();
pruefe($m->instanzStatus() === IS_ACTIVE, 'richtiger Schlüssel: Status aktiv');
pruefe(count(zeilenMit($m, 'Pre-Shared Key accepted')) === 1, 'richtiger Schlüssel: Behebung einmal im Log');

// --- Standby, falscher Schlüssel: die Listen ohne Schlüssel kommen an, die Apps nicht ---
$s = neueInstanz();
$s->antwortenAus('standby');
falscherSchluessel($s);
IPS_SetProperty($s->id(), 'Host', '192.168.178.21');
IPS_ApplyChanges($s->id());
pruefe($s->instanzStatus() === 203, 'Übernehmen im Standby, falscher Schlüssel: Status 203 (über getApplicationList)');
meldungVon(fn () => $s->RequestAction('UpdateRemoteKeyList', 0));
pruefe($s->instanzStatus() === 203, 'Standby: Tastenliste neu gelesen, Status bleibt 203');

ergebnis();
