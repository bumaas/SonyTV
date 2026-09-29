<?php

declare(strict_types=1);

/*
 * Gemeinsamer Testrahmen: bindet SonyTV an den offiziellen Kernel-Stub
 * (symcon/SymconStubs, Submodul tests/stubs, gepinnt auf bf2950f).
 *
 * Netz-Naht: SonyTV::executeCurl() ist die einzige Stelle mit Netzverkehr. Die Harness
 * überschreibt sie und liefert Antworten aus tests/fixtures/<zustand>/<service>_<methode>.json
 * (echte Mitschnitte des KD-75XE9405, anonymisiert). Jeder Aufruf wird in $anfragen protokolliert.
 *
 * Einbinden mit require_once __DIR__ . '/harness.php'; Instanzen über neueInstanz().
 */

require_once __DIR__ . '/stubs/autoload.php';

// PHP-Warnungen/-Notices sollen Tests abbrechen, nicht still durchlaufen.
set_error_handler(static function (int $nr, string $text, string $datei, int $zeile): bool {
    if (!(error_reporting() & $nr)) {
        return false;
    }
    if ($nr & (E_USER_ERROR | E_USER_WARNING | E_USER_NOTICE | E_WARNING | E_NOTICE)) {
        throw new ErrorException($text, 0, $nr, $datei, $zeile);
    }
    return false;
});

require_once dirname(__DIR__) . '/Sony TV/module.php';

final class SonyTVHarness extends SonyTV
{
    public const MODULE_ID = '{3B91F3E3-FB8F-4E3C-A4BB-4E5C92BBCD58}'; // Sony TV/module.json

    /**
     * Antworten je "service/methode": string = Antworttext, int = curl-Fehlernummer.
     * Nicht belegte Anfragen enden mit einer Exception - kein stiller Netzverkehr.
     *
     * @var array<string, string|int>
     */
    public array $antworten = [];

    /** @var list<array{url: string, service: string, method: string, params: mixed, version: string, headers: list<string>, data: string}> */
    public array $anfragen = [];

    /** @var list<array{0: string, 1: mixed}> jedes SetValue */
    public array $writes = [];
    /** @var list<int> jedes SetStatus */
    public array $status = [];
    /** @var array<string, int> letztes SetTimerInterval je Timer */
    public array $timer = [];
    private int $logOffset = 0;

    protected function getTime(): int
    {
        return time();
    }

    protected function executeCurl(string $url, array $headers, string $data): array
    {
        $service = substr($url, strrpos($url, '/sony/') + 6);
        $json    = json_decode($data, true);
        $method  = is_array($json) ? $json['method'] : 'X_SendIRCC';

        $this->anfragen[] = [
            'url'     => $url,
            'service' => $service,
            'method'  => $method,
            'params'  => is_array($json) ? $json['params'] : null,
            'version' => is_array($json) ? $json['version'] : '',
            'headers' => $headers,
            'data'    => $data,
        ];

        $schluessel = $service . '/' . $method;
        if (!array_key_exists($schluessel, $this->antworten)) {
            throw new RuntimeException('Keine Testantwort hinterlegt für ' . $schluessel);
        }
        $antwort = $this->antworten[$schluessel];
        if (is_int($antwort)) {
            return [false, $antwort, 'simulierter curl-Fehler ' . $antwort];
        }
        return [$antwort, 0, ''];
    }

    protected function SetValue(string $Ident, mixed $Value): bool
    {
        $ok             = parent::SetValue($Ident, $Value);
        $this->writes[] = [$Ident, $Value];
        return $ok;
    }

    protected function SetStatus(int $Status): bool
    {
        $this->status[] = $Status;
        return parent::SetStatus($Status);
    }

    protected function SetTimerInterval(string $Ident, int $Milliseconds): bool
    {
        $this->timer[$Ident] = $Milliseconds;
        return parent::SetTimerInterval($Ident, $Milliseconds);
    }

