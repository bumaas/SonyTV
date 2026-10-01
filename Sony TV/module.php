<?php

/** @noinspection AutoloadingIssuesInspection */

/*
    Links, die als Grundlage dienten (erreichbar am 29.09.2026)

Steuerwege im Überblick: https://pro-bravia.sony.net/remote-display-control/

REST-API: https://pro-bravia.sony.net/remote-display-control/rest-api/

Fernbedienungstasten (IRCC-IP): https://pro-bravia.sony.net/remote-display-control/ircc-ip/

https://community.openhab.org/t/sony-devices-binding/14052/263

https://github.com/gerard33/sony-bravia/blob/master/bravia.py (no longer maintained)

https://github.com/antonioparraga/braviarc/blob/master/braviarc/braviarc.py

https://github.com/waynehaffenden/bravia

 */

declare(strict_types=1);

if (function_exists('IPSUtils_Include')) {
    IPSUtils_Include('IPSLogger.inc.php', 'IPSLibrary::app::core::IPSLogger');
}

trait SonyConstants
{
    private const int STATUS_INST_NOT_REACHABLE = 201;
    private const int STATUS_INST_IP_IS_EMPTY   = 202;
    private const int STATUS_INST_PSK_REJECTED  = 203;
    private const int STATUS_INST_IP_IS_INVALID = 204; //IP-Adresse ist ungültig
    private const int STATUS_INST_INTERVAL_IS_INVALID = 205;

    private const int VOLUME_MIN = 0;
    private const int VOLUME_MAX = 100;

    private const string PROP_HOST            = 'Host';
    private const string PROP_PSK             = 'PSK';
    private const string PROP_UPDATE_INTERVAL = 'UpdateInterval';

    private const string ATTR_REMOTECONTROLLERINFO = 'RemoteControllerInfo';
    private const string ATTR_SOURCELIST           = 'SourceList';
    private const string ATTR_APPLICATIONLIST      = 'ApplicationList';
    // feste Variablenwerte je Listeneintrag: {Ident: {Schlüssel: Wert}}, wird nie kleiner
    private const string ATTR_LISTVALUES = 'ListValues';

    private const string VAR_IDENT_INPUT_SOURCE     = 'InputSource';
    private const string VAR_IDENT_POWER_STATUS     = 'PowerStatus';
    private const string VAR_IDENT_APPLICATION      = 'Application';
    private const string VAR_IDENT_SEND_REMOTE_KEY  = 'SendRemoteKey';
    private const string VAR_IDENT_AUDIO_MUTE       = 'AudioMute';
    private const string VAR_IDENT_SPEAKER_VOLUME   = 'SpeakerVolume';
    private const string VAR_IDENT_HEADPHONE_VOLUME = 'HeadphoneVolume';

    private const string TIMER_UPDATE = 'STV_UpdateTimer';

    private const string BUFFER_TIMESTAMP_LASTPOWERSTATUSFAIL = 'tsLastFailedGetBufferPowerState';
    private const string BUFFER_FAILED_POLLS                  = 'failedPowerStatusPolls';
    private const string BUFFER_PSK_REJECTED                  = 'pskRejected';
    private const int    LENGTH_OF_BOOTTIME                   = 90;

    // Verbindungsprüfung: wiederholt wird nur, wenn der TV zuletzt eingeschaltet war
    private const int PING_ATTEMPTS   = 3;
    private const int PING_TIMEOUT_MS = 1000;

    private const int SYSTEM_ERROR_ILLEGAL_STATE = 7;
    private const int SYSTEM_ERROR_FORBIDDEN     = 403;
    private const int SYSTEM_ERROR_DISPLAY_OFF   = 40005;
    private const int HTTP_ERROR_NOT_FOUND       = 404;

    private const int NO_SELECTION = -1;

    // Variablenprofile bis 2.1 build 27, seitdem Darstellungen je Variable; werden gelöscht, sobald unbenutzt
    private const array LEGACY_PROFILES = ['STV.Applications', 'STV.PowerStatus', 'STV.Volume', 'STV.RemoteKey', 'STV.Sources'];

    private const int STATUS_OFF     = 0;
    private const int STATUS_STANDBY = 1;
    private const int STATUS_ACTIVE  = 2;
}

class SonyTV extends IPSModuleStrict
{
    use SonyConstants;

    public function Create(): void
    {
        // Diese Zeile nicht löschen.
        parent::Create();

        $this->RegisterProperties();
        $this->RegisterAttributes();

        $this->RegisterTimer(self::TIMER_UPDATE, 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'UpdateAll\', 0);');

        // vor KR_READY fragt ApplyChanges den TV nicht ab, das wird mit KR_READY nachgeholt
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if (($Message === IPS_KERNELMESSAGE) && ($Data[0] === KR_READY)) {
            $this->ApplyChanges();
        }
    }

    /**
     * @throws \JsonException
     */
    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->RegisterVariables();
        $this->SetSummary($this->ReadPropertyString(self::PROP_HOST));

        $configurationError = $this->getConfigurationError();
        if ($configurationError !== 0) {
            $this->SetTimerInterval(self::TIMER_UPDATE, 0);
            $this->SetStatus($configurationError);
            // der Statuscode allein sagt einer KI über MCP nichts, der Text dazu steht nur in der form.json
            $this->Logger_Err('Configuration error: ' . $this->getConfigurationErrorText($configurationError));
            return;
        }

        if ($this->kernelRunlevel() !== KR_READY) {
            return;
        }

        $TimerInterval = $this->ReadPropertyInteger(self::PROP_UPDATE_INTERVAL);
        $this->SetTimerInterval(self::TIMER_UPDATE, $TimerInterval * 1000);
        $this->Logger_Inf('TimerInterval set to ' . $TimerInterval . 's.');

        $this->removeUnusedLegacyProfiles();

        $powerStatus = $this->getPowerStatus(false);
        if ($powerStatus === null) {
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        if ($powerStatus > self::STATUS_OFF) {
            //Fernbedienungstasten, Eingänge und Apps auslesen und als Optionen an die Variablen schreiben
            $this->UpdateRemoteKeyList();
            $this->GetSourceListInfo();
            $this->UpdateApplicationList();
        }
    }

    /**
     * @throws \JsonException
     */
    public function RequestAction(string $Ident, mixed $Value): void
    {
        // Die Methode ist void: Nur eine Warnung zeigt dem Aufrufer (Skript, Visualisierung, KI über MCP), dass
        // nichts gesendet wurde oder der TV abgelehnt hat. Ohne sie liefert das globale RequestAction true.
        $configurationError = $this->getConfigurationError();
        if ($configurationError !== 0) {
            trigger_error('Instance is not configured: ' . $this->getConfigurationErrorText($configurationError), E_USER_WARNING);
            return;
        }

        $command = $this->getCommand($Ident, $Value);
        if ($command === null) {
            return;
        }

        if (!$command()) {
            trigger_error(
                sprintf('Action "%s" with value %s failed (the TV did not confirm the command, see debug)', $Ident, var_export($Value, true)),
                E_USER_WARNING
            );
        }
    }

