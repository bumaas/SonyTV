<?php

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

    const VERSION = '0.8.1';

    // Überschreibt die interne IPS_Create($id) Funktion
    public function Create()
    {
        // Diese Zeile nicht löschen.
        parent::Create();

        $this->RegisterProperties();

        $this->RegisterTimer('Update', 0, 'STV_UpdateAll('.$this->InstanceID.');');
        IPS_LogMessage(get_class().'::'.__FUNCTION__, 'TimerIntervall set to 0 s.');
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $TimerInterval = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('Update', $TimerInterval * 1000);
        IPS_LogMessage(get_class().'::'.__FUNCTION__, 'TimerIntervall set to '.$TimerInterval.' s.');

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
                $VariableID = $this->GetIDForIdent($Ident);
                if ($Value >= 0) {
                    SetValue($VariableID, $Value);
                    $this->SendRemoteKey(GetValueFormatted($VariableID));
                }
                break;

            case 'InputSource':
                $VariableID = $this->GetIDForIdent($Ident);
                if ($Value >= 0) {
                    SetValue($VariableID, $Value);
                    $this->SetInputSource(GetValueFormatted($VariableID));
                }

                break;

            case 'Application':
                $VariableID = $this->GetIDForIdent($Ident);
                if ($Value >= 0) {
                    SetValue($VariableID, $Value);
                    $this->StartApplication(htmlentities(GetValueFormatted($VariableID)));
                }

                break;

            default:
                trigger_error('Unexpected ident: '.$Ident);
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

        $ret = $this->callPostRequest('accessControl', 'actRegister', json_encode($this->GetAuthorizationParams()), [], false, '1.0');

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
                    trigger_error('Unexpected error: '.$ret);

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
        $tv_auth_header = 'Authorization: Basic '.base64_encode(':'.$TVCode);
        $headers = [];
        $headers[] = $tv_auth_header;

        $ret = $this->callPostRequest('accessControl', 'actRegister', json_encode($this->GetAuthorizationParams()), $headers, true, '1.0');

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

    public function UpdateAll():bool
    {
        // IP-Symcon Kernel ready?
        if (IPS_GetKernelRunlevel() != KR_READY) { //Kernel ready
            IPS_LogMessage(get_class().'::'.__FUNCTION__, 'Kernel is not ready ('.IPS_GetKernelRunlevel().')');

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
                    trigger_error('Unexpected PowerStatus: '.$PowerStatus);
            }

            return $PowerStatus > 0;
        /*                $this->GetSystemInfos();
                        $this->GetEPGInfos();
                        $this->GetTimerliste();
                        $this->GetSenderliste();
        */
        } else {
            return false;
        }
    }

    public function SetPowerStatus(bool $Status)
    {
        $ret = $this->callPostRequest('system', 'setPowerStatus', json_encode([['status' => $Status]]), [], false, '1.0');

        if ($ret === false) {
            $PowerStatus = 0;
        } else {
            $json_a = json_decode($ret, true);
            if (isset($json_a['result'])) {
                //Neuen Wert in die Statusvariable schreiben
                if ($Status) {
                    $PowerStatus = 2;
                } else {
                    $PowerStatus = 1;
                }
            } else {
                trigger_error('Error: '.json_encode($json_a['error']));
                $PowerStatus = 0;
            }
        }
        SetValue($this->GetIDForIdent('PowerStatus'), $PowerStatus);
    }

    public function SetInputSource(string $source)
    {
        $Sources = json_decode($this->ReadPropertyString('SourceList'), true);

        $uri = $this->GetUriOfSource($Sources, $source);

        $response = $this->callPostRequest('avContent', 'setPlayContent', json_encode([['uri' => $uri]]), [], false, '1.0');

        $json_a = json_decode($response, true);

        if (!$response || !isset($json_a['result'])) {
            trigger_error('Unexpected return: '.$response);

            return false;
        }

        return true;
    }

    public function StartApplication(string $application)
    {
        $Applications = json_decode($this->ReadPropertyString('ApplicationList'), true);

        $uri = $this->GetUriOfSource($Applications['result'][0], $application);

        $response = $this->callPostRequest('appControl', 'setActiveApp', json_encode([['uri' => $uri]]), [], false, '1.0');

        $json_a = json_decode($response, true);

        if (!$response || !isset($json_a['result'])) {
            trigger_error('Unexpected return: '.$response);

            return false;
        }

        return true;
    }

    public function SendRemoteKey(string $Value):bool
    {
        $RemoteControllerInfo = json_decode($this->ReadPropertyString('RemoteControllerInfo'), true);

        $IRCCCode = $this->GetIRCCCode($RemoteControllerInfo, $Value);
        if ($IRCCCode === false) {
            trigger_error('Invalid RemoteKey');
        }

        $tv_ip = $this->ReadPropertyString('Host');
        $cookie = json_decode($this->ReadPropertyString('Cookie'), true)['auth'];

        $data = '<?xml version="1.0"?>';
        $data .= '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">';
        $data .= '   <s:Body>';
        $data .= '      <u:X_SendIRCC xmlns:u="urn:schemas-sony-com:service:IRCC:1">';
        $data .= '         <IRCCCode>'.$IRCCCode.'</IRCCCode>';
        $data .= '      </u:X_SendIRCC>';
        $data .= '   </s:Body>';
        $data .= '</s:Envelope>';

        $headers = [];
        if ($cookie != '') {
            $headers[] = 'Cookie: auth='.$cookie;
        }
        $headers[] = 'Content-Type: text/xml; charset=UTF-8';
        $headers[] = 'Content-Length: '.strlen($data);
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
        $response = $this->callPostRequest('system', 'getSystemInformation', json_encode([]), [], false, '1.0');
        if ($filename == '') {
            $filename = IPS_GetLogDir().'Sony '.json_decode($response, true)['result'][0]['model'].'.txt';
        }

        $return = PHP_EOL.'SystemInformation: '.$response.PHP_EOL.PHP_EOL;

        $response = $this->callPostRequest('guide', 'getServiceProtocols', json_encode([]), [], false, '1.0');

        if ($response) {
            $arr = json_decode($response, true);
            foreach ($arr['results'] as $service) {
                $this->ListAPIInfoOfService($service[0], $return);
            }
        }

        file_put_contents($filename, $return);
    }

    public function UpdateApplicationList()
    {
        $response = $this->callPostRequest('appControl', 'getApplicationList', json_encode([]), [], false, '1.0');

        if ($response === false) {
            return false;
        }

        $json_a = json_decode($response, true);

        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: '.$response);

            return false;
        }

        $ApplicationList = json_encode($json_a['result'][0]);

        IPS_SetProperty($this->InstanceID, 'ApplicationList', $response);
        IPS_ApplyChanges($this->InstanceID); //Achtung: $this->ApplyChanges funktioniert hier nicht

        $this->WriteApplicationListProfile($ApplicationList);

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

        $response = $this->callPostRequest('audio', 'getVolumeInformation', json_encode([]), [], false, '1.0');

        $json_a = json_decode($response, true);

        if (!$response || !isset($json_a['result'])) {
            trigger_error('Unexpected return: '.$response);

            return false;
        }

        $response = [];

        foreach ($json_a['result'][0] as $target) {
            switch ($target['target']) {
                case 'speaker':
                    $this->SetValueInteger('SpeakerVolume', $target['volume']);
                    $response[$target['target']] = ['volume' => $target['volume']];
                    break;

                case 'headphone':
                    $this->SetValueInteger('HeadphoneVolume', $target['volume']);
                    $response[$target['target']] = ['volume' => $target['volume']];
                    break;

                default:
                    trigger_error('Unerwarteter Target: '.$target['target']);

                    break;

            }
        }

        return $response;
    }

    private function GetInputSource()
    {
        if (IPS_GetInstance($this->InstanceID)['InstanceStatus'] != IS_ACTIVE) {
            return false;
        }

        $response = $this->callPostRequest('avContent', 'getPlayingContentInfo', json_encode([]), [], false, '1.0');

        $json_a = json_decode($response, true);

        if (!$response || isset($json_a['error'])) {
            // z.B. {'error':[7, 'Illegal State'}
            $this->SetValueInteger('InputSource', -1);

            return false;
        }

        $Sources = json_decode($this->ReadPropertyString('SourceList'), true);
        foreach ($Sources as $key=> $source) {
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
        $IP = (string) $this->ReadPropertyString('Host');
        if (@Sys_Ping($IP, 2000) === false) {
            $PowerStatus = 0;
        } else {
            $ret = $this->callPostRequest('system', 'getPowerStatus', json_encode([]), [], false, '1.0');

            if ($ret === false) {
                $PowerStatus = 0;
            } else {
                $json_a = json_decode($ret, true);

                if (!isset($json_a['result'])) {
                    trigger_error('Unexpected return: '.$ret);

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
                        trigger_error('Unexpected status: '.$json_a['result'][0]['status']);
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
            $ret = $this->callPostRequest('accessControl', 'actRegister', json_encode($this->GetAuthorizationParams()), [], true, '1.0');

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
        $return .= 'Service: '.$servicename.PHP_EOL;
        $response = $this->callPostRequest($servicename, 'getMethodTypes', json_encode(['']), [], false, '1.0');
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
                        $returns = ': '.$api[2][0];
                    } else {
                        $returns = '';
                    }

                    $return .= '   '.$api[0].'('.$params.')'.$returns.' - Version: '.$api[3].PHP_EOL;
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
                $params .= ', '.$elem.', ';
            }
        }

        return $params;
    }

    private function callPostRequest($service, $cmd, $params, $headers, $returnHeader, $version)
    {
        $tv_ip = $this->ReadPropertyString('Host');
        $cookie = json_decode($this->ReadPropertyString('Cookie'), true)['auth'];

        if ($cookie != '') {
            $headers[] = 'Cookie: auth='.$cookie;
        }

        $data = '{"method":"'.$cmd.'","params":'.$params.',"id":'.$this->InstanceID.', "version":"'.$version.'"}';

        return $this->SendCurlPost($tv_ip, $service, $headers, $data, $returnHeader);
    }

    private function SendCurlPost($tvip, $service, $headers, $data, $returnHeader)
    {
        parent::SendDebug('send:', $data, 0);
        $ch = curl_init('http://'.$tvip.'/sony/'.$service);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if (count($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        curl_setopt($ch, CURLOPT_HEADER, $returnHeader); //Header im Output?
        $ausgabe = curl_exec($ch);
        curl_close($ch);
        parent::SendDebug('received:', $ausgabe, 0);

        return $ausgabe;
    }

    private function GetSourceListInfo()
    {
        $response = $this->callPostRequest('avContent', 'getSourceList', json_encode([['scheme' =>'extInput']]), [], false, '1.0');

        if ($response === false) {
            return false;
        }

        $json_a = json_decode($response, true);

        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: '.$response);

            return false;
        }

        $SourceList = [];
        foreach ($json_a['result'][0] as $result) {
            if (in_array($result['source'], ['extInput:hdmi', 'extInput:composite', 'extInput:component'])) {//physical inputs
                $response = $this->callPostRequest('avContent', 'getContentList', json_encode([$result]), [], false, '1.0');
                if ($response === false) {
                    return false;
                }
                $json_a = json_decode($response, true);

                if (!isset($json_a['result'])) {
                    trigger_error('Unexpected return: '.$response);

                    return false;
                }

                $SourceList = array_merge($SourceList, $json_a['result'][0]);
            }
        }

        $response = json_encode($SourceList);

        IPS_SetProperty($this->InstanceID, 'SourceList', $response);
        IPS_ApplyChanges($this->InstanceID); //Achtung: $this->ApplyChanges funktioniert hier nicht

        $this->WriteSourceListProfile($response);

        return true;
    }

    private function GetRemoteControllerInfo()
    {
        $response = $this->callPostRequest('system', 'getRemoteControllerInfo', json_encode([]), [], false, '1.0');

        if (!$response) {
            trigger_error('callPostRequest failed!');
            $this->SetValueInteger('PowerStatus', 0); //off
            $this->SetStatus(IS_INACTIVE);

            return false;
        }

        $json_a = json_decode($response, true);

        if (!isset($json_a['result'])) {
            trigger_error('Unexpected return: '.$response);

            return false;
        }

        $response = json_encode(($json_a['result'][1]));
        IPS_SetProperty($this->InstanceID, 'RemoteControllerInfo', $response);
        IPS_ApplyChanges($this->InstanceID); //Achtung: $this->ApplyChanges funktioniert hier nicht

        $this->WriteRemoteControllerInfoProfile($response);

        return true;
    }

    private function WriteSourceListProfile(String $SourceList)
    {
        $sources = json_decode($SourceList, true);

        $ass[] = [-1, '-',  '', -1];
        foreach ($sources as $key => $source) {
            $ass[] = [$key, $source['title'],  '', -1];
        }

        $this->CreateProfileIntegerAss('STV.Sources', '', '', '', 0, 0, $ass);
    }

    private function WriteApplicationListProfile(String $ApplicationList)
    {
        $Applications = json_decode($ApplicationList, true);

        $ass[] = [-1, '-',  '', -1];
        foreach ($Applications as $key => $Application) {
            $ass[] = [$key, html_entity_decode($Application['title']),  '', -1];
        }

        $this->CreateProfileIntegerAss('STV.Applications', '', '', '', 0, 0, $ass);
    }

    private function WriteRemoteControllerInfoProfile(String $RemoteControllerInfo)
    {
        $codes = json_decode($RemoteControllerInfo, true);

        $ass[] = [-1, '-',  '', -1];
        foreach ($codes as $key => $code) {
            $ass[] = [$key, $code['name'],  '', -1];
        }

        $this->CreateProfileIntegerAss('STV.RemoteKey', '', '', '', 0, 0, $ass);
    }

    private function ExtractAndSaveCookie($return)
    {
        $CookieFound = false;
        list($headers) = explode("\r\n\r\n", $return, 2);
        $headers = explode("\n", $headers);
        $Cookie = [];
        foreach ($headers as $SetCookie) {
            if (stripos($SetCookie, 'Set-Cookie:') !== false) {
                // Beispiel:
                // Set-Cookie: auth=246554AA89E869DCD1FFC5F8C726AF5803F3AC6A; Path=/sony/; Max-Age=1209600; Expires=Do., 26 Apr. 2018 14:31:14 GMT+00:00
                $arr = $this->GetCookieElements(substr($SetCookie, strlen('Set-Cookie: ')));
                $Cookie['auth'] = $arr['auth'];
                $Cookie['ExpirationDate'] = time() + $arr[' Max-Age'];
                IPS_SetProperty($this->InstanceID, 'Cookie', json_encode($Cookie));
                IPS_ApplyChanges($this->InstanceID);
                $CookieFound = true;
                break;
            }
        }

        return $CookieFound;
    }

    private function GetCookieElements($SetCookie)
    {
        $ret = [];
        $elements = explode(';', $SetCookie);
        foreach ($elements as $element) {
            $expl = explode('=', $element);
            $ret[$expl[0]] = $expl[1];
        }

        return $ret;
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
        $uuid = $this->ReadPropertyString('UUID');

        return  [['clientid'    => $uuid,
                     'nickname' => $Nickname,
                     'level'    => 'private',
        ],
            [['function' => 'WOL',
               'value'   => 'yes', ],
            ], ];
    }

    private function CheckProfileType($ProfileName, $VarType)
    {
        $profile = IPS_GetVariableProfile($ProfileName);
        if ($profile['ProfileType'] != $VarType) {
            trigger_error('Variable profile type does not match for already existing profile "'.$ProfileName.'". The existing profile has to be deleted manually.');
        }
    }

    private function CreateProfileInteger($ProfileName, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $StepSize, $Digits)
    {
        if (!IPS_VariableProfileExists($ProfileName)) {
            IPS_CreateVariableProfile($ProfileName, 1);

            $this->SendDebug('Variablenprofil angelegt: ', $ProfileName, 0);
            IPS_LogMessage('Sony TV', 'Variablenprofil angelegt: '.$ProfileName);
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
            trigger_error(__FUNCTION__.': Associations of profil "'.$ProfileName.'" is empty');
            IPS_LogMessage(__FUNCTION__, json_encode(debug_backtrace()));

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
        $this->RegisterPropertyString('Nickname', 'Symcon ('.gethostname().')');
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
                'STV.PowerStatus', 'Power', '', '', 0, 0,
                [
                    [0, 'Ausgeschaltet', '', -1],
                    [1, 'Standby', '', -1],
                    [2, 'Eingeschaltet', '', -1],
                ]
            );
        }

        if (!IPS_VariableProfileExists('STV.RemoteKey')) {
            $this->WriteRemoteControllerInfoProfile('[]');
        }
        if (!IPS_VariableProfileExists('STV.Sources')) {
            $this->WriteSourceListProfile('[]');
        }
        if (!IPS_VariableProfileExists('STV.Applications')) {
            $this->WriteApplicationListProfile('[]');
        }

        $this->RegisterVariableInteger('PowerStatus', 'Status', 'STV.PowerStatus', 10);
        $this->RegisterVariableInteger('SpeakerVolume', 'Lautstärke Lautsprecher', '', 20);
        $this->RegisterVariableInteger('HeadphoneVolume', 'Lautstärke Kopfhörer', '', 30);
        $this->RegisterVariableInteger('SendRemoteKey', 'Sende FB Taste', 'STV.RemoteKey', 40);
        $this->RegisterVariableInteger('InputSource', 'Eingangsquelle', 'STV.Sources', 50);
        $this->RegisterVariableInteger('Application', 'Starte Applikation', 'STV.Applications', 50);

        // Aktivieren der Statusvariablen
        $this->EnableAction('PowerStatus');
        $this->EnableAction('SendRemoteKey');
        $this->EnableAction('InputSource');
        $this->EnableAction('Application');
    }

    private function SetInstanceStatus()
    {
        if (IPS_GetKernelRunlevel() != KR_READY) { //Kernel ready
            return;
        }

        //IP Prüfen
        $ip = (string) $this->ReadPropertyString('Host');
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
