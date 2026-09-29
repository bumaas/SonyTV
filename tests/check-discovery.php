<?php

declare(strict_types=1);

/**
 * Sony Discovery: Auswertung der SSDP-Suche, Zeilen des Konfigurators und das Flag „Suche läuft"
 * (Befund 13 des Reviews vom 29.09.2026).
 *
 * Mitschnitte: fixtures/discovery/ssdp_search.json (YC_SearchDevices am nuc, sieben Antworten, davon
 * eine vom TV) und fixtures/discovery/dd.xml (Gerätebeschreibung des TV).
 *
 * Aufruf: php tests/check-discovery.php
 */

require_once __DIR__ . '/harness.php';

/** Ersatz für die Funktion der SSDP-Instanz, die der Kernel-Stub nicht kennt. */
function YC_SearchDevices(int $InstanceID, string $SearchTarget): array|false
{
    DiscoveryHarness::$suchziele[] = $SearchTarget;
    return DiscoveryHarness::$suche;
}

require_once dirname(__DIR__) . '/Sony Discovery/module.php';

final class SsdpAttrappe extends IPSModuleStrict
{
}

final class DiscoveryHarness extends SonyDiscovery
{
    public static array|false $suche = [];
    public static array $suchziele   = [];

    /** @var array<string, mixed> letzter Wert je Formularfeld.Parameter */
    public array $formular = [];

    protected function getTime(): int
    {
        return 1790000000;
    }

    protected function fetchXml(string $url): string
    {
        return (string)file_get_contents(__DIR__ . '/fixtures/discovery/dd.xml');
    }

    protected function UpdateFormField(string $Field, string $Parameter, mixed $Value): bool
    {
        $this->formular[$Field . '.' . $Parameter] = $Value;
        return true;
    }

    public function sucheLaeuft(): bool
    {
        return json_decode($this->GetBuffer('SearchActive'), false, 512, JSON_THROW_ON_ERROR);
    }

    public function sucheStarten(): void
    {
        $this->SetBuffer('SearchActive', json_encode(true));
    }

    /** @return list<array> Zeilen des Konfigurators */
    public function zeilen(): array
    {
        return json_decode($this->formular['configurator.values'] ?? '[]', true, 512, JSON_THROW_ON_ERROR);
    }
}

function instanz(string $klasse, string $modulID, string $name, int $typ): object
{
    $id = IPS\ObjectManager::registerObject(1 /* Instance */);
    IPS\InstanceManager::createInstance($id, ['ModuleID' => $modulID, 'ModuleName' => $name, 'ModuleType' => $typ, 'Class' => $klasse]);
    return IPS\InstanceManager::getInstanceInterface($id);
}

instanz(SsdpAttrappe::class, '{FFFFA648-B296-E785-96ED-065F7CEE6F29}', 'SSDP', 0);
$d = instanz(DiscoveryHarness::class, '{D48DDD65-5EBD-82DD-32C6-28F47531DE75}', 'Sony Discovery', 5);

$mitschnitt = json_decode((string)file_get_contents(__DIR__ . '/fixtures/discovery/ssdp_search.json'), true, 512, JSON_THROW_ON_ERROR);

// Suche findet den TV, fremde Geräte (Hue Bridge) werden übergangen
DiscoveryHarness::$suche = $mitschnitt;
$d->sucheStarten();
$d->RequestAction('loadDevices', '');
$zeilen = $d->zeilen();
pruefe(DiscoveryHarness::$suchziele === ['urn:schemas-sony-com:service:ScalarWebAPI:1'], 'gesucht wird nach dem ScalarWebAPI-Dienst');
pruefe(count($zeilen) === 1, 'sieben SSDP-Antworten, eine Zeile (der TV)');
pruefe(($zeilen[0]['host'] ?? '') === '192.168.178.21' && $zeilen[0]['manufacturer'] === 'Sony Corporation' && $zeilen[0]['model'] === 'KD-75XE9405', 'Host, Hersteller und Modell aus Suche und Gerätebeschreibung');
pruefe($zeilen[0]['instanceID'] === 0 && ($zeilen[0]['create'][0]['configuration'] ?? null) === ['Host' => '192.168.178.21'], 'neues Gerät: instanceID 0, create mit Host');
pruefe($d->sucheLaeuft() === false, 'nach der Suche ist das Flag zurückgesetzt');
pruefe($d->formular['searchingInfo.visible'] === false, 'Suchhinweis wird ausgeblendet');

// Angelegte Instanz wird zugeordnet, eine nicht gefundene erscheint ohne create (laut Doku rot)
$tv      = konfigurierteInstanz('aktiv');
$offline = konfigurierteInstanz('aktiv', '192.168.178.99');
$d->RequestAction('loadDevices', '');
$zeilen = array_column($d->zeilen(), null, 'host');
pruefe($zeilen['192.168.178.21']['instanceID'] === $tv->id(), 'gefundener TV ist seiner Instanz zugeordnet');
pruefe($zeilen['192.168.178.99']['instanceID'] === $offline->id(), 'nicht gefundene Instanz steht in der Liste');
pruefe(!array_key_exists('create', $zeilen['192.168.178.99']), 'nicht gefundene Instanz hat kein create');

// Befund 13: die SSDP-Suche scheitert
DiscoveryHarness::$suche = false;
$d->sucheStarten();
$meldung = meldungVon(fn () => $d->RequestAction('loadDevices', ''));
pruefe($meldung === null, 'gescheiterte Suche bricht nicht ab' . ($meldung === null ? '' : ': ' . substr($meldung, 0, 80)));
pruefe($d->sucheLaeuft() === false, 'gescheiterte Suche: Flag ist zurückgesetzt');
pruefe(count($d->zeilen()) === 2 && $d->formular['searchingInfo.visible'] === false, 'gescheiterte Suche: angelegte Instanzen stehen in der Liste, Suchhinweis aus');

ergebnis();