    /**
     * Prüft den Wert einer Aktion und liefert den Befehl dazu, der true bei Erfolg liefert.
     * Bei einem ungültigen Wert, einer leeren Auswahlliste oder einem unbekannten Ident gibt es eine Warnung und null.
     *
     * @throws \JsonException
     */
    private function getCommand(string $ident, mixed $value): ?callable
    {
        switch ($ident) {
            case self::VAR_IDENT_POWER_STATUS:
                $status = is_bool($value) ? ($value ? self::STATUS_ACTIVE : self::STATUS_OFF) : $this->toInteger($value);
                if (!in_array($status, [self::STATUS_OFF, self::STATUS_STANDBY, self::STATUS_ACTIVE], true)) {
                    $this->rejectValue($ident, $value, '0 = Off, 1 = Standby, 2 = On');
                    return null;
                }
                return fn (): bool => $this->SetPowerStatus($status === self::STATUS_ACTIVE);

            case self::VAR_IDENT_SEND_REMOTE_KEY:
            case self::VAR_IDENT_INPUT_SOURCE:
            case self::VAR_IDENT_APPLICATION:
                return $this->getListCommand($ident, $value);

            case self::VAR_IDENT_AUDIO_MUTE:
                $mute = is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if (!is_bool($mute)) {
                    $this->rejectValue($ident, $value, 'true, false');
                    return null;
                }
                return fn (): bool => $this->SetAudioMute($mute);

            case self::VAR_IDENT_SPEAKER_VOLUME:
            case self::VAR_IDENT_HEADPHONE_VOLUME:
                $volume = is_numeric($value) ? (int)round((float)$value) : null;
                if (!$this->isValidVolume($ident, $volume ?? $value)) {
                    return null;
                }
                return $ident === self::VAR_IDENT_SPEAKER_VOLUME
                    ? fn (): bool => $this->SetSpeakerVolume($volume)
                    : fn (): bool => $this->SetHeadphoneVolume($volume);

            case 'UpdateAll':
                return fn (): bool => $this->UpdateAll();

            case 'UpdateStatusVariables':
                return fn (): bool => $this->runWithVisualFeedback([$this, 'UpdateAll'], 'Error while updating.');

            case 'GetSourceListInfo':
            case 'UpdateApplicationList':
            case 'UpdateRemoteKeyList':
                return fn (): bool => $this->runWithVisualFeedback([$this, $ident], 'Error while updating.');

            case 'WriteAPIInformationToFile':
                return fn (): bool => $this->runWithVisualFeedback(fn (): bool => $this->WriteAPIInformationToFile(''), 'Error writing the file.');

            default:
                trigger_error('Unexpected ident: ' . $ident, E_USER_WARNING);
                return null;
        }
    }

    /**
     * Befehl für einen Eintrag einer Auswahlliste (Taste, Eingang, App). -1 („-") ist keine Auswahl und tut nichts.
     *
     * @throws \JsonException
     */
    private function getListCommand(string $ident, mixed $value): ?callable
    {
        [, , , , $captionField, $emptyMessage] = $this->getListDefinition($ident);

        $entries = $this->getListEntries($ident);
        if ($entries === []) {
            trigger_error($emptyMessage, E_USER_WARNING);
            return null;
        }

        $number = $this->toInteger($value);
        if ($number === self::NO_SELECTION) {
            return static fn (): bool => true;
        }

        $entry = $number === null ? null : ($entries[$number] ?? null);
        if ($entry === null) {
            $allowed = [];
            foreach ($entries as $entryValue => $listEntry) {
                $allowed[] = $entryValue . ' = ' . html_entity_decode($listEntry[$captionField]);
            }
            $this->rejectValue($ident, $value, implode(', ', $allowed));
            return null;
        }

        return match ($ident) {
            self::VAR_IDENT_SEND_REMOTE_KEY => fn (): bool => $this->SendRemoteKey($entry['name']),
            self::VAR_IDENT_INPUT_SOURCE    => fn (): bool => $this->SetInputSource($entry['title']),
            self::VAR_IDENT_APPLICATION     => fn (): bool => $this->StartApplication($entry['title']),
        };
    }

