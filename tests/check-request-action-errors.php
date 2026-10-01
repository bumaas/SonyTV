<?php

declare(strict_types=1);

/**
 * RequestAction meldet jeden Fehlschlag (MCP-Test vom 01.10.2026, Regeln 2, 3 und 8 in mcp-tauglichkeit.md).
 *
 * Die Methode ist void; ohne Warnung liefert das globale RequestAction dem Aufrufer true, auch wenn nichts
 * gesendet wurde oder der TV abgelehnt hat. Geprüft wird: ungültiger Wert samt erlaubter Werte, Wert außerhalb
 * des Bereichs, leere Auswahlliste, abgelehnter oder nicht angekommener Befehl, fehlende Konfiguration.
 * Dazu die Wertebereiche der direkten Funktionen und des Aktualisierungsintervalls samt Klartext im Log.
 *
 * Abgelehnte Befehle: die Antwort 40005 ist mitgeschnitten auf getVolumeInformation im Standby und steht hier
 * für die Antwort auf einen Schaltbefehl (wie in check-lists-and-commands.php).
 *
 * Aufruf: php tests/check-request-action-errors.php
 */

require_once __DIR__ . '/harness.php';

$abgelehnt = mitschnitt('standby', 'audio_getVolumeInformation');
$ok        = '{"result":[],"id":1}';

$m                                       = konfigurierteInstanz('standby');
$m->antworten['appControl/setActiveApp'] = $ok;
$m->antworten['avContent/setPlayContent'] = $ok;
$m->antworten['IRCC/X_SendIRCC']          = '';
$m->antworten['audio/setAudioVolume']     = $ok;
$m->antworten['audio/setAudioMute']       = $ok;

// --- ungültiger Wert einer Auswahlliste: Meldung mit den erlaubten Werten, kein Befehl ---
foreach ([['Application', 999, '12 = Netflix'], ['InputSource', 42, '1 = HDMI 2'], ['SendRemoteKey', 500, '0 = Num1'], ['Application', 'Netflix', '12 = Netflix']] as [$ident, $wert, $beispiel]) {
    $m->marke();
    $meldung = meldungVon(fn () => $m->RequestAction($ident, $wert));
    pruefe(
        str_starts_with((string)$meldung, 'Invalid value "' . $wert . '" for "' . $ident . '" (allowed: ') && str_contains((string)$meldung, $beispiel),
        "$ident = " . var_export($wert, true) . ': Meldung nennt die erlaubten Werte (' . substr((string)$meldung, 0, 70) . ' …)'
    );
    pruefe($m->anfragen === [], "$ident = " . var_export($wert, true) . ': kein Befehl an den TV');
}

// „-" (keine Auswahl) ist ein erlaubter Wert und löst nichts aus
$m->marke();
pruefe(meldungVon(fn () => $m->RequestAction('Application', -1)) === null && $m->anfragen === [], 'Application = -1: keine Meldung, kein Befehl');

// --- leere Auswahlliste ---
$leer = konfigurierteInstanz('standby');
foreach ([['ApplicationList', 'Application', 'Application List not yet set. Please update the application list.'],
    ['SourceList', 'InputSource', 'Source list not yet read. Please update the list of input sources.'],
    ['RemoteControllerInfo', 'SendRemoteKey', 'Remote key list not yet read. Please update the remote key list.']] as [$attribut, $ident, $erwartet]) {
    $leer->attributSetzen($attribut, '[]');
    $leer->marke();
    $meldung = meldungVon(fn () => $leer->RequestAction($ident, 0));
    pruefe($meldung === $erwartet && $leer->anfragen === [], "$ident bei leerer Liste: „$erwartet\"");
}

