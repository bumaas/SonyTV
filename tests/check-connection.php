<?php

declare(strict_types=1);

/**
 * Verbindungsprüfung und Kernelstart: kein Netzverkehr vor KR_READY, höchstens drei Pings mit 1 s,
 * Konfigurationsfehler bleiben stehen (Befunde 1 und 5 des Reviews vom 29.09.2026), IPv6 in der URL.
 *
 * Aufruf: php tests/check-connection.php
 */

require_once __DIR__ . '/harness.php';

// Kernel noch nicht bereit: ApplyChanges fragt den TV nicht ab
SonyTVHarness::$runlevel = KR_INIT;
$m                       = konfigurierteInstanz('aktiv');
pruefe($m->pings === [] && $m->anfragen === [], 'vor KR_READY: kein Ping, keine Anfrage');
pruefe(($m->timer['STV_UpdateTimer'] ?? 0) === 0, 'vor KR_READY: Timer läuft nicht');
pruefe($m->UpdateAll() === false && $m->pings === [], 'vor KR_READY: UpdateAll fragt nicht ab');

// KR_READY holt es nach
SonyTVHarness::$runlevel = KR_READY;
$m->MessageSink(SonyTVHarness::$uhr, 0, IPS_KERNELMESSAGE, [KR_READY]);
pruefe($m->werte()['PowerStatus'] === 2 && $m->instanzStatus() === IS_ACTIVE, 'nach KR_READY: Zustand gelesen');
pruefe(count($m->optionen('SendRemoteKey')) === 147, 'nach KR_READY: Listen gelesen');
pruefe($m->timer['STV_UpdateTimer'] === 10000, 'nach KR_READY: Timer läuft mit 10 s');

// TV war eingeschaltet und antwortet nicht mehr: drei Versuche mit 1 s, dann „Aus"
SonyTVHarness::$ping = false;
$m->marke();
pruefe($m->UpdateAll() === true, 'TV antwortet nicht auf Ping: Zustand ist ermittelt (Aus)');
pruefe($m->pings === [['192.168.178.21', 1000], ['192.168.178.21', 1000], ['192.168.178.21', 1000]], 'TV war an: drei Pings mit 1 s Timeout');
pruefe($m->anfragen === [], 'ohne Ping-Antwort keine Anfrage an den TV');
pruefe($m->werte()['PowerStatus'] === 0 && $m->instanzStatus() === IS_INACTIVE, 'PowerStatus Aus, Instanz inaktiv');

// TV war schon aus: ein Versuch genügt
$m->marke();
$m->UpdateAll();
pruefe(count($m->pings) === 1, 'TV war aus: ein Ping');

// Ein verlorenes Paket bei eingeschaltetem TV ändert nichts
SonyTVHarness::$ping = true;
$m->UpdateAll();
SonyTVHarness::$ping = [false, true];
$m->marke();
$m->UpdateAll();
pruefe(count($m->pings) === 2 && $m->werte()['PowerStatus'] === 2, 'ein verlorener Ping: zweiter Versuch, TV bleibt An');
SonyTVHarness::$ping = true;

// Ausschalten blockiert höchstens die Pings eines Laufs
$m->antworten['system/setPowerStatus'] = '{"result":[],"id":1}';
SonyTVHarness::$ping                   = false;
$m->marke();
$m->RequestAction('PowerStatus', 0);
pruefe(count($m->pings) <= 3, 'Ausschalten: höchstens drei Pings');
SonyTVHarness::$ping = true;

// Konfigurationsfehler: kein Timer, UpdateAll lässt den Status stehen
$m = konfigurierteInstanz('aktiv', 'bravia.fritz.box');
pruefe($m->instanzStatus() === 204, 'Hostname statt IP-Adresse: Status 204');
pruefe($m->timer['STV_UpdateTimer'] === 0, 'Status 204: Timer läuft nicht');
$m->marke();
pruefe($m->UpdateAll() === false, 'Status 204: UpdateAll liefert false');
pruefe($m->instanzStatus() === 204 && $m->pings === [] && $m->anfragen === [], 'Status 204 bleibt stehen, kein Netzverkehr');

// IPv6-Adresse steht in der URL in eckigen Klammern
$m = konfigurierteInstanz('aktiv', 'fd00::21');
pruefe(($m->anfragen[0]['url'] ?? '') === 'http://[fd00::21]/sony/system', 'IPv6-Adresse in eckigen Klammern');

ergebnis();
