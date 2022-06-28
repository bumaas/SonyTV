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

    private const SYSTEM_ERROR_ILLEGAL_STATE = 7;
    private const HTTP_ERROR_NOT_FOUND = 404;

    // Überschreibt die interne IPS_Create($id) Funktion
    public function Create()
    {
        // Diese Zeile nicht löschen.
        parent::Create();

        $this->RegisterProperties();
        $this->RegisterAttributes();

        $this->RegisterTimer('Update', 0, 'STV_UpdateAll(' . $this->InstanceID . ');');
    }

    /**
     * @throws \JsonException
     */
    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $TimerInterval = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('Update', $TimerInterval * 1000);
        $this->Logger_Inf('TimerInterval set to ' . $TimerInterval . 's.');

        $this->RegisterVariables();

        $this->SetInstanceStatus();

        if ($this->GetStatus() === IS_ACTIVE){
            //RemoteController Informationen auslesen und in Profil schreiben
            if (!$this->GetRemoteControllerInfo()) {
                return;
            }

            //Sources auslesen und in Profil schreiben
            if (!$this->GetSourceListInfo()) {
                return;
            }

            //Applikationen auslesen und in Profil schreiben
            if (!$this->UpdateApplicationList()) {
                return;
            }

        }

        $this->SetSummary($this->ReadPropertyString(self::PROP_HOST));
    }

    /**
     * @throws \JsonException
     */
    public function RequestAction($Ident, $Value)
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

            case 'InputSource':
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

            default:
                trigger_error('Unexpected ident: ' . $Ident);
        }
    }

    /**
     * Die folgenden Funktionen stehen automatisch zur Verfügung, wenn das Modul über die "Module Control" eingefügt wurden.
     * Die Funktionen werden, mit dem selbst eingerichteten Prefix, in PHP und JSON-RPC wie folgt zur Verfügung gestellt:.
     *
     * @throws \JsonException
     */
