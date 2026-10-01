<?php

declare(strict_types=1);

/**
 * Eigene Statuscodes für „TV antwortet nicht" (201) und „Pre-Shared Key abgelehnt" (203), jeweils mit Klartext
 * im Log beim Wechsel und einer Meldung, wenn der Fehler behoben ist (MCP-Test vom 01.10.2026, Regeln 3 und 4 in
 * mcp-tauglichkeit.md). Bis 2.2 build 36 stand ein nicht erreichbarer TV stumm auf 104, ein falscher Schlüssel
 * hatte gar keinen Status.
 *
 * Falscher Schlüssel: mitgeschnitten ist die Antwort auf setPowerStatus und getPlayingContentInfo (wortgleich,
 * HTTP 403). Für getVolumeInformation steht hier derselbe Mitschnitt; dass der TV dort genauso antwortet, ist
 * eine Annahme. getPowerStatus antwortet auch mit falschem Schlüssel normal (Mitschnitt).
 *
 * Aufruf: php tests/check-status-reachability.php
 */

require_once __DIR__ . '/harness.php';

/** Meldungen seit der letzten Marke, die einen Text enthalten. */
function meldungenMit(SonyTVHarness $m, string $text): array
{
    return array_values(array_filter(array_column($m->logsSeitMarke(), 'Message'), static fn (string $s): bool => str_contains($s, $text)));
}

$form  = json_decode((string)file_get_contents(dirname(__DIR__) . '/Sony TV/form.json'), true, 512, JSON_THROW_ON_ERROR);
$codes = array_column($form['status'], null, 'code');
pruefe(($codes[201]['icon'] ?? '') === 'error' && str_contains($codes[201]['caption'] ?? '', 'does not answer'), 'Formular: Status 201 „TV does not answer" als Fehler');
pruefe(($codes[203]['icon'] ?? '') === 'error' && str_contains($codes[203]['caption'] ?? '', 'Pre-Shared Key'), 'Formular: Status 203 zum Pre-Shared Key als Fehler');

// --- TV antwortet nicht ---
$m = konfigurierteInstanz('standby');
pruefe($m->instanzStatus() === IS_ACTIVE, 'Ausgangslage: TV im Standby, Instanz aktiv');

SonyTVHarness::$ping = false;
$m->marke();
$m->UpdateAll();
pruefe($m->instanzStatus() === 201, 'kein Ping: Status 201');
pruefe($m->werte()['PowerStatus'] === 0, 'kein Ping: PowerStatus Ausgeschaltet');
$zeilen = meldungenMit($m, 'does not answer');
pruefe(count($zeilen) === 1 && str_contains($zeilen[0], '192.168.178.21'), 'Wechsel nach 201 steht einmal mit der Adresse im Log');

$m->marke();
$m->UpdateAll();
pruefe($m->instanzStatus() === 201 && meldungenMit($m, 'does not answer') === [], 'weiter kein Ping: Status bleibt 201, keine weitere Logzeile');

SonyTVHarness::$ping = true;
$m->marke();
$m->UpdateAll();
pruefe($m->instanzStatus() === IS_ACTIVE, 'Ping wieder da: Status aktiv');
pruefe(count(meldungenMit($m, 'answers again')) === 1, 'Behebung steht einmal im Log');

// TV beim Übernehmen nicht erreichbar
SonyTVHarness::$ping = false;
$aus                 = konfigurierteInstanz('standby');
pruefe($aus->instanzStatus() === 201, 'TV beim Übernehmen nicht erreichbar: Status 201');
SonyTVHarness::$ping = true;

// --- Pre-Shared Key abgelehnt ---
$falsch = ['http' => 403, 'body' => mitschnitt('falscher-psk', 'avContent_getPlayingContentInfo')];
$m      = konfigurierteInstanz('aktiv');
pruefe($m->instanzStatus() === IS_ACTIVE, 'Ausgangslage: TV an, Instanz aktiv');

$richtig                                         = $m->antworten;
$m->antworten['audio/getVolumeInformation']      = $falsch;
$m->antworten['avContent/getPlayingContentInfo'] = $falsch;
$m->marke();
$m->UpdateAll();
pruefe($m->instanzStatus() === 203, 'Antwort 403: Status 203');
pruefe(count(meldungenMit($m, 'Pre-Shared Key')) === 1, 'Wechsel nach 203 steht einmal im Log');

// getPowerStatus antwortet auch mit falschem Schlüssel, das darf den Status nicht zurücksetzen
$m->antworten['audio/getVolumeInformation'] = $richtig['audio/getVolumeInformation'];
$m->marke();
$m->RequestAction('UpdateAll', 0);
pruefe(array_column($m->anfragen, 'method')[0] === 'getPowerStatus', 'UpdateAll fragt zuerst getPowerStatus ab');
pruefe($m->instanzStatus() === 203, 'getPowerStatus und getPlayingContentInfo (weiter 403): Status bleibt 203');

// ein geschützter Aufruf gelingt: Schlüssel stimmt wieder
$m->antworten = $richtig;
$m->marke();
$m->UpdateAll();
pruefe($m->instanzStatus() === IS_ACTIVE, 'Antworten ohne 403: Status aktiv');
pruefe(count(meldungenMit($m, 'Pre-Shared Key accepted')) === 1, 'Behebung steht einmal im Log');

// Fernbedienungstaste: /sony/IRCC antwortet mit HTTP 403 und leerem Inhalt (Mitschnitt)
$m->antworten['IRCC/X_SendIRCC'] = ['http' => 403, 'body' => ''];
meldungVon(fn () => $m->SendRemoteKey('Home'));
pruefe($m->instanzStatus() === 203, 'IRCC mit HTTP 403: Status 203');

// Übernehmen mit neuem Schlüssel prüft neu
$m->antworten = $richtig;
IPS_SetProperty($m->id(), 'PSK', '1234');
IPS_ApplyChanges($m->id());
pruefe($m->instanzStatus() === IS_ACTIVE, 'Übernehmen, Antworten ohne 403: Status aktiv');

// „nicht erreichbar" geht dem abgelehnten Schlüssel vor: ob der Schlüssel stimmt, lässt sich dann nicht sagen
$m->antworten['avContent/getPlayingContentInfo'] = $falsch;
$m->UpdateAll();
pruefe($m->instanzStatus() === 203, 'Ausgangslage: Status 203');
SonyTVHarness::$ping = false;
$m->UpdateAll();
$m->UpdateAll();
pruefe($m->instanzStatus() === 201, 'TV danach nicht mehr erreichbar: Status 201');
SonyTVHarness::$ping = true;

ergebnis();
