<?php

declare(strict_types=1);

/**
 * Aktionsbereich des Formulars aus Anwendersicht (Durchsicht 24.10.2026):
 *  1. ein Knopf für alle drei Listen, der Text davor sagt, wann man ihn braucht
 *  2. einheitliche Begriffe (Apps, Eingänge, Tasten)
 *  3. der Text zu „Statusvariablen aktualisieren" nennt das Intervall 0
 *  4. die API-Datei steht im Panel „Support" und ihr Name im Text, die Meldung nennt den Pfad
 *  5. die Meldungsfenster nennen Anzahlen oder den Grund des Fehlschlags
 *  6. der Selbsttest hat einen Erklärtext
 *
 * Mitschnitte: aktiv und falscher-psk (KD-75XE9405). Ein nicht erreichbarer TV ist simuliert (kein Ping,
 * curl-Fehler 7), dafür gibt es keinen Mitschnitt.
 *
 * Aufruf: php tests/check-form-actions.php
 */

require_once __DIR__ . '/harness.php';

// --- Formular ---
$aktionen = formularElemente();
$knoepfe  = array_values(array_filter($aktionen, static fn (array $e): bool => $e['type'] === 'Button'));
$onClicks = array_column($knoepfe, 'onClick', 'caption');
$texte    = array_values(array_filter($aktionen, static fn (array $e): bool => $e['type'] === 'Label' && ($e['visible'] ?? true)));

/** Sichtbarer Text unmittelbar vor dem Knopf mit diesem onClick. */
$textVor = static function (string $onClick) use ($aktionen): string {
    foreach ($aktionen as $i => $element) {
        if (($element['onClick'] ?? '') === $onClick) {
            $davor = $aktionen[$i - 1] ?? [];
            return ($davor['type'] ?? '') === 'Label' && ($davor['visible'] ?? true) ? $davor['caption'] : '';
        }
    }
    return '';
};

pruefe(str_contains($textVor('echo STV_RunSelfTest($id);'), 'Pre-Shared Key'), 'Punkt 6: Selbsttest hat einen Erklärtext');

pruefe(in_array("IPS_RequestAction(\$id, 'UpdateLists', 0);", $onClicks, true), 'Punkt 1: ein Knopf liest alle Listen (UpdateLists)');
foreach (['UpdateApplicationList', 'GetSourceListInfo', 'UpdateRemoteKeyList'] as $ident) {
    pruefe(!in_array("IPS_RequestAction(\$id, '$ident', 0);", $onClicks, true), "Punkt 1: kein eigener Knopf mehr für $ident");
}
$listenText = $textVor("IPS_RequestAction(\$id, 'UpdateLists', 0);");
pruefe(str_contains($listenText, 'installed or removed'), 'Punkt 1: Text sagt, wann neu einlesen nötig ist');
pruefe(array_filter($texte, static fn (array $e): bool => str_starts_with($e['caption'], 'With \'')) === [], 'Punkt 1: kein Text wiederholt nur die Knopfbeschriftung');

pruefe(str_contains($listenText, 'apps, inputs and remote keys'), 'Punkt 2: Text nennt Apps, Eingänge und Tasten');
pruefe(array_filter(array_keys($onClicks), static fn (string $c): bool => str_contains(strtolower($c), 'application') || str_contains($c, 'Input Source')) === [], 'Punkt 2: keine Knöpfe mit „application"/„Input Source"');

pruefe(str_contains($textVor("IPS_RequestAction(\$id, 'UpdateStatusVariables', 0);"), 'interval 0'), 'Punkt 3: Text zu „Update status variables" nennt Intervall 0');

$formular = json_decode((string)file_get_contents(dirname(__DIR__) . '/Sony TV/form.json'), true, 512, JSON_THROW_ON_ERROR);
$panel    = array_values(array_filter($formular['actions'], static fn (array $e): bool => $e['type'] === 'ExpansionPanel' && $e['caption'] === 'Support'))[0] ?? null;
pruefe($panel !== null && in_array("IPS_RequestAction(\$id, 'WriteAPIInformationToFile', 0);", array_column($panel['items'], 'onClick'), true), 'Punkt 4: API-Datei im Panel „Support"');
pruefe(!in_array("IPS_RequestAction(\$id, 'WriteAPIInformationToFile', 0);", array_column($formular['actions'], 'onClick'), true), 'Punkt 4: API-Datei nicht mehr auf oberster Ebene');
pruefe(str_contains($textVor("IPS_RequestAction(\$id, 'WriteAPIInformationToFile', 0);"), "'Sony <model>.txt'"), 'Punkt 4: Text nennt den Dateinamen');

$hinweis = implode("\n", array_column(array_filter($aktionen, static fn (array $e): bool => $e['type'] === 'Label' && ($e['visible'] ?? true) === false), 'caption'));
pruefe(str_contains($hinweis, "'UpdateLists'") || str_contains($hinweis, 'UpdateLists'), 'unsichtbarer Hinweis nennt UpdateLists');

