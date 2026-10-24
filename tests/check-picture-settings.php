<?php

declare(strict_types=1);

/**
 * Bildeinstellungen als Variablen (Wunsch Burkhard, 24.10.2026): Mit dem Schalter ShowPictureSettings legt das
 * Modul je Ziel aus video.getPictureQualitySettings eine Variable an. Bereiche (candidate mit min/max/step) werden
 * Integer mit Schieberegler, Wertelisten String mit Aufzählung. Geschaltet wird über setPictureQualitySettings.
 *
 * Fixtures fixtures/aktiv/video_getPictureQualitySettings.json und video_setPictureQualitySettings.json: echte
 * Mitschnitte des KD-75XE9405 vom 24.10.2026 (TV an, HDMI 3/ARC). Die Antwort auf das Setzen stammt aus
 * „Schärfe 50 auf 50". Die Fälle „Schärfe 86" und „Ziel fällt weg" verändern den Lese-Mitschnitt, der abgelehnte
 * Befehl nutzt die 403-Antwort aus fixtures/falscher-psk.
 *
 * Aufruf: php tests/check-picture-settings.php
 */

require_once __DIR__ . '/harness.php';

$lesen   = mitschnitt('aktiv', 'video_getPictureQualitySettings');
$picture = static fn (SonyTVHarness $m): array => array_filter($m->werte(), static fn (string $ident): bool => str_starts_with($ident, 'Picture'), ARRAY_FILTER_USE_KEY);
$videoAnfragen = static fn (SonyTVHarness $m): array => array_values(array_filter($m->anfragen, static fn (array $a): bool => $a['service'] === 'video'));
$aktion  = static fn (SonyTVHarness $m, string $ident): int => IPS_GetVariable(IPS_GetObjectIDByIdent($ident, $m->id()))['VariableAction'];

// --- Schalter aus (Vorgabe): keine Variablen, keine Abfrage ---
$m = konfigurierteInstanz('aktiv');
pruefe($picture($m) === [], 'Schalter aus: keine Bildvariablen');
$m->marke();
$m->UpdateAll();
pruefe($videoAnfragen($m) === [], 'Schalter aus: UpdateAll fragt die Bildeinstellungen nicht ab');

// --- Schalter an, TV an: alle 13 Ziele werden Variablen ---
IPS_SetProperty($m->id(), 'ShowPictureSettings', true);
$m->marke();
IPS_ApplyChanges($m->id());
$werte = $picture($m);
pruefe(count($werte) === 13, 'Schalter an: 13 Bildvariablen (eine je gemeldetem Ziel), gefunden ' . count($werte));
pruefe(($werte['PictureSharpness'] ?? null) === 50, 'PictureSharpness = 50 (Integer)');
pruefe(($werte['PictureBrightness'] ?? null) === 35 && ($werte['PictureHue'] ?? null) === 0, 'PictureBrightness = 35, PictureHue = 0');
pruefe(($werte['PictureColorTemperature'] ?? null) === 'warm', 'PictureColorTemperature = "warm" (String)');
pruefe(($werte['PictureColorSpace'] ?? null) === 'auto', 'PictureColorSpace = "auto"');
pruefe(array_key_exists('PicturePictureMode', $werte) && $werte['PicturePictureMode'] === '', 'PicturePictureMode mit leerem Wert, wie gemeldet');
pruefe(count($videoAnfragen($m)) === 1 && $videoAnfragen($m)[0]['params'] === [['target' => '']], 'ApplyChanges liest alle Ziele mit einer Anfrage (target "")');

