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
- **`RequestAction` meldet jeden Fehlschlag per `trigger_error(…, E_USER_WARNING)`** (Regel 8 der
  MCP-Tauglichkeit, seit 2.2 build 36): Die Methode ist `void`, ohne Warnung bekäme der Aufrufer `true`.
  `getCommand()` prüft den Wert und liefert den Befehl. Ein ungültiger Wert ergibt `Invalid value "…" for "…"
  (allowed: …)` mit den erlaubten Werten, eine leere Liste die Meldung aus `getListDefinition()` und ein
  Befehl, der `false` liefert, `Action "…" with value … failed`. Die Lautstärke prüfen auch die `STV_`-Funktionen
  (0 bis 100). Bei einem Konfigurationsfehler weist `SendCurlPost()` jede Anfrage mit Warnung ab.
  `tests/check-request-action-errors.php` hält das fest.
- Im Standby antwortet der TV auf `getPowerStatus`, `getRemoteControllerInfo`,
  `getCurrentExternalInputsStatus` und `getApplicationList`; Audio- und Content-Abfragen enden mit
  `40005 Display Is Turned off`.
- **Falscher PSK:** Was Sonys Referenz mit Authentifizierungsstufe „none“ führt, antwortet trotzdem normal (HTTP
  200): `getPowerStatus`, `getVolumeInformation`, `getCurrentExternalInputsStatus`, `getRemoteControllerInfo`,
  `getPictureQualitySettings` (Mitschnitte 29.09. und 24.10.2026, `METHODS_WITHOUT_KEY`). Die Stufen „private“
  und „generic“ enden mit HTTP 403 und `[403, "Forbidden"]` samt `auth_url` (mitgeschnitten für
  `getPlayingContentInfo`, `getApplicationList`, `getSystemInformation`, `setPowerStatus`), `/sony/IRCC` mit HTTP
  403 und leerem Inhalt. Bis 2.3 build 47 galt nur `getPowerStatus` als schlüsselfrei; dann wechselte der Status
  bei falschem Schlüssel in jedem Zyklus zwischen 203 und 102 (`tests/check-psk-without-key.php`).
- **Was `getPlayingContentInfo` meldet** (Mitschnitte 29.09.2026): bei einem HDMI-Eingang dessen `uri`
  (`extInput:hdmi?port=3`), beim Tuner `tv:dvbt?trip=…` mit Sendername, bei Bildschirmspiegelung
  `extInput:widi?port=1`, **bei einer App im Vordergrund dauerhaft `[7, "Illegal State"]`**. Welche App läuft,
  verrät der TV nicht: `Application` zeigt nur, was das Modul selbst gestartet hat, und geht auf -1, sobald
  wieder ein Inhalt läuft.
- In der Bootphase gilt Fehler 7 als „startet noch": `isStillBooting()` prüft 90 s nach dem letzten
  Fehlschlag (kein Ping oder zwei Aussetzer in Folge) zusätzlich `getPlayingContentInfo`. Startet der TV mit
  einer App im Vordergrund, bleibt der Zustand deshalb bis zu 90 s unbekannt. Was der TV während des
  Hochfahrens wirklich meldet, ist nicht mitgeschnitten.
- Ein **einzelner Aussetzer** von `getPowerStatus` (im Mitschnitt belegt: curl-Fehler 28, drei Sekunden später
  wieder eine Antwort) ändert nichts; `UpdateAll` liefert dann `false` für „Zustand unbekannt". Eine
  Fehlerantwort zählt wie keine Antwort: `executeRestApiRequestWithRetry()` wiederholt nach 3 s (am 01.10.2026
  kam `{"error":[404,"Not Found"]}`, Fixture `fehler-404`).

## Bildeinstellungen

