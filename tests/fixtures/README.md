# Fixtures

Echte Mitschnitte des KD-75XE9405 (Firmware-Generation 5.4.0), anonymisiert (Seriennummer, MAC, CID).
Je Datei die unveränderte Antwort des TV auf `<service>_<methode>`.

| Ordner | Zustand | aufgenommen |
|---|---|---|
| `standby` | TV im Standby | 29.09.2026 |
| `aktiv` | TV eingeschaltet, HDMI 3 läuft | 29.09.2026 |
| `falscher-psk` | Anfrage mit falschem Pre-Shared Key: HTTP 403, für `setPowerStatus` und `getPlayingContentInfo` wortgleich. `getPowerStatus` antwortet auch mit falschem Schlüssel (HTTP 200). `/sony/IRCC` antwortet mit HTTP 403 und leerem Inhalt. | 29.09.2026 |
| `illegal-state` | TV eingeschaltet, `getPlayingContentInfo` meldet Fehler 7. Aufgezeichnet um 19:45:07, kurz nach dem Einschalten; welcher Inhalt dabei lief, ist nicht festgehalten. | 29.09.2026 |
| `tuner` | TV-Tuner, Sender „RTL HD" (`tv:dvbt`). Dazu die Antwort auf `setPlayContent`, mit dem umgeschaltet wurde. | 29.09.2026 |
| `spiegelung` | Bildschirmspiegelung (`extInput:widi?port=1`), ohne verbundenes Gerät. Dazu die Antwort auf `setPlayContent`. | 29.09.2026 |
| `fehler-404` | Antwort `{"error":[404,"Not Found"]}` auf `getPowerStatus`, am nuc um 18:18:30. Nicht direkt mitgeschnitten: Das Modul hat die Antwort unverändert ins Log geschrieben, die Datei ist aus dieser Logzeile übernommen. In welchem Zustand der TV war, ist nicht bekannt. | 01.10.2026 |
| `app-im-vordergrund` | Netflix im Vordergrund, über `STV_StartApplication` gestartet: `getPlayingContentInfo` meldet dauerhaft Fehler 7. Dieselbe Antwort kam vorher, als Netflix mit der Fernbedienung gestartet war. | 29.09.2026 |

Im selben Mitschnitt wie `illegal-state`: ein einzelner Aussetzer bei eingeschaltetem TV (curl-Fehler 28 um
19:45:01, drei Sekunden später wieder eine reguläre Antwort).

In `tuner`, `spiegelung` und `app-im-vordergrund` stand die Antwort 4, 8 und 12 Sekunden nach dem Umschalten
jeweils wortgleich da. Die übrigen Abfragen (`getPowerStatus`, `getVolumeInformation`,
`getCurrentExternalInputsStatus`) antworteten in allen drei Zuständen gleich und liegen deshalb nicht noch
einmal bei. Gegenüber `aktiv` weichen nur die Lautstärke (63 statt 40) und das Feld `connection` von HDMI 3 ab.

**Nicht mitgeschnitten:** die Antwort auf `setActiveApp` (das Modul hat die App gestartet und Erfolg gemeldet)
und ein Zustand mit angeschlossenem Kopfhörer.
