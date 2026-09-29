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
- `tests/` — Tests gegen den offiziellen Kernel-Stub, Fixtures unter `tests/fixtures/` (siehe `README.md` dort)

## Kommunikation mit dem TV

- JSON-RPC über `POST http://<Host>/sony/<service>` mit `Content-Type: application/json`,
  Authentifizierung per Header `X-Auth-PSK`. Bis build 29 gingen die Anfragen als `text/xml` samt einer
  namenlosen Headerzeile raus; der KD-75XE9405 antwortet auf beide Varianten identisch (geprüft 29.09.2026).
  Fernbedienungstasten gehen als SOAP an `/sony/IRCC` (`SendRemoteKey`); dort sagt nur der HTTP-Status,
  ob der TV den Befehl angenommen hat.
- Die Fehlerbehandlung liegt in `SendCurlPost()`: Listen ignorierter curl- und TV-Fehler entscheiden, ob
  ein Fehler nur ins Debug oder ins Log geht; jede nicht ignorierte TV-Fehlerantwort
  (`{"error":[code, text]}`) wird zu `false`. `getResult()` liefert `result` bzw. `results` einer Antwort.
- `callRestApi()` (privat) ist der interne Weg für alle REST-Aufrufe. **`STV_SendRestAPIRequest`
  ist nur die öffentliche Diagnose-Variante** mit der Parameterliste als JSON-String.