Mit `ShowPictureSettings` (Vorgabe aus, seit 2.3) liest das Modul bei eingeschaltetem TV in `ApplyChanges` und
`UpdateAll` `video.getPictureQualitySettings` mit `[{"target":""}]` und legt je Ziel eine Variable an, Ident
`Picture` + Ziel mit großem Anfangsbuchstaben (`PictureSharpness`, `PicturePictureMode`). Eine Liste nicht leerer
`value` ergibt String mit Aufzählung, deren Optionswert der Text des TV ist (`warm`, `sRgb/bt709`); sonst ergibt
ein ganzzahliges `min`/`max`/`step` mit `max` > `min` Integer mit Schieberegler. Laut Referenz sind die drei
Kommazahlen, bei Wertelisten -1, und ein Bereich darf `value: ""` tragen; der KD-75XE9405 lässt sie bei Listen weg.
Nicht ganzzahlige Bereiche überspringt das Modul (Debug „Unexpected picture setting“). Gesetzt wird mit `setPictureQualitySettings`
`[{"settings":[{"target":…,"value":"<String>"}]}]`, Antwort `{"result":[]}`. Ziele und Bereiche stehen ohne Wert im
Attribut `PictureSettings`; die Variablen werden nur neu registriert, wenn sich dort etwas ändert. `isAvailable:
false` heißt keine Aktion (`DisableAction`), ein nicht mehr gemeldetes Ziel behält seine Variable ohne Aktion. Namen
für Ziele und Werte stehen in `PICTURE_TARGET_NAMES`/`PICTURE_VALUE_NAMES` (Stand KD-75XE9405), Unbekanntes erscheint
roh. Ein Wert außerhalb des Bereichs (Schärfe 500) beantwortet der TV mit `[3, "Illegal Argument"]` und übernimmt
nichts (24.10.2026, nur im Log gesehen, nicht als Fixture). Zwei Ziele, die sich nur in Sonderzeichen unterscheiden,
ergäben denselben Ident; das zuerst gemeldete behält ihn, das zweite steht nur im Debug. Geht der Schalter aus, werden
die Variablen entfernt und das Attribut geleert. Der Selbsttest nennt die Zahl der Ziele und wie viele davon schaltbar
sind; nicht mehr gemeldete Ziele zählen mit, aber nicht als schaltbar. `readListAttribute()` liefert im Reload-Fenster
(Attribut noch nicht registriert, der Kernel gibt `false`) eine leere Liste; der Stub kann das nicht nachstellen.
`tests/check-picture-settings.php` hält das fest.

## Protokoll

