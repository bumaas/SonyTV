# Fixtures

Echte Mitschnitte des KD-75XE9405 (Firmware-Generation 5.4.0), anonymisiert (Seriennummer, MAC, CID).
Je Datei die unveränderte Antwort des TV auf `<service>_<methode>`.

| Ordner | Zustand | aufgenommen |
|---|---|---|
| `standby` | TV im Standby | 29.09.2026 |
| `aktiv` | TV eingeschaltet, HDMI 3 läuft | 29.09.2026 |
| `falscher-psk` | Anfrage mit falschem Pre-Shared Key: HTTP 403, für `setPowerStatus` und `getPlayingContentInfo` wortgleich. `getPowerStatus` antwortet auch mit falschem Schlüssel (HTTP 200). `/sony/IRCC` antwortet mit HTTP 403 und leerem Inhalt. | 29.09.2026 |
| `illegal-state` | TV eingeschaltet, `getPlayingContentInfo` meldet Fehler 7. Aufgezeichnet um 17:45:07, kurz nach dem Einschalten; welcher Inhalt dabei lief, ist nicht festgehalten. | 29.09.2026 |

Im selben Mitschnitt: ein einzelner Aussetzer bei eingeschaltetem TV (curl-Fehler 28 um 17:45:01, drei
Sekunden später wieder eine reguläre Antwort).

**Noch offen:** App im Vordergrund, TV-Tuner, Bildschirmspiegelung. Bis dahin prüft kein Test, was der TV in
diesen Zuständen meldet.
