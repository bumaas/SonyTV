# Sony TV

[![Checks](https://github.com/bumaas/SonyTV/actions/workflows/check.yml/badge.svg)](https://github.com/bumaas/SonyTV/actions/workflows/check.yml)

Steuert Sony-Bravia-Fernseher über das Netzwerk: ein- und ausschalten, Lautstärke und Stummschaltung, Eingang wählen, Apps starten und jede Taste der Fernbedienung senden. Der Zustand des Fernsehers wird regelmäßig abgefragt und in Statusvariablen abgelegt.

[English version](README.en.md)

### Inhalt

1. [Wann brauche ich das?](#1-wann-brauche-ich-das)
2. [Installation und erster Lauf](#2-installation-und-erster-lauf)
3. [Statusvariablen](#3-statusvariablen)
4. [Automatisieren](#4-automatisieren)
5. [Funktionen für Skripte](#5-funktionen-für-skripte)
6. [Grenzen](#6-grenzen)
7. [Begriffe](#7-begriffe)
8. [Anhang: Technik](#8-anhang-technik)

## 1. Wann brauche ich das?

- **Der Fernseher soll in Szenen mitspielen.** „Fernsehabend“ schaltet den TV ein, wählt den Eingang des Receivers und stellt die Lautstärke ein; beim Verlassen des Hauses geht er aus.
- **Der Zustand des Fernsehers soll andere Dinge steuern.** Läuft der TV, wird das Licht gedimmt oder die Beschattung gefahren – dafür gibt es die Statusvariable *Status*.
- **Die Fernbedienung soll in der Visualisierung liegen.** Eingänge, Apps und alle Tasten, die der Fernseher kennt, stehen als Auswahllisten zur Verfügung.

## 2. Installation und erster Lauf

**Voraussetzungen**

- Symcon ab Version 8.1
- Ein Sony-Bravia-Fernseher im selben Netzwerk wie Symcon, am besten mit fester IP-Adresse.

**Am Fernseher einstellen** (Menünamen nach Sony, je nach Gerätegeneration abweichend):

1. Unter *Network & Internet → Local network → IP control* die Authentifizierung *Pre-Shared Key* (oder *Normal and Pre-Shared Key*) wählen und einen Schlüssel festlegen, z. B. `0000`.
2. *Remote start* einschalten. Ohne diese Einstellung lässt sich der Fernseher aus dem Standby nicht über das Netzwerk einschalten.

Sonys eigene Beschreibung: [Remote Display Control](https://pro-bravia.sony.net/remote-display-control/).

**In Symcon**

1. Das Modul im Module Store unter *Sony TV* installieren.
2. Instanz anlegen: entweder direkt eine Instanz *Sony TV* oder die Instanz *Sony Discovery*. Die Discovery sucht die Fernseher im Netzwerk und legt die passenden *Sony TV*-Instanzen per Klick an; sie braucht dafür die SSDP-Instanz von Symcon. Sie trägt nur die IP-Adresse ein, den Pre-Shared Key danach in der Instanz *Sony TV* nachtragen.
3. In der Instanz *Sony TV* eintragen:

| Feld | Standard | Bedeutung |
| :--- | :------- | :-------- |
| `Host` (IP address of Sony TV) | | IP-Adresse des Fernsehers |
| `PSK` (Pre-Shared Key) | `0000` | derselbe Schlüssel wie am Fernseher |
| `UpdateInterval` (Update Interval) | 10 | Abfrageintervall in Sekunden, 0 = keine automatische Abfrage |

Unter *Experten Einstellungen* stehen drei Schalter für die Protokollierung:

| Feld | Bedeutung |
| :--- | :-------- |
| `WriteLogInformationToIPSLogger` | Informationen gehen in das Logfile der IPSLibrary statt in das Symcon-Log, Fehlermeldungen in beide |
| `WriteDebugInformationToLogfile` | Debug-Informationen werden zusätzlich in das Symcon-Log geschrieben |
| `WriteDebugInformationToIPSLogger` | Debug-Informationen werden zusätzlich in das Logfile der IPSLibrary geschrieben |

4. Übernehmen. Ist der Fernseher eingeschaltet oder im Standby, liest die Instanz jetzt die Liste der Eingänge, Apps und Fernbedienungstasten ein. War er ausgeschaltet, holt die Instanz das nach, sobald er erreichbar ist. Die Knöpfe *Tastenliste aktualisieren*, *Liste der Eingangsquellen aktualisieren* und *Applikationsliste aktualisieren* lesen die Listen jederzeit neu ein.

**Meldungen der Instanz**

| Status | Bedeutung | Was tun? |
| :----- | :-------- | :------- |
| aktiv | Fernseher antwortet (eingeschaltet oder Standby) | – |
| inaktiv | Zustand noch unbekannt, etwa beim Übernehmen während der Fernseher startet | – |
| Fernseher antwortet nicht | kein Ping, oder `getPowerStatus` bleibt zweimal in Folge aus; die Variable *Status* steht auf *Ausgeschaltet* | ist er vom Netz getrennt? Ist *Remote start* eingeschaltet? Stimmt die IP-Adresse? |
| Der Fernseher hat den Pre-Shared Key abgelehnt | der Fernseher antwortet mit Fehler 403 | denselben Schlüssel wie am Fernseher eintragen |
| IP-Adresse darf nicht leer sein | `Host` fehlt | IP-Adresse eintragen |
| IP-Adresse ist nicht gültig | `Host` ist keine IP-Adresse | IP-Adresse statt Hostnamen eintragen |
| Das Aktualisierungsintervall darf nicht negativ sein | `UpdateInterval` ist kleiner als 0 | 0 (keine Aktualisierung) oder eine Zahl von Sekunden eintragen |

Beide Fehler stehen beim Wechsel einmal im Log von Symcon, ebenso ihre Behebung (`TV … answers again.`, `Pre-Shared Key accepted …`). Der Status „Pre-Shared Key abgelehnt" bleibt stehen, bis ein Befehl mit Schlüssel gelingt – die Abfrage des Ein/Aus-Zustands beantwortet der Fernseher auch ohne gültigen Schlüssel und sagt darüber nichts.

Bei den drei Konfigurationsfehlern (IP-Adresse, Intervall) läuft keine Aktualisierung, und Schaltbefehle werden abgewiesen. Im Log von Symcon steht der Fehler zusätzlich mit dem eingetragenen Wert, z. B. `Configuration error: update interval -5 is not valid (allowed: 0 or more seconds).`

## 3. Statusvariablen

| Name | Ident | Darstellung | Bedeutung |
| :--- | :---- | :---------- | :-------- |
| Status | `PowerStatus` | Aufzählung | Ausgeschaltet, Standby oder Eingeschaltet. Auswahl *Eingeschaltet* schaltet ein, alles andere aus. |
| Mute | `AudioMute` | Schalter | Stummschaltung |
| Lautstärke Lautsprecher | `SpeakerVolume` | Schieberegler 0–100 % | Lautstärke der Lautsprecher |
| Lautstärke Kopfhörer | `HeadphoneVolume` | Schieberegler 0–100 % | Lautstärke des Kopfhörerausgangs |
| Sende FB Taste | `SendRemoteKey` | Aufzählung | Auswahl sendet die Taste an den Fernseher |
| Eingangsquelle | `InputSource` | Aufzählung | Laufender Eingang; Auswahl schaltet um |
| Starte Applikation | `Application` | Aufzählung | Auswahl startet die App |

Die Auswahllisten für Tasten, Eingänge und Apps kommen vom Fernseher selbst und stehen direkt an der jeweiligen Variable – bei mehreren Fernsehern also je Gerät getrennt und ohne Begrenzung der Anzahl. Jeder Eintrag hat eine feste Nummer als Wert. Sie bleibt ihm erhalten, auch wenn später Apps hinzukommen oder entfallen; -1 steht für „keine Auswahl“.

Die Variablen zeigen einen Eingang, eine App oder eine Taste erst, wenn der Fernseher den Befehl angenommen hat. Läuft keiner der Eingänge aus der Liste, steht *Eingangsquelle* auf „-“. *Ausgeschaltet* heißt: Der Fernseher antwortet nicht im Netzwerk.

**Hinweis für Nutzer älterer Versionen:** Bis 2.1 build 27 nutzte das Modul die Variablenprofile `STV.PowerStatus`, `STV.Volume`, `STV.RemoteKey`, `STV.Sources` und `STV.Applications`. Sie werden automatisch gelöscht, sobald keine Variable und kein Diagramm sie mehr verwendet. Hat eine Variable eines dieser Profile als eigenes Profil eingetragen, bleibt es dort stehen und wird nicht mehr aktualisiert – dann in den Variableneinstellungen das eigene Profil entfernen, damit die Darstellung des Moduls greift. Solange Symcon ein Diagramm nicht lesen kann, bleiben die Profile vorsichtshalber erhalten.

## 4. Automatisieren

Schalten lässt sich alles über die Statusvariablen, wie bei jedem anderen Gerät: in Ablaufplänen, Szenen oder per `RequestAction`. Ein Skript für einen Fernsehabend, das nur die Instanz-ID kennt:

```php
$tv = 12345; // ID der Instanz Sony TV

RequestAction(IPS_GetObjectIDByIdent('PowerStatus', $tv), 2); // einschalten
IPS_Sleep(5000);                                              // der Fernseher braucht einen Moment
STV_SetInputSource($tv, 'HDMI 2');                            // Name wie in der Auswahlliste
RequestAction(IPS_GetObjectIDByIdent('SpeakerVolume', $tv), 20);
```

Auf das Einschalten reagieren, z. B. mit einem ausgelösten Ereignis auf die Variable *Status* (Ident `PowerStatus`) mit der Bedingung „Wert = 2“.

Die Statusvariablen sind schreibgeschützt: `SetValue` aus einem Skript ändert sie nicht. Geschaltet wird mit `RequestAction` oder mit den Funktionen aus dem nächsten Abschnitt.

`RequestAction` meldet jeden Fehlschlag als Warnung und liefert dann `false`: einen ungültigen Wert samt der erlaubten Werte (`Invalid value "150" for "SpeakerVolume" (allowed: 0 to 100)`), eine noch nicht eingelesene Auswahlliste und einen Befehl, den der Fernseher abgelehnt oder nicht erhalten hat (`Action "Application" with value 12 failed …`). Der Wert `-1` (`-`) der Auswahllisten ist erlaubt und tut nichts.

## 5. Funktionen für Skripte

**Schalten**

```php
STV_SetPowerStatus(int $InstanzID, bool $Status): bool;    // true = ein, false = aus
STV_SetAudioMute(int $InstanzID, bool $Status): bool;      // true = stumm
STV_SetSpeakerVolume(int $InstanzID, int $Volume): bool;   // 0..100, andere Werte: Warnung
STV_SetHeadphoneVolume(int $InstanzID, int $Volume): bool; // 0..100, andere Werte: Warnung
STV_SetInputSource(int $InstanzID, string $Quelle): bool;  // z. B. 'HDMI 2'
STV_StartApplication(int $InstanzID, string $App): bool;   // z. B. 'YouTube'
STV_SendRemoteKey(int $InstanzID, string $Taste): bool;    // z. B. 'Home', 'VolumeUp'
```

Alle liefern `true`, wenn der Fernseher den Befehl bestätigt hat. `STV_SetPowerStatus` liefert auch dann `true`, wenn der Fernseher danach im gewünschten Zustand ist, ohne geantwortet zu haben – beim Einschalten kommt das vor.

Die Namen von Eingängen, Apps und Tasten sind je Gerät verschieden; gültig ist, was in den Auswahllisten der Statusvariablen steht. `STV_SetHeadphoneVolume` unterstützt nicht jedes Modell (der KD-65X8505B antwortet mit `40800 - target not supported`).

**Abfragen**

```php
STV_UpdateAll(int $InstanzID): bool;              // alle Statusvariablen sofort aktualisieren; false, wenn der Zustand nicht zu ermitteln war
STV_ReadApplicationList(int $InstanzID): string;  // installierte Apps als JSON-Liste (title, uri, icon)
```

**Listen neu einlesen** – etwa nach der Installation einer neuen App; dasselbe tun die Knöpfe im Formular:

```php
IPS_RequestAction($InstanzID, 'UpdateApplicationList', 0);
IPS_RequestAction($InstanzID, 'UpdateRemoteKeyList', 0);
IPS_RequestAction($InstanzID, 'GetSourceListInfo', 0);
```

**Für Tüftler und den Support**

```php
STV_WriteAPIInformationToFile(int $InstanzID, string $Dateiname): bool;
STV_SendRestAPIRequest(int $InstanzID, string $Service, string $Methode, string $Parameter, string $Version): string;
```

`STV_WriteAPIInformationToFile` schreibt alle Funktionen, die der Fernseher kennt, in eine Datei – mit leerem Dateinamen (`''`) als *Sony \<Modell\>.txt* ins Log-Verzeichnis von Symcon. Der Parameter muss immer angegeben werden. Diese Datei hilft bei Supportanfragen.

`STV_SendRestAPIRequest` sendet eine beliebige Anfrage an den Fernseher, um Funktionen auszuprobieren, die das Modul nicht selbst anbietet. `$Parameter` ist die Parameterliste als JSON, also immer in eckigen Klammern; zurück kommt die Antwort des Fernsehers als JSON, bei einem Fehler ein Leerstring:

```php
$Antwort = STV_SendRestAPIRequest(12345, 'avContent', 'setPlayContent', '[{"uri":"extInput:hdmi?port=2"}]', '1.0');
```

## 6. Grenzen

- **Sony dokumentiert die Schnittstelle nicht für Heimgeräte.** Das Modul nutzt die Schnittstelle der professionellen Bravia-Displays, die auch die Heimgeräte sprechen. Getestet wurde es mit KD-65XG8588, KD-75XE9405, KD-65X8505B, KD-55XE8505, KD-55XE9005, KD-55XE8096, KD-43XD8305, KD-55A1BAEP und KDL-50W805B. Andere Modelle funktionieren meist ebenfalls; Rückmeldungen dazu gern im Forum.
- **Ganz ausgeschaltet ist nicht Standby.** Ist der Fernseher vom Strom getrennt oder hat er das Netzwerk im Standby abgeschaltet, kann das Modul ihn nicht einschalten und meldet *Ausgeschaltet*.
- **Nur die physischen Eingänge.** *Eingangsquelle* kennt HDMI-, AV- und Component-Eingänge. Über HDMI-CEC angemeldete Geräte, der eingebaute Tuner und die Bildschirmspiegelung stehen nicht in der Liste; läuft etwas anderes als einer dieser Eingänge, zeigt die Variable „-“.
- **Apps kennt das Modul nur, wenn es sie selbst gestartet hat.** Der Fernseher verrät nicht, welche App gerade läuft. *Starte Applikation* zeigt deshalb die zuletzt über Symcon gestartete App und geht auf „-“, sobald wieder ein Eingang oder ein Sender läuft. Eine mit der Fernbedienung gestartete App erscheint nicht; *Eingangsquelle* steht dann auf „-“.
- **Nach dem Einschalten dauert es.** Während der Fernseher startet, meldet er sich bereits als eingeschaltet, obwohl er noch nicht bedienbar ist. Das Modul wartet deshalb bis zu 90 Sekunden, bevor es *Eingeschaltet* setzt.
- **Aussetzer werden abgefangen.** Antwortet ein eingeschalteter Fernseher nicht mehr, prüft das Modul die Verbindung dreimal mit je einer Sekunde, bevor es *Ausgeschaltet* meldet. Ein einzelner Aussetzer der Schnittstelle ändert den Status nicht, erst der zweite in Folge.
- **Ein falscher Pre-Shared Key fällt erst beim ersten Befehl mit Schlüssel auf.** Seinen Ein/Aus-Zustand meldet der Fernseher auch ohne gültigen Schlüssel. Im Standby fragt das Modul nur diesen ab; dann zeigt sich der Fehler erst beim Einlesen der Listen oder beim ersten Schaltbefehl.

## 7. Begriffe

| Begriff | Bedeutung |
| :------ | :-------- |
| Pre-Shared Key (PSK) | Kennwort, das am Fernseher und in der Instanz gleich eingetragen sein muss |
| Standby | Fernseher aus, aber im Netzwerk erreichbar; kann per Symcon eingeschaltet werden |
| IRCC | Das Verfahren, mit dem das Modul Fernbedienungstasten über das Netzwerk sendet |

## 8. Anhang: Technik

- Der Fernseher wird per JSON-RPC über `http://<Host>/sony/<Service>` angesprochen, authentifiziert über den Header `X-Auth-PSK`. Fernbedienungstasten gehen als SOAP-Aufruf an `/sony/IRCC`.
- Die Werte der Auswahlvariablen sind feste Nummern je Eintrag (Eingänge und Apps nach URI, Tasten nach Namen). Bei der ersten Einrichtung entsprechen sie der Position in der Liste, `-1` steht für „keine Auswahl“.
- Die Discovery sucht per SSDP nach `urn:schemas-sony-com:service:ScalarWebAPI:1`.

**GUIDs**

| Modul | GUID |
| :---- | :--- |
| Sony TV | `{3B91F3E3-FB8F-4E3C-A4BB-4E5C92BBCD58}` |
| Sony Discovery | `{D48DDD65-5EBD-82DD-32C6-28F47531DE75}` |