// --- Punkt 5: Meldungen ---
$m = konfigurierteInstanz('aktiv');
$m->meldungsfenster();
$m->RequestAction('UpdateLists', 0);
$text = (string)$m->meldungsfenster();
$tasten   = count($m->optionen('SendRemoteKey')) - 1;
$eingaenge = count($m->optionen('InputSource')) - 1;
$apps     = count($m->optionen('Application')) - 1;
pruefe($tasten === 146 && $eingaenge > 0 && $apps > 0, "Listen gelesen: $tasten Tasten, $eingaenge Eingänge, $apps Apps");
pruefe($text === "$tasten remote keys read. $eingaenge inputs read. $apps apps read.", 'UpdateLists: Meldung mit den drei Anzahlen (' . $text . ')');

$m->RequestAction('UpdateApplicationList', 0);
pruefe($m->meldungsfenster() === "$apps apps read.", 'einzelne Liste (Skript-Ident): Meldung mit Anzahl');

$m->RequestAction('UpdateStatusVariables', 0);
pruefe($m->meldungsfenster() === 'Status variables updated, power status: On.', 'Statusvariablen: Meldung nennt den Zustand');

foreach (glob(__DIR__ . '/fixtures/aktiv/*_getMethodTypes.json') as $datei) {
    $m->antworten[basename($datei, '_getMethodTypes.json') . '/getMethodTypes'] = (string)file_get_contents($datei);
}
$logDir = IPS_GetLogDir();
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}
$m->RequestAction('WriteAPIInformationToFile', 0);
$erwartet = $logDir . 'Sony KD-75XE9405.txt';
pruefe($m->meldungsfenster() === 'File created: ' . $erwartet, 'API-Datei: Meldung nennt den Pfad');
@unlink($erwartet);

// falscher Schlüssel: Tasten und Eingänge kommen ohne Schlüssel, die Apps nicht
$m->antwortenAus('falscher-psk');
$meldung = meldungVon(fn () => $m->RequestAction('UpdateLists', 0));
$text    = (string)$m->meldungsfenster();
pruefe(str_starts_with((string)$meldung, 'Action "UpdateLists"'), 'falscher Schlüssel: RequestAction meldet den Fehlschlag');
pruefe(str_contains($text, 'remote keys read.') && str_contains($text, 'inputs read.'), 'falscher Schlüssel: Tasten und Eingänge gelesen (' . $text . ')');
pruefe(str_contains($text, 'Apps not read: The TV rejected the Pre-Shared Key (error 403).'), 'falscher Schlüssel: Grund bei den Apps');

// TV nicht erreichbar
$n = konfigurierteInstanz('aktiv');
SonyTVHarness::$ping = false;
foreach (['system/getRemoteControllerInfo', 'avContent/getCurrentExternalInputsStatus', 'appControl/getApplicationList', 'system/getPowerStatus', 'system/getSystemInformation'] as $schluessel) {
    $n->antworten[$schluessel] = 7; // CURLE_COULDNT_CONNECT
}
$n->meldungsfenster();
meldungVon(fn () => $n->RequestAction('UpdateApplicationList', 0));
pruefe($n->meldungsfenster() === "Apps not read: TV does not answer: disconnected from mains, 'Remote start' switched off or wrong IP address?", 'nicht erreichbar: Grund bei der Liste');
$n->RequestAction('UpdateStatusVariables', 0);
pruefe($n->meldungsfenster() === 'Status variables updated, power status: Off / not reachable.', 'nicht erreichbar: Statusvariablen melden „Aus / nicht erreichbar"');
meldungVon(fn () => $n->RequestAction('WriteAPIInformationToFile', 0));
pruefe(str_starts_with((string)$n->meldungsfenster(), 'File not created: TV does not answer'), 'nicht erreichbar: Grund bei der API-Datei');
SonyTVHarness::$ping = true;

// einzelner Aussetzer von getPowerStatus (Ping geht): Zustand unbekannt, UpdateAll liefert false
$a                                    = konfigurierteInstanz('aktiv');
$a->antworten['system/getPowerStatus'] = 28; // CURLE_OPERATION_TIMEDOUT, so im Mitschnitt belegt
$a->meldungsfenster();
meldungVon(fn () => $a->RequestAction('UpdateStatusVariables', 0));
pruefe($a->meldungsfenster() === 'Status variables not updated: The TV gave no usable answer, details are in the debug output.', 'Aussetzer: Statusvariablen melden den Grund');

// Review 24.10.2026: die Erreichbarkeit geht vor dem Schlüssel wie im Instanzstatus (201 vor 203). Erst falscher
// Schlüssel (Mitschnitt), dann ist der TV nicht mehr erreichbar (simuliert).
$p = konfigurierteInstanz('aktiv');
$p->antwortenAus('falscher-psk');
meldungVon(fn () => $p->RequestAction('UpdateApplicationList', 0));
SonyTVHarness::$ping = false;
foreach (['system/getPowerStatus', 'appControl/getApplicationList'] as $schluessel) {
    $p->antworten[$schluessel] = 7; // CURLE_COULDNT_CONNECT
}
$p->meldungsfenster();
meldungVon(fn () => $p->RequestAction('UpdateApplicationList', 0));
pruefe(str_starts_with((string)$p->meldungsfenster(), 'Apps not read: TV does not answer'), 'erst falscher Schlüssel, dann nicht erreichbar: Grund „antwortet nicht"');
SonyTVHarness::$ping = true;

