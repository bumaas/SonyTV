<?php

declare(strict_types=1);

/**
 * STV_SendRestAPIRequest: Parameterliste als JSON-String, Antwort als String.
 *
 * Aufruf: php tests/check-rest-api-request.php
 */

require_once __DIR__ . '/harness.php';

$m = konfigurierteInstanz('standby');

// Gültige Anfrage: Parameter kommen dekodiert beim TV an, Antwort unverändert zurück
$m->marke();
$antwort = $m->SendRestAPIRequest('avContent', 'getCurrentExternalInputsStatus', '[]', '1.0');
pruefe($antwort === $m->antworten['avContent/getCurrentExternalInputsStatus'], 'Antwort des TV wird unverändert zurückgegeben');
pruefe(count($m->anfragen) === 1, 'genau eine Anfrage an den TV');
pruefe($m->anfragen[0]['url'] === 'http://192.168.178.21/sony/avContent', 'URL aus Host und Service');
pruefe($m->anfragen[0]['params'] === [] && $m->anfragen[0]['version'] === '1.0', 'Parameterliste und Version im Request');

// Header: jede Zeile "Name: Wert", JSON-Anfrage mit passendem Content-Type und PSK
$header = $m->anfragen[0]['headers'];
$ohneName = array_filter($header, fn (string $zeile): bool => !preg_match('/^[A-Za-z-]+: /', $zeile));
pruefe($ohneName === [], 'jede Headerzeile hat einen Namen' . ($ohneName === [] ? '' : ' (ohne: ' . implode(' | ', $ohneName) . ')'));
$contentTypes = array_values(array_filter($header, fn (string $zeile): bool => stripos($zeile, 'Content-Type:') === 0));
pruefe($contentTypes === ['Content-Type: application/json; charset=UTF-8'], 'genau ein Content-Type, application/json');
pruefe(in_array('X-Auth-PSK: 0000', $header, true), 'X-Auth-PSK mit dem eingestellten Pre-Shared Key');

$m->antworten['avContent/setPlayContent'] = '{"result":[],"id":1}';
$m->marke();
$m->SendRestAPIRequest('avContent', 'setPlayContent', '[{"uri":"extInput:hdmi?port=2"}]', '1.0');
pruefe($m->anfragen[0]['params'] === [['uri' => 'extInput:hdmi?port=2']], 'verschachtelte Parameter kommen dekodiert beim TV an');

// Fehlerantwort des TV (Standby: 40005 Display Is Turned off) -> Leerstring
$m->marke();
pruefe($m->SendRestAPIRequest('audio', 'getVolumeInformation', '[]', '1.0') === '', 'Fehlerantwort des TV ergibt einen Leerstring');

// curl-Fehler -> Leerstring
$m->antworten['system/getInterfaceInformation'] = CURLE_OPERATION_TIMEDOUT;
pruefe($m->SendRestAPIRequest('system', 'getInterfaceInformation', '[]', '1.0') === '', 'curl-Fehler ergibt einen Leerstring');

// Ungültige Parameter -> Warnung, kein Request
$m->marke();
$meldung = meldungVon(fn () => $m->SendRestAPIRequest('system', 'getPowerStatus', 'kein json', '1.0'));
pruefe($meldung !== null && str_contains($meldung, 'Invalid JSON'), 'ungültiges JSON erzeugt eine Warnung');
$meldung = meldungVon(fn () => $m->SendRestAPIRequest('system', 'getPowerStatus', '"text"', '1.0'));
pruefe($meldung !== null && str_contains($meldung, 'JSON array'), 'JSON ohne Liste erzeugt eine Warnung');
pruefe($m->anfragen === [], 'bei ungültigen Parametern geht nichts an den TV');

ergebnis();
