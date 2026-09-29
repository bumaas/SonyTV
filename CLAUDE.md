# SonyTV — Projekt-Hinweise

Symcon-Modulbibliothek zur Steuerung von Sony-Bravia-Fernsehern über deren REST-API
(`IPSModuleStrict`, `declare(strict_types=1)`, Präfix `STV`).

## Struktur

- `Sony TV/` — Geräte-Instanz (`type: 3`, ohne Parent), die gesamte Logik in `module.php`
  - `form.json` — statisches Formular; **englische Labels sind zugleich die Übersetzungsschlüssel**
  - `locale.json` — deutsche Übersetzungen
- `Sony Discovery/` — Discovery-Instanz (`type: 5`), sucht per SSDP (`YC_SearchDevices`) nach
  `urn:schemas-sony-com:service:ScalarWebAPI:1`, Formular dynamisch in `GetConfigurationForm()`
- `actions/switchPowerStatus.json` — Aktion „Switch to Power State" (ruft `STV_SetPowerStatus`)
- `library.json` (Repo-Wurzel) — Version, Build, Datum (Konvention siehe globale CLAUDE.md)
- `tests/` — Tests gegen den offiziellen Kernel-Stub, Fixtures unter `tests/fixtures/<zustand>/`

## Kommunikation mit dem TV

- JSON-RPC über `POST http://<Host>/sony/<service>`, Authentifizierung per Header `X-Auth-PSK`.
  Fernbedienungstasten gehen als SOAP an `/sony/IRCC` (`SendRemoteKey`).
- **`executeCurl()` ist die einzige Stelle mit Netzverkehr** (Naht für die Tests). Die
  Fehlerbehandlung darüber liegt in `SendCurlPost()`: Listen ignorierter curl- und TV-Fehler
  entscheiden, ob ein Fehler nur ins Debug oder ins Log geht; jede nicht ignorierte
  TV-Fehlerantwort (`{"error":[code, text]}`) wird zu `false`.
- `callRestApi()` (privat) ist der interne Weg für alle REST-Aufrufe. **`STV_SendRestAPIRequest`
  ist nur die öffentliche Diagnose-Variante** mit der Parameterliste als JSON-String:
  Öffentliche Modulfunktionen dürfen nur `bool`/`int`/`float`/`string` als Parameter haben,
  sonst legt Symcon die Funktion beim Laden gar nicht an (bis build 24 so passiert;
  `tests/check-public-signatures.php` hält das fest).
- Im Standby antwortet der TV auf `getPowerStatus`, `getRemoteControllerInfo`,
  `getCurrentExternalInputsStatus` und `getApplicationList`; Audio- und Content-Abfragen enden mit
  `40005 Display Is Turned off`. Ein falscher PSK liefert `[403, "Forbidden"]` samt `auth_url`.
- Während des Bootens meldet `getPowerStatus` fälschlich `active`; `isStillBooting()` prüft deshalb
  90 s nach einem Fehlschlag zusätzlich `getPlayingContentInfo`.

## Prüfen und Ausrollen

```bash
git submodule update --init                       # einmalig: .style (Regelwerk) und tests/stubs (Kernel-Stub)
C:/php/php ~/.claude/tools/modul_build.php T:\modules\SonyTV   # Syntax, JSON, Tests, Locale, Stil, Git-Stand
C:/php/php tests/check-<thema>.php                # einzelner Test, Schlusszeile "N Prüfungen, M Fehler"
```

Die CI (`.github/workflows/check.yml`) fährt `php -l`, php-cs-fixer gegen `.style` (`--dry-run`),
JSON-Validität, `check_locale.php` und alle `tests/check-*.php`, darunter die Doku-Sperrklinke
`check-readme.php`.

**Fixtures sind echte Mitschnitte** des KD-75XE9405 (Firmware-Generation 5.4.0) in den Zuständen
`standby` und `aktiv`, anonymisiert
(Seriennummer, MAC, CID). Neue Fixtures nur mitschneiden, nie von Hand bauen; lesende Methoden
genügen, schaltende (`setPowerStatus`, `requestReboot` …) nie gegen das echte Gerät zum Mitschneiden.

Das Repo liegt unter `T:\modules` — **das ist das produktive Modulverzeichnis des nuc**, und der
PHP-Worker liest `module.php` bei jedem Aufruf neu. Jede gespeicherte Änderung wirkt sofort auf
die Instanz #36393; halbfertige Zwischenstände erzeugen dort Fehler im Log. Größere Umbauten also
in einem Git-Worktree außerhalb von `T:\modules` machen oder in einem Rutsch schreiben.
Neue Bibliotheksdaten (Funktionsliste, `library.json`) per `MC_ReloadModule` mit Ordnername
`SonyTV` einlesen.

## Darstellungen

Alle Variablen nutzen Darstellungen (seit 2.10 build 28). Tasten, Eingänge und Apps stehen als Optionen einer
Aufzählung an der Variable (`registerListVariable()`), der Wert ist der **Index** in der jeweiligen
Attributliste (`RemoteControllerInfo`, `SourceList`, `ApplicationList`); `RequestAction` schaltet über
diesen Index, nicht über den formatierten Text. Die Profile `STV.*` früherer Versionen räumt
`removeUnusedLegacyProfiles()` in `ApplyChanges` ab, sobald keine Variable und kein Diagramm sie nutzt.
`IPS_GetMediaContent()` liefert für Diagramme einer nicht verfügbaren Instanz `false` samt Warnung
(am nuc gesehen) - deshalb dort `@` und `is_string`; der Stub kennt keine Diagramme, das ist nur live prüfbar.
`tests/check_presentations.php` prüft die Darstellungsparameter gegen die Liste aus Symcon 9.1.

## Offene Punkte

- `getCommonHeaders()` sendet eine Headerzeile ohne Namen (`'application/json; charset=UTF-8'`).
