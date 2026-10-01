<?php

declare(strict_types=1);

/**
 * Mit WriteLogInformationToIPSLogger gehen Info-Meldungen zusätzlich, nicht statt ins Symcon-Log in die IPSLibrary.
 * Bis 2.2 build 41 landeten sie dann nur in der IPSLibrary und im Debug; eine KI über MCP sieht aber nur das
 * Symcon-Log (symcon_log) und einen Debug-Puffer von wenigen Minuten. Am nuc steht der Schalter an #36393.
 *
 * Aufruf: php tests/check-logging.php
 */

/** Attrappe der IPSLibrary: sammelt, was das Modul an den IPSLogger gibt. */
$ipsLogger = [];
function IPSLogger_Inf(string $sender, string $message): void
{
    global $ipsLogger;
    $ipsLogger[] = ['Inf', $message];
}
function IPSLogger_Err(string $sender, string $message): void
{
    global $ipsLogger;
    $ipsLogger[] = ['Err', $message];
}

require_once __DIR__ . '/harness.php';

function symconLog(SonyTVHarness $m): array
{
    return array_column($m->logsSeitMarke(), 'Message');
}

// Schalter aus: nur Symcon-Log
$m = neueInstanz();
$m->antwortenAus('standby');
IPS_SetProperty($m->id(), 'Host', '192.168.178.21');
$m->marke();
IPS_ApplyChanges($m->id());
pruefe(in_array('TimerInterval set to 10s.', symconLog($m), true), 'Schalter aus: Info im Symcon-Log');
pruefe($ipsLogger === [], 'Schalter aus: nichts an den IPSLogger');

// Schalter an: Symcon-Log und IPSLibrary
IPS_SetProperty($m->id(), 'WriteLogInformationToIPSLogger', true);
$m->marke();
IPS_ApplyChanges($m->id());
pruefe(in_array('TimerInterval set to 10s.', symconLog($m), true), 'Schalter an: Info weiterhin im Symcon-Log');
pruefe(in_array(['Inf', 'TimerInterval set to 10s.'], $ipsLogger, true), 'Schalter an: Info zusätzlich an den IPSLogger');

// Fehler gingen schon immer in beide
$ipsLogger = [];
SonyTVHarness::$ping = false;
$m->marke();
$m->UpdateAll();
SonyTVHarness::$ping = true;
$fehlerzeilen = array_filter(symconLog($m), static fn (string $s): bool => str_contains($s, 'does not answer'));
pruefe(count($fehlerzeilen) === 1 && in_array('Err', array_column($ipsLogger, 0), true), 'Schalter an: Fehler in beiden Logs');

// Formular und Übersetzung sagen „zusätzlich"
$form    = (string)file_get_contents(dirname(__DIR__) . '/Sony TV/form.json');
$locale  = (string)file_get_contents(dirname(__DIR__) . '/Sony TV/locale.json');
pruefe(!str_contains($form, 'instead of standard logfile'), 'Formular: kein „instead of standard logfile" mehr');
pruefe(!str_contains($locale, 'anstatt in das Standardlogfile'), 'Übersetzung: kein „anstatt in das Standardlogfile" mehr');

ergebnis();
