# SonyTV

Dieses Modul ermöglicht die Kommunikation mit einem Sony TV.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)  
2. [Voraussetzungen](#2-voraussetzungen)  
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)  
6. [WebFront](#6-webfront)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz) 
8. [Anhang](#8-anhang)  

### 1. Funktionsumfang

Mit dem Modul lassen sich Befehle an einen Sony TV absenden und die Statusrückmeldung in IP-Symcon empfangen.

Es werden zur Zeit Funktionen zum Ein-/Ausschalten, zur Lautstärkeregelung, zum Senden der Fernbedienungsfunktionen und zum Starten der Apps unterstützt.


#### Status Rückmeldungen:  

Der Status des Gerätes wird im eingestellten Intervall gelesen und in den Statusvariablen abgelegt.


### 2. Voraussetzungen

 - IPS 5.3
 - Sony TV mit Netzwerkanschluss. Fernsteuerung des Sony TV muss aktiviert sein (siehe Dokumentation des TV und https://pro-bravia.sony.net/develop/integrate/ip-control/). IP-Symcon muss im gleichen Netzwerk wie der TV sein.

#### Unterstützte Modelle:

Leider gibt es keine Dokumentation von Sony zu den angebotenen Schnittstellen der Geräte. Getestet wurde das Modul bislang mit folgenden Modellen:
- KD-65XG8588 
- KD-75XE9405 (Firmware V6.5629)
- KD-65X8505B (Firmware v3.0)
- KD-55XE8505
- KD-55XE9005
- KD-55XE8096
- KD-43XD8305
- KD-55A1BAEP
- KDL-50W805B (Firmware v3.0)

Ob und wieweit es auch mit anderen Geräten funktioniert, muss ausprobert werden. Würde mich über Feedback freuen.


### 3. Software-Installation

Das Modul wird über den Modul Store installiert.  

### 4. Einrichten der Instanzen in IP-Symcon

In IP-Symcon ist für jedes TV Gerät das genutzt werden soll eine separate Instanz anzulegen.

Über _**Sony TV**_ kann die Instanz gefunden werden.



#### Konfiguration der Instanz:
##### Eigenschaften

| Eigenschaft | Typ     | Standardwert | Funktion                                                              |
| :---------: | :-----: | :----------: | :-------------------------------------------------------------------: |
| Host        | string  |              | IP Adresse des Sony TV                  |
| PSK | string  |  0000            | Der Pre-Shared Key, der im Sony TV eingestellt ist                            |
| UpdateInterval    | int     |  10            | Wenn die Statusvariablen zyklisch aktualisiert werden sollen, dann ist hier das Intervall in Sekunden anzugeben|

#### Testfunktionen

Alle Daten aktualisieren

### 5. Statusvariablen und Profile
### 6. WebFront
### 7. PHP-Befehlsreferenz

Das Modul stellt folgende PHP-Befehle zur Verfügung.

```php
STV_SetPowerStatus(int $InstanceID, bool $Status)
```
Einschalten/Ausschalten des TV

Parameter $Status: false (Off) / true (On)

```php
STV_SetAudioMute(int $InstanceID, bool $Status)
```
TV Gerät auf lautlos setzen

Parameter $Status: false (Off) / true (On)

```php
STV_SetSpeakerVolume(int $InstanceID, int $Volume)
```
Setzt die Laustärke der Lautsprecher

Parameter $Volume: Lautstärke von 0 .. 100

```php
STV_SetHeadphoneVolume(int $InstanceID, int $Volume)
```
Setzt die Laustärke des Kopfhörerausgangs

Parameter $Volume: Lautstärke von 0 .. 100

Anmerkung: wird vom KD-65X8505B nicht unterstützt ('40800 - target not supported')

```php
STV_SendRemoteKey(int $InstanceID, string $Value)
```
Sendet einen Key der Fernbedienung

Parameter $Value: Name des Keys

Die Keys sind je Gerät unterschiedlich und werden automatisch bei der Anmeldung ausgelesen.

Die unterstützen Keys können dann dem Profil _*STV.RemoteKeys*_ entnommen werden.

```php
STV_SetInputSource(int $InstanceID, string $source)
```
Auf eine Eingabe Quelle schalten.

Die Keys sind je Gerät unterschiedlich und werden automatisch bei der Anmeldung ausgelesen.

Die möglichen Eingabequellen können dem Profil _*STV.Sources*_ entnommen werden.

```php
STV_StartApplication(int $InstanceID, string $application)
```
Eine Applikation starten.

Die Applikationen sind je Gerät unterschiedlich und werden automatisch bei der Anmeldung ausgelesen.

Die möglichen Applikationen können dem Profil _*STV.Applications*_ entnommen werden.

```php
STV_UpdateAll(int $InstanceID)
```
Alle Statusvariablen werden aktualisiert. 

```php
STV_UpdateApplicationList(int $InstanceID)
```
Die auf dem TV installierten Applikationen werden neu eingelesen und das Profil der Statusvariablen Application aktualisiert. Da die Anzahl der Assoziationen eines Profils auf 128 begrenzt sind, kann es hier zu einem Hinweis
kommen, dass nicht alle Applikationen in die Liste aufgenommen wurden.

Bei Bedarf - um z.B. eine eigene Auswahlliste zu erstellen - kann die vollständige Liste dem Property ApplicationList entnommen werden. Beispiel:

```php
STV_ReadApplicationList(int $InstanceID):string
```
Die Funktion liefert eine json kodierte Liste der auf dem TV installierten Applikationen.


```php
STV_WriteAPIInformationToFile(int $InstanceID, $filename)
```
Die API Informationen werden zu Supportzwecken in die angegebene Datei geschrieben. Wird kein Dateiname angegeben('')), so werden die Informationen in die Datei _*Sony \<Modellname\>.txt*_ im Log-Verzeichnis von IP-Symcon geschrieben. 

### 8. Anhang

#### GUIDs:

Sony TV: `{3B91F3E3-FB8F-4E3C-A4BB-4E5C92BBCD58}`