if (isset($werte['PictureSharpness'], $werte['PictureHue'], $werte['PictureBrightness'])) {
    $schaerfe = $m->darstellung('PictureSharpness');
    pruefe(
        $schaerfe['PRESENTATION'] === VARIABLE_PRESENTATION_SLIDER && $schaerfe['MIN'] === 0 && $schaerfe['MAX'] === 100 && $schaerfe['STEP_SIZE'] === 1,
        'PictureSharpness: Schieberegler 0 bis 100, Schrittweite 1'
    );
    pruefe($m->darstellung('PictureBrightness')['MAX'] === 50, 'PictureBrightness: Maximum 50 aus candidate');
    pruefe($m->darstellung('PictureHue')['MIN'] === -50 && $m->darstellung('PictureHue')['MAX'] === 50, 'PictureHue: -50 bis 50 aus candidate');
}
if (isset($werte['PictureColorTemperature'], $werte['PictureColorSpace'])) {
    pruefe(
        array_keys($m->optionen('PictureColorTemperature')) === ['cool', 'neutral', 'warm', 'expert1', 'expert2'],
        'PictureColorTemperature: Optionen in der Reihenfolge des TV'
    );
    pruefe(array_key_exists('sRgb/bt709', $m->optionen('PictureColorSpace')), 'PictureColorSpace: Option "sRgb/bt709"');
    $felder = json_decode($m->darstellung('PictureColorTemperature')['OPTIONS'], true);
    pruefe(array_diff(['Value', 'Caption', 'IconActive', 'IconValue', 'Color'], array_keys($felder[0])) === [], 'Optionen tragen alle Felder der Doku');
}
if (isset($werte['PictureSharpness'], $werte['PictureHdrMode'])) {
    pruefe($aktion($m, 'PictureSharpness') === $m->id(), 'PictureSharpness (isAvailable true) ist schaltbar');
    pruefe($aktion($m, 'PictureHdrMode') === 0, 'PictureHdrMode (isAvailable false) hat keine Aktion');
}

// --- UpdateAll liest die Werte neu (Mitschnitt verändert: Schärfe 86 wie nach dem Stromausfall) ---
$m->antworten['video/getPictureQualitySettings'] = str_replace('"target":"sharpness","currentValue":"50"', '"target":"sharpness","currentValue":"86"', $lesen);
$m->UpdateAll();
pruefe(($picture($m)['PictureSharpness'] ?? null) === 86, 'UpdateAll bei TV an: PictureSharpness folgt dem TV (86)');
$m->antworten['video/getPictureQualitySettings'] = $lesen;

// --- schalten ---
$m->marke();
$meldung = meldungVon(fn () => $m->RequestAction('PictureSharpness', 60));
$set     = $videoAnfragen($m);
pruefe($meldung === null, 'RequestAction PictureSharpness 60: keine Warnung' . ($meldung === null ? '' : ' (' . $meldung . ')'));
pruefe(
    count($set) === 1 && $set[0]['method'] === 'setPictureQualitySettings' && $set[0]['params'] === [['settings' => [['target' => 'sharpness', 'value' => '60']]]],
    'PictureSharpness 60: setPictureQualitySettings mit target sharpness und value "60"'
);
pruefe(($picture($m)['PictureSharpness'] ?? null) === 60, 'PictureSharpness 60: Variable nach Bestätigung 60');

$m->marke();
$meldung = meldungVon(fn () => $m->RequestAction('PictureColorTemperature', 'neutral'));
$set     = $videoAnfragen($m);
pruefe($meldung === null && ($set[0]['params'][0]['settings'][0] ?? null) === ['target' => 'colorTemperature', 'value' => 'neutral'], 'PictureColorTemperature "neutral": gesendet');
pruefe(($picture($m)['PictureColorTemperature'] ?? null) === 'neutral', 'PictureColorTemperature: Variable nach Bestätigung "neutral"');

// --- ungültige Werte: Warnung mit den erlaubten Werten, kein Befehl ---
foreach ([
    ['PictureSharpness', 101, 'allowed: 0 to 100'],
    ['PictureSharpness', 'scharf', 'allowed: 0 to 100'],
    ['PictureHue', -51, 'allowed: -50 to 50'],
    ['PictureColorTemperature', 'hot', 'allowed: cool, neutral, warm, expert1, expert2'],
] as [$ident, $wert, $erwartet]) {
    $m->marke();
    $meldung = (string)meldungVon(fn () => $m->RequestAction($ident, $wert));
    pruefe(str_starts_with($meldung, 'Invalid value "' . $wert . '" for "' . $ident . '"') && str_contains($meldung, $erwartet), "$ident = " . var_export($wert, true) . ": Warnung nennt $erwartet");
    pruefe($videoAnfragen($m) === [], "$ident = " . var_export($wert, true) . ': kein Befehl an den TV');
}