- **Öffentliche Modulfunktionen** dürfen nur `bool`/`int`/`float`/`string` als Parameter haben (sonst legt
  Symcon die Funktion beim Laden gar nicht an, bis build 24 so passiert) **und keine Vorgabewerte**: Die
  exportierte `STV_…`-Funktion verlangt jeden Parameter („Parameter count does not match", am nuc belegt).
  `tests/check-public-signatures.php` hält beides fest.
- Im Standby antwortet der TV auf `getPowerStatus`, `getRemoteControllerInfo`,
  `getCurrentExternalInputsStatus` und `getApplicationList`; Audio- und Content-Abfragen enden mit
  `40005 Display Is Turned off`.
- **Falscher PSK:** `getPowerStatus` antwortet trotzdem (HTTP 200). Alles andere endet mit HTTP 403 und
  `[403, "Forbidden"]` samt `auth_url`, `/sony/IRCC` mit HTTP 403 und leerem Inhalt.
- `getPlayingContentInfo` meldet bei eingeschaltetem TV zeitweise `[7, "Illegal State"]` (Mitschnitt
  29.09.2026). In der Bootphase gilt das als „startet noch": `isStillBooting()` prüft 90 s nach dem letzten
  Fehlschlag (kein Ping oder zwei Aussetzer in Folge) zusätzlich `getPlayingContentInfo`.
- Ein **einzelner Aussetzer** von `getPowerStatus` (im Mitschnitt belegt: curl-Fehler 28, drei Sekunden später
  wieder eine Antwort) ändert nichts; `UpdateAll` liefert dann `false` für „Zustand unbekannt".

## Nähte für die Tests

Alles, was im Betrieb ins Netz geht, wartet oder vom Kernel abhängt, läuft über eine überschreibbare
Methode: `executeCurl()` (HTTP, liefert auch den HTTP-Status), `ping()` (`Sys_Ping`), `pause()` (`sleep`),
`now()` (`time`), `kernelRunlevel()`, `chartContents()`; in der Discovery `fetchXml()`.
`tests/harness.php` ersetzt sie. Neuer Netzverkehr oder neue Wartezeiten gehören hinter eine dieser Nähte.

## Kernelstart

Vor `KR_READY` fragt `ApplyChanges` den TV nicht ab und räumt keine Profile auf; `Create` registriert
`IPS_KERNELMESSAGE`, `MessageSink` holt `ApplyChanges` mit `KR_READY` nach. Bei einem Konfigurationsfehler
(202/204) läuft kein Timer.

## Prüfen und Ausrollen

```bash
git submodule update --init                       # einmalig: .style (Regelwerk) und tests/stubs (Kernel-Stub)
C:/php/php ~/.claude/tools/modul_build.php T:\modules\SonyTV   # Syntax, JSON, Tests, Locale, Stil, Git-Stand
C:/php/php tests/check-<thema>.php                # einzelner Test, Schlusszeile "N Prüfungen, M Fehler"
```

Die CI (`.github/workflows/check.yml`) fährt `php -l`, php-cs-fixer gegen `.style` (`--dry-run`),
JSON-Validität, `check_locale.php`, `check_presentations.php` und alle `tests/check-*.php`, darunter die
Doku-Sperrklinke `check-readme.php`.

**Fixtures sind echte Mitschnitte** (KD-75XE9405, SSDP-Suche am nuc), anonymisiert. Neue Fixtures nur
mitschneiden, nie von Hand bauen; lesende Methoden genügen, schaltende (`setPowerStatus`,
`requestReboot` …) nie gegen das echte Gerät zum Mitschneiden. Wo ein Test eine Liste verändert
(App kommt hinzu, Eingang fehlt), geht er von einem Mitschnitt aus und sagt das im Kommentar.

Das Repo liegt unter `T:\modules` — **das ist das produktive Modulverzeichnis des nuc**, und der
PHP-Worker liest `module.php` bei jedem Aufruf neu. Jede gespeicherte Änderung wirkt sofort auf
die Instanz #36393; halbfertige Zwischenstände erzeugen dort Fehler im Log. Größere Umbauten also
in einem Git-Worktree außerhalb von `T:\modules` machen oder in einem Rutsch schreiben.
Neue Bibliotheksdaten (Funktionsliste, `library.json`) per `MC_ReloadModule` mit Ordnername
`SonyTV` einlesen.

## Darstellungen und Listenwerte

Alle Variablen nutzen Darstellungen (seit 2.10 build 28). Tasten, Eingänge und Apps stehen als Optionen einer
Aufzählung an der Variable (`registerListVariable()`).

**Der Wert eines Eintrags ist eine feste Nummer**, keine Position: Das Attribut `ListValues` hält je Ident die
Zuordnung Schlüssel → Wert (Eingänge und Apps nach `uri`, Tasten nach `name`) und wird nie kleiner. Neue
Einträge bekommen den nächsten freien Wert, entfallene behalten ihren für den Fall, dass sie wiederkommen.
Die Erstbelegung übernimmt die Position, die bis build 31 der Wert war. `getListEntries()` liefert die
Einträge mit ihrem Wert als Schlüssel; `RequestAction` schaltet darüber, nicht über den angezeigten Text.
Die Variablen werden erst nach bestätigtem Befehl geschrieben.

Die Profile `STV.*` früherer Versionen räumt `removeUnusedLegacyProfiles()` in `ApplyChanges` ab, sobald
keine Variable (Profil, eigenes Profil, Darstellung „Legacy Profil") und kein Diagramm sie nutzt.
`IPS_GetMediaContent()` liefert für manche Diagramme `false` samt Warnung (am nuc 21 von 58); **ein
unlesbares Diagramm gilt als „in Benutzung"**, dann wird nichts gelöscht.
`tests/check_presentations.php` prüft die Darstellungsparameter gegen die Liste aus Symcon 9.1.

## Offene Punkte

- **Mitschnitte fehlen** für App im Vordergrund, TV-Tuner und Bildschirmspiegelung. Bis dahin ist ungeprüft,
  was `getPlayingContentInfo` dann meldet.
- Der Kopfhörer-Eintrag von `getVolumeInformation` schreibt ebenfalls `AudioMute` und kann den Wert des
  Lautsprechers überschreiben. Ohne Mitschnitt mit angeschlossenem Kopfhörer nicht prüfbar, deshalb unverändert.
- Ein falscher PSK hat keinen eigenen Instanzstatus; er zeigt sich nur an leeren Listen und im Log.
- Das Modul sendet Tasten an `/sony/IRCC`, was der KD-75XE9405 annimmt. Seine Gerätebeschreibung (`dd.xml`)
  nennt als `controlURL` aber `/sony/ircc` (klein), und manche Modelle sollen nur das annehmen. Ob die
  Kleinschreibung überall geht, ist ungeprüft.
- `tests/check-readme.php` (Kopie der Skill-Vorlage) legt beide README-Sprachen zusammen und merkt nicht,
  wenn eine Funktion nur in einer Sprache steht.
