<?php

declare(strict_types=1);

/*
 * Gemeinsamer Testrahmen: bindet SonyTV an den offiziellen Kernel-Stub
 * (symcon/SymconStubs, Submodul tests/stubs, gepinnt auf bf2950f).
 *
 * Nähte zum Modul (alles, was im Betrieb ins Netz geht, wartet oder vom Kernel abhängt):
 *   executeCurl()    HTTP-Aufruf      -> Antworten aus tests/fixtures/<zustand>/<service>_<methode>.json
 *   ping()           Sys_Ping         -> SonyTVHarness::$ping
 *   pause()          sleep            -> wird nur protokolliert, die Tests warten nicht
 *   now()            time             -> SonyTVHarness::$uhr (verstellbar)
 *   kernelRunlevel() Kernel-Runlevel  -> SonyTVHarness::$runlevel
 *   chartContents()  Diagramme        -> SonyTVHarness::$diagramme
 * Die Stellgrößen sind statisch, weil createInstance() sofort Create und ApplyChanges ausführt.
 *
 * Fixtures sind echte Mitschnitte des KD-75XE9405, anonymisiert.
 *
 * Einbinden mit require_once __DIR__ . '/harness.php'; Instanzen über neueInstanz().
 */

require_once __DIR__ . '/stubs/autoload.php';

// PHP-Warnungen/-Notices sollen Tests abbrechen, nicht still durchlaufen.
set_error_handler(static function (int $nr, string $text, string $datei, int $zeile): bool {
    if (!(error_reporting() & $nr)) {
        return false;
    }
    if ($nr & (E_USER_ERROR | E_USER_WARNING | E_USER_NOTICE | E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_DEPRECATED)) {
        throw new ErrorException($text, 0, $nr, $datei, $zeile);
    }
    return false;
});

require_once dirname(__DIR__) . '/Sony TV/module.php';

final class SonyTVHarness extends SonyTV
{
    public const MODULE_ID = '{3B91F3E3-FB8F-4E3C-A4BB-4E5C92BBCD58}'; // Sony TV/module.json

    /** Ergebnis von ping(): true/false für alle Aufrufe oder eine Liste, die der Reihe nach verbraucht wird */
    public static bool|array $ping = true;
    public static int $runlevel    = KR_READY;
    public static int $uhr         = 1790000000;
    /** @var list<string|false> Inhalt je Diagramm, false = nicht lesbar */
    public static array $diagramme = [];

    /**
     * Antworten je "service/methode":
     *   string                      Antworttext mit HTTP 200
     *   int                         curl-Fehlernummer
     *   array{http: int, body: string}  Antwort mit abweichendem HTTP-Status
     * Nicht belegte Anfragen enden mit einer Exception - kein stiller Netzverkehr.
     *
     * @var array<string, string|int|array{http: int, body: string}>
     */
    public array $antworten = [];

    /** @var list<array{url: string, service: string, method: string, params: mixed, version: string, headers: list<string>, data: string}> */
    public array $anfragen = [];

    /** @var list<array{0: string, 1: int}> jeder Ping (Host, Timeout in ms) */
    public array $pings = [];
    /** @var list<int> jede Pause in Sekunden */
    public array $pausen = [];
    /** @var list<array{0: string, 1: mixed}> jedes SetValue */
    public array $writes = [];
    /** @var list<int> jedes SetStatus */
    public array $status = [];
    /** @var array<string, int> letztes SetTimerInterval je Timer */
    public array $timer = [];
    private int $logOffset = 0;

    protected function getTime(): int
    {
        return self::$uhr;
    }

    protected function now(): int
    {
        return self::$uhr;
    }

    protected function kernelRunlevel(): int
    {
        return self::$runlevel;
    }

    protected function ping(string $host, int $timeoutMs): bool
    {
        $this->pings[] = [$host, $timeoutMs];
        if (is_array(self::$ping)) {
            return count(self::$ping) > 0 ? (bool)array_shift(self::$ping) : true;
        }
        return self::$ping;
    }

    protected function pause(int $seconds): void
    {
        $this->pausen[] = $seconds;
    }

    protected function chartContents(): array
    {
        return self::$diagramme;
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
            return [false, $antwort, 'simulierter curl-Fehler ' . $antwort, 0];
        }
        if (is_array($antwort)) {
            return [$antwort['body'], 0, '', $antwort['http']];
        }
        return [$antwort, 0, '', 200];
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

    public function attributSetzen(string $Name, string $Wert): void
    {
        $this->WriteAttributeString($Name, $Wert);
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

    /** Optionen der Aufzählung einer Variable (Wert => Beschriftung), leer bei anderer Darstellung. */
    public function optionen(string $ident): array
    {
        $darstellung = $this->darstellung($ident);
        if (($darstellung['PRESENTATION'] ?? '') !== VARIABLE_PRESENTATION_ENUMERATION) {
            return [];
        }
        return array_column(json_decode($darstellung['OPTIONS'], true, 512, JSON_THROW_ON_ERROR), 'Caption', 'Value');
    }

    public function darstellung(string $ident): array
    {
        return IPS_GetVariable(IPS_GetObjectIDByIdent($ident, $this->InstanceID))['VariablePresentation'];
    }

    /** Wert der Option mit dieser Beschriftung. */
    public function wertVon(string $ident, string $beschriftung): int
    {
        $wert = array_search($beschriftung, $this->optionen($ident), true);
        if ($wert === false) {
            throw new RuntimeException("Keine Option '$beschriftung' an $ident");
        }
        return $wert;
    }

    /** Protokoll zurücksetzen, bevor ein Testabschnitt beginnt. */
    public function marke(): void
    {
        $this->anfragen  = [];
        $this->pings     = [];
        $this->pausen    = [];
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

/** Mitschnitt als Text. */
function mitschnitt(string $zustand, string $name): string
{
    $datei = __DIR__ . '/fixtures/' . $zustand . '/' . $name . '.json';
    if (!is_file($datei)) {
        throw new RuntimeException('Mitschnitt fehlt: ' . $datei);
    }
    return (string)file_get_contents($datei);
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