// --- TV lehnt ab: Meldung statt still true ---
foreach ([['appControl/setActiveApp', 'Application', 12], ['avContent/setPlayContent', 'InputSource', 1], ['audio/setAudioVolume', 'SpeakerVolume', 30],
    ['audio/setAudioVolume', 'HeadphoneVolume', 30], ['audio/setAudioMute', 'AudioMute', true]] as [$schluessel, $ident, $wert]) {
    $vorher                  = $m->antworten[$schluessel];
    $m->antworten[$schluessel] = $abgelehnt;
    $meldung                 = meldungVon(fn () => $m->RequestAction($ident, $wert));
    $text                    = 'Action "' . $ident . '" with value ' . var_export($wert, true) . ' failed';
    pruefe(str_starts_with((string)$meldung, $text), "$ident: TV lehnt ab (40005) → „$text …\"");
    $m->antworten[$schluessel] = $vorher;
}
$m->antworten['IRCC/X_SendIRCC'] = ['http' => 403, 'body' => ''];
pruefe(str_starts_with((string)meldungVon(fn () => $m->RequestAction('SendRemoteKey', 0)), 'Action "SendRemoteKey" with value 0 failed'), 'SendRemoteKey: HTTP 403 → Meldung');
$m->antworten['IRCC/X_SendIRCC'] = 7; // curl: keine Verbindung
pruefe(str_starts_with((string)meldungVon(fn () => $m->RequestAction('SendRemoteKey', 0)), 'Action "SendRemoteKey" with value 0 failed'), 'SendRemoteKey: TV nicht erreichbar → Meldung');
$m->antworten['IRCC/X_SendIRCC'] = '';

// --- Lautstärke: Bereich 0 bis 100 (Darstellung MIN/MAX) ---
foreach (['SpeakerVolume', 'HeadphoneVolume'] as $ident) {
    foreach ([150, -5, 'laut'] as $wert) {
        $m->marke();
        $meldung = meldungVon(fn () => $m->RequestAction($ident, $wert));
        pruefe($meldung === 'Invalid value "' . $wert . '" for "' . $ident . '" (allowed: 0 to 100)' && $m->anfragen === [], "$ident = " . var_export($wert, true) . ': abgewiesen mit Bereich, kein Befehl');
    }
}
foreach ([0, 100] as $wert) {
    pruefe(meldungVon(fn () => $m->RequestAction('SpeakerVolume', $wert)) === null && $m->werte()['SpeakerVolume'] === $wert, "SpeakerVolume = $wert (Grenze) wird angenommen");
}
// direkte Funktionen prüfen denselben Bereich
foreach (['SetSpeakerVolume' => 'SpeakerVolume', 'SetHeadphoneVolume' => 'HeadphoneVolume'] as $funktion => $ident) {
    $m->marke();
    $meldung = meldungVon(fn () => $m->$funktion(150));
    pruefe($meldung === 'Invalid value "150" for "' . $ident . '" (allowed: 0 to 100)' && $m->anfragen === [], "$funktion(150): abgewiesen, kein Befehl");
}

// --- Stummschaltung und Power-Status: nur erlaubte Werte ---
foreach ([['AudioMute', 'vielleicht', 'Invalid value "vielleicht" for "AudioMute" (allowed: true, false)'],
    ['AudioMute', 5, 'Invalid value "5" for "AudioMute" (allowed: true, false)'],
    ['PowerStatus', 5, 'Invalid value "5" for "PowerStatus" (allowed: 0 = Off, 1 = Standby, 2 = On)'],
    ['PowerStatus', 'an', 'Invalid value "an" for "PowerStatus" (allowed: 0 = Off, 1 = Standby, 2 = On)']] as [$ident, $wert, $erwartet]) {
    $m->marke();
    $meldung = meldungVon(fn () => $m->RequestAction($ident, $wert));
    pruefe($meldung === $erwartet && $m->anfragen === [], "$ident = " . var_export($wert, true) . ": „$erwartet\"");
}

// --- Einschalten: Erfolg nur, wenn der TV bestätigt oder danach eingeschaltet ist ---
$p                                     = konfigurierteInstanz('standby');
$p->antworten['system/setPowerStatus'] = 7; // curl: keine Verbindung, TV bleibt im Standby
$meldung                               = meldungVon(fn () => $p->RequestAction('PowerStatus', 2));
pruefe(str_starts_with((string)$meldung, 'Action "PowerStatus" with value 2 failed'), 'Einschalten scheitert, TV bleibt Standby → Meldung');
$p->marke();
pruefe($p->SetPowerStatus(true) === false, 'STV_SetPowerStatus(true) liefert dann false');

$p->antworten['system/setPowerStatus'] = 28; // Timeout, der TV schaltet trotzdem
$p->antworten['system/getPowerStatus'] = mitschnitt('aktiv', 'system_getPowerStatus');
pruefe($p->SetPowerStatus(true) === true && $p->werte()['PowerStatus'] === 2, 'Timeout, TV danach an: SetPowerStatus liefert true');

$p->antworten['system/setPowerStatus'] = $ok;
$p->antworten['system/getPowerStatus'] = mitschnitt('standby', 'system_getPowerStatus');
pruefe(meldungVon(fn () => $p->RequestAction('PowerStatus', 0)) === null, 'Ausschalten bestätigt: keine Meldung');

