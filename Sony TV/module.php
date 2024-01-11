<?php

/** @noinspection AutoloadingIssuesInspection */

/*
    hier ein paar Links, die als Grundlage dienten

API Beschreibung: https://developer.sony.com/develop/audio-control-api/hardware-overview/api-overview

https://pro-bravia.sony.net/develop/integrate/ip-control/

Return Werte: https://developer.sony.com/develop/audio-control-api/hardware-overview/error-codes

https://community.openhab.org/t/sony-devices-binding/14052/263

https://github.com/gerard33/sony-bravia/blob/master/bravia.py (no longer maintained)

https://github.com/aparraga/braviarc/blob/master/braviarc/braviarc.py

https://github.com/waynehaffenden/bravia

 */

declare(strict_types=1);

if (function_exists('IPSUtils_Include')) {
    IPSUtils_Include('IPSLogger.inc.php', 'IPSLibrary::app::core::IPSLogger');
}

class SonyTV extends IPSModule
{
    private const STATUS_INST_IP_IS_EMPTY   = 202;
    private const STATUS_INST_IP_IS_INVALID = 204; //IP-Adresse ist ungültig

    private const MAX_PROFILE_ASSOCIATIONS = 128;

    private const PROP_HOST = 'Host';
    private const PROP_PSK  = 'PSK';

    private const ATTR_REMOTECONTROLLERINFO = 'RemoteControllerInfo';
    private const ATTR_SOURCELIST           = 'SourceList';
    private const ATTR_APPLICATIONLIST      = 'ApplicationList';
    private const ATTR_UUID                 = 'UUID';

    private const VAR_IDENT_INPUT_SOURCE = 'InputSource';

    private const BUF_TS_LASTFAILEDGETPOWERSTATE = 'tsLastFailedGetBufferPowerState';
    private const LENGTH_OF_BOOTTIME             = 90;

    private const SYSTEM_ERROR_ILLEGAL_STATE = 7;
    private const SYSTEM_ERROR_FORBIDDEN     = 403;
    private const HTTP_ERROR_NOT_FOUND       = 404;

    private const PROFILE_APPLICATIONS = 'STV.Applications';
    private const PROFILE_POWERSTATUS  = 'STV.PowerStatus';
    private const PROFILE_VOLUME       = 'STV.Volume';
    private const PROFILE_REMOTEKEY    = 'STV.RemoteKey';
    private const PROFILE_SOURCES      = 'STV.Sources';

    // Überschreibt die interne IPS_Create($id) Funktion
    public function Create(): void
    {
        // Diese Zeile nicht löschen.
        parent::Create();

        $this->RegisterProperties();
        $this->RegisterAttributes();

        $this->RegisterTimer('Update', 0, 'STV_UpdateAll(' . $this->InstanceID . ');');
    }

    public function Destroy(): void
    {
        $this->UnregisterProfile(self::PROFILE_APPLICATIONS);
        $this->UnregisterProfile(self::PROFILE_POWERSTATUS);
        $this->UnregisterProfile(self::PROFILE_VOLUME);
        $this->UnregisterProfile(self::PROFILE_REMOTEKEY);
        $this->UnregisterProfile(self::PROFILE_SOURCES);

        parent::Destroy();
    }

    /**
     * @throws \JsonException
     */
    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        $TimerInterval = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('Update', $TimerInterval * 1000);
        $this->Logger_Inf('TimerInterval set to ' . $TimerInterval . 's.');

        $this->RegisterVariables();

        $this->SetInstanceStatus();

        $this->SetSummary($this->ReadPropertyString(self::PROP_HOST));