// --- nicht verfügbar: Warnung, kein Befehl ---
$m->marke();
$meldung = (string)meldungVon(fn () => $m->RequestAction('PictureHdrMode', 'auto'));
pruefe(str_contains($meldung, 'PictureHdrMode') && str_contains($meldung, 'not available'), 'PictureHdrMode (isAvailable false): Warnung „not available"');
pruefe($videoAnfragen($m) === [], 'PictureHdrMode: kein Befehl an den TV');

// --- TV lehnt ab (403-Antwort aus falscher-psk): Warnung, Variable bleibt ---
$m->antworten['video/setPictureQualitySettings'] = ['http' => 403, 'body' => mitschnitt('falscher-psk', 'system_setPowerStatus')];
$m->marke();
$meldung = (string)meldungVon(fn () => $m->RequestAction('PictureSharpness', 40));
pruefe(str_starts_with($meldung, 'Action "PictureSharpness" with value 40 failed'), 'abgelehnter Befehl: Warnung „Action … failed"');
pruefe(($picture($m)['PictureSharpness'] ?? null) === 60, 'abgelehnter Befehl: PictureSharpness bleibt 60');
$m->antworten['video/setPictureQualitySettings'] = mitschnitt('aktiv', 'video_setPictureQualitySettings');
// der 403 hat Status 203 gesetzt; die nächste Antwort mit Schlüssel hebt ihn wieder auf
$m->UpdateAll();

// --- Ziel fällt weg (Mitschnitt verändert: ohne hue): Variable bleibt, ist aber nicht mehr schaltbar ---
$ohneHue = json_decode($lesen, true);
$ohneHue['result'][0] = array_values(array_filter($ohneHue['result'][0], static fn (array $z): bool => $z['target'] !== 'hue'));
$m->antworten['video/getPictureQualitySettings'] = json_encode($ohneHue);
$m->UpdateAll();
pruefe(array_key_exists('PictureHue', $picture($m)), 'Ziel hue nicht mehr gemeldet: Variable bleibt');
if (array_key_exists('PictureHue', $picture($m))) {
    pruefe($aktion($m, 'PictureHue') === 0, 'Ziel hue nicht mehr gemeldet: keine Aktion');
}
$m->antworten['video/getPictureQualitySettings'] = $lesen;
$m->UpdateAll();
if (array_key_exists('PictureHue', $picture($m))) {
    pruefe($aktion($m, 'PictureHue') === $m->id(), 'Ziel hue wieder gemeldet: wieder schaltbar');
}

// --- Standby: keine Abfrage, Schalten mit Hinweis abgewiesen ---
$m->antwortenAus('standby');
$m->UpdateAll();
$m->marke();
$m->UpdateAll();
pruefe($m->werte()['PowerStatus'] === 1 && $videoAnfragen($m) === [], 'Standby: UpdateAll fragt die Bildeinstellungen nicht ab');
$meldung = (string)meldungVon(fn () => $m->RequestAction('PictureSharpness', 50));
pruefe(str_contains($meldung, 'only while the TV is on'), 'Standby: Schalten mit Warnung abgewiesen („only while the TV is on")');
pruefe($videoAnfragen($m) === [], 'Standby: kein Befehl an den TV');