// --- UpdateAll per RequestAction: Zustand unbekannt ---
$p->antworten['system/getPowerStatus'] = 28; // einzelner Aussetzer → Zustand unbekannt
pruefe(str_starts_with((string)meldungVon(fn () => $p->RequestAction('UpdateAll', 0)), 'Action "UpdateAll" with value 0 failed'), 'UpdateAll ohne Ergebnis → Meldung');

// --- unbekannter Ident ---
pruefe(meldungVon(fn () => $p->RequestAction('Gibtsnicht', 0)) === 'Unexpected ident: Gibtsnicht', 'unbekannter Ident → Meldung');

// --- fehlende Konfiguration: Meldung, kein Netzverkehr (vorher http:///sony/…) ---
$k = neueInstanz();
$k->antwortenAus('aktiv');
$k->marke();
IPS_ApplyChanges($k->id());
pruefe($k->instanzStatus() === 202, 'Host leer: Status 202');
pruefe(in_array('Configuration error: IP address can not be empty.', array_column($k->logsSeitMarke(), 'Message'), true), 'Status 202 steht als Klartext im Log');
$k->marke();
$meldung = meldungVon(fn () => $k->RequestAction('SpeakerVolume', 20));
pruefe($meldung === 'Instance is not configured: IP address can not be empty.' && $k->anfragen === [], 'RequestAction bei Status 202: Meldung, kein Befehl');
$k->marke();
$meldung = meldungVon(fn () => $k->SetSpeakerVolume(20));
pruefe($meldung === 'Instance is not configured: IP address can not be empty.' && $k->anfragen === [], 'STV_SetSpeakerVolume bei Status 202: Meldung, kein Befehl');
$k->attributSetzen('RemoteControllerInfo', $m->attribut('RemoteControllerInfo')); // Tastenliste vorhanden, nur der Host fehlt
$k->marke();
$meldung = meldungVon(fn () => $k->SendRemoteKey('Num1'));
pruefe($meldung === 'Instance is not configured: IP address can not be empty.' && $k->anfragen === [], 'STV_SendRemoteKey bei Status 202: Meldung, kein Befehl');

IPS_SetProperty($k->id(), 'Host', 'bravia.fritz.box');
$k->marke();
IPS_ApplyChanges($k->id());
pruefe(in_array("Configuration error: IP address 'bravia.fritz.box' is not valid (allowed: IPv4 or IPv6 address).", array_column($k->logsSeitMarke(), 'Message'), true), 'Status 204 steht mit dem Wert im Log');

// --- Aktualisierungsintervall: negativ ist ein Konfigurationsfehler (vorher Timer mit -5 s und „TimerInterval set to -5s.") ---
IPS_SetProperty($k->id(), 'Host', '192.168.178.21');
IPS_SetProperty($k->id(), 'UpdateInterval', -5);
$k->marke();
IPS_ApplyChanges($k->id());
pruefe($k->instanzStatus() === 205, 'UpdateInterval -5: Status 205');
pruefe($k->timer['STV_UpdateTimer'] === 0, 'UpdateInterval -5: Timer läuft nicht');
$logs = array_column($k->logsSeitMarke(), 'Message');
pruefe(in_array('Configuration error: update interval -5 is not valid (allowed: 0 or more seconds).', $logs, true), 'Status 205 steht mit Wert und Bereich im Log');
pruefe(!in_array('TimerInterval set to -5s.', $logs, true), 'kein „TimerInterval set to -5s." mehr');

// Formular und Prüfung nennen dieselbe Grenze (Regel 2)
$form    = json_decode((string)file_get_contents(dirname(__DIR__) . '/Sony TV/form.json'), true, 512, JSON_THROW_ON_ERROR);
$spinner = null;
$suche = static function (array $elemente) use (&$suche, &$spinner): void {
    foreach ($elemente as $e) {
        if (($e['name'] ?? '') === 'UpdateInterval') {
            $spinner = $e;
        }
        $suche($e['items'] ?? []);
    }
};
$suche($form['elements']);
pruefe(($spinner['minimum'] ?? null) === 0, 'Formular: UpdateInterval minimum 0, wie die Prüfung in ApplyChanges');
$codes = array_column($form['status'], 'caption', 'code');
pruefe(isset($codes[205]), 'Formular: Status 205 hat einen Text');

ergebnis();