        if ($this->GetStatus() === IS_ACTIVE) {
            //RemoteController Informationen auslesen und in Profil schreiben
            if (!$this->GetRemoteControllerInfo()) {
                return;
            }

            //Sources auslesen und in Profil schreiben
            if (!$this->GetSourceListInfo()) {
                return;
            }

            //Applikationen auslesen und in Profil schreiben
            $this->UpdateApplicationList();
        }
    }

    /**
     * @throws \JsonException
     */
    public function RequestAction($Ident, $Value): bool
    {
        switch ($Ident) {
            case 'PowerStatus':
                $this->SetPowerStatus($Value === 2);
                break;

            case 'SendRemoteKey':
                if ($Value >= 0) {
                    $this->SetValue($Ident, $Value);
                    $this->SendRemoteKey(GetValueFormatted($this->GetIDForIdent($Ident)));
                }
                break;

            case self::VAR_IDENT_INPUT_SOURCE:
                if ($Value >= 0) {
                    $this->SetValue($Ident, $Value);
                    $this->SetInputSource(GetValueFormatted($this->GetIDForIdent($Ident)));
                }
                break;

            case 'Application':
                if ($Value >= 0) {
                    $this->SetValue($Ident, $Value);
                    $this->StartApplication(htmlentities(GetValueFormatted($this->GetIDForIdent($Ident))));
                }
                break;

            case 'AudioMute':
                $this->SetAudioMute($Value);
                break;

            case 'SpeakerVolume':
                $this->SetSpeakerVolume($Value);
                break;

            case 'HeadphoneVolume':
                $this->SetHeadphoneVolume($Value);
                break;

            case 'GetSourceListInfo':
            case 'UpdateApplicationList':
                $this->executeAndUpdateMsg([$this, $Ident], 'Error while updating.');
                break;

            default:
                trigger_error('Unexpected ident: ' . $Ident);
        }

        return true;
    }

    private function executeAndUpdateMsg(callable $callback, string $errorMessage): void
    {
        if ($callback()){
            $this->MsgBox($this->Translate('OK'));
        } else {
            $this->MsgBox($this->Translate($errorMessage));
        }
    }

    /**
     * Updates all elements
     *
     * @return bool Returns true if the update was successful, false otherwise.
     *
     * @throws \JsonException When an error occurs during the JSON parsing.
     */
    public function UpdateAll(): bool
    {
        // IP-Symcon Kernel ready?
        if (!$this->isKernelReady()) {
            return false;
        }

        if ($this->ReadPropertyString(self::PROP_HOST) !== '') {
            return false;
        }

        $PowerStatus = $this->GetPowerStatus();
        return $this->handlePowerStatus($PowerStatus);
    }

    private function isKernelReady(): bool
    {
        $status = IPS_GetKernelRunlevel();
        if ($status !== KR_READY) { //Kernel ready
            $this->Logger_Dbg(__FUNCTION__, 'Kernel is not ready (' . $status . ')');
            return false;
        }

        return true;
    }

    private function handlePowerStatus(int $PowerStatus): bool
    {
        $this->Logger_Dbg(__FUNCTION__, 'PowerStatus: ' . $PowerStatus);

        switch ($PowerStatus) {
            case 0:
            case 1:
                return $PowerStatus > 0;
            case 2:
                $this->SetStatus(IS_ACTIVE);
                $this->GetVolume();
                $this->GetInputSource();
                return true;
            default:
                trigger_error('Unexpected PowerStatus: ' . $PowerStatus);
                return false;
        }
    }
    /**
     * @throws \JsonException
     */
    public function SetPowerStatus(bool $Status): void
    {
        $PowerStatus = 0;
        $ret = $this->SendRestAPIRequest('system', 'setPowerStatus', [['status' => $Status]], '1.0', [28], []);

        if ($ret !== false) {
            $PowerStatus = $this->handlePowerStatusResponse($ret);
        }

        $this->SetValue('PowerStatus', $PowerStatus);
    }

    private function handlePowerStatusResponse($response): int
    {
        $PowerStatus = 0;

        $jsonResponse = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        if (isset($jsonResponse['result'])) {
            sleep(2); // wait until command processed by Sony
            $this->GetPowerStatus();
        } else {
            trigger_error('Error: ' . json_encode($jsonResponse['error'], JSON_THROW_ON_ERROR));
        }

        return $PowerStatus;
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
        $Sources = $this->parseAndValidateSources();
        if ($Sources === false) {
            return false;
        }

        $uri = $this->GetUriOfSource($Sources, $source);
        $response = $this->SendRestAPIRequest('avContent', 'setPlayContent', [['uri' => $uri]], '1.0', [], []);

        return $this->handleAndValidateResponse($response);
    }

    private function parseAndValidateSources(): array | false
    {
        $Sources = json_decode($this->ReadAttributeString(self::ATTR_SOURCELIST), true, 512, JSON_THROW_ON_ERROR);
        if ($Sources === null) {
            trigger_error('Source List not yet set. Please repeat the registration');
            return false;
        }
        return $Sources;
    }

    private function handleAndValidateResponse($response): bool
    {
        if ($response === false) {
            return false;
        }

        $json_a = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: ' . $response);
            return false;
        }

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
        $response = $this->SendRestAPIRequest('audio', 'setAudioMute', [['status' => $status]], '1.0', [], []);

        if ($this->isResponseValid($response)) {
            $this->SetValue('AudioMute', $status);
            return true;
        }

        return false;
    }

    private function isResponseValid($response): bool
    {
        if ($response === false) {
            return false;
        }

        $json_a = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: ' . $response);
            return false;
        }

        return true;
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
        $response = $this->SendRestAPIRequest('audio', 'setAudioVolume', [['target' => 'speaker', 'volume' => (string)$volume]], '1.0', [], []);

        if ($this->isResponseValid($response)) {
            $this->SetValue('SpeakerVolume', $volume);
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
        $response = $this->SendRestAPIRequest(
            'audio', 'setAudioVolume', [['target' => 'headphone', 'volume' => (string)$volume]], '1.0', [], []
        );

        if ($this->isResponseValid($response)) {
            $this->SetValue('HeadphoneVolume', $volume);
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
        $Applications = $this->getApplicationList();
        if ($Applications === null) {
            trigger_error('Application List not yet set. Please update the application list.');
            return false;
        }
        $uri = $this->GetUriOfSource($Applications['result'][0], $application);
        $response = $this->SendRestAPIRequest('appControl', 'setActiveApp', [['uri' => $uri]], '1.0', [], []);
        return $this->isResponseValid($response);
    }

    private function getApplicationList(): ?array
    {
        return json_decode($this->ReadAttributeString(self::ATTR_APPLICATIONLIST), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Writes the API information to a file.
     *
     * @param string $filename The name of the file to write the information to. Default is an empty string.
     *
     * @return bool Returns true if the API information is successfully written to the file, false otherwise.
     * @throws \JsonException
     */
    public function WriteAPIInformationToFile(string $filename = ''): bool
    {
        $response = $this->SendRestAPIRequest('system', 'getSystemInformation', [], '1.0', [], []);
        if (!$response) {
            return false;
        }

        if ($filename === '') {
            $filename = IPS_GetLogDir() . 'Sony ' . json_decode($response, true, 512, JSON_THROW_ON_ERROR)['result'][0]['model'] . '.txt';
        }

        $fileContent = PHP_EOL . 'SystemInformation: ' . $response . PHP_EOL . PHP_EOL;

        //$response = $this->SendRestAPIRequest('guide', 'getSupportedApiInfo', ['services' => ['system']], '1.0');
        $response = $this->SendRestAPIRequest('guide', 'getServiceProtocols', [], '1.0', [], []);

        if ($response) {
            $arr = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            foreach ($arr['results'] as $service) {
                $this->ListAPIInfoOfService($service[0], $fileContent);
            }
        }

        $this->Logger_Inf('Writing API Information to \'' . $filename . '\'');


        return file_put_contents($filename, $fileContent) > 0;
    }


    /**
     * Updates the application list and writes it to the attribute and list profile.
     *
     * @return bool true if the application list was successfully updated, false otherwise.
     * @throws \JsonException if JSON decoding fails.
     *
     */
    private function UpdateApplicationList(): bool
    {
        $applicationList = $this->getJsonApplicationList();
        if ($applicationList === null) {
            return false;
        }

        $applicationListJson = json_encode($applicationList, JSON_THROW_ON_ERROR);
        $this->WriteAttributeString(self::ATTR_APPLICATIONLIST, $applicationListJson);
        $this->WriteListProfile(self::PROFILE_APPLICATIONS, $applicationListJson, 'title');
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

    private function getJsonApplicationList(): ?array
    {
        $response = $this->SendRestAPIRequest('appControl', 'getApplicationList', [], '1.0', [], []);
        if ($response === false) {
            return null;
        }
        $json_a = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: ' . $response);
            return null;
        }
        return $json_a['result'][0];
    }

    //
    // private functions for internal use
    //
    /**
     * @throws \JsonException
     */
    private function GetVolume(): void
    {
        if (IPS_GetInstance($this->InstanceID)['InstanceStatus'] !== IS_ACTIVE) {
            return;
        }

        $response = $this->SendRestAPIRequest('audio', 'getVolumeInformation', [], '1.0', [], []);

        if ($response === false) {
            return;
        }

        $json_a = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: ' . $response);
            return;
        }

        foreach ($json_a['result'][0] as $target) {
            switch ($target['target']) {
                case 'speaker':
                    $this->SetValue('AudioMute', $target['mute']);
                    $this->SetValue('SpeakerVolume', $target['volume']);
                    break;

                case 'headphone':
                    $this->SetValue('AudioMute', $target['mute']);
                    $this->SetValue('HeadphoneVolume', $target['volume']);
                    break;

                default:
                    trigger_error('Unerwarteter Target: ' . $target['target']);

                    break;
            }
        }
    }

    /**
     * @throws \JsonException
     */
    private function GetInputSource(): void
    {
        if (IPS_GetInstance($this->InstanceID)['InstanceStatus'] !== IS_ACTIVE) {
            return;
        }

        $response = $this->SendRestAPIRequest(
            'avContent', 'getPlayingContentInfo', [], '1.0', [], [self::SYSTEM_ERROR_ILLEGAL_STATE, self::SYSTEM_ERROR_FORBIDDEN]
        );

        if ($response === false) {
            $this->SetValue(self::VAR_IDENT_INPUT_SOURCE, -1);
            return;
        }

        $Sources = json_decode($this->ReadAttributeString(self::ATTR_SOURCELIST), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($Sources)) {
            return;
        }

        $json_a = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($json_a['result'])) {
            $this->SetValue(self::VAR_IDENT_INPUT_SOURCE, -1);
            return;
        }

        foreach ($Sources as $key => $source) {
            if ($source['uri'] === $json_a['result'][0]['uri']) {
                $this->SetValue(self::VAR_IDENT_INPUT_SOURCE, $key);
                $this->SetValue('Application', -1);
            }
        }
    }

    /**
     * @throws \JsonException
     */
    private function GetPowerStatus(): ?int
    {
        $IP = $this->ReadPropertyString(self::PROP_HOST);

        $this->Logger_Dbg(__FUNCTION__, 'Start ...');

        for ($i = 1; $i <= 10; $i++) {
            $connected = @Sys_Ping($IP, 5000);
            $this->Logger_Dbg(__FUNCTION__, sprintf('Connected (%s. Versuch): %s', $i, $connected ? 'true' : 'false'));

            if ($connected || ($this->GetStatus() !== IS_ACTIVE)) {
                break;
            }
            //next try if the current status is active
        }

        if (!$connected) {
            $PowerStatus = 0;
        } else {
            $ret = $this->SendRestAPIRequest('system', 'getPowerStatus', [], '1.0', [CURLE_OPERATION_TIMEDOUT], [self::HTTP_ERROR_NOT_FOUND]);

            if ($ret === false) {
                // zweiter Versuch, da ab und zu eine Abfrage schon mal scheitert
                sleep(3);
                $ret = $this->SendRestAPIRequest('system', 'getPowerStatus', [], '1.0', [CURLE_OPERATION_TIMEDOUT], [self::HTTP_ERROR_NOT_FOUND]);
            }

            if ($ret === false) {
                $this->SetBuffer(self::BUF_TS_LASTFAILEDGETPOWERSTATE, (string)time());
                $this->Logger_Dbg(__FUNCTION__, sprintf('Connected, but getPowerStatus failed at %s', date(DATE_RSS)));
                $PowerStatus = 0;
            } else {
                $json_a = json_decode($ret, true, 512, JSON_THROW_ON_ERROR);

                if (isset($json_a['error'])) {
                    $this->SetBuffer(self::BUF_TS_LASTFAILEDGETPOWERSTATE, (string)time());
                    return null;
                }

                if (!isset($json_a['result'])) {
                    trigger_error('Unexpected return: ' . $ret);
                    return null;
                }

                //während der Bootphase ist der status zunächst nicht korrekt (immer 'active') und wird daher anhand von 'getPlayingContentInfo' überprüft
                $tsLastFailedGetBufferPowerState = (int)$this->GetBuffer(self::BUF_TS_LASTFAILEDGETPOWERSTATE);
                $status                          = $json_a['result'][0]['status'];

                if (($status === 'active') && (time() - $tsLastFailedGetBufferPowerState) <= self::LENGTH_OF_BOOTTIME) {
                    $response = $this->SendRestAPIRequest(
                        'avContent',
                        'getPlayingContentInfo',
                        [],
                        '1.0',
                        [CURLE_OPERATION_TIMEDOUT],
                        [self::SYSTEM_ERROR_ILLEGAL_STATE, self::SYSTEM_ERROR_FORBIDDEN]
                    );
                    if ($response !== false) {
                        $json_response = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
                    }
                    if (($response === false)
                        || (isset($json_response['error'])
                            && ($json_response['error'][0] === self::SYSTEM_ERROR_ILLEGAL_STATE))) {
                        $this->Logger_Dbg(
                            __FUNCTION__,
                            sprintf(
                                'Bootphase noch nicht abgeschlossen: %ss (%ss)',
                                (time() - $tsLastFailedGetBufferPowerState),
                                self::LENGTH_OF_BOOTTIME
                            )
                        );
                        return null;
                    }
                }

                switch ($json_a['result'][0]['status']) {
                    case 'standby':
                        $PowerStatus = 1;
                        break;
                    case 'active':
                        $PowerStatus = 2;
                        break;
                    default:
                        $PowerStatus = 0;
                        trigger_error('Unexpected status: ' . $json_a['result'][0]['status']);
                }
            }
        }
        $this->SetValue('PowerStatus', $PowerStatus); // 0-AUS, 1-Standby, 2-Active

        if ($PowerStatus === 0) {
            $this->SetStatus(IS_INACTIVE);
        } else {
            $this->SetStatus(IS_ACTIVE);
        }

        return $PowerStatus;
    }

    private function GetUriOfSource($Sources, $Name): string
    {
        foreach ($Sources as $source) {
            if ($source['title'] === $Name) {
                return $source['uri'];
            }
        }

        return '';
    }

    /**
     * This method is used to retrieve the Ircc Code by its `name` from the provided codes array.
     *
     * @param array $codes The array of codes.
     * @param string $name The name of the Ircc Code to fetch its corresponding value.
     *
     * @return string The IRCC code value corresponding to the given name. Returns an empty string if the name is not found in the codes array.
     */
    private function getIrccCodeByName(array $codes, string $name): string
    {
        foreach ($codes as $code) {
            if ($code['name'] === $name) {
                return $code['value'];
            }
        }

        return '';
    }
    /**
     * @throws \JsonException
     */
    private function ListAPIInfoOfService($servicename, &$return): void
    {
        $return .= 'Service: ' . $servicename . PHP_EOL;
        // der Service 'Contentshare' hat wohl keine Funktionen → 404 wird ignoriert
        $response = $this->SendRestAPIRequest($servicename, 'getMethodTypes', [''], '1.0', [], [self::HTTP_ERROR_NOT_FOUND]);
        if ($response) {
            $results = $this->getResults($response);
            foreach ($results as $api) {
                if (!in_array($api[0], ['getMethodTypes', 'getVersions'])) {
                    $params = $this->ListParams($api[1]);

                    $returns = count($api[2]) > 0 ? ': ' . $api[2][0] : '';

                    $return .= '   ' . $api[0] . '(' . $params . ')' . $returns . ' - Version: ' . $api[3] . PHP_EOL;
                }
            }
            $return .= PHP_EOL;
        }
    }

    private function getResults($response): array {
        $arr = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        if (isset($arr['result']))
            return $arr['result'];

        return $arr['results'] ?? [];
    }

    private function ListParams(array $arrParams): string
    {
        return implode(', ', $arrParams);
    }


    private function SendCurlPost(
        string $tvip,
        string $service,
        array $headers,
        string $data,
        bool $ignoreResponse,
        array $ignoredCurlErrors,
        array $ignoredResponseErrors
    ) {
        //$this->Logger_Dbg(__FUNCTION__, sprintf('tvip: %s, service: %s, headers: %s, data: %s, returnHeader: %s', $tvip, $service, json_encode($headers), $data_json, $returnHeader?'true':'false'));
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

        $url = 'http://' . $tvip . '/sony/' . $service;
        $ch  = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if (count($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response   = curl_exec($ch);
        $curl_errno = curl_errno($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_errno) {
            if (in_array($curl_errno, $ignoredCurlErrors, true)) {
                $this->Logger_Dbg(
                    __FUNCTION__,
                    sprintf(
                        'Curl call of \'%s\' with data \'%s\' returned with \'%s\': %s (ignored Errors: %s)',
                        $url,
                        $data,
                        $curl_errno,
                        $curl_error,
                        json_encode($ignoredCurlErrors, JSON_THROW_ON_ERROR)
                    )
                );
            } else {
                $this->Logger_Inf(
                    sprintf(
                        'Curl call of \'%s\' with data \'%s\' returned with \'%s\': %s (ignored Errors: %s)',
                        $url,
                        $data,
                        $curl_errno,
                        $curl_error,
                        json_encode($ignoredCurlErrors, JSON_THROW_ON_ERROR)
                    )
                );
            }
            return false;
        }

        $this->Logger_Dbg(__FUNCTION__, 'received:' . $response);

        if ($ignoreResponse) {
            return true;
        }
        try {
            $json_a = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->Logger_Err(
                sprintf('json_decode returned with \'%s\': %s<br>service : %s,  postfields: %s', $e->getMessage(), $response, $service, $data)
            );
            return false;
        }

        if (isset($json_a['error']) && !in_array($json_a['error'][0], $ignoredResponseErrors, true)) {
            $this->Logger_Inf(
                sprintf(
                    'TV replied with error \'%s\' to the data \'%s\' (ignored Errors: %s)',
                    implode(', ', $json_a['error']),
                    $data,
                    json_encode($ignoredResponseErrors, JSON_THROW_ON_ERROR)
                )
            );
            return false;
        }

        return $response;
    }

    /**
     * Send a remote key command to the device.
     *
     * @param string $name The name of the remote key command.
     *
     * @return bool Returns true if the remote key command was successfully sent, false otherwise.
     *
     * @throws \JsonException Throws an exception if there is an error parsing the JSON response.
     */
    public function SendRemoteKey(string $name): bool
    {
        $remoteControllerInfo = json_decode($this->ReadAttributeString(self::ATTR_REMOTECONTROLLERINFO), true, 512, JSON_THROW_ON_ERROR);

        if ($remoteControllerInfo === null) {
            trigger_error('Remote Controller Info not yet set. Please repeat the registration');
            return false;
        }

        $irccCode = $this->getIrccCodeByName($remoteControllerInfo, $name);
        if ($irccCode === '') {
            trigger_error('Invalid RemoteKey');
        }

        $data = $this->getXMLEnvelopeData($irccCode);
        $headers = $this->getHeadersArray(strlen($data));

        $ret = $this->SendCurlPost($this->ReadPropertyString(self::PROP_HOST), 'IRCC', $headers, $data, true, [], []);

        return !($ret === false);
    }

    private function getXMLEnvelopeData(string $irccCode): string
    {
        $data = '<?xml version="1.0"?>';
        $data .= '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">';
        $data .= '   <s:Body>';
        $data .= '      <u:X_SendIRCC xmlns:u="urn:schemas-sony-com:service:IRCC:1">';
        $data .= '         <IRCCCode>' . $irccCode . '</IRCCCode>';
        $data .= '      </u:X_SendIRCC>';
        $data .= '   </s:Body>';
        $data .= '</s:Envelope>';

        return $data;
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
     * @throws \JsonException
     */
    public function SendRestAPIRequest(string $service, string $method, $params, string $version, $ignoredCurlErrors, $ignoredErrors)
    {
        $this->Logger_Dbg(__FUNCTION__, sprintf('service: %s, message: %s, params: %s, version: %s', $service, $method,
                                                json_encode($params, JSON_THROW_ON_ERROR), $version));

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
        $headers[] = 'Content-Type: text/xml; charset=UTF-8';
        $headers[] = 'application/json; charset=UTF-8';
        $headers[] = 'Pragma: no-cache';
        $headers[] = 'X-Auth-PSK: ' . $this->ReadPropertyString(self::PROP_PSK);
        $headers[] = 'Content-Length: ' . strlen($data);

        return $headers;
    }

    /**
     * @throws \JsonException
     */
    private function GetSourceListInfo(): bool
    {
        $response = $this->SendRestAPIRequest('avContent', 'getCurrentExternalInputsStatus', [], '1.0', [], []);

        if (!$this->isResponseValid($response)) {
            return false;
        }

        $sourceList = $this->createSourceList($response);
        $this->updateSourceList($sourceList, 'STV.Sources', 'title');

        return true;
    }

    private function createSourceList($response): array
    {
        $json_a = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        $sourceList = [];
        foreach ($json_a['result'][0] as $result) {
            if (in_array(explode('?', $result['uri'])[0], ['extInput:hdmi', 'extInput:composite', 'extInput:component'], true)) { //physical inputs
                $sourceList[] = ['title' => $result['title'], 'uri' => $result['uri']];
            }
        }
        return $sourceList;
    }

    private function updateSourceList(array $sourceList, string $profile, string $property): void
    {
        $jsonSourceList = json_encode($sourceList, JSON_THROW_ON_ERROR);
        $this->WriteAttributeString(self::ATTR_SOURCELIST, $jsonSourceList);
        $this->WriteListProfile($profile, $jsonSourceList, $property);
        $this->Logger_Dbg(__FUNCTION__, 'SourceList: ' . $jsonSourceList);
    }
    /**
     * @throws \JsonException
     */
    private function GetRemoteControllerInfo(): bool
    {
        $response = $this->SendRestAPIRequest('system', 'getRemoteControllerInfo', [], '1.0', [], []);

        if ($response === false) {
            trigger_error('GetRemoteControllerInfo failed!');
            return false;
        }

        $json_a = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: ' . $response);
            return false;
        }

        $response = json_encode($json_a['result'][1], JSON_THROW_ON_ERROR);
        $this->WriteAttributeString(self::ATTR_REMOTECONTROLLERINFO, $response);

        $this->WriteListProfile('STV.RemoteKey', $response, 'name');

        $this->Logger_Dbg(__FUNCTION__, 'RemoteControllerInfo: ' . json_encode($response, JSON_THROW_ON_ERROR));

        return true;
    }

    /**
     * @throws \JsonException
     */
    private function WriteListProfile(string $ProfileName, string $jsonList, string $elementName = ''): void
    {
        $list = json_decode($jsonList, true, 512, JSON_THROW_ON_ERROR);

        $ass[] = [-1, '-', '', -1];
        foreach ($list as $key => $listElement) {
            $ass[] = [$key, html_entity_decode($listElement[$elementName]), '', -1];
        }

        if (count($ass) > self::MAX_PROFILE_ASSOCIATIONS) {
            $this->Logger_Inf(
                __FUNCTION__ . ': Die maximale Anzahl Assoziationen (' . self::MAX_PROFILE_ASSOCIATIONS
                . ') wurde überschritten. Folgende Einträge wurden nicht in das Profil \'' . $ProfileName . '\' übernommen: ' . PHP_EOL . implode(
                    ', ',
                    array_column(array_slice($ass, self::MAX_PROFILE_ASSOCIATIONS - count($ass)), 1)
                )
            );
        }

        $this->CreateProfileIntegerAss($ProfileName, '', '', '', 0, array_slice($ass, 0, self::MAX_PROFILE_ASSOCIATIONS));
    }

    /*
        private function ExtractAndSaveCookie($return): bool
        {
            $CookieFound = false;
            [$headers] = explode("\r\n\r\n", $return, 2);
            $headers = explode("\n", $headers);
            if (count($headers) === 0) {
                trigger_error('Unerwarteter Header: ' . $return);
            }

            $Cookie = [];
            foreach ($headers as $SetCookie) {
                if (stripos($SetCookie, 'Set-Cookie:') !== false) {
                    // Beispiele:
                    // Set-Cookie: auth=246554AA89E869DCD1FFC5F8C726AF5803F3AC6A; Path=/sony/; Max-Age=1209600; Expires=Do., 26 Apr. 2018 14:31:14 GMT+00:00
                    // Set-Cookie: auth=5c62b5874a067cecc1561803d08d5090c9a8724b8e1413a3aedc06c289326cad; path=/sony/; max-age=1209600; expires=Sat, 05-May-2018 09:45:38 GMT;
                    $arr                      = $this->GetCookieElements(substr($SetCookie, strlen('Set-Cookie: ')));
                    $Cookie['auth']           = $arr['auth'];
                    $Cookie['ExpirationDate'] = time() + $arr['max-age'];
                    $this->WriteAttributeString(self::ATTR_COOKIE, json_encode($Cookie, JSON_THROW_ON_ERROR, 512));
                    $CookieFound = true;
                    break;
                }
            }

            $this->Logger_Dbg(__FUNCTION__, 'Cookie: ' . json_encode($Cookie, JSON_THROW_ON_ERROR, 512));
            return $CookieFound;
        }

        private function GetCookieElements($SetCookie): array
        {
            $ret      = [];
            $elements = explode(';', $SetCookie);
            foreach ($elements as $element) {
                $expl = explode('=', $element);
                if (count($expl) === 2) {
                    $ret[strtolower(trim($expl[0]))] = $expl[1];
                }
            }

            return $ret;
        }
    */

    /*
       private function GetAuthorizationParams(): array
       {
           $Nickname = $this->ReadPropertyString('Nickname');
           $uuid     = $this->ReadAttributeString(self::ATTR_UUID);

           return [
               [
                   'clientid' => $uuid,
                   'nickname' => $Nickname,
                   'level'    => 'private'
               ],
               [
                   [
                       'function' => 'WOL',
                       'value'    => 'yes'
                   ]
               ]
           ];
       }
   */
    private function CheckProfileType($ProfileName, $VarType): void
    {
        $profile = IPS_GetVariableProfile($ProfileName);
        if ($profile['ProfileType'] !== $VarType) {
            trigger_error(
                'Variable profile type does not match for already existing profile "' . $ProfileName
                . '". The existing profile has to be deleted manually.'
            );
        }
    }

    private function CreateProfileInteger($ProfileName, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $StepSize, $Digits): void
    {
        if (!IPS_VariableProfileExists($ProfileName)) {
            IPS_CreateVariableProfile($ProfileName, 1);

            $this->Logger_Inf('Variablenprofil angelegt: ' . $ProfileName);
        } else {
            $this->CheckProfileType($ProfileName, VARIABLETYPE_INTEGER);
        }

        IPS_SetVariableProfileIcon($ProfileName, $Icon);
        IPS_SetVariableProfileText($ProfileName, $Prefix, $Suffix);
        IPS_SetVariableProfileDigits($ProfileName, $Digits); //  Nachkommastellen
        IPS_SetVariableProfileValues($ProfileName, $MinValue, $MaxValue, $StepSize);
    }

    /**
     * @throws \JsonException
     */
    private function CreateProfileIntegerAss($ProfileName, $Icon, $Prefix, $Suffix, $Digits, $Associations): void
    {
        if (count($Associations) === 0) {
            trigger_error(__FUNCTION__ . ': Associations of profil "' . $ProfileName . '" is empty');
            $this->Logger_Err(json_encode(debug_backtrace(), JSON_THROW_ON_ERROR));

            return;
        }

        $MinValue = $Associations[0][0];
        $MaxValue = $Associations[count($Associations) - 1][0];

        $this->CreateProfileInteger($ProfileName, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, 0, $Digits);

        //zunächst werden alte Assoziationen gelöscht
        //bool IPS_SetVariableProfileAssociation ( string $ProfilName, float $Wert, string $Name, string $Icon, integer $Farbe )
        foreach (IPS_GetVariableProfile($ProfileName)['Associations'] as $Association) {
            IPS_SetVariableProfileAssociation($ProfileName, $Association['Value'], '', '', -1);
        }

        //dann werden die aktuellen eingetragen
        foreach ($Associations as $Association) {
            IPS_SetVariableProfileAssociation($ProfileName, $Association[0], $Association[1], '', -1);
        }
    }

    private function RegisterProperties(): void
    {
        //Properties, die im Konfigurationsformular gesetzt werden können
        $this->RegisterPropertyString(self::PROP_HOST, '');
        $this->RegisterPropertyString(self::PROP_PSK, '0000');
        $this->RegisterPropertyInteger('UpdateInterval', 10);

        $this->RegisterPropertyBoolean('WriteLogInformationToIPSLogger', false);
        $this->RegisterPropertyBoolean('WriteDebugInformationToLogfile', false);
        $this->RegisterPropertyBoolean('WriteDebugInformationToIPSLogger', false);
    }

    private function RegisterAttributes(): void
    {
        $this->RegisterAttributeString(self::ATTR_UUID, uniqid('', true));
        $this->RegisterAttributeString(self::ATTR_REMOTECONTROLLERINFO, json_encode(null, JSON_THROW_ON_ERROR));
        $this->RegisterAttributeString(self::ATTR_SOURCELIST, json_encode([], JSON_THROW_ON_ERROR));
        $this->RegisterAttributeString(self::ATTR_APPLICATIONLIST, json_encode([], JSON_THROW_ON_ERROR));
    }

    /**
     * @throws \JsonException
     */
    private function RegisterVariables(): void
    {
        if (!IPS_VariableProfileExists(self::PROFILE_POWERSTATUS)) {
            $this->CreateProfileIntegerAss(
                self::PROFILE_POWERSTATUS, 'Power', '', '', 0, [
                                             [0, 'Ausgeschaltet', '', -1],
                                             [1, 'Standby', '', -1],
                                             [2, 'Eingeschaltet', '', -1]
                                         ]
            );
        }

        if (!IPS_VariableProfileExists(self::PROFILE_REMOTEKEY)) {
            $this->WriteListProfile(self::PROFILE_REMOTEKEY, '[]');
        }
        if (!IPS_VariableProfileExists(self::PROFILE_SOURCES)) {
            $this->WriteListProfile(self::PROFILE_SOURCES, '[]');
        }
        if (!IPS_VariableProfileExists(self::PROFILE_APPLICATIONS)) {
            $this->WriteListProfile(self::PROFILE_APPLICATIONS, '[]');
        }

        if (!IPS_VariableProfileExists(self::PROFILE_VOLUME)) {
            $this->CreateProfileInteger(
                'STV.Volume',
                'Intensity',
                '',
                ' %',
                0,
                100,
                1,
                1
            );
        }

        $this->RegisterVariableInteger('PowerStatus', 'Status', self::PROFILE_POWERSTATUS, 10);
        $this->RegisterVariableBoolean('AudioMute', 'Mute', '~Switch', 20);
        $this->RegisterVariableInteger('SpeakerVolume', 'Lautstärke Lautsprecher', self::PROFILE_VOLUME, 30);
        $this->RegisterVariableInteger('HeadphoneVolume', 'Lautstärke Kopfhörer', self::PROFILE_VOLUME, 40);
        $this->RegisterVariableInteger('SendRemoteKey', 'Sende FB Taste', self::PROFILE_REMOTEKEY, 50);
        $this->RegisterVariableInteger(self::VAR_IDENT_INPUT_SOURCE, 'Eingangsquelle', self::PROFILE_SOURCES, 60);
        $this->RegisterVariableInteger('Application', 'Starte Applikation', self::PROFILE_APPLICATIONS, 70);

        // Aktivieren der Statusvariablen
        $this->EnableAction('PowerStatus');
        $this->EnableAction('AudioMute');
        $this->EnableAction('SendRemoteKey');
        $this->EnableAction(self::VAR_IDENT_INPUT_SOURCE);
        $this->EnableAction('Application');
        $this->EnableAction('SpeakerVolume');
        $this->EnableAction('HeadphoneVolume');
    }


    private function SetInstanceStatus(): void
    {
        $ip = $this->ReadPropertyString(self::PROP_HOST);
        $this->SetStatus($this->determineStatus($ip));
    }

    private function determineStatus($ip): int
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return self::STATUS_INST_IP_IS_INVALID; // Invalid IP address
        }

        if ($ip === '') {
            return self::STATUS_INST_IP_IS_EMPTY;
        }

        return $this->GetPowerStatus() > 0 ? IS_ACTIVE : IS_INACTIVE;
    }
    private function Logger_Err(string $message): void
    {
        $this->SendDebug('LOG_ERR', $message, 0);
        if (function_exists('IPSLogger_Err') && $this->ReadPropertyBoolean('WriteLogInformationToIPSLogger')) {
            IPSLogger_Err(__CLASS__, $message);
        }

        $this->LogMessage($message, KL_ERROR);
        //$this->SetValue(self::VAR_IDENT_LAST_MESSAGE, $message);
    }

    private function Logger_Inf(string $message): void
    {
        $this->SendDebug('LOG_INFO', $message, 0);
        if (function_exists('IPSLogger_Inf') && $this->ReadPropertyBoolean('WriteLogInformationToIPSLogger')) {
            IPSLogger_Inf(__CLASS__, $message);
        } else {
            $this->LogMessage($message, KL_NOTIFY);
        }
        //$this->SetValue(self::VAR_IDENT_LAST_MESSAGE, $message);
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
     * Unregister a variable profile.
     *
     * @param string $Name The name of the variable profile to unregister.
     *
     * @return void
     * @throws \JsonException
     */
    private function UnregisterProfile(string $Name): void
    {
        if (!IPS_VariableProfileExists($Name) || $this->IsProfileInVariableList($Name) || $this->IsProfileInMediaList($Name)) {
            return;
        }

        // Delete the profile only if it's not used anywhere
        IPS_DeleteVariableProfile($Name);
    }

    private function IsProfileInVariableList(string $ProfileName): bool
    {
        $instanceID = $this->InstanceID;

        foreach (IPS_GetVariableList() as $VarID) {
            if (IPS_GetParent($VarID) === $instanceID || IPS_GetVariable($VarID)['VariableCustomProfile'] === $ProfileName || IPS_GetVariable($VarID)['VariableProfile'] === $ProfileName) {
                return true;
            }
        }
        return false;
    }

    private function IsProfileInMediaList(string $ProfileName): bool
    {
        foreach (IPS_GetMediaListByType(MEDIATYPE_CHART) as $mediaID) {
            $content = json_decode(base64_decode(IPS_GetMediaContent($mediaID)), true, 512, JSON_THROW_ON_ERROR);
            foreach ($content['axes'] as $axis) {
                if ($axis['profile'] === $ProfileName) {
                    return true;
                }
            }
        }
        return false;
    }

    private function MsgBox(string $Message): void
    {
        $this->UpdateFormField('MsgText', 'caption', $Message);

        $this->UpdateFormField('MsgBox', 'visible', true);
    }

}