    /** Lädt alle Mitschnitte eines Zustands (standby, aktiv …) als Antworten. */
    public function antwortenAus(string $zustand): void
    {
        foreach (glob(__DIR__ . '/fixtures/' . $zustand . '/*.json') as $datei) {
            $name = basename($datei, '.json');
            if (substr_count($name, '_') !== 1) {
                continue; // Sonderfälle wie *_falscher_psk.json holt sich der Test selbst
            }
            [$service, $method]                       = explode('_', $name);
            $this->antworten[$service . '/' . $method] = (string)file_get_contents($datei);
        }
    }

    public function id(): int
    {
        return $this->InstanceID;
    }

    public function attribut(string $Name): string
    {
        return $this->ReadAttributeString($Name);
    }

    public function instanzStatus(): int
    {
        return $this->GetStatus();
    }

    /** Alle Variablenwerte der Instanz (Ident -> Wert), typgetreu aus dem Kernel-Stub. */
    public function werte(): array
    {
        $werte = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $vid) {
            $obj = IPS_GetObject($vid);
            if ($obj['ObjectType'] === 2 /* Variable */) {
                $werte[$obj['ObjectIdent']] = GetValue($vid);
            }
        }
        return $werte;
    }

    /** Protokoll zurücksetzen, bevor ein Testabschnitt beginnt. */
    public function marke(): void
    {
        $this->anfragen  = [];
        $this->writes    = [];
        $this->status    = [];
        $this->logOffset = count(IPS\LogServer::getLogMessages((string)$this->InstanceID));
    }

    /** @return list<array{Message: string, Type: int}> */
    public function logsSeitMarke(): array
    {
        return array_values(array_slice(IPS\LogServer::getLogMessages((string)$this->InstanceID), $this->logOffset));
    }
}

/** Legt eine Instanz im Kernel-Stub an (Create + ApplyChanges laufen in createInstance). */
function neueInstanz(): SonyTVHarness
{
    $id = IPS\ObjectManager::registerObject(1 /* Instance */);
    IPS\InstanceManager::createInstance($id, [
        'ModuleID'   => SonyTVHarness::MODULE_ID,
        'ModuleName' => 'Sony TV',
        'ModuleType' => 3,
        'Class'      => SonyTVHarness::class,
    ]);
    return IPS\InstanceManager::getInstanceInterface($id);
}

/** Instanz mit Host und Mitschnitten eines Zustands konfigurieren und ApplyChanges ausführen. */
function konfigurierteInstanz(string $zustand, string $host = '192.168.178.21'): SonyTVHarness
{
    $m = neueInstanz();
    $m->antwortenAus($zustand);
    IPS_SetProperty($m->id(), 'Host', $host);
    IPS_ApplyChanges($m->id());
    return $m;
}

/** Führt $aufruf aus und liefert die Meldung einer Warnung/Exception, sonst null. */
function meldungVon(callable $aufruf): ?string
{
    try {
        $aufruf();
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return null;
}

$pruefungen = 0;
$fehler     = [];
function pruefe(bool $ok, string $text): void
{
    global $pruefungen, $fehler;
    $pruefungen++;
    if (!$ok) {
        $fehler[] = $text;
    }
    echo ($ok ? '  ok   ' : '  FEHL ') . $text . "\n";
}

/** Schlusszeile und Exit-Code - Format ist Pflicht (K2), rotgruen.php parst genau das. */
function ergebnis(): never
{
    global $pruefungen, $fehler;
    echo "\n$pruefungen Prüfungen, " . count($fehler) . " Fehler\n";
    exit($fehler === [] ? 0 : 1);
}

IPS\Kernel::reset(); // einmal je Testlauf; weitere Instanzen entstehen im selben Kernel

// Systemprofile, die das Modul voraussetzt - der ProfileManager des Stubs startet leer.
IPS_CreateVariableProfile('~Switch', VARIABLETYPE_BOOLEAN);