// --- deutsche Namen ---
SonyTVHarness::$sprache = 'de';
$d = konfigurierteInstanz('aktiv');
IPS_SetProperty($d->id(), 'ShowPictureSettings', true);
IPS_ApplyChanges($d->id());
$name = static fn (string $ident): string => IPS_GetName(IPS_GetObjectIDByIdent($ident, $d->id()));
if (array_key_exists('PictureSharpness', $picture($d))) {
    pruefe($name('PictureSharpness') === 'Schärfe' && $name('PictureColorTemperature') === 'Farbtemperatur', 'deutsch: Schärfe, Farbtemperatur');
    pruefe(($d->optionen('PictureColorTemperature')['cool'] ?? '') === 'Kalt' && ($d->optionen('PictureAutoLocalDimming')['off'] ?? '') === 'Aus', 'deutsch: Optionen Kalt, Aus');
}
$selbsttest = $d->RunSelfTest();
pruefe(str_contains($selbsttest, '✓ 13 Bildeinstellungen eingelesen (Variablen Picture…)'), 'Selbsttest: Zeile „13 Bildeinstellungen eingelesen"');
$leer = konfigurierteInstanz('standby');
IPS_SetProperty($leer->id(), 'ShowPictureSettings', true);
IPS_ApplyChanges($leer->id());
pruefe(str_contains($leer->RunSelfTest(), '⚠ 0 Bildeinstellungen eingelesen'), 'Selbsttest, Schalter an, nur Standby erlebt: Warnung „0 Bildeinstellungen"');
SonyTVHarness::$sprache = null;

// --- andere Modelle: Sonys Referenz erlaubt min/max/step = -1 bei Wertelisten, value "" bei Bereichen und
// Kommazahlen. Der Lese-Mitschnitt wird dafür verändert, ein Mitschnitt eines solchen Geräts fehlt. ---
$anders = json_decode($lesen, true);
foreach ($anders['result'][0] as &$ziel) {
    if ($ziel['target'] === 'colorTemperature') {
        $ziel['candidate'] = array_map(static fn (array $c): array => $c + ['max' => -1, 'min' => -1, 'step' => -1], $ziel['candidate']);
    }
    if ($ziel['target'] === 'contrast') {
        $ziel['candidate'] = [['value' => '', 'max' => 100, 'min' => 0, 'step' => 1]];
    }
    if ($ziel['target'] === 'sharpness') {
        $ziel['candidate'] = [['max' => 10, 'min' => 0, 'step' => 0.5]];
        $ziel['currentValue'] = '5.5';
    }
}
unset($ziel);
$a = konfigurierteInstanz('aktiv');
$a->antworten['video/getPictureQualitySettings'] = json_encode($anders);
IPS_SetProperty($a->id(), 'ShowPictureSettings', true);
$a->marke();
$meldung = meldungVon(fn () => IPS_ApplyChanges($a->id()));
pruefe($meldung === null, 'andere Kandidaten: keine Warnung' . ($meldung === null ? '' : ' (' . $meldung . ')'));
$werteA = $picture($a);
pruefe(
    ($werteA['PictureColorTemperature'] ?? null) === 'warm' && ($a->darstellung('PictureColorTemperature')['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_ENUMERATION,
    'Werteliste mit min/max/step = -1: bleibt Aufzählung mit "warm"'
);
pruefe(
    ($werteA['PictureContrast'] ?? null) === 77 && ($a->darstellung('PictureContrast')['MAX'] ?? null) === 100,
    'Bereich mit value "": Schieberegler bis 100, Wert 77'
);
pruefe(!array_key_exists('PictureSharpness', $werteA), 'Bereich mit Schrittweite 0,5: übersprungen statt verfälscht');
pruefe(count($werteA) === 12, 'übrige Ziele unverändert: 12 Variablen');

// --- Schalter wieder aus: Variablen entfallen ---
IPS_SetProperty($m->id(), 'ShowPictureSettings', false);
IPS_ApplyChanges($m->id());
pruefe($picture($m) === [], 'Schalter wieder aus: Bildvariablen entfernt');
pruefe(meldungVon(fn () => $m->RequestAction('PictureSharpness', 50)) === 'Unexpected ident: PictureSharpness', 'Schalter aus: PictureSharpness ist ein unbekannter Ident');

ergebnis();
