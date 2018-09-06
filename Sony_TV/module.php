<?php

/*
    hier ein paar Links, die als Grundlage dienten

API Beschreibung: https://developer.sony.com/develop/audio-control-api/hardware-overview/api-references

Return Werte: https://developer.sony.com/develop/audio-control-api/hardware-overview/error-codes

https://community.openhab.org/t/sony-devices-binding/14052/263

https://github.com/gerard33/sony-bravia/blob/master/bravia.py (no longer maintained)

https://github.com/aparraga/braviarc/blob/master/braviarc/braviarc.py

https://github.com/waynehaffenden/bravia

 */

declare(strict_types=1);

class IPSVarType extends stdClass
{

    //  API VariableTypes
    const vtNone = -1;
    const vtBoolean = 0;
    const vtInteger = 1;
    const vtFloat = 2;
    const vtString = 3;
}

// Klassendefinition
class SonyTV extends IPSModule
{

    const STATUS_INST_IP_IS_EMPTY = 202;
    const STATUS_INST_IP_IS_INVALID = 204; //IP Adresse ist ungültig

    const MAX_PROFILE_ASSOCIATIONS = 128;

    const VERSION = '1.0.0';

    // Überschreibt die interne IPS_Create($id) Funktion
    public function Create()
    {
        // Diese Zeile nicht löschen.
        parent::Create();

        $this->RegisterProperties();

        $this->RegisterTimer('Update', 0, 'STV_UpdateAll(' . $this->InstanceID . ');');
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $TimerInterval = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('Update', $TimerInterval * 1000);
        $this->LogMessage('TimerIntervall set to ' . $TimerInterval . 's.', KL_NOTIFY);

        $this->RegisterVariables();

        $this->SetInstanceStatus();
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'PowerStatus':
                $this->SetPowerStatus($Value == 2);

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
     */
    public function StartRegistration()
    {
        // use only A-Z a-z 0-9 for device. Probably. Havent checked.
        // Start anlernen

        $ret = $this->callPostRequest('accessControl', 'actRegister', $this->GetAuthorizationParams(), [], false, '1.0', true);

        if ($ret === false) {
            return false;
        }

        $json_a = json_decode($ret, true);

        if (isset($json_a['result'])) {
            //echo 'Die Instanz ist bereits am TV angemeldet!';
            return false;
        } elseif (isset($json_a['error'])) {
            switch ($json_a['error'][0]) {
                case 401: //Unauthorized
                    return true;
                    break;
                default:
                    trigger_error('Unexpected error: ' . $ret);

                    return false;
                    break;
            }
        } else {
            trigger_error('Unexpected else');

            return false;
        }
    }

    public function SendAuthorizationKey(string $TVCode)
    {
        $TVCode = trim($TVCode);
        if ($TVCode == '') {
            echo 'Bitte TV Code angeben.';

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

    public function UpdateAll(): bool
    {
        // IP-Symcon Kernel ready?
        if (IPS_GetKernelRunlevel() != KR_READY) { //Kernel ready
            $this->LogMessage('Kernel is not ready (' . IPS_GetKernelRunlevel() . ')', KL_NOTIFY);

            return false;
        }

        parent::SendDebug('call function', __FUNCTION__, 0);

        if (strlen($IP = (string) $this->ReadPropertyString('Host')) != '') {
            $PowerStatus = $this->GetPowerStatus();

            switch ($PowerStatus) {
                case 0:
                    break;
                case 1:
                    break;
                case 2:
                    $this->SetStatus(IS_ACTIVE);
                    $this->GetVolume();
                    $this->GetInputSource();
                    $this->UpdateCookie();
                    break;
                default:
                    trigger_error('Unexpected PowerStatus: ' . $PowerStatus);
            }

            return $PowerStatus > 0;
        } else {
            return false;
        }
    }

    public function SetPowerStatus(bool $Status)
    {
        $ret = $this->callPostRequest('system', 'setPowerStatus', [['status' => $Status]], [], false, '1.0');

        if ($ret === false) {
            $PowerStatus = 0;
        } else {
            $json_a = json_decode($ret, true);
            if (isset($json_a['result'])) {
                //Neuen Wert in die Statusvariable schreiben
                if ($Status) {
                    //manchmal wird der Bildschirm nicht eingeschaltet, daher sicherheitshalber noch ein TvPower hinterherschicken, sofern unterstützt
                    $RemoteCommandNames = array_column(json_decode($this->ReadPropertyString('RemoteControllerInfo'), true), 'name');
                    if (in_array('TvPower', $RemoteCommandNames)) {
                        $this->SendRemoteKey('TvPower');
                    }
                    $PowerStatus = 2;
                } else {
                    $PowerStatus = 1;
                }
            } else {
                trigger_error('Error: ' . json_encode($json_a['error']));
                $PowerStatus = 0;
            }
        }
        $this->SetValue('PowerStatus', $PowerStatus);
    }

    public function SetInputSource(string $source)
    {
        $Sources = json_decode($this->ReadPropertyString('SourceList'), true);

        $uri = $this->GetUriOfSource($Sources, $source);

        $response = $this->callPostRequest('avContent', 'setPlayContent', [['uri' => $uri]], [], false, '1.0');

        if ($response === false) {

            return false;
        }

        $json_a = json_decode($response, true);

        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: ' . $response);

            return false;
        }

        return true;
    }

    public function SetAudioMute(bool $status)
    {
        $response = $this->callPostRequest('audio', 'setAudioMute', [['status' => $status]], [], false, '1.0');

        if ($response === false) {

            return false;
        }

        $json_a = json_decode($response, true);

        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: ' . $response);

            return false;
        }

        $this->SetValue('AudioMute', $status);

        return true;
    }

    public function SetSpeakerVolume(int $volume)
    {
        $response = $this->callPostRequest('audio', 'setAudioVolume', [['target' => 'speaker', 'volume' => (string) $volume]], [], false, '1.0');

        if ($response === false) {

            return false;
        }

        $json_a = json_decode($response, true);

        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: ' . $response);

            return false;
        }

        $this->SetValue('SpeakerVolume', $volume);

        return true;
    }