/*
    public function StartRegistration(): ?bool
    {
        // use only A-Z a-z 0-9 for device. Probably. Haven't checked.
        // Start anlernen

        $ret = $this->callPostRequest('accessControl', 'actRegister', $this->GetAuthorizationParams(), [], false, '1.0', true);

        if ($ret === false) {
            return false;
        }

        $json_a = json_decode($ret, true, 512, JSON_THROW_ON_ERROR);

        if (isset($json_a['result'])) {
            $this->Logger_Err('Die Instanz ist bereits am TV angemeldet!');
            return false;
        }

        if (isset($json_a['error'])) {
            //@noinspection DegradedSwitchInspection
            switch ($json_a['error'][0]) {
                case 401: //Unauthorized
                    return true;
                    break;
                default:
                    trigger_error('Unexpected error: ' . $ret);
                    return false;
            }
        } else {
            trigger_error('Unexpected else');
            return false;
        }
    }

    public function SendAuthorizationKey(string $TVCode): bool
    {
        $TVCode = trim($TVCode);
        if ($TVCode === '') {
            echo $this->Translate('Bitte TV Code angeben.') . ' ';
            return false;
        }

        // Key senden
        $tv_auth_header = 'Authorization: Basic ' . base64_encode(':' . $TVCode);
        $headers        = [];
        $headers[]      = $tv_auth_header;

        $ret = $this->callPostRequest('accessControl', 'actRegister', $this->GetAuthorizationParams(), $headers, true, '1.0');

        if ($ret === false) {
            return false;
        }

        //Cookie aus Header ermitteln und in Property setzen
        if (!$this->ExtractAndSaveCookie($ret)) {
            return false;
        }

        //RemoteController Infos auslesen und in Profil schreiben
        if (!$this->GetRemoteControllerInfo()) {
            return false;
        }

        //Sources auslesen und in Profil schreiben
        if (!$this->GetSourceListInfo()) {
            return false;
        }

        //Applikationen auslesen und in Profil schreiben
        if (!$this->UpdateApplicationList()) {
            return false;
        }

        return true;
    }
*/

    public function UpdateAll(): bool
    {
        // IP-Symcon Kernel ready?
        if (IPS_GetKernelRunlevel() !== KR_READY) { //Kernel ready
            $this->Logger_Dbg(__FUNCTION__, 'Kernel is not ready (' . IPS_GetKernelRunlevel() . ')');
            return false;
        }

        $IP = $this->ReadPropertyString(self::PROP_HOST);
        if ($IP !== '') {
            $PowerStatus = $this->GetPowerStatus();
            $this->Logger_Dbg(__FUNCTION__, 'PowerStatus: ' . $PowerStatus);

            switch ($PowerStatus) {
                case 0:
                case 1:
                    break;
                case 2:
                    $this->SetStatus(IS_ACTIVE);
                    $this->GetVolume();
                    $this->GetInputSource();
                    break;
                default:
                    trigger_error('Unexpected PowerStatus: ' . $PowerStatus);
            }

            return $PowerStatus > 0;
        }

        return false;
    }

    /**
     * @throws \JsonException
     */
    public function SetPowerStatus(bool $Status): void
    {
        //$ret = $this->callPostRequest('system', 'setPowerStatus', [['status' => $Status]], [], false, '1.0');
        $ret = $this->SendRestAPIRequest('system', 'setPowerStatus', [['status' => $Status]], '1.0',[28] , []);

        if ($ret !== false) {
            $json_a = json_decode($ret, true, 512, JSON_THROW_ON_ERROR);
            if (isset($json_a['result'])) {
                sleep(2); //warten bis Kommando von Sony verarbeitet wurde

                // neuen Wert abfragen
                $this->GetPowerStatus();
                return;
            }

            trigger_error('Error: ' . json_encode($json_a['error'], JSON_THROW_ON_ERROR));
        }
        $PowerStatus = 0;
        $this->SetValue('PowerStatus', $PowerStatus);
    }

    /**
     * @throws \JsonException
     */
    public function SetInputSource(string $source): bool
    {
        $Sources = json_decode($this->ReadAttributeString(self::ATTR_SOURCELIST), true, 512, JSON_THROW_ON_ERROR);

        if ($Sources === null) {
            trigger_error('Source List not yet set. Please repeat the registration');
            return false;
        }
        $uri = $this->GetUriOfSource($Sources, $source);

        $response = $this->SendRestAPIRequest('avContent', 'setPlayContent', [['uri' => $uri]], '1.0', [], []);

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
     * @throws \JsonException
     */
    public function SetAudioMute(bool $status): bool
    {
        $response = $this->SendRestAPIRequest('audio', 'setAudioMute', [['status' => $status]], '1.0', [], []);

        if ($response === false) {
            return false;
        }

        $json_a = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: ' . $response);
            return false;
        }

        $this->SetValue('AudioMute', $status);

        return true;
    }

    /**
     * @throws \JsonException
     */
    public function SetSpeakerVolume(int $volume): bool
    {
        $response = $this->SendRestAPIRequest('audio', 'setAudioVolume', [['target' => 'speaker', 'volume' => (string)$volume]], '1.0', [], []);

        if ($response === false) {
            return false;
        }

        $json_a = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: ' . $response);
            return false;
        }

        $this->SetValue('SpeakerVolume', $volume);

        return true;
    }

    /**
     * @throws \JsonException
     */
    public function SetHeadphoneVolume(int $volume): bool
    {
        $response = $this->SendRestAPIRequest(
            'audio',
            'setAudioVolume',
            [['target' => 'headphone', 'volume' => (string)$volume]],
            '1.0',
            [],
            []
        );

        if ($response === false) {
            return false;
        }

        $json_a = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: ' . $response);
            return false;
        }

        $this->SetValue('HeadphoneVolume', $volume);

        return true;
    }

    /**
     * @throws \JsonException
     */
    public function StartApplication(string $application): bool
    {
        $Applications = json_decode($this->ReadAttributeString(self::ATTR_APPLICATIONLIST), true, 512, JSON_THROW_ON_ERROR);

        if ($Applications === null) {
            trigger_error('Application List not yet set. Please update the application list.');
            return false;
        }

        $uri = $this->GetUriOfSource($Applications['result'][0], $application);

        $response = $this->SendRestAPIRequest('appControl', 'setActiveApp', [['uri' => $uri]], '1.0', [], []);

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

        $return = PHP_EOL . 'SystemInformation: ' . $response . PHP_EOL . PHP_EOL;

        //$response = $this->SendRPC('guide', 'getSupportedApiInfo', ['services' => ['system']], '1.0');
        $response = $this->SendRestAPIRequest('guide', 'getServiceProtocols', [], '1.0', [], []);

        if ($response) {
            $arr = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            foreach ($arr['results'] as $service) {
                $this->ListAPIInfoOfService($service[0], $return);
            }
        }

        $this->Logger_Inf('Writing API Information to \'' . $filename . '\'');


        return file_put_contents($filename, $return) > 0;
    }

    /**
     * @throws \JsonException
     */
    public function UpdateApplicationList(): bool
    {
        $response = $this->SendRestAPIRequest('appControl', 'getApplicationList', [], '1.0', [], []);

        if ($response === false) {
            return false;
        }

        $json_a = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: ' . $response);
            return false;
        }

        $ApplicationList = json_encode($json_a['result'][0], JSON_THROW_ON_ERROR);

        $this->WriteAttributeString(self::ATTR_APPLICATIONLIST, $response);

        $this->WriteListProfile('STV.Applications', $ApplicationList, 'title');

        $this->Logger_Dbg(__FUNCTION__, 'ApplicationList: ' . json_encode($response, JSON_THROW_ON_ERROR));

        return true;
    }

    /**
     * @throws \JsonException
     */
    public function ReadApplicationList(): string
    {
        $response = $this->SendRestAPIRequest('appControl', 'getApplicationList', [], '1.0', [], []);

        if ($response === false) {
            return '';
        }

        $json_a = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: ' . $response);
            return '';
        }

        $ApplicationList = json_encode($json_a['result'][0], JSON_THROW_ON_ERROR);

        $this->Logger_Dbg(__FUNCTION__, 'ApplicationList: ' . json_encode($response, JSON_THROW_ON_ERROR));

        return $ApplicationList;
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
    private function GetInputSource():void
    {
        if (IPS_GetInstance($this->InstanceID)['InstanceStatus'] !== IS_ACTIVE) {
            return;
        }

        $response = $this->SendRestAPIRequest('avContent', 'getPlayingContentInfo', [], '1.0', [], [self::SYSTEM_ERROR_ILLEGAL_STATE]);

        if ($response === false) {
            $this->SetValue('InputSource', -1);
            return;
        }

        $Sources = json_decode($this->ReadAttributeString(self::ATTR_SOURCELIST), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($Sources)) {
            return;
        }

        $json_a = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($json_a['result'])){
            $this->SetValue('InputSource', -1);
            return;
        }

        foreach ($Sources as $key => $source) {
            if ($source['uri'] === $json_a['result'][0]['uri']) {
                $this->SetValue('InputSource', $key);
                $this->SetValue('Application', -1);
            }
        }
    }

    /**
     * @throws \JsonException
     */
    private function GetPowerStatus(): ?int
    {
        $IP        = $this->ReadPropertyString(self::PROP_HOST);

        $connected = @Sys_Ping($IP, 20000);
        //second try if the current status is active
        if (!$connected && ($this->GetStatus() === IS_ACTIVE)) {
            $connected = @Sys_Ping($IP, 20000);
            $this->Logger_Dbg(__FUNCTION__, sprintf('connected beim 2. Versuch: %s', (int) $connected));
        }

        if (!$connected) {
            $PowerStatus = 0;
        } else {
            $ret = $this->SendRestAPIRequest('system', 'getPowerStatus', [], '1.0', [CURLE_OPERATION_TIMEDOUT], [self::HTTP_ERROR_NOT_FOUND]);

            if ($ret === false) {
                $this->SetBuffer('tsLastFailedGetBufferPowerState', (string)time());
                $PowerStatus = 0;
            } else {
                $json_a = json_decode($ret, true, 512, JSON_THROW_ON_ERROR);

                if (isset($json_a['error'])) {
                    $this->SetBuffer('tsLastFailedGetBufferPowerState', (string)time());
                    return null;
                }

                if (!isset($json_a['result'])) {
                    trigger_error('Unexpected return: ' . $ret);
                    return null;
                }

                //während der Bootphase ist der status zunächst nicht korrekt (immer 'active') und wird daher ignoriert
                $tsLastFailedGetBufferPowerState = (int)$this->GetBuffer('tsLastFailedGetBufferPowerState');
                if (($json_a['result'][0]['status'] === 'active') && (time() - $tsLastFailedGetBufferPowerState <= 90)) {
                    $response = $this->SendRestAPIRequest('avContent', 'getPlayingContentInfo', [], '1.0', [CURLE_OPERATION_TIMEDOUT], [self::SYSTEM_ERROR_ILLEGAL_STATE]);

                    if (($response === false) || (isset($response['error']) && ($response['error'][0] !== self::SYSTEM_ERROR_ILLEGAL_STATE))) {
                        $this->Logger_Dbg(__FUNCTION__, sprintf('Bootphase noch nicht abgeschlossen: %s (90)s', (time() - $tsLastFailedGetBufferPowerState)));
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

    private function GetUriOfSource($Sources, $Name)
    {
        foreach ($Sources as $source) {
            if ($source['title'] === $Name) {
                return $source['uri'];
            }
        }

        return false;
    }

    private function GetIRCCCode($codes, $Name)
    {
        foreach ($codes as $code) {
            if ($code['name'] === $Name) {
                return $code['value'];
            }
        }

        return false;
    }

    /**
     * @throws \JsonException
     */
    private function ListAPIInfoOfService($servicename, &$return): void
    {
        $return   .= 'Service: ' . $servicename . PHP_EOL;
        // der Service 'Contentshare' hat wohl keine Funktionen → 404 wird ignoriert
        $response = $this->SendRestAPIRequest($servicename, 'getMethodTypes', [''], '1.0', [], [self::HTTP_ERROR_NOT_FOUND]);
        if ($response) {
            $arr = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            if (isset($arr['result'])) {
                $results = $arr['result'];
            } elseif (isset($arr['results'])) {
                $results = $arr['results'];
            } else {
                $results = [];
            }
            foreach ($results as $api) {
                if (!in_array($api[0], ['getMethodTypes', 'getVersions'])) {
                    $params = $this->ListParams($api[1]);

                    if (count($api[2]) > 0) {
                        $returns = ': ' . $api[2][0];
                    } else {
                        $returns = '';
                    }

                    $return .= '   ' . $api[0] . '(' . $params . ')' . $returns . ' - Version: ' . $api[3] . PHP_EOL;
                }
            }
            $return .= PHP_EOL;
        }
    }

    private function ListParams(array $arrParams): string
    {
        $params = '';
        foreach ($arrParams as $key => $elem) {
            if ($key === 0) {
                $params .= $elem;
            } else {
                $params .= ', ' . $elem . ', ';
            }
        }

        return $params;
    }


    private function SendCurlPost(string $tvip, string $service, array $headers, string $data, bool $ignoreResponse, array $ignoredCurlErrors, array $ignoredResponseErrors)
    {
        //$this->Logger_Dbg(__FUNCTION__, sprintf('tvip: %s, service: %s, headers: %s, data: %s, returnHeader: %s', $tvip, $service, json_encode($headers), $data_json, $returnHeader?'true':'false'));
        $this->Logger_Dbg(__FUNCTION__, sprintf('service: %s, data: %s, ignoreResponse: %s, $ignoredCurlErrors: %s, $ignoredResponseErrors: %s', $service, $data, (int) $ignoreResponse, json_encode($ignoredCurlErrors), json_encode($ignoredResponseErrors)));

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
            if (in_array($curl_errno, $ignoredCurlErrors)){
                $this->Logger_Dbg(__FUNCTION__, sprintf('Curl call of \'%s\' with data \'%s\' returned with \'%s\': %s (ignored Errors: %s)', $url, $data, $curl_errno, $curl_error, json_encode($ignoredCurlErrors)));
            } else {
                $this->Logger_Inf(sprintf('Curl call of \'%s\' with data \'%s\' returned with \'%s\': %s (ignored Errors: %s)', $url, $data, $curl_errno, $curl_error, json_encode($ignoredCurlErrors)));
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

        if (isset($json_a['error'])){
            if (!in_array($json_a['error'][0], $ignoredResponseErrors)){
                $this->Logger_Inf(sprintf('TV replied with error \'%s\' to the data \'%s\' (ignored Errors: %s)', implode(', ', $json_a['error']), $data, json_encode($ignoredResponseErrors)));
                return false;
            }
        }

        return $response;
    }

    /**
     * @param string $Value
     *
     * @return bool
     */
    public function SendRemoteKey(string $Value): bool
    {
        $RemoteControllerInfo = json_decode($this->ReadAttributeString(self::ATTR_REMOTECONTROLLERINFO), true);


        if ($RemoteControllerInfo === null) {
            trigger_error('Remote Controler Info not yet set. Please repeat the registration');
            return false;
        }

        $IRCCCode = $this->GetIRCCCode($RemoteControllerInfo, $Value);
        if ($IRCCCode === false) {
            trigger_error('Invalid RemoteKey');
        }

        $data = '<?xml version="1.0"?>';
        $data .= '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">';
        $data .= '   <s:Body>';
        $data .= '      <u:X_SendIRCC xmlns:u="urn:schemas-sony-com:service:IRCC:1">';
        $data .= '         <IRCCCode>' . $IRCCCode . '</IRCCCode>';
        $data .= '      </u:X_SendIRCC>';
        $data .= '   </s:Body>';
        $data .= '</s:Envelope>';

        $headers = [];
        $headers[] = 'X-Auth-PSK: ' . $this->ReadPropertyString(self::PROP_PSK);
        $headers[] = 'Content-Type: text/xml; charset=UTF-8';
        $headers[] = 'Content-Length: ' . strlen($data);
        $headers[] = 'SOAPAction: "urn:schemas-sony-com:service:IRCC:1#X_SendIRCC"';

        $ret = $this->SendCurlPost($this->ReadPropertyString(self::PROP_HOST), 'IRCC', $headers, $data, true, [], []);

        return !($ret === false);
    }


    /**
     * @throws \JsonException
     */
    public function SendRestAPIRequest(string $service, string $method, $params, string $version, $ignoredCurlErrors, $ignoredErrors)
    {
        //$this->Logger_Dbg(__FUNCTION__, sprintf('tvip: %s, service: %s, headers: %s, data: %s, returnHeader: %s', $tvip, $service, json_encode($headers), $data_json, $returnHeader?'true':'false'));
        $this->Logger_Dbg(__FUNCTION__, sprintf('service: %s, message: %s, params: %s, id: %s', $service, $method, json_encode($params), $version));

        $data = json_encode(
            [
                'method'  => $method,
                'params'  => $params,
                'id'      => $this->InstanceID,
                'version' => $version
            ],
            JSON_THROW_ON_ERROR
        );

        $headers = [];
        $headers[] = 'Accept: */*';
        $headers[] = 'Cache-Control: no-cache';
        $headers[] = 'Connection: close';
        $headers[] = 'Content-Type: text/xml; charset=UTF-8';
        $headers[] = 'application/json; charset=UTF-8';
        $headers[] = 'Pragma: no-cache';
        $headers[] = 'X-Auth-PSK: ' . $this->ReadPropertyString(self::PROP_PSK);
        $headers[] = 'Content-Length: ' . strlen($data);

        return $this->SendCurlPost($this->ReadPropertyString(self::PROP_HOST), $service, $headers, $data, false, $ignoredCurlErrors, $ignoredErrors);

    }

    /**
     * @throws \JsonException
     */
    private function GetSourceListInfo(): bool
    {
        $response = $this->SendRestAPIRequest('avContent', 'getSourceList', [['scheme' => 'extInput']], '1.0', [], []);

        if ($response === false) {
            return false;
        }

        $json_a = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: ' . $response);
            return false;
        }

        $SourceList = [];
        foreach ($json_a['result'][0] as $result) {
            if (in_array($result['source'], ['extInput:hdmi', 'extInput:composite', 'extInput:component'])) {//physical inputs
                $response = $this->SendRestAPIRequest('avContent', 'getContentList', [['uri' => $result['source']]], '1.5', [], []);

                if ($response === false) {
                    return false;
                }

                $json_a = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

                if (!isset($json_a['result'])) {
                    trigger_error('Unexpected return: ' . $response);
                    return false;
                }

                $SourceList[] = $json_a['result'][0];
            }
        }

        $SourceList = array_merge(...$SourceList);

        $response = json_encode($SourceList, JSON_THROW_ON_ERROR);

        $this->WriteAttributeString(self::ATTR_SOURCELIST, $response);

        $this->WriteListProfile('STV.Sources', $response, 'title');

        $this->Logger_Dbg(__FUNCTION__, 'SourceList: ' . json_encode($response, JSON_THROW_ON_ERROR));

        return true;
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
            $this->Logger_Inf(__FUNCTION__ . ': Die maximale Anzahl Assoziationen (' . self::MAX_PROFILE_ASSOCIATIONS . ') wurde überschritten. Folgende Einträge wurden nicht in das Profil \'' . $ProfileName
                 . '\' übernommen: ' . PHP_EOL . implode(', ', array_column(array_slice($ass, self::MAX_PROFILE_ASSOCIATIONS - count($ass)), 1))
            );
        }

        $this->CreateProfileIntegerAss($ProfileName, '', '', '', 1, 0, array_slice($ass, 0, self::MAX_PROFILE_ASSOCIATIONS));
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
    private function CreateProfileIntegerAss($ProfileName, $Icon, $Prefix, $Suffix, $StepSize, $Digits, $Associations): void
    {
        if (count($Associations) === 0) {
            trigger_error(__FUNCTION__ . ': Associations of profil "' . $ProfileName . '" is empty');
            $this->Logger_Err(json_encode(debug_backtrace(), JSON_THROW_ON_ERROR));

            return;
        }

        $MinValue = $Associations[0][0];
        $MaxValue = $Associations[count($Associations) - 1][0];

        $this->CreateProfileInteger($ProfileName, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $StepSize, $Digits);

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
        $this->RegisterAttributeString(self::ATTR_REMOTECONTROLLERINFO, '');
        $this->RegisterAttributeString(self::ATTR_SOURCELIST, '');
        $this->RegisterAttributeString(self::ATTR_APPLICATIONLIST, '');
    }

    /**
     * @throws \JsonException
     */
    private function RegisterVariables(): void
    {
        if (!IPS_VariableProfileExists('STV.PowerStatus')) {
            $this->CreateProfileIntegerAss(
                'STV.PowerStatus',
                'Power',
                '',
                '',
                1,
                0,
                [
                    [0, 'Ausgeschaltet', '', -1],
                    [1, 'Standby', '', -1],
                    [2, 'Eingeschaltet', '', -1]
                ]
            );
        }

        if (!IPS_VariableProfileExists('STV.RemoteKey')) {
            $this->WriteListProfile('STV.RemoteKey', '[]');
        }
        if (!IPS_VariableProfileExists('STV.Sources')) {
            $this->WriteListProfile('STV.Sources', '[]');
        }
        if (!IPS_VariableProfileExists('STV.Applications')) {
            $this->WriteListProfile('STV.Applications', '[]');
        }

        if (!IPS_VariableProfileExists('STV.Volume')) {
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

        $this->RegisterVariableInteger('PowerStatus', 'Status', 'STV.PowerStatus', 10);
        $this->RegisterVariableBoolean('AudioMute', 'Mute', '~Switch', 20);
        $this->RegisterVariableInteger('SpeakerVolume', 'Lautstärke Lautsprecher', 'STV.Volume', 30);
        $this->RegisterVariableInteger('HeadphoneVolume', 'Lautstärke Kopfhörer', 'STV.Volume', 40);
        $this->RegisterVariableInteger('SendRemoteKey', 'Sende FB Taste', 'STV.RemoteKey', 50);
        $this->RegisterVariableInteger('InputSource', 'Eingangsquelle', 'STV.Sources', 60);
        $this->RegisterVariableInteger('Application', 'Starte Applikation', 'STV.Applications', 70);

        // Aktivieren der Statusvariablen
        $this->EnableAction('PowerStatus');
        $this->EnableAction('AudioMute');
        $this->EnableAction('SendRemoteKey');
        $this->EnableAction('InputSource');
        $this->EnableAction('Application');
        $this->EnableAction('SpeakerVolume');
        $this->EnableAction('HeadphoneVolume');
    }

    /**
     * @throws \JsonException
     */
    private function SetInstanceStatus(): void
    {
        //IP Prüfen
        $ip = $this->ReadPropertyString(self::PROP_HOST);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            if ($ip === '') {
                $this->SetStatus(self::STATUS_INST_IP_IS_EMPTY);
            } elseif ($this->GetPowerStatus() > 0) {
                $this->SetStatus(IS_ACTIVE);
            } else {
                $this->SetStatus(IS_INACTIVE);
            }
        } else {
            $this->SetStatus(self::STATUS_INST_IP_IS_INVALID); //IP-Adresse ist ungültig
        }
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

}
