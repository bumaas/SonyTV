<?php

declare(strict_types=1);

/**
 * STV_RunSelfTest: Probelauf ohne Wirkung, der Konfiguration, Erreichbarkeit, Schlüssel und Listen prüft und als
 * Text zurückgibt (MCP-Test vom 01.10.2026, Befund 7, Regeln 5, 6 und 7 in mcp-tauglichkeit.md).
 *
 * Schlüsselprüfung über getPlayingContentInfo, alles Mitschnitte: richtiger Schlüssel im Standby 40005, an
 * ein Ergebnis, mit App im Vordergrund Fehler 7; falscher Schlüssel HTTP 403.
 *
 * Aufruf: php tests/check-self-test.php
 */

require_once __DIR__ . '/harness.php';

const NUR_LESEND = ['getPowerStatus', 'getPlayingContentInfo'];

function selbsttest(SonyTVHarness $m): string
{
    $m->marke();
    return $m->RunSelfTest();
}

function nurLesend(SonyTVHarness $m): bool
{
    return array_diff(array_column($m->anfragen, 'method'), NUR_LESEND) === [];
}

pruefe(method_exists(SonyTVHarness::class, 'RunSelfTest'), 'die Funktion STV_RunSelfTest gibt es');
if (!method_exists(SonyTVHarness::class, 'RunSelfTest')) {
    ergebnis();
}

// --- alles in Ordnung, TV im Standby ---
$m    = konfigurierteInstanz('standby');
$text = selbsttest($m);
echo $text, "\n";
pruefe(str_contains($text, '✓ Configuration'), 'Standby: Konfiguration in Ordnung');
pruefe(str_contains($text, '✓ TV 192.168.178.21 answers'), 'Standby: TV antwortet');
pruefe(str_contains($text, 'Standby'), 'Standby: Zustand genannt');
pruefe(str_contains($text, '✓ Pre-Shared Key accepted'), 'Standby: 40005 heißt Schlüssel angenommen');
pruefe(str_contains($text, '146 remote keys') && str_contains($text, '6 input sources') && str_contains($text, '27 applications'), 'Standby: Listen mit Anzahl');
pruefe(str_ends_with(rtrim($text), '0 errors, 0 warnings'), 'Standby: Schlusszeile 0 errors, 0 warnings');
pruefe(nurLesend($m), 'Standby: nur lesende Abfragen');

// --- TV an, App im Vordergrund (Fehler 7) ---
$m                                               = konfigurierteInstanz('aktiv');
$m->antworten['avContent/getPlayingContentInfo'] = mitschnitt('app-im-vordergrund', 'avContent_getPlayingContentInfo');
$text                                            = selbsttest($m);
pruefe(str_contains($text, '✓ Pre-Shared Key accepted') && str_contains($text, 'On'), 'App im Vordergrund: Schlüssel angenommen, Zustand An');

// --- falscher Schlüssel ---
$m->antworten['avContent/getPlayingContentInfo'] = ['http' => 403, 'body' => mitschnitt('falscher-psk', 'avContent_getPlayingContentInfo')];
$text                                            = selbsttest($m);
pruefe(str_contains($text, '✗ Pre-Shared Key rejected'), 'falscher Schlüssel: ✗ mit Klartext');
pruefe(str_contains($text, 'IP control'), 'falscher Schlüssel: Hinweis, wo der Schlüssel am TV steht');
pruefe(str_ends_with(rtrim($text), '1 errors, 0 warnings'), 'falscher Schlüssel: Schlusszeile 1 errors');
pruefe(nurLesend($m), 'falscher Schlüssel: nur lesende Abfragen');

// --- leere Liste ---
$m = konfigurierteInstanz('standby');
$m->attributSetzen('ApplicationList', '[]');
$text = selbsttest($m);
pruefe(str_contains($text, '⚠ 0 applications') && str_contains($text, "IPS_RequestAction(\$InstanceID, 'UpdateApplicationList', 0)"), 'leere App-Liste: ⚠ mit dem Aufruf zum Nachladen');
pruefe(str_ends_with(rtrim($text), '0 errors, 1 warnings'), 'leere App-Liste: Schlusszeile 1 warnings');

// --- TV antwortet nicht ---
SonyTVHarness::$ping = false;
$text                = selbsttest($m);
pruefe(str_contains($text, '✗ TV 192.168.178.21 does not answer'), 'kein Ping: ✗ mit Adresse');
pruefe(str_contains($text, 'Remote start'), 'kein Ping: Hinweis auf Remote start');
pruefe($m->anfragen === [] && count($m->pings) === 3, 'kein Ping: drei Pings, keine Anfrage');
SonyTVHarness::$ping = true;

// --- Konfigurationsfehler ---
$k    = neueInstanz();
$text = selbsttest($k);
pruefe(str_contains($text, '✗ Configuration: IP address can not be empty.'), 'Host leer: ✗ mit Klartext');
pruefe($k->anfragen === [] && $k->pings === [], 'Host leer: kein Netzverkehr');

// --- der Selbsttest ändert nichts ---
$m      = konfigurierteInstanz('standby');
$vorher = [$m->werte(), $m->instanzStatus()];
$m->marke();
$m->RunSelfTest();
pruefe($m->writes === [] && [$m->werte(), $m->instanzStatus()] === $vorher, 'Selbsttest schreibt keine Variable und lässt den Status stehen');

// --- Formular: Knopf und unsichtbarer Hinweis für Skripte (Regel 6) ---
$form    = json_decode((string)file_get_contents(dirname(__DIR__) . '/Sony TV/form.json'), true, 512, JSON_THROW_ON_ERROR);
$actions = $form['actions'];
pruefe(in_array('echo STV_RunSelfTest($id);', array_column($actions, 'onClick'), true), 'Formular: Knopf ruft STV_RunSelfTest auf');
$hinweise = array_filter($actions, static fn (array $e): bool => $e['type'] === 'Label' && ($e['visible'] ?? true) === false);
$hinweis  = implode("\n", array_column($hinweise, 'caption'));
pruefe(str_contains($hinweis, 'STV_RunSelfTest($InstanceID)') && str_contains($hinweis, 'UpdateRemoteKeyList'), 'Formular: unsichtbarer Hinweis nennt STV_RunSelfTest und die Listen-Aufrufe');

ergebnis();
