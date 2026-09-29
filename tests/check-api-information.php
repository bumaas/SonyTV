<?php

declare(strict_types=1);

/**
 * WriteAPIInformationToFile: Supportdatei mit Systeminformation und allen Methoden je Service,
 * aus den Mitschnitten getServiceProtocols und <service>_getMethodTypes (KD-75XE9405, eingeschaltet).
 *
 * Aufruf: php tests/check-api-information.php
 */

require_once __DIR__ . '/harness.php';

$m = konfigurierteInstanz('aktiv');
foreach (glob(__DIR__ . '/fixtures/aktiv/*_getMethodTypes.json') as $datei) {
    $m->antworten[basename($datei, '_getMethodTypes.json') . '/getMethodTypes'] = (string)file_get_contents($datei);
}

$datei = tempnam(sys_get_temp_dir(), 'stv');
$m->marke();
pruefe($m->WriteAPIInformationToFile($datei) === true, 'WriteAPIInformationToFile meldet Erfolg');
$inhalt = (string)file_get_contents($datei);
unlink($datei);

pruefe(str_contains($inhalt, 'SystemInformation: {"result":[{"product":"TV"'), 'Systeminformation steht am Anfang');
$services = array_column(json_decode($m->antworten['guide/getServiceProtocols'], true, 512, JSON_THROW_ON_ERROR)['results'], 0);
foreach ($services as $service) {
    pruefe(str_contains($inhalt, 'Service: ' . $service . PHP_EOL), "Abschnitt für Service $service");
}
pruefe(str_contains($inhalt, '   getPlayingContentInfo(): {"uri":"string", "source":"string", "title":"string"'), 'Methode mit Parametern und Rückgabe (avContent/getPlayingContentInfo)');
pruefe(!str_contains($inhalt, 'getMethodTypes(') && !str_contains($inhalt, 'getVersions('), 'getMethodTypes/getVersions werden nicht aufgeführt');
$fehlerLogs = array_filter($m->logsSeitMarke(), fn (array $eintrag): bool => str_contains($eintrag['Message'], 'contentshare') || str_contains($eintrag['Message'], '404'));
pruefe($fehlerLogs === [], 'contentshare (404) erzeugt keinen Fehler im Log');

ergebnis();
