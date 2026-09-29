<?php

declare(strict_types=1);

/**
 * Instanzstatus und Status-Variable nach ApplyChanges und UpdateAll.
 *
 * Aufruf: php tests/check-instance-status.php
 */

require_once __DIR__ . '/harness.php';

// Ohne Host: 202, kein Netzverkehr
$m = neueInstanz();
pruefe($m->instanzStatus() === 202, 'leere IP-Adresse ergibt Status 202 (IP address can not be empty)');
pruefe($m->anfragen === [] && $m->pings === [], 'ohne IP-Adresse kein Netzverkehr');

// Ungültiger Host: 204
$m = konfigurierteInstanz('standby', 'kein host');
pruefe($m->instanzStatus() === 204, 'ungültige IP-Adresse ergibt Status 204 (IP address is not valid)');
pruefe($m->anfragen === [] && $m->pings === [], 'bei ungültiger IP-Adresse kein Netzverkehr');

// TV im Standby: Instanz aktiv, Status-Variable Standby, Listen werden eingelesen
$m = konfigurierteInstanz('standby');
pruefe($m->instanzStatus() === IS_ACTIVE, 'TV im Standby: Instanz aktiv');
pruefe($m->werte()['PowerStatus'] === 1, 'TV im Standby: PowerStatus = 1 (Standby)');
pruefe(array_count_values($m->status)[IS_ACTIVE] === 1, 'ApplyChanges setzt den Status aktiv genau einmal');
$methoden = array_column($m->anfragen, 'method');
foreach (['getRemoteControllerInfo', 'getCurrentExternalInputsStatus', 'getApplicationList'] as $methode) {
    pruefe(in_array($methode, $methoden, true), "ApplyChanges liest $methode");
}

$m->marke();
pruefe($m->UpdateAll() === true, 'UpdateAll im Standby liefert true');
pruefe(array_column($m->anfragen, 'method') === ['getPowerStatus'], 'UpdateAll im Standby fragt nur den Power-Status ab');

// TV vom Netz: Zustand ist ermittelt (Aus), UpdateAll meldet deshalb Erfolg
SonyTVHarness::$ping = false;
$m->marke();
pruefe($m->UpdateAll() === true, 'TV aus: UpdateAll liefert true, der Zustand ist ermittelt');
pruefe($m->werte()['PowerStatus'] === 0 && $m->instanzStatus() === IS_INACTIVE, 'TV aus: PowerStatus = 0, Instanz inaktiv');
SonyTVHarness::$ping = true;

// Eine Liste lässt sich nicht lesen (Mitschnitt falscher-psk): die anderen werden trotzdem gelesen
$m = neueInstanz();
$m->antwortenAus('aktiv');
$m->antworten['system/getRemoteControllerInfo'] = ['http' => 403, 'body' => mitschnitt('falscher-psk', 'avContent_getPlayingContentInfo')];
IPS_SetProperty($m->id(), 'Host', '192.168.178.21');
$meldung = meldungVon(fn () => IPS_ApplyChanges($m->id()));
pruefe($meldung === null, 'Tastenliste nicht lesbar: ApplyChanges meldet keinen Fehler');
pruefe(count($m->optionen('InputSource')) === 7 && count($m->optionen('Application')) === 28, 'Tastenliste nicht lesbar: Eingänge und Apps werden trotzdem gelesen');

ergebnis();
