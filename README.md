# SonyTV

Modul für IP-Symcon ab Version 5.0. Ermöglicht die Kommunikation mit einem Sony TV.

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)  
2. [Voraussetzungen](#2-voraussetzungen)  
3. [Installation](#3-installation)  
4. [Funktionsreferenz](#4-funktionsreferenz)
5. [Konfiguration](#5-konfiguartion)  
6. [Anhang](#6-anhang)  

## 1. Funktionsumfang

Mit dem Modul lassen sich Befehle an einen Sony TV absenden und die Statusrückmeldung in IP-Symcon empfangen.

Es werden zur Zeit Funktionen zum Ein-/Ausschalten und zum Senden der Fernbedienungsfunktionen unterstützt.


### Befehle an den Sony TV senden:  

 - Alle dokumentierten Befehle können an den Sony TV gesendet werden  

### Status Rückmeldung:  

Der Status des Gerätes wird im eingestellten Intervall gelesen und in den Statusvariablen abgelegt.

### unterstützte Modelle
Leider gibt es keine Dokumentation von Sony zu den angebotenen Schnittstellen der Geräte. Getestet wurde das Modul bislang mit folgenden Modellen:
- KD-75XE9405
- KD-65X8505B

Ob und wieweit es auch mit anderen Geräten funktioniert, muss ausprobert werden. Würde mich über Feedback freuen.


## 2. Voraussetzungen

 - IPS 5.0
 - Sony TV mit Netzwerkanschluss. Fernsteuerung des Sony TV muss aktiviert sein (siehe Dokumentation des TV). IP-Symcon muss im gleichen Netzwerk wie der TV sein.

## 3. Installation

### a. Laden des Moduls

   Wir wechseln zu IP-Symcon und fügen unter Kerninstanzen über _*Modules*_ -> Hinzufügen das Modul hinzu mit der URL
	
    `https://github.com/bumaas/SonyTV`  

### b. Einrichtung in IPS

In IP-Symcon ist für jedes TV Gerät das genutzt werden soll eine separate Instanz anzulegen.

Über _**Sony TV**_ kann die Instanz gefunden werden.


## 4. Funktionsreferenz



## 5. Konfiguration:
### a. Eigenschaften

| Eigenschaft | Typ     | Standardwert | Funktion                                                              |
| :---------: | :-----: | :----------: | :-------------------------------------------------------------------: |
| Host        | string  |              | IP Adresse des Sony TV                  |
| Bezeichnung | string  |  Symcon(\<ServerName\>)            | Die Bezeichnung unter der die App am TV angezeigt werden soll                            |
| Interval    | int     |  10            | Wenn die Statusvariablen zyklisch aktualisiert werden sollen, dann ist hier das Intervall in Sekunden anzugeben|

### b. Testfunktionen

Anmeldung starten

Anmeldecode senden

Alle Daten aktualisieren

## 6. Anhang

###  a. Funktionen:

#### SonyTV Modul:

```php
STV_GetPowerStatus(int $InstanceID)
```
Return: 0 - Ausgeschaltet, 1 - Standby, 2 - Eingeschaltet

```php
STV_SetPowerStatus(int $InstanceID, bool $Status)
```
Einschalten/Ausschalten des TV

Parameter $Status: false (Off) / true (On)

```php
STV_SendRemoteKey(int $InstanceID, string $Value)
```
Sendet einen Key der Fernbedienung

Parameter $Value: Name des Keys

Die Keys sind je Gerät unterschiedlich und werden automatisch bei der Anmeldung ausgelesen.

Die unterstützen Keys können dann dem Profil _*STV.RemoteKeys*_ entnommen werden.
```php
STV_StartRegistration(int $InstanceID)
```
Startet die Anmeldung/Registrierung des Moduls am Fernseher. Nach dem Start wird am Fernseher ein Code angezeigt.

```php
STV_SendAuthorizationKey(int $InstanceID, string $TVCode)
```
Parameter $TVCode der bei der Anmeldung angezeigte Code

Sendet den Code zum Abschluss der Registrierung an den Sony TV.

```php
STV_UpdateAll(int $InstanceID)
```
Alles Statusvariablen werden aktualisiert. 


###  b. GUIDs und Datenaustausch:

#### SonyTV:

GUID: `{3B91F3E3-FB8F-4E3C-A4BB-4E5C92BBCD58}`