    public function SetHeadphoneVolume(int $volume)
    {
        $response = $this->callPostRequest(
            'audio', 'setAudioVolume', [['target' => 'headphone', 'volume' => (string) $volume]], [], false, '1.0'
        );

        if ($response === false) {

            return false;
        }

        $json_a = json_decode($response, true);

        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: ' . $response);

            return false;
        }

        $this->SetValue('HeadphoneVolume', $volume);

        return true;
    }

    public function StartApplication(string $application)
    {
        $Applications = json_decode($this->ReadPropertyString('ApplicationList'), true);

        $uri = $this->GetUriOfSource($Applications['result'][0], $application);

        $response = $this->callPostRequest('appControl', 'setActiveApp', [['uri' => $uri]], [], false, '1.0');

        if ($response === false) {

            return false;
        }

        $json_a = json_decode($response, true);

        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: ' . $response);

            return false;
        }

        return true;
    }

    public function SendRemoteKey(string $Value): bool
    {
        $RemoteControllerInfo = json_decode($this->ReadPropertyString('RemoteControllerInfo'), true);

        $IRCCCode = $this->GetIRCCCode($RemoteControllerInfo, $Value);
        if ($IRCCCode === false) {
            trigger_error('Invalid RemoteKey');
        }

        $tv_ip  = $this->ReadPropertyString('Host');
        $cookie = json_decode($this->ReadPropertyString('Cookie'), true)['auth'];

        $data = '<?xml version="1.0"?>';
        $data .= '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">';
        $data .= '   <s:Body>';
        $data .= '      <u:X_SendIRCC xmlns:u="urn:schemas-sony-com:service:IRCC:1">';
        $data .= '         <IRCCCode>' . $IRCCCode . '</IRCCCode>';
        $data .= '      </u:X_SendIRCC>';
        $data .= '   </s:Body>';
        $data .= '</s:Envelope>';

        $headers = [];
        if ($cookie != '') {
            $headers[] = 'Cookie: auth=' . $cookie;
        }
        $headers[] = 'Content-Type: text/xml; charset=UTF-8';
        $headers[] = 'Content-Length: ' . strlen($data);
        $headers[] = 'SOAPAction: "urn:schemas-sony-com:service:IRCC:1#X_SendIRCC"';

        $ret = $this->SendCurlPost($tv_ip, 'IRCC', $headers, $data, true);

        if ($ret === false) {
            return false;
        } else {
            return true;
        }
    }

    public function WriteAPIInformationToFile(string $filename = '')
    {
        $response = $this->callPostRequest('system', 'getSystemInformation', [], [], false, '1.0');
        if ($filename == '') {
            $filename = IPS_GetLogDir() . 'Sony ' . json_decode($response, true)['result'][0]['model'] . '.txt';
        }

        $return = PHP_EOL . 'SystemInformation: ' . $response . PHP_EOL . PHP_EOL;

        $response = $this->callPostRequest('guide', 'getServiceProtocols', json_encode([]), [], false, '1.0');

        if ($response) {
            $arr = json_decode($response, true);
            foreach ($arr['results'] as $service) {
                $this->ListAPIInfoOfService($service[0], $return);
            }
        }

        $this->LogMessage('Writing API Information to \'' . $filename . '\'', KL_NOTIFY);

        return file_put_contents($filename, $return) > 0;
    }

    public function UpdateApplicationList()
    {
        $response = $this->callPostRequest('appControl', 'getApplicationList', [], [], false, '1.0');

        if ($response === false) {
            return false;
        }

        $json_a = json_decode($response, true);

        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: ' . $response);

            return false;
        }

        $ApplicationList = json_encode($json_a['result'][0]);

        IPS_SetProperty($this->InstanceID, 'ApplicationList', $response);
        IPS_ApplyChanges($this->InstanceID); //Achtung: $this->ApplyChanges funktioniert hier nicht

        $this->WriteListProfile('STV.Applications', $ApplicationList, 'title');

        $this->SendDebug(__FUNCTION__, 'ApplicationList: ' . json_encode($response), 0);

        return true;
    }

    //
    // private functions for internal use
    //
    private function GetVolume()
    {
        if (IPS_GetInstance($this->InstanceID)['InstanceStatus'] != IS_ACTIVE) {
            return false;
        }

        $response = $this->callPostRequest('audio', 'getVolumeInformation', [], [], false, '1.0');

        if ($response === false) {

            return false;
        }

        $json_a = json_decode($response, true);

        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: ' . $response);

            return false;
        }

        $return = [];

        foreach ($json_a['result'][0] as $target) {
            switch ($target['target']) {
                case 'speaker':
                    $this->SetValueBoolean('AudioMute', $target['mute']);
                    $this->SetValueInteger('SpeakerVolume', $target['volume']);
                    $return[$target['target']] = ['mute' => $target['mute']];
                    $return[$target['target']] = ['volume' => $target['volume']];
                    break;

                case 'headphone':
                    $this->SetValueBoolean('AudioMute', $target['mute']);
                    $this->SetValueInteger('HeadphoneVolume', $target['volume']);
                    $return[$target['target']] = ['mute' => $target['mute']];
                    $return[$target['target']] = ['volume' => $target['volume']];
                    break;

                default:
                    trigger_error('Unerwarteter Target: ' . $target['target']);

                    break;

            }
        }

        return $return;
    }

    private function GetInputSource()
    {
        if (IPS_GetInstance($this->InstanceID)['InstanceStatus'] != IS_ACTIVE) {
            return false;
        }

        $response = $this->callPostRequest('avContent', 'getPlayingContentInfo', [], [], false, '1.0');

        if (!$response || isset(json_decode($response, true)['error'])) {
            // z.B. {'error':[7, 'Illegal State'}
            $this->SetValueInteger('InputSource', -1);

            return false;
        }

        $Sources = json_decode($this->ReadPropertyString('SourceList'), true);

        if (!is_array($Sources)) {
            return false;
        }

        $json_a = json_decode($response, true);

        foreach ($Sources as $key => $source) {
            if ($source['uri'] == $json_a['result'][0]['uri']) {
                $this->SetValueInteger('InputSource', $key);
                $this->SetValueInteger('Application', -1);

                return $source['title'];
            }
        }

        return false;
    }

    private function GetPowerStatus()
    {
        $IP = $this->ReadPropertyString('Host');
        if (@Sys_Ping($IP, 2000) === false) {
            $PowerStatus = 0;
        } else {
            $ret = $this->callPostRequest('system', 'getPowerStatus', [], [], false, '1.0');

            if ($ret === false) {
                $PowerStatus = 0;
            } else {
                $json_a = json_decode($ret, true);

                if (isset($json_a['error'])) {
                    return false;
                }

                if (!isset($json_a['result'])) {
                    trigger_error('Unexpected return: ' . $ret);

                    return false;
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
        $this->SetValueInteger('PowerStatus', $PowerStatus); // 0-AUS, 1-Standby, 2-Active

        if ($PowerStatus == 0) {
            $this->SetStatus(IS_INACTIVE);
        } else {
            $this->SetStatus(IS_ACTIVE);
        }

        return $PowerStatus;
    }

    private function UpdateCookie()
    {
        $cookie = json_decode($this->ReadPropertyString('Cookie'), true);
        if ($cookie['ExpirationDate'] < time() - (24 * 60 * 60)) {
            $ret = $this->callPostRequest('accessControl', 'actRegister', $this->GetAuthorizationParams(), [], true, '1.0');

            if ($ret === false) {
                return false;
            }

            //Cookie aus Header ermitteln und in Property setzen
            if (!$this->ExtractAndSaveCookie($ret)) {
                return false;
            }
        }

        return true;
    }

    private function GetUriOfSource($Sources, $Name)
    {
        foreach ($Sources as $source) {
            if ($source['title'] == $Name) {
                return $source['uri'];
                break;
            }
        }

        return false;
    }

    private function GetIRCCCode($codes, $Name)
    {
        foreach ($codes as $code) {
            if ($code['name'] == $Name) {
                return $code['value'];
                break;
            }
        }

        return false;
    }

    private function ListAPIInfoOfService($servicename, &$return)
    {
        $return   .= 'Service: ' . $servicename . PHP_EOL;
        $response = $this->callPostRequest($servicename, 'getMethodTypes', [''], [], false, '1.0');
        if ($response) {
            $arr = json_decode($response, true);
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

    private function ListParams($arrParams)
    {
        $params = '';
        foreach ($arrParams as $key => $elem) {
            if ($key == 0) {
                $params .= $elem;
            } else {
                $params .= ', ' . $elem . ', ';
            }
        }

        return $params;
    }

    private function callPostRequest($service, $cmd, $params, $headers, $returnHeader, $version, $ignoreError401 = false)
    {
        $tv_ip  = $this->ReadPropertyString('Host');
        $cookie = json_decode($this->ReadPropertyString('Cookie'), true)['auth'];

        if ($cookie != '') {
            $headers[] = 'Cookie: auth=' . $cookie;
        }

        $data = json_encode(
            [
                'method'  => $cmd,
                'params'  => $params,
                'id'      => $this->InstanceID,
                'version' => $version]
        );

        return $this->SendCurlPost($tv_ip, $service, $headers, $data, $returnHeader, $ignoreError401);
    }

    private function SendCurlPost($tvip, $service, $headers, $data_json, $returnHeader, $ignoreError401 = false)
    {
        parent::SendDebug('send:', $data_json, 0);
        $ch = curl_init('http://' . $tvip . '/sony/' . $service);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if (count($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        curl_setopt($ch, CURLOPT_HEADER, $returnHeader); //Header im Output?
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response   = curl_exec($ch);
        $curl_errno = curl_errno($ch);
        curl_close($ch);
        parent::SendDebug('received:', $response, 0);

        if ($curl_errno) {
            $this->LogMessage('Curl call returned with \'' . $curl_errno . '\'', KL_ERROR);

            return false;
        }

        $json_a = json_decode($response, true);

        if (isset($json_a['error'])) {
            if (!($json_a['error'][0] == 401 && $ignoreError401)) {
                $this->LogMessage('TV replied with error \'' . implode(', ', $json_a['error']) . '\'', KL_ERROR);

                return false;
            }
        }


        return $response;
    }

    private function GetSourceListInfo()
    {
        $response = $this->callPostRequest('avContent', 'getSourceList', [['scheme' => 'extInput']], [], false, '1.0');

        if ($response === false) {
            return false;
        }

        $json_a = json_decode($response, true);

        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: ' . $response);

            return false;
        }

        $SourceList = [];
        foreach ($json_a['result'][0] as $result) {
            if (in_array($result['source'], ['extInput:hdmi', 'extInput:composite', 'extInput:component'])) {//physical inputs
                $response = $this->callPostRequest('avContent', 'getContentList', [$result], [], false, '1.0');

                if ($response === false) {

                    return false;
                }

                $json_a = json_decode($response, true);

                if (!isset($json_a['result'])) {
                    trigger_error('Unexpected return: ' . $response);

                    return false;
                }

                $SourceList = array_merge($SourceList, $json_a['result'][0]);
            }
        }

        $response = json_encode($SourceList);

        IPS_SetProperty($this->InstanceID, 'SourceList', $response);
        IPS_ApplyChanges($this->InstanceID); //Achtung: $this->ApplyChanges funktioniert hier nicht

        $this->WriteListProfile('STV.Sources', $response, 'title');

        $this->SendDebug(__FUNCTION__, 'SourceList: ' . json_encode($response), 0);

        return true;
    }

    private function GetRemoteControllerInfo()
    {
        $response = $this->callPostRequest('system', 'getRemoteControllerInfo', [], [], false, '1.0');

        if (!$response) {
            trigger_error('callPostRequest failed!');
            $this->SetValueInteger('PowerStatus', 0); //off
            $this->SetStatus(IS_INACTIVE);

            return false;
        }

        $json_a = json_decode($response, true);

        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: ' . $response);

            return false;
        }

        $response = json_encode(($json_a['result'][1]));
        IPS_SetProperty($this->InstanceID, 'RemoteControllerInfo', $response);
        IPS_ApplyChanges($this->InstanceID); //Achtung: $this->ApplyChanges funktioniert hier nicht

        $this->WriteListProfile('STV.RemoteKey', $response, 'name');

        $this->SendDebug(__FUNCTION__, 'RemoteControllerInfo: ' . json_encode($response), 0);

        return true;
    }


    private function WriteListProfile(String $ProfileName, String $jsonList, String $elementName = '')
    {
        $list = json_decode($jsonList, true);

        $ass[] = [-1, '-', '', -1];
        foreach ($list as $key => $listElement) {
            $ass[] = [$key, html_entity_decode($listElement[$elementName]), '', -1];
        }

        if (count($ass) > self::MAX_PROFILE_ASSOCIATIONS) {
            echo 'Die maximale Anzahl Assoziationen ist überschritten. Folgende Einträge wurden nicht in das Profil \'' . $ProfileName
                 . '\' übernommen:' . PHP_EOL . implode(', ', array_column(array_slice($ass, self::MAX_PROFILE_ASSOCIATIONS - count($ass)), 1))
                 . PHP_EOL . PHP_EOL;
        }

        $this->CreateProfileIntegerAss($ProfileName, '', '', '', 0, 0, array_slice($ass, 0, self::MAX_PROFILE_ASSOCIATIONS));
    }

    private function ExtractAndSaveCookie($return)
    {
        $CookieFound = false;
        list($headers) = explode("\r\n\r\n", $return, 2);
        $headers = explode("\n", $headers);
        if (count($headers) == 0) {
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
                IPS_SetProperty($this->InstanceID, 'Cookie', json_encode($Cookie));
                IPS_ApplyChanges($this->InstanceID);
                $CookieFound = true;
                break;
            }
        }

        $this->SendDebug(__FUNCTION__, 'Cookie: ' . json_encode($Cookie), 0);
        return $CookieFound;
    }

    private function GetCookieElements($SetCookie)
    {
        $ret      = [];
        $elements = explode(';', $SetCookie);
        foreach ($elements as $element) {
            $expl = explode('=', $element);
            if (count($expl) == 2) {
                $ret[trim(strtolower($expl[0]))] = $expl[1];
            }
        }

        return $ret;
    }

    private function SetValueBoolean($Ident, $Value)
    {
        if ($this->GetValue($Ident) != $Value) {
            $this->SetValue($Ident, $Value);

            return true;
        }

        return false;
    }

    private function SetValueInteger($Ident, $Value)
    {
        $ID = $this->GetIDForIdent($Ident);
        if (GetValueInteger($ID) != $Value) {
            SetValueInteger($ID, intval($Value));

            return true;
        }

        return false;
    }

    private function GetAuthorizationParams()
    {
        $Nickname = $this->ReadPropertyString('Nickname');
        $uuid     = $this->ReadPropertyString('UUID');

        return [
            [
                'clientid' => $uuid,
                'nickname' => $Nickname,
                'level'    => 'private'],
            [
                [
                    'function' => 'WOL',
                    'value'    => 'yes']]];
    }

    private function CheckProfileType($ProfileName, $VarType)
    {
        $profile = IPS_GetVariableProfile($ProfileName);
        if ($profile['ProfileType'] != $VarType) {
            trigger_error(
                'Variable profile type does not match for already existing profile "' . $ProfileName
                . '". The existing profile has to be deleted manually.'
            );
        }
    }

    private function CreateProfileInteger($ProfileName, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $StepSize, $Digits)
    {
        if (!IPS_VariableProfileExists($ProfileName)) {
            IPS_CreateVariableProfile($ProfileName, 1);

            $this->SendDebug('Variablenprofil angelegt: ', $ProfileName, 0);
            $this->LogMessage('Variablenprofil angelegt: ' . $ProfileName, KL_SUCCESS);
        } else {
            $this->CheckProfileType($ProfileName, IPSVarType::vtInteger);
        }

        IPS_SetVariableProfileIcon($ProfileName, $Icon);
        IPS_SetVariableProfileText($ProfileName, $Prefix, $Suffix);
        IPS_SetVariableProfileDigits($ProfileName, $Digits); //  Nachkommastellen
        IPS_SetVariableProfileValues($ProfileName, $MinValue, $MaxValue, $StepSize);
    }

    private function CreateProfileIntegerAss($ProfileName, $Icon, $Prefix, $Suffix, $StepSize, $Digits, $Associations)
    {
        if (count($Associations) == 0) {
            trigger_error(__FUNCTION__ . ': Associations of profil "' . $ProfileName . '" is empty');
            $this->LogMessage(json_encode(debug_backtrace()), KL_ERROR);

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

    private function RegisterProperties()
    {

        //Properties, die im Konfigurationsformular gesetzt werden können
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('UpdateInterval', 10);
        $this->RegisterPropertyString('Nickname', 'Symcon (' . gethostname() . ')');
        $this->RegisterPropertyString('UUID', uniqid());
        $this->RegisterPropertyString('Cookie', '');
        $this->RegisterPropertyInteger('CookieExpiration', time());

        // interne Properties
        $this->RegisterPropertyString('RemoteControllerInfo', '');
        $this->RegisterPropertyString('SourceList', '');
        $this->RegisterPropertyString('ApplicationList', '');
    }

    private function RegisterVariables()
    {
        if (!IPS_VariableProfileExists('STV.PowerStatus')) {
            $this->CreateProfileIntegerAss(
                'STV.PowerStatus', 'Power', '', '', 0, 0, [
                                     [0, 'Ausgeschaltet', '', -1],
                                     [1, 'Standby', '', -1],
                                     [2, 'Eingeschaltet', '', -1],]
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
                'STV.Volume', 'Intensity', '', ' %', 0, 100, 1, 1
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

    private function SetInstanceStatus()
    {
        //IP Prüfen
        $ip = $this->ReadPropertyString('Host');
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            if ($ip == '') {
                $this->SetStatus(self::STATUS_INST_IP_IS_EMPTY);
            } elseif ($this->GetPowerStatus() > 0) {
                $this->SetStatus(IS_ACTIVE);
            } else {
                $this->SetStatus(IS_INACTIVE);
            }
        } else {
            $this->SetStatus(self::STATUS_INST_IP_IS_INVALID); //IP Adresse ist ungültig
        }
    }
}