// ein verlorenes Ping-Paket macht einen eingeschalteten TV nicht zu „antwortet nicht" (drei Pings wie checkConnection)
$s                                          = konfigurierteInstanz('aktiv');
$s->antworten['appControl/getApplicationList'] = 28; // CURLE_OPERATION_TIMEDOUT
SonyTVHarness::$ping                         = [false];
$s->meldungsfenster();
meldungVon(fn () => $s->RequestAction('UpdateApplicationList', 0));
pruefe($s->meldungsfenster() === 'Apps not read: The TV gave no usable answer, details are in the debug output.', 'ein verlorener Ping: Grund „keine verwertbare Antwort"');
SonyTVHarness::$ping = true;

// TV nicht erreichbar, alle drei Listen scheitern: der Grund wird einmal ermittelt und steht einmal da
SonyTVHarness::$ping = false;
$n->marke();
$n->meldungsfenster();
meldungVon(fn () => $n->RequestAction('UpdateLists', 0));
pruefe(count($n->pings) === 1, 'drei gescheiterte Listen: ein Ping (' . count($n->pings) . ')');
pruefe(
    $n->meldungsfenster() === "Remote keys, inputs and apps not read: TV does not answer: disconnected from mains, 'Remote start' switched off or wrong IP address?",
    'drei gescheiterte Listen: Grund einmal genannt'
);
SonyTVHarness::$ping = true;

// die Datei lässt sich nicht schreiben (ein Ordner mit ihrem Namen liegt im Weg): Grund ist der Schreibfehler,
// nicht der TV, und es wird nicht gepingt
$w = konfigurierteInstanz('aktiv');
foreach (glob(__DIR__ . '/fixtures/aktiv/*_getMethodTypes.json') as $datei) {
    $w->antworten[basename($datei, '_getMethodTypes.json') . '/getMethodTypes'] = (string)file_get_contents($datei);
}
mkdir($erwartet);
$w->marke();
$w->meldungsfenster();
meldungVon(fn () => $w->RequestAction('WriteAPIInformationToFile', 0));
$text = (string)$w->meldungsfenster();
rmdir($erwartet);
pruefe(str_starts_with($text, 'File not created: The file ' . $erwartet . ' could not be written'), 'Schreibfehler: Meldung nennt die Datei (' . $text . ')');
pruefe($w->pings === [], 'Schreibfehler: kein Ping');

// ein Modellname mit „/" (Mitschnitt verändert, ein solches Modell ist nicht belegt) ergibt trotzdem eine Datei
$w->antworten['system/getSystemInformation'] = str_replace('"model":"KD-75XE9405"', '"model":"KD-75/XE9405"', $w->antworten['system/getSystemInformation']);
meldungVon(fn () => $w->RequestAction('WriteAPIInformationToFile', 0));
pruefe($w->meldungsfenster() === 'File created: ' . $logDir . 'Sony KD-75_XE9405.txt', 'Modellname mit „/": Datei mit „_"');
@unlink($logDir . 'Sony KD-75_XE9405.txt');

// auf Deutsch bleibt nichts englisch
SonyTVHarness::$sprache = 'de';
$d = konfigurierteInstanz('aktiv');
$d->RequestAction('UpdateLists', 0);
pruefe($d->meldungsfenster() === "$tasten Tasten eingelesen. $eingaenge Eingänge eingelesen. $apps Apps eingelesen.", 'deutsch: Meldung übersetzt');
$d->antwortenAus('falscher-psk');
meldungVon(fn () => $d->RequestAction('UpdateApplicationList', 0));
pruefe($d->meldungsfenster() === 'Apps nicht eingelesen: Der Fernseher hat den Pre-Shared Key abgelehnt (Fehler 403). Denselben Schlüssel wie am Fernseher eintragen.', 'deutsch: Grund übersetzt');
SonyTVHarness::$ping = false;
foreach (['system/getRemoteControllerInfo', 'avContent/getCurrentExternalInputsStatus', 'appControl/getApplicationList'] as $schluessel) {
    $d->antworten[$schluessel] = 7; // CURLE_COULDNT_CONNECT
}
meldungVon(fn () => $d->RequestAction('UpdateLists', 0));
pruefe(str_starts_with((string)$d->meldungsfenster(), 'Tasten, Eingänge und Apps nicht eingelesen: Fernseher antwortet nicht'), 'deutsch: drei gescheiterte Listen');
SonyTVHarness::$ping = true;
SonyTVHarness::$sprache = null;

ergebnis();
