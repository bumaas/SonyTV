# Sony TV

[![Checks](https://github.com/bumaas/SonyTV/actions/workflows/check.yml/badge.svg)](https://github.com/bumaas/SonyTV/actions/workflows/check.yml)

Controls Sony Bravia TVs over the network: power on and off, volume and mute, input selection, starting apps and sending any key of the remote control. The state of the TV is polled regularly and stored in status variables.

[Deutsche Version](README.md)

### Contents

1. [When do I need this?](#1-when-do-i-need-this)
2. [Installation and first run](#2-installation-and-first-run)
3. [Status variables](#3-status-variables)
4. [Automation](#4-automation)
5. [Functions for scripts](#5-functions-for-scripts)
6. [Limitations](#6-limitations)
7. [Terms](#7-terms)
8. [Appendix: technical details](#8-appendix-technical-details)

## 1. When do I need this?

- **The TV should take part in scenes.** "Movie night" switches the TV on, selects the receiver's input and sets the volume; leaving the house switches it off.
- **The TV's state should control other things.** While the TV is on, the lights are dimmed or the blinds are closed – that is what the status variable *Status* is for.
- **The remote control should be available in the visualization.** Inputs, apps and all keys the TV knows are offered as selection lists.

## 2. Installation and first run

**Requirements**

- Symcon 8.1 or later
- A Sony Bravia TV in the same network as Symcon, preferably with a fixed IP address.

**Settings on the TV** (menu names as used by Sony, they may differ between TV generations):

1. Under *Network & Internet → Local network → IP control*, choose the authentication *Pre-Shared Key* (or *Normal and Pre-Shared Key*) and set a key, e.g. `0000`.
2. Switch on *Remote start*. Without it, the TV cannot be switched on from standby over the network.

Sony's own description: [Remote Display Control](https://pro-bravia.sony.net/remote-display-control/).

**In Symcon**

1. Install the module from the Module Store under *Sony TV*.
2. Create an instance: either a *Sony TV* instance directly or a *Sony Discovery* instance. The discovery searches the network for TVs and creates the matching *Sony TV* instances with one click; it needs Symcon's SSDP instance for that. It only fills in the IP address; enter the Pre-Shared Key in the *Sony TV* instance afterwards.
3. In the *Sony TV* instance, enter:

| Field | Default | Meaning |
| :---- | :------ | :------ |
| `Host` (IP address of Sony TV) | | IP address of the TV |
| `PSK` (Pre-Shared Key) | `0000` | the same key as on the TV |
| `UpdateInterval` (Update Interval) | 10 | polling interval in seconds, 0 = no automatic polling |

Under *Expert Parameters* there are three switches for logging:

| Field | Meaning |
| :---- | :------ |
| `WriteLogInformationToIPSLogger` | information and error messages are additionally written to the IPSLibrary log file (up to 2.2 build 41, information then went there only) |
| `WriteDebugInformationToLogfile` | debug information is additionally written to the Symcon log |
| `WriteDebugInformationToIPSLogger` | debug information is additionally written to the IPSLibrary log file |

4. Apply. If the TV is on or in standby, the instance now reads the lists of inputs, apps and remote keys. If it was off, the instance catches up as soon as the TV is reachable. The buttons *Update remote key list*, *Update Input Source list* and *Update application list* read the lists again at any time.

**Instance states**

| State | Meaning | What to do |
| :---- | :------ | :--------- |
| active | the TV answers (on or in standby) | – |
| inactive | state not known yet, e.g. when applying while the TV is starting | – |
| TV does not answer | no ping, or `getPowerStatus` fails twice in a row; the variable *Status* shows *Off* | is it disconnected from mains? Is *Remote start* switched on? Is the IP address correct? |
| The TV rejected the Pre-Shared Key | the TV answers with error 403 | enter the same key as on the TV |
| IP address can not be empty | `Host` is missing | enter the IP address |
| IP address is not valid | `Host` is not an IP address | enter an IP address instead of a host name |
| Update interval must not be negative | `UpdateInterval` is less than 0 | enter 0 (no update) or a number of seconds |

Both errors are written once to the Symcon log when they occur, and so is their recovery (`TV … answers again.`, `Pre-Shared Key accepted …`). The state "Pre-Shared Key rejected" stays until a command that needs the key succeeds – the TV answers the query for its power state even without a valid key and tells nothing about it.

With any of the three configuration errors (IP address, interval) no update runs and switching commands are rejected. The Symcon log additionally shows the error with the value entered, e.g. `Configuration error: update interval -5 is not valid (allowed: 0 or more seconds).`

## 3. Status variables

| Name | Ident | Presentation | Meaning |
| :--- | :---- | :----------- | :------ |
| Status | `PowerStatus` | Enumeration | Off, Standby or On. Selecting *On* switches the TV on; *Off* and *Standby* both put it into standby – the TV cannot be switched fully off over the network. *Off* only appears when the TV does not answer. |
| Mute | `AudioMute` | Switch | mute |
| Speaker Volume | `SpeakerVolume` | Slider 0–100 % | volume of the speakers |
| Headphone Volume | `HeadphoneVolume` | Slider 0–100 % | volume of the headphone output |
| Send Remote Key | `SendRemoteKey` | Enumeration | selecting a key sends it to the TV |
| Input Source | `InputSource` | Enumeration | current input; selecting switches over |
| Start Application | `Application` | Enumeration | selecting starts the app |

The selection lists for keys, inputs and apps come from the TV itself and are stored directly at the respective variable – separately for each TV if there are several, and without a limit on their number. Every entry has a fixed number as its value. It keeps that number even if apps are added or removed later; -1 means "no selection".

The variables show an input, an app or a key only after the TV has accepted the command. If none of the inputs in the list is playing, *Input Source* shows "-". *Off* means: the TV does not answer on the network.

**Note for users of older versions:** Up to 2.1 build 27 the module used the variable profiles `STV.PowerStatus`, `STV.Volume`, `STV.RemoteKey`, `STV.Sources` and `STV.Applications`. They are deleted automatically as soon as no variable and no chart uses them any more. If a variable has one of these profiles set as its custom profile, it stays there and is no longer updated – remove the custom profile in the variable settings so that the module's presentation takes effect. As long as Symcon cannot read one of the charts, the profiles are kept as a precaution.

## 4. Automation

Everything can be switched via the status variables, like any other device: in flows, scenes or with `RequestAction`. A script for a movie night that only needs the instance ID:

```php
$tv = 12345; // ID of the Sony TV instance

RequestAction(IPS_GetObjectIDByIdent('PowerStatus', $tv), 2); // switch on
IPS_Sleep(5000);                                              // the TV needs a moment
STV_SetInputSource($tv, 'HDMI 2');                            // name as shown in the selection list
RequestAction(IPS_GetObjectIDByIdent('SpeakerVolume', $tv), 20);
```

To react to the TV being switched on, use for example a triggered event on the variable *Status* (ident `PowerStatus`) with the condition "value = 2".

The status variables are read-only: `SetValue` from a script does not change them. Switch with `RequestAction` or with the functions in the next section.

`RequestAction` reports every failure as a warning and then returns `false`: an invalid value together with the allowed values (`Invalid value "150" for "SpeakerVolume" (allowed: 0 to 100)`), a selection list that has not been read yet, and a command the TV rejected or did not receive (`Action "Application" with value 12 failed …`). The value `-1` (`-`) of the selection lists is allowed and does nothing.

## 5. Functions for scripts

**Switching**

```php
STV_SetPowerStatus(int $InstanceID, bool $Status): bool;    // true = on, false = standby
STV_SetAudioMute(int $InstanceID, bool $Status): bool;      // true = muted
STV_SetSpeakerVolume(int $InstanceID, int $Volume): bool;   // 0..100, other values: warning
STV_SetHeadphoneVolume(int $InstanceID, int $Volume): bool; // 0..100, other values: warning
STV_SetInputSource(int $InstanceID, string $Source): bool;  // e.g. 'HDMI 2'
STV_StartApplication(int $InstanceID, string $App): bool;   // e.g. 'YouTube'
STV_SendRemoteKey(int $InstanceID, string $Key): bool;      // e.g. 'Home', 'VolumeUp'
```

All of them return `true` when the TV confirmed the command. `STV_SetPowerStatus` also returns `true` when the TV is in the requested state afterwards without having answered – this happens when switching on.

The names of inputs, apps and keys differ from device to device; valid are the ones shown in the selection lists of the status variables. `STV_SetHeadphoneVolume` is not supported by every model (the KD-65X8505B answers `40800 - target not supported`).

**Reading**

```php
STV_UpdateAll(int $InstanceID): bool;              // update all status variables now; false if the state could not be determined
STV_ReadApplicationList(int $InstanceID): string;  // installed apps as a JSON list (title, uri, icon)
STV_RunSelfTest(int $InstanceID): string;          // self test as text, switches nothing
```

`STV_RunSelfTest` checks configuration, reachability, Pre-Shared Key and the three lists and returns one line per check (✓ fine, ⚠ warning, ✗ error, below it after → what to do) and finally `N errors, M warnings`. The button *Run self test* in the form shows the same.

**Reading the lists again** – for example after installing a new app; the buttons in the form do the same:

```php
IPS_RequestAction($InstanceID, 'UpdateApplicationList', 0);
IPS_RequestAction($InstanceID, 'UpdateRemoteKeyList', 0);
IPS_RequestAction($InstanceID, 'GetSourceListInfo', 0);
```

**For tinkerers and support**

```php
STV_WriteAPIInformationToFile(int $InstanceID, string $Filename): bool;
STV_SendRestAPIRequest(int $InstanceID, string $Service, string $Method, string $Params, string $Version): string;
```

`STV_WriteAPIInformationToFile` writes all functions the TV knows to a file – with an empty file name (`''`) as *Sony \<model\>.txt* in Symcon's log directory. The parameter must always be given. This file helps with support requests.

`STV_SendRestAPIRequest` sends any request to the TV, to try out functions the module does not offer itself. `$Params` is the parameter list as JSON, so always in square brackets; the TV's answer is returned as JSON, an empty string on error:

```php
$Answer = STV_SendRestAPIRequest(12345, 'avContent', 'setPlayContent', '[{"uri":"extInput:hdmi?port=2"}]', '1.0');
```

## 6. Limitations

- **Sony does not document the interface for consumer TVs.** The module uses the interface of the professional Bravia displays, which the consumer TVs speak as well. It has been tested with KD-65XG8588, KD-75XE9405, KD-65X8505B, KD-55XE8505, KD-55XE9005, KD-55XE8096, KD-43XD8305, KD-55A1BAEP and KDL-50W805B. Other models usually work too; feedback is welcome in the forum.
- **Completely off is not standby.** If the TV is disconnected from power or has switched off its network in standby, the module cannot switch it on and reports *Off*.
- **Physical inputs only.** *Input Source* knows HDMI, AV and component inputs. Devices announced via HDMI-CEC, the built-in tuner and screen mirroring are not in the list; if anything other than one of these inputs is playing, the variable shows "-".
- **The module only knows apps it has started itself.** The TV does not tell which app is running. *Start Application* therefore shows the app last started via Symcon and changes to "-" as soon as an input or a channel is playing again. An app started with the remote control does not appear; *Input Source* then shows "-".
- **Switching on takes a while.** While the TV is booting it already reports itself as on although it cannot be operated yet. The module therefore waits up to 90 seconds before setting *On*.
- **Dropouts are absorbed.** If a TV that is on or in standby stops answering, the module checks the connection three times with one second each before reporting *Off*. A single dropout of the interface does not change the status, only the second one in a row does. An error answer such as `404 Not Found` counts as a dropout as well.
- **A wrong pre-shared key only shows with the first command that needs the key.** The TV reports its power state even without a valid key. In standby the module only queries that state; the error then shows when the lists are read or with the first switching command.

## 7. Terms

| Term | Meaning |
| :--- | :------ |
| Pre-Shared Key (PSK) | password that must be the same on the TV and in the instance |
| Standby | TV off, but reachable on the network; can be switched on via Symcon |
| IRCC | the method the module uses to send remote control keys over the network |

## 8. Appendix: technical details

- The TV is addressed via JSON-RPC at `http://<Host>/sony/<Service>`, authenticated with the header `X-Auth-PSK`. Remote keys are sent as a SOAP call to `/sony/IRCC`.
- The values of the selection variables are fixed numbers per entry (inputs and apps by URI, keys by name). On first setup they equal the position in the list, `-1` means "no selection".
- The discovery searches via SSDP for `urn:schemas-sony-com:service:ScalarWebAPI:1`.

**GUIDs**

| Module | GUID |
| :----- | :--- |
| Sony TV | `{3B91F3E3-FB8F-4E3C-A4BB-4E5C92BBCD58}` |
| Sony Discovery | `{D48DDD65-5EBD-82DD-32C6-28F47531DE75}` |