`Logger_Err`, `Logger_Wrn` und `Logger_Inf` schreiben immer ins Debug und ins Symcon-Log, mit `WriteLogInformationToIPSLogger`
zusätzlich in die IPSLibrary (bis build 41 gingen Infos dann nur dorthin und waren über MCP nicht zu sehen, an
#36393 ist der Schalter an). `tests/check-logging.php` hält das fest.

## Selbsttest

`STV_RunSelfTest()` (Knopf im Formular, für Skripte und KI im unsichtbaren Label daneben beschrieben) prüft
Konfiguration, Ping, `getPowerStatus`, den Schlüssel über `getPlayingContentInfo` (richtig: Ergebnis, 40005 oder
7; falsch: 403 — alles mitgeschnitten) und die drei Listen. Er schaltet nichts und schreibt keine Variable;
nur `noteAuthentication()` kann den Instanzstatus dabei auf 203 setzen, was dann auch stimmt.

Die Ausgabe läuft über `Translate()` (seit build 45, vorher nur englisch), ebenso `getConfigurationErrorText()` und
damit die Log-Zeile zum Konfigurationsfehler. Bezeichner (`PowerStatus`, `IPS_RequestAction(…)`) und die Menünamen
am TV bleiben wörtlich. Der Stub übersetzt nicht; `SonyTVHarness::$sprache = 'de'` lädt `locale.json`, und
`tests/check-self-test.php` verlangt damit für jeden Fall, dass keine Zeile englisch bleibt.

## Knöpfe im Aktionsbereich

Seit 2.3 build 48 (Durchsicht aus Anwendersicht am 24.10.2026): Jeder Knopf hat davor einen Text, der sagt, wann man
ihn braucht, nicht was die Beschriftung schon sagt. Die drei Listen liest ein Knopf `UpdateLists`; die Idents je
Liste (`UpdateRemoteKeyList`, `GetSourceListInfo`, `UpdateApplicationList`) bleiben für Skripte. Die API-Datei steht
im Panel „Support". Die Meldungsfenster nennen Anzahlen, Zustand oder Pfad, bei einem Fehlschlag den Grund aus
`getFailureReason()`: Konfiguration, dann Erreichbarkeit über `checkConnection()` (drei Pings, bei „Aus" einer), erst
danach Buffer `pskRejected`, sonst „keine verwertbare Antwort". Die Reihenfolge folgt dem Instanzstatus (201 vor 203),
weil der Buffer aus der Zeit stammen kann, als der TV noch antwortete (Review build 49). Scheitern mehrere Listen,
wird der Grund einmal ermittelt und einmal genannt („Tasten, Eingänge und Apps nicht eingelesen: …“). Scheitert bei
der API-Datei das Schreiben, nennt die Meldung den Schreibfehler, nicht den TV; Zeichen, die Windows im Dateinamen
nicht erlaubt, ersetzt das Modul im Modellnamen durch `_`. Ein nicht erreichbarer TV ist für „Update status
variables" kein Fehlschlag: `UpdateAll` liefert true und die Meldung „Aus / nicht erreichbar".
`tests/check-form-actions.php` hält das fest.

## Nähte für die Tests

Alles, was im Betrieb ins Netz geht, wartet oder vom Kernel abhängt, läuft über eine überschreibbare
Methode: `executeCurl()` (HTTP, liefert auch den HTTP-Status), `ping()` (`Sys_Ping`), `pause()` (`sleep`),
`now()` (`time`), `kernelRunlevel()`, `chartContents()`; in der Discovery `fetchXml()`.
`tests/harness.php` ersetzt sie. Neuer Netzverkehr oder neue Wartezeiten gehören hinter eine dieser Nähte.

## Kernelstart

Vor `KR_READY` fragt `ApplyChanges` den TV nicht ab und räumt keine Profile auf; `Create` registriert
`IPS_KERNELMESSAGE`, `MessageSink` holt `ApplyChanges` mit `KR_READY` nach. Bei einem Konfigurationsfehler
(202 Host leer, 204 keine IP-Adresse, 205 Intervall negativ) läuft kein Timer, und der Fehler steht mit Wert
im Log (`getConfigurationErrorText()`), weil eine KI über MCP nur den Statuscode sieht.

## Instanzstatus im Betrieb

`refreshInstanceStatus()` setzt den Status aus der Variable `PowerStatus` und dem Buffer `pskRejected` und
schreibt jeden Wechsel einmal ins Log (201 als `Logger_Wrn`, 203 als `Logger_Err`, Behebung als `Logger_Inf`). 201 ist
seit build 50 nur eine Warnung: Am nuc wird der TV nach dem Fernsehabend stromlos geschaltet, und an einen Error hängt
der IPSLogger die ganze Aufrufkette an die Push-Nachricht (Entscheidung Burkhard 24.10.2026; wird es zu viel, wäre
Info der nächste Schritt). Die Stufen:
**201** TV antwortet nicht (`PowerStatus` = Aus; geht vor), **203** Pre-Shared Key abgelehnt, sonst 102.
104 bleibt nur für „Zustand unbekannt" in `ApplyChanges`. `noteAuthentication()` in `SendCurlPost()` wertet
jede Antwort **vor** dem Filter der ignorierten Fehler aus (`getPlayingContentInfo` ignoriert 403): HTTP 403
oder Fehler 403 setzt `pskRejected`, jede andere Antwort ohne HTTP-Fehler löscht ihn, außer auf die Methoden
in `METHODS_WITHOUT_KEY`, die auch mit falschem Schlüssel antworten (siehe „Falscher PSK“). Neue Abfragen vorher
in Sonys Referenz auf die Authentifizierungsstufe prüfen und bei „none“ dort eintragen.

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
mitschneiden, nie von Hand bauen; lesende Methoden genügen meist. Schaltende Aufrufe gegen das echte Gerät
nur nach Burkhards OK (so am 29.09.2026 für Tuner, Spiegelung und App: `setPlayContent`, `setActiveApp`),
`setPowerStatus` und `requestReboot` gar nicht. Wo ein Test eine Liste verändert
(App kommt hinzu, Eingang fehlt), geht er von einem Mitschnitt aus und sagt das im Kommentar.

Das Repo liegt unter `T:\modules` — **das ist das produktive Modulverzeichnis des nuc**, und der
PHP-Worker liest `module.php` bei jedem Aufruf neu. Jede gespeicherte Änderung wirkt sofort auf
die Instanz #36393; halbfertige Zwischenstände erzeugen dort Fehler im Log. Größere Umbauten also
in einem Git-Worktree außerhalb von `T:\modules` machen oder in einem Rutsch schreiben.
Neue Bibliotheksdaten (Funktionsliste, `library.json`) per `MC_ReloadModule` mit Ordnername
`SonyTV` einlesen.

## Darstellungen und Listenwerte

Alle Variablen nutzen Darstellungen (seit 2.1 build 28). Tasten, Eingänge und Apps stehen als Optionen einer
Aufzählung an der Variable (`registerListVariable()`). `PowerStatus` hat die Optionen 0 „Aus / nicht erreichbar"
(der TV antwortet nicht; ganz ausschalten geht über das Netz nicht), 1 Standby, 2 Eingeschaltet.

**Der Wert eines Eintrags ist eine feste Nummer**, keine Position: Das Attribut `ListValues` hält je Ident die
Zuordnung Schlüssel → Wert (Eingänge und Apps nach `uri`, Tasten nach `name`) und wird nie kleiner. Neue
Einträge bekommen den nächsten freien Wert, entfallene behalten ihren für den Fall, dass sie wiederkommen.
Die Erstbelegung übernimmt die Position, die bis build 31 der Wert war. `getListEntries()` liefert die
Einträge mit ihrem Wert als Schlüssel; `RequestAction` schaltet darüber, nicht über den angezeigten Text.
Die Variablen werden erst nach bestätigtem Befehl geschrieben.

Jede Option einer Aufzählung trägt alle Felder der Doku (`Value`, `Caption`, `IconActive`, `IconValue`,
`Color`), gebaut von `enumerationOption()`. Bis build 43 fehlten die drei letzten. Der Bearbeiten-Dialog der
Variable meldete dann „Ungültiges Formular“ samt `Undefined array key "IconActive"` (Forum-PN t/144573,
StephanBERN, KD-75XF8596). Bestehende Variablen bekommen die vollständigen Optionen beim nächsten `ApplyChanges`.

Die Profile `STV.*` früherer Versionen räumt `removeUnusedLegacyProfiles()` in `ApplyChanges` ab, sobald
keine Variable (Profil, eigenes Profil, Darstellung „Legacy Profil") und kein Diagramm sie nutzt.
`IPS_GetMediaContent()` liefert für manche Diagramme `false` samt Warnung (am nuc 21 von 58); **ein
unlesbares Diagramm gilt als „in Benutzung"**, dann wird nichts gelöscht.
`tests/check_presentations.php` prüft die Darstellungsparameter gegen die Liste aus Symcon 9.1.

## Offene Punkte

- **MCP-Test vom 01.10.2026** (Regeln in `~\.claude\skills\symcon-modul-repo\mcp-tauglichkeit.md`): erledigt
  sind Regel 8, die Wertebereiche und Regel 1 (PSK-Hinweis in Formular und Konfigurator, `tests/check-form-help.php`;
  build 36) sowie Regel 3 (Status 201 und 203, `tests/check-status-reachability.php`; build 37) und die Rohmeldung
  zur 404-Antwort (`tests/check-power-status-404.php`; build 38), das Flattern im Standby (Regel 11, drei Pings
  auch im Standby, `tests/check-connection.php`; build 39) und die Regeln 5 bis 7 (`STV_RunSelfTest`, Knopf und
  unsichtbarer Hinweis im Formular, `tests/check-self-test.php`; build 40). „Aus" (0) und „Standby" (1) schalten
  beide in den Standby, ganz aus geht über das Netz nicht; Entscheidung Burkhard: beide erlaubt lassen, Formular
  (unsichtbar), Selbsttest und README sagen es (build 41). Die englischen Variablennamen der Altinstanz #36393
  sind am 01.10.2026 von Hand auf die deutschen gesetzt; das Modul benennt nichts um. Blindtest an build 42
  wiederholt und bestanden (Protokoll im Projekt Eigenes, `nuc\checks\2026-10-01_mcp-evaluierung.md`); dessen
  Wortlaut-Punkte in build 43: Option 0 heißt „Aus / nicht erreichbar" (Entscheidung Burkhard), der PSK-Hinweis
  nennt Status 203, ein unsichtbarer Hinweis die Parameter der `STV_`-Funktionen. Nicht im Modul lösbar:
  `trigger_error` hängt „in …\module.php on line N" an jede Meldung (PHP-Fehlerausgabe, bei HomeAssistant genauso).
- **Mitschnitte fehlen** für das Hochfahren nach „ganz aus", für einen angeschlossenen Kopfhörer und für die
  Antwort auf `setActiveApp`; bei den Bildeinstellungen für die Antwort im Standby, mit einer App im Vordergrund
  und an einem Eingang mit HDR. Das Modul fragt sie nur bei eingeschaltetem TV ab und behandelt 7 und 40005 als
  stille Fehler, beides ohne Mitschnitt.
- Warum der TV am HDMI-Eingang `pictureMode`, `autoPictureMode`, `hdrMode` und `lightSensor` mit `isAvailable:
  false` meldet (`pictureMode` dazu mit leerem Wert), ist ungeklärt. Ob sie trotzdem setzbar wären, ist nicht
  geprüft; das Modul bietet sie dann nicht zum Schalten an.
- Der Kopfhörer-Eintrag von `getVolumeInformation` schreibt ebenfalls `AudioMute` und kann den Wert des
  Lautsprechers überschreiben. Ohne Mitschnitt mit angeschlossenem Kopfhörer nicht prüfbar, deshalb unverändert.
- Das Modul sendet Tasten an `/sony/IRCC`, was der KD-75XE9405 annimmt. Seine Gerätebeschreibung (`dd.xml`)
  nennt als `controlURL` aber `/sony/ircc` (klein), und manche Modelle sollen nur das annehmen. Ob die
  Kleinschreibung überall geht, ist ungeprüft.
- `tests/check-readme.php` (Kopie der Skill-Vorlage) legt beide README-Sprachen zusammen und merkt nicht,
  wenn eine Funktion nur in einer Sprache steht.