    /**
     * Ganzzahl aus einem Aktionswert (auch '12' oder 12.0), null bei allem anderen.
     */
    private function toInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if ((is_float($value) || is_string($value)) && is_numeric($value) && (float)$value === floor((float)$value)) {
            return (int)$value;
        }
        return null;
    }

    private function isValidVolume(string $ident, mixed $volume): bool
    {
        if (is_int($volume) && $volume >= self::VOLUME_MIN && $volume <= self::VOLUME_MAX) {
            return true;
        }
        $this->rejectValue($ident, $volume, self::VOLUME_MIN . ' to ' . self::VOLUME_MAX);
        return false;
    }

    private function rejectValue(string $ident, mixed $value, string $allowed): void
    {
        $shownValue = match (true) {
            is_bool($value)   => $value ? 'true' : 'false',
            is_scalar($value) => (string)$value,
            default           => gettype($value),
        };
        trigger_error(sprintf('Invalid value "%s" for "%s" (allowed: %s)', $shownValue, $ident, $allowed), E_USER_WARNING);
    }

    private function runWithVisualFeedback(callable $action, string $failureTranslationKey): bool
    {
        if ($action()) {
            $this->MsgBox($this->Translate('OK'));
            return true;
        }

        $this->MsgBox($this->Translate($failureTranslationKey));
        return false;
    }

    /**
     * Fragt den Zustand des TV ab und aktualisiert die Statusvariablen.
     *
     * @return bool true, wenn der Zustand ermittelt wurde (auch „ausgeschaltet"), sonst false
     *
     * @throws \JsonException
     */
    public function UpdateAll(): bool
    {
        $this->Logger_Dbg(__FUNCTION__, 'Start ...');

        if ($this->kernelRunlevel() !== KR_READY) {
            $this->Logger_Dbg(__FUNCTION__, 'Kernel is not ready');
            return false;
        }

        if ($this->getConfigurationError() !== 0) {
            return false;
        }

        $PowerStatus = $this->getPowerStatus();
        if ($PowerStatus === null) {
            return false;
        }

        if ($PowerStatus === self::STATUS_ACTIVE) {
            $this->getVolumes();
            $this->GetInputSource();
        }

        return true;
    }

    /**
     * Probelauf ohne Wirkung: prüft Konfiguration, Erreichbarkeit, Pre-Shared Key und Listen und liefert das
     * Ergebnis als Text, eine Zeile je Prüfung (✓ in Ordnung, ⚠ Warnung, ✗ Fehler) und am Ende die Zahl der
     * Fehler und Warnungen. Schaltet nichts und schreibt keine Variable.
     *
     * @throws \JsonException
     */
    public function RunSelfTest(): string
    {
        $lines    = [];
        $errors   = 0;
        $warnings = 0;
        $add      = static function (string $level, string $label, string $hint = '') use (&$lines, &$errors, &$warnings): void {
            $lines[] = match ($level) {
                'ok'    => '✓',
                'warn'  => '⚠',
                'error' => '✗',
                default => '•',
            } . ' ' . $label;
            if ($hint !== '') {
                $lines[] = '   → ' . $hint;
            }
            $errors += $level === 'error' ? 1 : 0;
            $warnings += $level === 'warn' ? 1 : 0;
        };
        $summary = static function () use (&$lines, &$errors, &$warnings): string {
            $lines[] = sprintf('%d errors, %d warnings', $errors, $warnings);
            return implode("\n", $lines);
        };

        $configurationError = $this->getConfigurationError();
        if ($configurationError !== 0) {
            $add('error', 'Configuration: ' . $this->getConfigurationErrorText($configurationError), 'Correct the setting and apply the changes.');
            return $summary();
        }
        $interval = $this->ReadPropertyInteger(self::PROP_UPDATE_INTERVAL);
        $add('ok', 'Configuration: host ' . $this->ReadPropertyString(self::PROP_HOST) . ', update interval '
                   . ($interval === 0 ? 'off (no automatic update)' : $interval . ' s'));

        $host    = $this->ReadPropertyString(self::PROP_HOST);
        $reached = false;
        for ($i = 1; $i <= self::PING_ATTEMPTS && !$reached; $i++) {
            $reached = $this->ping($host, self::PING_TIMEOUT_MS);
        }
        if (!$reached) {
            $add(
                'error',
                sprintf('TV %s does not answer ping (%d attempts)', $host, self::PING_ATTEMPTS),
                "Is it disconnected from mains, is 'Remote start' switched off on the TV, or is the IP address wrong?"
            );
            return $summary();
        }

        $status = $this->getResult($this->callRestApi('system', 'getPowerStatus', [], '1.0', [CURLE_OPERATION_TIMEDOUT], [self::HTTP_ERROR_NOT_FOUND]))[0]['status'] ?? null;
        if (!is_string($status)) {
            $add('error', sprintf('TV %s answers ping, but not getPowerStatus', $host), 'Is the device at this address a Sony Bravia TV with IP control switched on?');
            return $summary();
        }
        $add('ok', sprintf('TV %s answers, power status: %s', $host, match ($status) {
            'standby' => 'Standby',
            'active'  => 'On',
            default   => $status,
        }));
        $add('info', 'Switching PowerStatus: 2 = On; 0 (Off) and 1 (Standby) both put the TV into standby, it cannot be switched fully off over the network');

        // getPlayingContentInfo verlangt den Schlüssel; mit richtigem Schlüssel kommt ein Ergebnis, 40005 (Standby)
        // oder 7 (App im Vordergrund), mit falschem 403 (alles mitgeschnitten)
        $response = $this->callRestApi('avContent', 'getPlayingContentInfo', [], '1.0', [CURLE_OPERATION_TIMEDOUT], [
            self::SYSTEM_ERROR_ILLEGAL_STATE, self::SYSTEM_ERROR_FORBIDDEN, self::SYSTEM_ERROR_DISPLAY_OFF
        ]);
        $answer = $response === false ? null : json_decode($response, true);
        if (!is_array($answer)) {
            $add('warn', 'Pre-Shared Key could not be checked: no answer to getPlayingContentInfo');
        } elseif (($answer['error'][0] ?? null) === self::SYSTEM_ERROR_FORBIDDEN) {
            $add(
                'error',
                'Pre-Shared Key rejected by the TV (error 403)',
                "Enter the same key as on the TV under Network & Internet → Local network → IP control (authentication 'Pre-Shared Key')."
            );
        } else {
            $add('ok', 'Pre-Shared Key accepted');
        }

        foreach ([
            [self::VAR_IDENT_SEND_REMOTE_KEY, 'remote keys', 'UpdateRemoteKeyList'],
            [self::VAR_IDENT_INPUT_SOURCE, 'input sources', 'GetSourceListInfo'],
            [self::VAR_IDENT_APPLICATION, 'applications', 'UpdateApplicationList'],
        ] as [$ident, $name, $action]) {
            $count = count($this->getListEntries($ident));
            $add(
                $count === 0 ? 'warn' : 'ok',
                sprintf('%d %s read (options of the variable %s)', $count, $name, $ident),
                $count === 0 ? sprintf("Read the list with IPS_RequestAction(\$InstanceID, '%s', 0) while the TV is on or in standby.", $action) : ''
            );
        }

        return $summary();
    }

    /**
     * Schaltet den TV ein (true) oder aus (false).
     *
     * @return bool true, wenn der TV den Befehl bestätigt hat oder danach im gewünschten Zustand ist
     *              (ein Timeout beim Einschalten kommt vor, obwohl der TV schaltet)
     *
     * @throws \JsonException
     */
    public function SetPowerStatus(bool $Status): bool
    {
        $response  = $this->callRestApi('system', 'setPowerStatus', [['status' => $Status]], '1.0', [CURLE_OPERATION_TIMEDOUT], []);
        $confirmed = $this->getResult($response) !== null;

        if ($confirmed) {
            $this->pause(2); // pause until Sony processes the command
        }

        // auch nach einem Fehlschlag den tatsächlichen Zustand lesen, statt „Aus" anzunehmen
        $powerStatus = $this->getPowerStatus();

        return $confirmed || ($powerStatus !== null && ($powerStatus === self::STATUS_ACTIVE) === $Status);
    }

    /**
     * Set the input source of the device.
     *
     * @param string $source The desired input source.
     *
     * @return bool Returns true if the input source was successfully set, false otherwise.
     *
     * @throws \JsonException Throws an exception if there is an error parsing the JSON response.
     */
    public function SetInputSource(string $source): bool
    {
        $entries = $this->getListEntries(self::VAR_IDENT_INPUT_SOURCE);
        if ($entries === []) {
            trigger_error($this->getListDefinition(self::VAR_IDENT_INPUT_SOURCE)[5], E_USER_WARNING);
            return false;
        }

        $value = $this->findEntryByTitle($entries, $source);
        if ($value === null) {
            trigger_error('Unknown input source: ' . $source, E_USER_WARNING);
            return false;
        }

        $response = $this->callRestApi('avContent', 'setPlayContent', [['uri' => $entries[$value]['uri']]], '1.0', [], []);
        if ($this->getResult($response) === null) {
            return false;
        }

        $this->SetValue(self::VAR_IDENT_INPUT_SOURCE, $value);
        return true;
    }

    /**
     * Sets the audio mute status.
     *
     * @param bool $status The audio mute status to set.
     *
     * @return bool Indicates whether the audio mute status was set successfully.
     *
     * @throws \JsonException If an error occurs while processing the REST API request.
     */
    public function SetAudioMute(bool $status): bool
    {
        $response = $this->callRestApi('audio', 'setAudioMute', [['status' => $status]], '1.0', [], []);

        if ($this->getResult($response) !== null) {
            $this->SetValue(self::VAR_IDENT_AUDIO_MUTE, $status);
            return true;
        }

        return false;
    }

    /**
     * Liefert das Ergebnis ('result' bzw. 'results') einer Antwort des TV oder null, wenn es keines gibt.
     *
     * @throws \JsonException
     */
    private function getResult(false|string $response): ?array
    {
        if ($response === false) {
            return null;
        }

        $json = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        // Sony API nutzt je nach Endpunkt 'result' oder 'results'
        $result = $json['result'] ?? $json['results'] ?? null;

        return is_array($result) ? $result : null;
    }

    /**
     * Set the volume of the speaker.
     *
     * @param int $volume The desired volume level.
     *
     * @return bool Returns true if the volume was set successfully, false otherwise.
     * @throws \JsonException If an error occurs while processing the response.
     */
    public function SetSpeakerVolume(int $volume): bool
    {
        if (!$this->isValidVolume(self::VAR_IDENT_SPEAKER_VOLUME, $volume)) {
            return false;
        }

        $response = $this->callRestApi('audio', 'setAudioVolume', [['target' => 'speaker', 'volume' => (string)$volume]], '1.0', [], []);

        if ($this->getResult($response) !== null) {
            $this->SetValue(self::VAR_IDENT_SPEAKER_VOLUME, $volume);
            return true;
        }

        return false;
    }

    /**
     * Set the volume of the headphones.
     *
     * @param int $volume The volume level to set for the headphones.
     *
     * @return bool Returns true if the operation was successful, false otherwise.
     *
     * @throws \JsonException
     */
    public function SetHeadphoneVolume(int $volume): bool
    {
        if (!$this->isValidVolume(self::VAR_IDENT_HEADPHONE_VOLUME, $volume)) {
            return false;
        }

        $response = $this->callRestApi('audio', 'setAudioVolume', [['target' => 'headphone', 'volume' => (string)$volume]], '1.0', [], []);

        if ($this->getResult($response) !== null) {
            $this->SetValue(self::VAR_IDENT_HEADPHONE_VOLUME, $volume);
            return true;
        }

        return false;
    }

    /**
     * Starts the specified application.
     *
     * @param string $application The name of the application to start.
     *
     * @return bool True if the application was started successfully, false otherwise.
     *
     * @throws \JsonException if an error occurs while parsing the response from the REST API.
     */
    public function StartApplication(string $application): bool
    {
        $entries = $this->getListEntries(self::VAR_IDENT_APPLICATION);
        if ($entries === []) {
            trigger_error($this->getListDefinition(self::VAR_IDENT_APPLICATION)[5], E_USER_WARNING);
            return false;
        }

        $value = $this->findEntryByTitle($entries, $application);
        if ($value === null) {
            trigger_error('Unknown application: ' . $application, E_USER_WARNING);
            return false;
        }

        $response = $this->callRestApi('appControl', 'setActiveApp', [['uri' => $entries[$value]['uri']]], '1.0', [], []);
        if ($this->getResult($response) === null) {
            return false;
        }

        $this->SetValue(self::VAR_IDENT_APPLICATION, $value);
        return true;
    }

    /**
     * Sucht einen Eintrag über seinen Namen, so wie der TV ihn liefert oder wie die Auswahlliste ihn zeigt.
     *
     * @return int|null Variablenwert des Eintrags
     */
    private function findEntryByTitle(array $entries, string $title): ?int
    {
        foreach ($entries as $value => $entry) {
            if ($entry['title'] === $title || html_entity_decode($entry['title']) === $title) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Liest ein JSON-Attribut (eine Liste des TV oder die Wertezuordnung); ein leeres oder ungültiges Attribut ergibt eine leere Liste.
     */
    private function readListAttribute(string $attribute): array
    {
        try {
            $list = json_decode($this->ReadAttributeString($attribute), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($list) ? $list : [];
    }

    /**
     * Name, Position, Attribut, Schlüsselfeld, Beschriftungsfeld und Meldung bei leerer Liste einer Auswahlvariablen.
     *
     * @return array{0: string, 1: int, 2: string, 3: string, 4: string, 5: string}
     */
    private function getListDefinition(string $ident): array
    {
        return match ($ident) {
            self::VAR_IDENT_SEND_REMOTE_KEY => ['Send Remote Key', 50, self::ATTR_REMOTECONTROLLERINFO, 'name', 'name',
                'Remote key list not yet read. Please update the remote key list.'],
            self::VAR_IDENT_INPUT_SOURCE => ['Input Source', 60, self::ATTR_SOURCELIST, 'uri', 'title',
                'Source list not yet read. Please update the list of input sources.'],
            self::VAR_IDENT_APPLICATION => ['Start Application', 70, self::ATTR_APPLICATIONLIST, 'uri', 'title',
                'Application List not yet set. Please update the application list.'],
        };
    }

    /**
     * Die Einträge einer Liste mit ihrem Variablenwert als Schlüssel, in der Reihenfolge des TV.
     *
     * Jeder Eintrag behält seinen Wert, auch wenn der TV die Liste später in anderer Reihenfolge oder mit
     * zusätzlichen Einträgen liefert; neue Einträge bekommen den nächsten freien Wert. Beim ersten Lauf
     * wird die Position übernommen, die bis 2.1 build 31 der Wert war.
     *
     * @return array<int, array>
     * @throws \JsonException
     */
    private function getListEntries(string $ident): array
    {
        [, , $attribute, $keyField] = $this->getListDefinition($ident);

        $list = array_values(array_filter($this->readListAttribute($attribute), static fn ($entry): bool => is_array($entry) && isset($entry[$keyField])));
        if ($list === []) {
            return [];
        }

        $allValues = $this->readListAttribute(self::ATTR_LISTVALUES);
        $values    = $allValues[$ident] ?? [];
        $changed   = false;

        if ($values === []) {
            foreach ($list as $index => $entry) {
                $values[$entry[$keyField]] ??= $index;
            }
            $changed = true;
        }

        $next    = max($values) + 1;
        $entries = [];
        foreach ($list as $entry) {
            $key = $entry[$keyField];
            if (!isset($values[$key])) {
                $values[$key] = $next++;
                $changed      = true;
            }
            $entries[$values[$key]] = $entry;
        }

        if ($changed) {
            $allValues[$ident] = $values;
            $this->WriteAttributeString(self::ATTR_LISTVALUES, json_encode($allValues, JSON_THROW_ON_ERROR));
        }

        return $entries;
    }

    /**
     * Writes the API information to a file.
     *
     * @param string $filename The name of the file to write the information to, '' = 'Sony <model>.txt' in the log directory.
     *
     * @return bool Returns true if the API information is successfully written to the file, false otherwise.
     * @throws \JsonException
     */
    public function WriteAPIInformationToFile(string $filename): bool
    {
        $response = $this->callRestApi('system', 'getSystemInformation', [], '1.0', [], []);
        $result   = $this->getResult($response);
        if ($result === null) {
            return false;
        }

        if ($filename === '') {
            $filename = IPS_GetLogDir() . 'Sony ' . $result[0]['model'] . '.txt';
        }

        $fileContent = PHP_EOL . 'SystemInformation: ' . $response . PHP_EOL . PHP_EOL;

        $services = $this->getResult($this->callRestApi('guide', 'getServiceProtocols', [], '1.0', [], []));
        foreach ($services ?? [] as $service) {
            $this->ListAPIInfoOfService($service[0], $fileContent);
        }

        $this->Logger_Inf('Writing API Information to \'' . $filename . '\'');

        return file_put_contents($filename, $fileContent) > 0;
    }

    /**
     * Liest die installierten Apps vom TV und schreibt sie als Optionen an die Variable.
     *
     * @return bool true if the application list was successfully updated, false otherwise.
     * @throws \JsonException if JSON decoding fails.
     */
    private function UpdateApplicationList(): bool
    {
        $applicationList = $this->getJsonApplicationList();
        if ($applicationList === null) {
            return false;
        }

        $applicationListJson = json_encode($applicationList, JSON_THROW_ON_ERROR);
        $this->WriteAttributeString(self::ATTR_APPLICATIONLIST, $applicationListJson);
        $this->registerListVariable(self::VAR_IDENT_APPLICATION);
        $this->Logger_Dbg(__FUNCTION__, 'ApplicationList: ' . $applicationListJson);

        return true;
    }

    /**
     * @throws \JsonException
     * @noinspection PhpUnused
     */
    public function ReadApplicationList(): string
    {
        $applicationList = $this->getJsonApplicationList();
        if ($applicationList === null) {
            return '';
        }

        $applicationListJson = json_encode($applicationList, JSON_THROW_ON_ERROR);
        $this->Logger_Dbg(__FUNCTION__, 'ApplicationList: ' . $applicationListJson);

        return $applicationListJson;
    }

    /**
     * @throws \JsonException
     */
    private function getJsonApplicationList(): ?array
    {
        $result = $this->getResult($this->callRestApi('appControl', 'getApplicationList', [], '1.0', [], []));

        return is_array($result[0] ?? null) ? $result[0] : null;
    }

    /**
     * @throws \JsonException
     */
    private function getVolumes(): void
    {
        $result = $this->getResult($this->callRestApi('audio', 'getVolumeInformation', [], '1.0', [], []));
        if ($result === null) {
            return;
        }

        foreach ($result[0] as $target) {
            switch ($target['target']) {
                case 'speaker':
                    $this->SetValue(self::VAR_IDENT_AUDIO_MUTE, $target['mute']);
                    $this->SetValue(self::VAR_IDENT_SPEAKER_VOLUME, $target['volume']);
                    break;

                case 'headphone':
                    $this->SetValue(self::VAR_IDENT_AUDIO_MUTE, $target['mute']);
                    $this->SetValue(self::VAR_IDENT_HEADPHONE_VOLUME, $target['volume']);
                    break;

                default:
                    trigger_error('Unerwarteter Target: ' . $target['target']);

                    break;
            }
        }
    }

    /**
     * Liest den laufenden Inhalt. Ist es einer der physischen Eingänge, steht er in der Variablen,
     * sonst (Tuner, Bildschirmspiegelung, App im Vordergrund) „keine Auswahl".
     *
     * @throws \JsonException
     */
    private function GetInputSource(): void
    {
        $response = $this->callRestApi(
            'avContent',
            'getPlayingContentInfo',
            [],
            '1.0',
            [],
            [self::SYSTEM_ERROR_ILLEGAL_STATE, self::SYSTEM_ERROR_FORBIDDEN]
        );

        $uri = $this->getResult($response)[0]['uri'] ?? null;
        if ($uri === null) {
            $this->SetValue(self::VAR_IDENT_INPUT_SOURCE, self::NO_SELECTION);
            return;
        }

        $inputSource = self::NO_SELECTION;
        foreach ($this->getListEntries(self::VAR_IDENT_INPUT_SOURCE) as $value => $source) {
            if ($source['uri'] === $uri) {
                $inputSource = $value;
                break;
            }
        }

        $this->SetValue(self::VAR_IDENT_INPUT_SOURCE, $inputSource);
        // der TV zeigt einen Inhalt, also ist keine App im Vordergrund
        $this->SetValue(self::VAR_IDENT_APPLICATION, self::NO_SELECTION);
    }

    /**
     * Ruft den aktuellen Power-Status vom Gerät ab, ohne Variablen zu setzen.
     *
     * @return int|null null, wenn der Zustand gerade nicht sicher zu bestimmen ist
     * @throws \JsonException
     */
    private function fetchPowerStatus(): ?int
    {
        if (!$this->checkConnection($this->ReadPropertyString(self::PROP_HOST))) {
            // der nächste Start beginnt mit einer Bootphase
            $this->handlePowerStatusFailure('No reply to ping');
            $this->SetBuffer(self::BUFFER_FAILED_POLLS, '0');
            return self::STATUS_OFF;
        }

        $apiResult = $this->executeRestApiRequestWithRetry('system', 'getPowerStatus');
        if ($apiResult === false) {
            // ein einzelner Aussetzer ändert nichts, erst der zweite in Folge gilt als „aus"
            $failedPolls = (int)$this->GetBuffer(self::BUFFER_FAILED_POLLS) + 1;
            $this->SetBuffer(self::BUFFER_FAILED_POLLS, (string)$failedPolls);
            if ($failedPolls < 2) {
                $this->Logger_Dbg(__FUNCTION__, 'Connected, but getPowerStatus failed once');
                return null;
            }

            $this->handlePowerStatusFailure('Connected, but getPowerStatus failed repeatedly');
            return self::STATUS_OFF;
        }
        $this->SetBuffer(self::BUFFER_FAILED_POLLS, '0');

        $status = $this->getResult($apiResult)[0]['status'] ?? null;
        if (!is_string($status)) {
            $this->Logger_Inf('Unexpected answer of the TV to getPowerStatus: ' . $apiResult);
            return null;
        }

        // Annahme aus früheren Versionen, nicht mitgeschnitten: Beim Hochfahren meldet der TV schon 'active'.
        // In den ersten 90 s nach einem Fehlschlag gilt er deshalb erst als eingeschaltet, wenn
        // 'getPlayingContentInfo' einen Inhalt liefert. Fehler 7 meldet der TV auch bei einer App im Vordergrund.
        if ($this->isStillBooting($status)) {
            return null;
        }

        return $this->assignPowerStatus($status);
    }

    /**
     * Fragt den Power-Status ab und setzt Status-Variable und Instanzstatus.
     *
     * @throws \JsonException
     */
    private function getPowerStatus(bool $loadMissingLists = true): ?int
    {
        $previousStatus = $this->GetValue(self::VAR_IDENT_POWER_STATUS);
        $powerStatus    = $this->fetchPowerStatus();

        $this->Logger_Dbg(__FUNCTION__, sprintf('PowerStatus: %s', $powerStatus ?? 'null'));

        if ($powerStatus === null) {
            return null;
        }

        // Statusvariable und Instanzstatus folgen dem ermittelten Zustand
        $this->SetValue(self::VAR_IDENT_POWER_STATUS, $powerStatus);
        $this->refreshInstanceStatus();

        if ($loadMissingLists && $previousStatus === self::STATUS_OFF && $powerStatus > self::STATUS_OFF) {
            $this->loadMissingLists();
        }

        return $powerStatus;
    }

    /**
     * Holt Listen nach, die beim Übernehmen nicht gelesen werden konnten, weil der TV aus war.
     *
     * @throws \JsonException
     */
    private function loadMissingLists(): void
    {
        if ($this->readListAttribute(self::ATTR_REMOTECONTROLLERINFO) === []) {
            $this->UpdateRemoteKeyList();
        }
        if ($this->readListAttribute(self::ATTR_SOURCELIST) === []) {
            $this->GetSourceListInfo();
        }
        if ($this->readListAttribute(self::ATTR_APPLICATIONLIST) === []) {
            $this->UpdateApplicationList();
        }
    }

    private function checkConnection(string $host): bool
    {
        // ein verlorenes Paket soll einen erreichbaren TV (An oder Standby) nicht auf „Aus" setzen; ist er schon aus, genügt einer
        $attempts = $this->GetValue(self::VAR_IDENT_POWER_STATUS) === self::STATUS_OFF ? 1 : self::PING_ATTEMPTS;

        for ($i = 1; $i <= $attempts; $i++) {
            $isConnected = $this->ping($host, self::PING_TIMEOUT_MS);
            $this->Logger_Dbg(__FUNCTION__, sprintf('Connected (%s. Versuch): %s', $i, $isConnected ? 'true' : 'false'));
            if ($isConnected) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws \JsonException
     */
    private function executeRestApiRequestWithRetry(string $endpoint, string $method): false|string
    {
        // zwei Versuche; eine Fehlerantwort zählt wie keine Antwort (am 01.10.2026 kam {"error":[404,"Not Found"]})
        for ($attempt = 1; ; $attempt++) {
            $ret = $this->callRestApi($endpoint, $method, [], '1.0', [CURLE_OPERATION_TIMEDOUT], [self::HTTP_ERROR_NOT_FOUND]);
            if ($ret !== false && $this->getResult($ret) !== null) {
                return $ret;
            }
            if ($attempt === 2) {
                return false;
            }
            $this->pause(3);
        }
    }

    private function handlePowerStatusFailure(string $message): void
    {
        $this->SetBuffer(self::BUFFER_TIMESTAMP_LASTPOWERSTATUSFAIL, (string)$this->now());
        $this->Logger_Dbg(__FUNCTION__, sprintf('%s at %s', $message, date(DATE_RSS, $this->now())));
    }

    /**
     * @throws \JsonException
     */
    private function isStillBooting(string $status): bool
    {
        if ($status !== 'active') {
            return false;
        }

        $tsLastFailed      = (int)$this->GetBuffer(self::BUFFER_TIMESTAMP_LASTPOWERSTATUSFAIL);
        $timeSinceLastFail = $this->now() - $tsLastFailed;

        if ($timeSinceLastFail > self::LENGTH_OF_BOOTTIME) {
            return false;
        }

        // Während der Bootphase prüfen, ob Content-Info bereits verfügbar ist
        $response = $this->callRestApi(
            'avContent',
            'getPlayingContentInfo',
            [],
            '1.0',
            [CURLE_OPERATION_TIMEDOUT],
            [self::SYSTEM_ERROR_ILLEGAL_STATE, self::SYSTEM_ERROR_FORBIDDEN]
        );

        if ($response === false) {
            $this->logBootStatus($timeSinceLastFail);
            return true;
        }

        $error = json_decode($response, true, 512, JSON_THROW_ON_ERROR)['error'] ?? null;
        if (is_array($error) && ($error[0] ?? null) === self::SYSTEM_ERROR_ILLEGAL_STATE) {
            $this->logBootStatus($timeSinceLastFail);
            return true;
        }

        return false;
    }

    private function logBootStatus(int $elapsed): void
    {
        $this->Logger_Dbg(
            __FUNCTION__,
            sprintf('Bootphase noch nicht abgeschlossen: %ss (%ss)', $elapsed, self::LENGTH_OF_BOOTTIME)
        );
    }

    private function assignPowerStatus(string $status): int
    {
        switch ($status) {
            case 'standby':
                return self::STATUS_STANDBY;
            case 'active':
                return self::STATUS_ACTIVE;
            default:
                trigger_error('Unexpected status: ' . $status);
                return self::STATUS_OFF;
        }
    }

    /**
     * @throws \JsonException
     */
    private function ListAPIInfoOfService(string $servicename, string &$return): void
    {
        $return .= 'Service: ' . $servicename . PHP_EOL;
        // der Service 'contentshare' antwortet auf getMethodTypes mit 404 (Mitschnitt aktiv), das ist kein Fehler
        $results = $this->getResult($this->callRestApi($servicename, 'getMethodTypes', [''], '1.0', [], [self::HTTP_ERROR_NOT_FOUND]));
        if ($results === null) {
            return;
        }

        foreach ($results as $api) {
            if (!in_array($api[0], ['getMethodTypes', 'getVersions'])) {
                $returns = count($api[2]) > 0 ? ': ' . $api[2][0] : '';

                $return .= '   ' . $api[0] . '(' . implode(', ', $api[1]) . ')' . $returns . ' - Version: ' . $api[3] . PHP_EOL;
            }
        }
        $return .= PHP_EOL;
    }

    /**
     * @throws \JsonException
     */
    private function SendCurlPost(
        string $host,
        string $service,
        array $headers,
        string $data,
        bool $ignoreResponse,
        array $ignoredCurlErrors,
        array $ignoredResponseErrors
    ): false|string {
        $this->Logger_Dbg(
            __FUNCTION__,
            sprintf(
                'service: %s, data: %s, ignoreResponse: %s, $ignoredCurlErrors: %s, $ignoredResponseErrors: %s',
                $service,
                $data,
                (int)$ignoreResponse,
                json_encode($ignoredCurlErrors, JSON_THROW_ON_ERROR),
                json_encode($ignoredResponseErrors, JSON_THROW_ON_ERROR)
            )
        );

        // ohne gültigen Host ginge die Anfrage an „http:///sony/…" und liefe erst in den Timeout
        $configurationError = $this->getConfigurationError();
        if ($configurationError !== 0) {
            trigger_error('Instance is not configured: ' . $this->getConfigurationErrorText($configurationError), E_USER_WARNING);
            return false;
        }

        // eine IPv6-Adresse steht in einer URL in eckigen Klammern
        $url = 'http://' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . '/sony/' . $service;
        [$response, $curl_errno, $curl_error, $httpCode] = $this->executeCurl($url, $headers, $data);

        if ($curl_errno) {
            $message = sprintf(
                'Curl call of \'%s\' with data \'%s\' returned with \'%s\': %s (ignored Errors: %s)',
                $url,
                $data,
                $curl_errno,
                $curl_error,
                json_encode($ignoredCurlErrors, JSON_THROW_ON_ERROR)
            );
            if (in_array($curl_errno, $ignoredCurlErrors, true)) {
                $this->Logger_Dbg(__FUNCTION__, $message);
            } else {
                $this->Logger_Inf($message);
            }
            return false;
        }

        $this->Logger_Dbg(__FUNCTION__, sprintf('received (HTTP %s): %s', $httpCode, $response));

        // vor dem Filter der ignorierten Fehler: auch eine ignorierte 403 heißt „Schlüssel abgelehnt"
        $this->noteAuthentication($httpCode, $response, $data);

        if ($ignoreResponse) {
            // der Inhalt der Antwort sagt nichts aus, der HTTP-Status schon
            if ($httpCode >= 400) {
                $this->Logger_Inf(sprintf('TV replied with HTTP status %s to the data \'%s\'', $httpCode, $data));
                return false;
            }
            return '';
        }

        try {
            $json_a = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->Logger_Err(
                sprintf('json_decode returned with \'%s\': %s<br>service : %s,  postfields: %s', $e->getMessage(), $response, $service, $data)
            );
            return false;
        }

        if (isset($json_a['error'])) {
            $error     = is_array($json_a['error']) ? $json_a['error'] : [$json_a['error']];
            $errorCode = $error[0] ?? null;
            if (!in_array($errorCode, $ignoredResponseErrors, true)) {
                $this->Logger_Inf(
                    sprintf(
                        'TV replied with error \'%s\' to the data \'%s\' (ignored Errors: %s)',
                        implode(', ', array_map(static fn ($part): string => is_scalar($part) ? (string)$part : json_encode($part), $error)),
                        $data,
                        json_encode($ignoredResponseErrors, JSON_THROW_ON_ERROR)
                    )
                );
                return false;
            }
        }

        return $response;
    }

    /**
     * Merkt sich, ob der TV den Pre-Shared Key zuletzt abgelehnt (HTTP 403 bzw. Fehler 403) oder angenommen hat.
     * getPowerStatus antwortet auch mit falschem Schlüssel und sagt darüber nichts (Mitschnitt falscher-psk).
     */
    private function noteAuthentication(int $httpCode, string $response, string $data): void
    {
        $answer   = json_decode($response, true);
        $rejected = $httpCode === self::SYSTEM_ERROR_FORBIDDEN
                    || (is_array($answer) && is_array($answer['error'] ?? null) && ($answer['error'][0] ?? null) === self::SYSTEM_ERROR_FORBIDDEN);

        if (!$rejected) {
            $request = json_decode($data, true);
            if ($httpCode >= 400 || (is_array($request) && ($request['method'] ?? '') === 'getPowerStatus')) {
                return;
            }
        }

        if (($this->GetBuffer(self::BUFFER_PSK_REJECTED) === '1') === $rejected) {
            return;
        }

        $this->SetBuffer(self::BUFFER_PSK_REJECTED, $rejected ? '1' : '');
        $this->refreshInstanceStatus();
    }

    /**
     * Setzt den Instanzstatus aus Erreichbarkeit und Schlüssel und meldet jeden Wechsel einmal im Log.
     * Eine KI über MCP sieht vom Status nur die Zahl; der Text dazu steht nur in der form.json.
     */
    private function refreshInstanceStatus(): void
    {
        if ($this->getConfigurationError() !== 0) {
            return;
        }

        $host      = $this->ReadPropertyString(self::PROP_HOST);
        $newStatus = match (true) {
            $this->GetValue(self::VAR_IDENT_POWER_STATUS) === self::STATUS_OFF => self::STATUS_INST_NOT_REACHABLE,
            $this->GetBuffer(self::BUFFER_PSK_REJECTED) === '1'               => self::STATUS_INST_PSK_REJECTED,
            default                                                            => IS_ACTIVE,
        };

        $oldStatus = $this->GetStatus();
        if ($newStatus === $oldStatus) {
            return;
        }
        $this->SetStatus($newStatus);

        if ($oldStatus === self::STATUS_INST_NOT_REACHABLE) {
            $this->Logger_Inf(sprintf('TV %s answers again.', $host));
        }
        if ($oldStatus === self::STATUS_INST_PSK_REJECTED && $newStatus === IS_ACTIVE) {
            $this->Logger_Inf(sprintf('Pre-Shared Key accepted by the TV %s.', $host));
        }

        if ($newStatus === self::STATUS_INST_NOT_REACHABLE) {
            $this->Logger_Err(sprintf(
                "TV %s does not answer (no reply to ping or to getPowerStatus). Is it disconnected from mains, is 'Remote start' switched off, or is the IP address wrong?",
                $host
            ));
        }
        if ($newStatus === self::STATUS_INST_PSK_REJECTED) {
            $this->Logger_Err(sprintf('The TV %s rejected the Pre-Shared Key (error 403). Enter the same key as on the TV under IP control.', $host));
        }
    }

    /**
     * Der HTTP-Aufruf (Naht für die Tests).
     *
     * @return array{0: false|string, 1: int, 2: string, 3: int} Antwort, curl-Fehlernummer, curl-Fehlertext, HTTP-Status
     */
    protected function executeCurl(string $url, array $headers, string $data): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if (count($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);

        return [$response, curl_errno($ch), curl_error($ch), (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE)];
    }

    /**
     * Prüft, ob der TV im Netzwerk antwortet (Naht für die Tests).
     */
    protected function ping(string $host, int $timeoutMs): bool
    {
        return @Sys_Ping($host, $timeoutMs);
    }

    /**
     * Wartet (Naht für die Tests).
     */
    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * Aktuelle Zeit (Naht für die Tests).
     */
    protected function now(): int
    {
        return time();
    }

    /**
     * Runlevel des Kernels (Naht für die Tests).
     */
    protected function kernelRunlevel(): int
    {
        return IPS_GetKernelRunlevel();
    }

    /**
     * Inhalt aller Diagramme (Naht für die Tests).
     *
     * @return list<string|false> false, wenn ein Diagramm nicht lesbar ist
     */
    protected function chartContents(): array
    {
        $contents = [];
        foreach (IPS_GetMediaListByType(MEDIATYPE_CHART) as $mediaID) {
            // Diagramme, deren Datei nicht verfügbar ist (MediaIsAvailable = false), liefern false samt Warnung "InstanceInterface is not available"
            $raw        = @IPS_GetMediaContent($mediaID);
            $contents[] = is_string($raw) ? $raw : false;
        }

        return $contents;
    }

    /**
     * Send a remote key command to the device.
     *
     * @param string $name The name of the remote key command.
     *
     * @return bool Returns true if the TV accepted the remote key command (HTTP status), false otherwise.
     *
     * @throws \JsonException
     */
    public function SendRemoteKey(string $name): bool
    {
        $this->SendDebug(__FUNCTION__, 'name: ' . $name, 0);

        $entries = $this->getListEntries(self::VAR_IDENT_SEND_REMOTE_KEY);
        if ($entries === []) {
            trigger_error($this->getListDefinition(self::VAR_IDENT_SEND_REMOTE_KEY)[5], E_USER_WARNING);
            return false;
        }

        $value = array_find_key($entries, static fn (array $entry): bool => $entry['name'] === $name);
        if ($value === null || !isset($entries[$value]['value'])) {
            trigger_error('Invalid RemoteKey: ' . $name);
            return false;
        }

        $data    = $this->getXMLEnvelopeData($entries[$value]['value']);
        $headers = $this->getHeadersArray(strlen($data));

        // ausgewertet wird nur der HTTP-Status, nicht der Inhalt der Antwort
        $ret = $this->SendCurlPost($this->ReadPropertyString(self::PROP_HOST), 'IRCC', $headers, $data, true, [], []);

        $this->SendDebug(__FUNCTION__, 'return: ' . json_encode($ret), 0);

        if ($ret === false) {
            return false;
        }

        $this->SetValue(self::VAR_IDENT_SEND_REMOTE_KEY, $value);
        return true;
    }

    /**
     * Erstellt eine XML-Envelope für IRCC-Befehle.
     */
    private function getXMLEnvelopeData(string $irccCode): string
    {
        $xml      = new DOMDocument('1.0', 'UTF-8');
        $envelope = $xml->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 's:Envelope');
        $envelope->setAttributeNS('http://schemas.xmlsoap.org/soap/envelope/', 's:encodingStyle', 'http://schemas.xmlsoap.org/soap/encoding/');

        $body   = $xml->createElement('s:Body');
        $action = $xml->createElementNS('urn:schemas-sony-com:service:IRCC:1', 'u:X_SendIRCC');
        $code   = $xml->createElement('IRCCCode', $irccCode);

        $action->appendChild($code);
        $body->appendChild($action);
        $envelope->appendChild($body);
        $xml->appendChild($envelope);

        return $xml->saveXML();
    }

    private function getHeadersArray(int $contentLength): array
    {
        $headers   = [];
        $headers[] = 'X-Auth-PSK: ' . $this->ReadPropertyString(self::PROP_PSK);
        $headers[] = 'Content-Type: text/xml; charset=UTF-8';
        $headers[] = 'Content-Length: ' . $contentLength;
        $headers[] = 'SOAPAction: "urn:schemas-sony-com:service:IRCC:1#X_SendIRCC"';

        return $headers;
    }

    /**
     * Sendet eine beliebige Anfrage an die REST-API des TV (für Diagnose und eigene Skripte).
     *
     * @param string $params Parameterliste als JSON, z.B. '[{"uri":"extInput:hdmi?port=2"}]'
     *
     * @return string Antwort des TV als JSON, bei einem Fehler ein Leerstring
     *
     * @throws \JsonException
     */
    public function SendRestAPIRequest(string $service, string $method, string $params, string $version): string
    {
        try {
            $paramList = json_decode($params, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            trigger_error('Invalid JSON in params: ' . $e->getMessage(), E_USER_WARNING);
            return '';
        }

        if (!is_array($paramList) || !array_is_list($paramList)) {
            trigger_error('params must be a JSON array', E_USER_WARNING);
            return '';
        }

        $response = $this->callRestApi($service, $method, $paramList, $version, [], []);

        return $response === false ? '' : $response;
    }

    /**
     * @throws \JsonException
     */
    private function callRestApi(string $service, string $method, array $params, string $version, array $ignoredCurlErrors, array $ignoredErrors): false|string
    {
        $this->Logger_Dbg(
            __FUNCTION__,
            sprintf(
                'service: %s, message: %s, params: %s, version: %s',
                $service,
                $method,
                json_encode($params, JSON_THROW_ON_ERROR),
                $version
            )
        );

        $data = json_encode(
            [
                'method'  => $method,
                'params'  => $params,
                'id'      => $this->InstanceID,
                'version' => $version
            ],
            JSON_THROW_ON_ERROR
        );

        $headers = $this->getCommonHeaders($data);

        return $this->SendCurlPost($this->ReadPropertyString(self::PROP_HOST), $service, $headers, $data, false, $ignoredCurlErrors, $ignoredErrors);
    }

    private function getCommonHeaders(string $data): array
    {
        $headers   = [];
        $headers[] = 'Accept: */*';
        $headers[] = 'Cache-Control: no-cache';
        $headers[] = 'Connection: close';
        $headers[] = 'Content-Type: application/json; charset=UTF-8';
        $headers[] = 'Pragma: no-cache';
        $headers[] = 'X-Auth-PSK: ' . $this->ReadPropertyString(self::PROP_PSK);
        $headers[] = 'Content-Length: ' . strlen($data);

        return $headers;
    }

    /**
     * Liest die Eingänge vom TV und schreibt die physischen als Optionen an die Variable.
     *
     * @throws \JsonException
     */
    private function GetSourceListInfo(): bool
    {
        $result = $this->getResult($this->callRestApi('avContent', 'getCurrentExternalInputsStatus', [], '1.0', [], []));
        if (!is_array($result[0] ?? null)) {
            return false;
        }

        $sourceList = [];
        foreach ($result[0] as $source) {
            if (in_array(explode('?', $source['uri'])[0], ['extInput:hdmi', 'extInput:composite', 'extInput:component'], true)) { //physical inputs
                $sourceList[] = ['title' => $source['title'], 'uri' => $source['uri']];
            }
        }

        $jsonSourceList = json_encode($sourceList, JSON_THROW_ON_ERROR);
        $this->WriteAttributeString(self::ATTR_SOURCELIST, $jsonSourceList);
        $this->registerListVariable(self::VAR_IDENT_INPUT_SOURCE);
        $this->Logger_Dbg(__FUNCTION__, 'SourceList: ' . $jsonSourceList);

        return true;
    }

    /**
     * Liest die Tasten der Fernbedienung vom TV und schreibt sie als Optionen an die Variable.
     *
     * @throws \JsonException
     */
    private function UpdateRemoteKeyList(): bool
    {
        $result = $this->getResult($this->callRestApi('system', 'getRemoteControllerInfo', [], '1.0', [], []));
        if (!is_array($result[1] ?? null)) {
            return false;
        }

        $remoteKeys = json_encode($result[1], JSON_THROW_ON_ERROR);
        $this->WriteAttributeString(self::ATTR_REMOTECONTROLLERINFO, $remoteKeys);
        $this->registerListVariable(self::VAR_IDENT_SEND_REMOTE_KEY);
        $this->Logger_Dbg(__FUNCTION__, 'RemoteControllerInfo: ' . $remoteKeys);

        return true;
    }

    private function RegisterProperties(): void
    {
        //Properties, die im Konfigurationsformular gesetzt werden können
        $this->RegisterPropertyString(self::PROP_HOST, '');
        $this->RegisterPropertyString(self::PROP_PSK, '0000');
        $this->RegisterPropertyInteger(self::PROP_UPDATE_INTERVAL, 10);

        $this->RegisterPropertyBoolean('WriteLogInformationToIPSLogger', false);
        $this->RegisterPropertyBoolean('WriteDebugInformationToLogfile', false);
        $this->RegisterPropertyBoolean('WriteDebugInformationToIPSLogger', false);
    }

    private function RegisterAttributes(): void
    {
        $this->RegisterAttributeString(self::ATTR_REMOTECONTROLLERINFO, json_encode([], JSON_THROW_ON_ERROR));
        $this->RegisterAttributeString(self::ATTR_SOURCELIST, json_encode([], JSON_THROW_ON_ERROR));
        $this->RegisterAttributeString(self::ATTR_APPLICATIONLIST, json_encode([], JSON_THROW_ON_ERROR));
        $this->RegisterAttributeString(self::ATTR_LISTVALUES, '{}');
    }

    /**
     * @throws \JsonException
     */
    private function RegisterVariables(): void
    {
        $this->RegisterVariableInteger(self::VAR_IDENT_POWER_STATUS, $this->Translate('Status'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode([
                ['Value' => self::STATUS_OFF, 'Caption' => $this->Translate('Off')],
                ['Value' => self::STATUS_STANDBY, 'Caption' => $this->Translate('Standby')],
                ['Value' => self::STATUS_ACTIVE, 'Caption' => $this->Translate('On')],
            ], JSON_THROW_ON_ERROR),
        ], 10);

        $this->RegisterVariableBoolean(self::VAR_IDENT_AUDIO_MUTE, $this->Translate('Mute'), [
            'PRESENTATION'   => VARIABLE_PRESENTATION_SWITCH,
            'USAGE_TYPE'     => 1, // Stumm schalten
            'USE_ICON_FALSE' => true,
            'ICON_TRUE'      => 'volume-xmark',
            'ICON_FALSE'     => 'volume',
        ], 20);

        $volume = [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'MIN'          => 0,
            'MAX'          => 100,
            'STEP_SIZE'    => 1,
            'SUFFIX'       => ' %',
            'USAGE_TYPE'   => 3, // Lautstärke
        ];
        $this->RegisterVariableInteger(self::VAR_IDENT_SPEAKER_VOLUME, $this->Translate('Speaker Volume'), $volume, 30);
        $this->RegisterVariableInteger(self::VAR_IDENT_HEADPHONE_VOLUME, $this->Translate('Headphone Volume'), $volume, 40);

        $this->registerListVariable(self::VAR_IDENT_SEND_REMOTE_KEY);
        $this->registerListVariable(self::VAR_IDENT_INPUT_SOURCE);
        $this->registerListVariable(self::VAR_IDENT_APPLICATION);

        // Statusvariablen bedienbar machen
        $this->EnableAction(self::VAR_IDENT_POWER_STATUS);
        $this->EnableAction(self::VAR_IDENT_AUDIO_MUTE);
        $this->EnableAction(self::VAR_IDENT_SEND_REMOTE_KEY);
        $this->EnableAction(self::VAR_IDENT_INPUT_SOURCE);
        $this->EnableAction(self::VAR_IDENT_APPLICATION);
        $this->EnableAction(self::VAR_IDENT_SPEAKER_VOLUME);
        $this->EnableAction(self::VAR_IDENT_HEADPHONE_VOLUME);
    }

    /**
     * Registriert eine Auswahlvariable, deren Optionen aus einer vom TV gelesenen Liste stammen
     * (Tasten, Eingänge, Apps). Jeder Eintrag hat einen festen Wert, -1 steht für „keine Auswahl".
     *
     * @throws \JsonException
     */
    private function registerListVariable(string $ident): void
    {
        [$name, $position, , , $captionField] = $this->getListDefinition($ident);

        $options = [['Value' => self::NO_SELECTION, 'Caption' => '-']];
        foreach ($this->getListEntries($ident) as $value => $entry) {
            $options[] = ['Value' => $value, 'Caption' => html_entity_decode($entry[$captionField])];
        }

        $created = $this->RegisterVariableInteger($ident, $this->Translate($name), [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode($options, JSON_THROW_ON_ERROR),
        ], $position);

        if ($created) {
            $this->SetValue($ident, self::NO_SELECTION);
        }
    }

    /**
     * @return int 0, wenn die Konfiguration vollständig ist, sonst der Instanzstatus des Fehlers
     */
    private function getConfigurationError(): int
    {
        $host = $this->ReadPropertyString(self::PROP_HOST);

        if ($host === '') {
            return self::STATUS_INST_IP_IS_EMPTY;
        }

        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            return self::STATUS_INST_IP_IS_INVALID;
        }

        if ($this->ReadPropertyInteger(self::PROP_UPDATE_INTERVAL) < 0) {
            return self::STATUS_INST_INTERVAL_IS_INVALID;
        }

        return 0;
    }

    /**
     * Klartext zu einem Konfigurationsfehler, mit Wert und erlaubtem Bereich.
     */
    private function getConfigurationErrorText(int $configurationError): string
    {
        return match ($configurationError) {
            self::STATUS_INST_IP_IS_EMPTY   => 'IP address can not be empty.',
            self::STATUS_INST_IP_IS_INVALID => sprintf("IP address '%s' is not valid (allowed: IPv4 or IPv6 address).", $this->ReadPropertyString(self::PROP_HOST)),
            self::STATUS_INST_INTERVAL_IS_INVALID => sprintf(
                'update interval %s is not valid (allowed: 0 or more seconds).',
                $this->ReadPropertyInteger(self::PROP_UPDATE_INTERVAL)
            ),
        };
    }

    private function Logger_Err(string $message): void
    {
        $this->SendDebug('LOG_ERR', $message, 0);
        if (function_exists('IPSLogger_Err') && $this->ReadPropertyBoolean('WriteLogInformationToIPSLogger')) {
            IPSLogger_Err(__CLASS__, $message);
        }

        $this->LogMessage($message, KL_ERROR);
    }

    private function Logger_Inf(string $message): void
    {
        $this->SendDebug('LOG_INFO', $message, 0);
        if (function_exists('IPSLogger_Inf') && $this->ReadPropertyBoolean('WriteLogInformationToIPSLogger')) {
            IPSLogger_Inf(__CLASS__, $message);
        } else {
            $this->LogMessage($message, KL_NOTIFY);
        }
    }

    private function Logger_Dbg(string $message, string $data): void
    {
        $this->SendDebug($message, $data, 0);
        if (function_exists('IPSLogger_Dbg') && $this->ReadPropertyBoolean('WriteDebugInformationToIPSLogger')) {
            IPSLogger_Dbg(__CLASS__ . '.' . IPS_GetObject($this->InstanceID)['ObjectName'] . '.' . $message, $data);
        }
        if ($this->ReadPropertyBoolean('WriteDebugInformationToLogfile')) {
            $this->LogMessage(sprintf('%s: %s', $message, $data), KL_DEBUG);
        }
    }

    /**
     * Löscht die Variablenprofile früherer Versionen, sobald keine Variable und kein Diagramm sie mehr nutzt.
     */
    private function removeUnusedLegacyProfiles(): void
    {
        $existing = array_values(array_filter(self::LEGACY_PROFILES, static fn (string $profile): bool => IPS_VariableProfileExists($profile)));
        if ($existing === []) {
            return;
        }

        $used = $this->getProfilesInUse();
        if ($used === null) {
            $this->Logger_Dbg(__FUNCTION__, 'Mindestens ein Diagramm ist nicht lesbar, die alten Profile bleiben vorerst erhalten');
            return;
        }

        foreach (array_diff($existing, $used) as $profile) {
            IPS_DeleteVariableProfile($profile);
            $this->Logger_Inf('Variablenprofil gelöscht (ersetzt durch Darstellungen): ' . $profile);
        }
    }

    /**
     * @return list<string>|null Namen aller benutzten Profile; null, wenn das nicht sicher zu ermitteln ist
     */
    private function getProfilesInUse(): ?array
    {
        $used = [];

        foreach (IPS_GetVariableList() as $varID) {
            $variable = IPS_GetVariable($varID);
            $used[]   = $variable['VariableProfile'];
            $used[]   = $variable['VariableCustomProfile'];
            // ein Profil kann auch über die Darstellung „Legacy Profil" zugewiesen sein
            $used[] = $variable['VariablePresentation']['PROFILE'] ?? '';
            $used[] = $variable['VariableCustomPresentation']['PROFILE'] ?? '';
        }

        foreach ($this->chartContents() as $raw) {
            if ($raw === false) {
                // ein nicht lesbares Diagramm könnte ein Profil benutzen
                return null;
            }
            $content = json_decode((string)base64_decode($raw), true);
            foreach ($content['axes'] ?? [] as $axis) {
                $used[] = $axis['profile'] ?? '';
            }
        }

        return array_values(array_unique(array_filter($used, static fn ($profile): bool => is_string($profile) && $profile !== '')));
    }

    private function MsgBox(string $Message): void
    {
        $this->UpdateFormField('MsgText', 'caption', $Message);

        $this->UpdateFormField('MsgBox', 'visible', true);
    }
}
