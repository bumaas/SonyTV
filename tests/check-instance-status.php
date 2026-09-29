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
pruefe($m->anfragen === [], 'ohne IP-Adresse keine Anfrage an den TV');

// Ungültiger Host: 204
$m = konfigurierteInstanz('standby', 'kein host');
pruefe($m->instanzStatus() === 204, 'ungültige IP-Adresse ergibt Status 204 (IP address is not valid)');
pruefe($m->anfragen === [], 'bei ungültiger IP-Adresse keine Anfrage an den TV');

// TV im Standby: Instanz aktiv, Status-Variable Standby, Listen werden eingelesen
$m = konfigurierteInstanz('standby');
pruefe($m->instanzStatus() === IS_ACTIVE, 'TV im Standby: Instanz aktiv');
pruefe($m->werte()['PowerStatus'] === 1, 'TV im Standby: PowerStatus = 1 (Standby)');
$methoden = array_column($m->anfragen, 'method');
foreach (['getRemoteControllerInfo', 'getCurrentExternalInputsStatus', 'getApplicationList'] as $methode) {
    pruefe(in_array($methode, $methoden, true), "ApplyChanges liest $methode");
}

$m->marke();
pruefe($m->UpdateAll() === true, 'UpdateAll im Standby liefert true');
pruefe(array_column($m->anfragen, 'method') === ['getPowerStatus'], 'UpdateAll im Standby fragt nur den Power-Status ab');

// TV antwortet nicht (Timeout bei beiden Versuchen): Instanz inaktiv, PowerStatus 0
$m->antworten['system/getPowerStatus'] = CURLE_OPERATION_TIMEDOUT;
$m->marke();
pruefe($m->UpdateAll() === false, 'UpdateAll ohne Antwort liefert false');
pruefe(count($m->anfragen) === 2, 'getPowerStatus wird nach einem Fehler einmal wiederholt');
pruefe($m->werte()['PowerStatus'] === 0, 'ohne Antwort: PowerStatus = 0 (Aus)');
pruefe($m->instanzStatus() === IS_INACTIVE, 'ohne Antwort: Instanz inaktiv');

ergebnis();
