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

    const VERSION = '0.8';

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

        if (IPS_GetKernelRunlevel() != KR_READY) { //Kernel ready
            IPS_LogMessage(get_class() . '::' . __FUNCTION__, 'Kernel is not ready (' . IPS_GetKernelRunlevel() . ')');

            return;
        }

        $this->SetTimerInterval('Update', $this->ReadPropertyInteger('UpdateInterval') * 1000);

        $this->RegisterVariables();

        $this->SetInstanceStatus();
    }

    public function RequestAction($Ident, $Value) {

        switch($Ident) {
            case 'PowerStatus':
                $this->SetPowerStatus($Value == 2);

                break;

            case 'SendRemoteKey':
                $this->SendRemoteKey($Value);
                SetValue($this->GetIDForIdent($Ident), $Value);

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
        # use only A-Z a-z 0-9 for device. Probably. Havent checked.
        // Start anlernen

        $ret = $this->callPostRequest('accessControl', 'actRegister', json_encode($this->GetAuthorizationParams()), [], false, '1.0');

        if ($ret === false){
            return false;
        }

        $json_a = json_decode($ret,true);

        if (isset($json_a['result'])) {
            //echo 'Die Instanz ist bereits am TV angemeldet!';
            return false;
        } elseif (isset($json_a['error'])) {
            switch ($json_a['error'][0]){
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
        if ($TVCode == ''){
            echo 'Bitte TV Code angeben.';
            return false;
        }

        // Key senden
        $tv_auth_header='Authorization: Basic '.base64_encode(':'.$TVCode);
        $headers = [];
        $headers[]=$tv_auth_header;

        $ret = $this->callPostRequest('accessControl', 'actRegister', json_encode($this->GetAuthorizationParams()), $headers, true, '1.0');

        if ($ret === false){
            return false;
        }

        //Cookie aus Header ermitteln und in Property setzen
        list($headers) = explode("\r\n\r\n", $ret, 2);
        $headers = explode("\n", $headers);
        foreach($headers as $header) {
            if (stripos($header, 'Set-Cookie:') !== false) {
                $header = substr($header,0,strpos($header,";"));
                $auth = strstr($header, "a");
                IPS_SetProperty($this->InstanceID, 'Cookie', $auth);
                IPS_ApplyChanges($this->InstanceID);
                return true;
            }
        }

        return false;

    }

    public function UpdateAll():bool
    {
        // IP-Symcon Kernel ready?
        if (IPS_GetKernelRunlevel() != KR_READY) { //Kernel ready
            IPS_LogMessage(get_class() . '::' . __FUNCTION__, 'Kernel is not ready (' . IPS_GetKernelRunlevel() . ')');

            return false;
        }

        parent::SendDebug('call function' , __FUNCTION__, 0);

        if (strlen($IP = (string) $this->ReadPropertyString("Host")) != ""){
            $PowerStatus = $this->GetPowerStatus();

            switch ($PowerStatus){
                case 0:
                    break;
                case 1:
                    break;
                case 2:
                    $this->SetStatus(IS_ACTIVE);
                    $this->GetVolume();
                    break;
                default:
                    trigger_error('Unexpected PowerStatus: '. $PowerStatus);
            }

            return $PowerStatus>0;
/*                $this->GetSystemInfos();
                $this->GetEPGInfos();
                $this->GetTimerliste();
                $this->GetSenderliste();
*/
        } else {
            return false;
        }
    }

    public function GetRemoteControllerInfo(){
        if (IPS_GetInstance($this->InstanceID)['InstanceStatus'] != IS_ACTIVE){
            return false;
        }

        $ret = $this->callPostRequest('system', 'getRemoteControllerInfo', json_encode([]), [], false, '1.0');

        if (!$ret){
            trigger_error('callPostRequest failed!');
            $this->SetValueInteger('PowerStatus', 0); //off
            $this->SetStatus(IS_INACTIVE);
            return false;
        }

        $json_a = json_decode($ret,true);

        if (!isset($json_a['result'])){
            trigger_error('Unexpected return: ' . $ret);
            return false;
        }

        $RemoteControllerInfo = $ret;

        if ($RemoteControllerInfo != $this->ReadPropertyString('RemoteControllerInfo')){
            // wenn sich die Informationen zum ersten mal geholt wurden oder sie sich geändert haben,
            // dann werden sie gespeíchert und das Profil wird aktualisiert
            IPS_SetProperty($this->InstanceID, 'RemoteControllerInfo', $RemoteControllerInfo);
            IPS_ApplyChanges($this->InstanceID); //Achtung: $this->ApplyChanges funktioniert hier nicht

            $this->WriteRemoteControllerInfoProfile($RemoteControllerInfo);
        }

        return $RemoteControllerInfo;

    }

    public function GetPowerStatus()
    {
        $IP = (string) $this->ReadPropertyString('Host');
        if (@Sys_Ping($IP, 2000) === false) {
            $PowerStatus = 0;
        } else {

            $ret = $this->callPostRequest('system', 'getPowerStatus', json_encode([]), [], false, '1.0');

            if ($ret === false){
                $PowerStatus = 0;
            } else {

                $json_a = json_decode($ret,true);

                if (!isset($json_a['result'])){
                    trigger_error('Unexpected return: ' . $ret);
                    return false;
                }

                switch ($json_a['result'][0]['status']){
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

        if ($PowerStatus == 0){
            $this->SetStatus(IS_INACTIVE);
        } else {
            $this->SetStatus(IS_ACTIVE);
        }
        return $PowerStatus;
    }

    public function SetPowerStatus(bool $Status){

        $ret = $this->callPostRequest('system', 'setPowerStatus', json_encode([['status' => $Status]]), [], false, '1.0');

        if ($ret === false){
            $PowerStatus = 0;
        } else {
            $json_a = json_decode($ret, true);
            if (isset($json_a['result'])) {
                //Neuen Wert in die Statusvariable schreiben
                if ($Status){
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
    public function GetVolume()
    {
        if (IPS_GetInstance($this->InstanceID)['InstanceStatus'] != IS_ACTIVE){
            return false;
        }

        $ret = $this->callPostRequest('audio', 'getVolumeInformation', json_encode([]), [], false, '1.0');

        $json_a = json_decode($ret,true);

        if (!$ret || !isset($json_a['result'])){
            trigger_error('Unexpected return: ' . $ret);
            return false;
        }

        $ret = [];

        foreach ($json_a['result'][0] as $target){

            switch ($target['target']){
                case 'speaker':
                    $this->SetValueInteger('SpeakerVolume', $target['volume']);
                    $ret[$target['target']] = ['volume' => $target['volume']];
                    break;

                case 'headphone':
                    $this->SetValueInteger('HeadphoneVolume', $target['volume']);
                    $ret[$target['target']] = ['volume' => $target['volume']];
                    break;

                default:
                    trigger_error('Unerwarteter Target: '.$target['target']);

                    break;

            }
        }

        return $ret;
    }


    public function SendRemoteKey($Value) {
        $RemoteControllerInfo = json_decode($this->ReadPropertyString('RemoteControllerInfo'), true);

        $codes = $RemoteControllerInfo['result'][1];

        $tv_ip = $this->ReadPropertyString('Host');
        $cookie = $this->ReadPropertyString('Cookie');

        $data  = '<?xml version="1.0"?>';
        $data .= '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">';
        $data .= '   <s:Body>';
        $data .= '      <u:X_SendIRCC xmlns:u="urn:schemas-sony-com:service:IRCC:1">';
        $data .= '         <IRCCCode>'.$codes[$Value]['value'].'</IRCCCode>';
        $data .= '      </u:X_SendIRCC>';
        $data .= '   </s:Body>';
        $data .= '</s:Envelope>';

        $headers = [];
        if ($cookie != ''){
            $headers[] = "Cookie: " . $cookie;
        }
        $headers[] = "Content-Type: text/xml; charset=UTF-8";
        $headers[] = "Content-Length: " . strlen($data);
        $headers[] = 'SOAPAction: "urn:schemas-sony-com:service:IRCC:1#X_SendIRCC"';

        $this->sendCurlPost($tv_ip, 'IRCC', $headers, $data, true);
        }

    private function callPostRequest($service, $cmd, $params, $headers, $returnHeader, $version){
        $tv_ip = $this->ReadPropertyString('Host');
        $cookie = $this->ReadPropertyString('Cookie');

        if ($cookie != ''){
            $headers[] = "Cookie: " . $cookie;
        }

        $data = '{"method":"'.$cmd.'","params":'.$params.',"id":'. $this->InstanceID.', "version":"'.$version.'"}';

        return $this->sendCurlPost($tv_ip, $service, $headers, $data, $returnHeader);

    }


    private function sendCurlPost($tvip, $service, $headers, $data, $returnHeader) {
        parent::SendDebug('send:' , $data, 0);
        $ch = curl_init('http://'. $tvip . '/sony/'.$service);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if (count($headers)){
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        curl_setopt($ch, CURLOPT_HEADER, $returnHeader); //Header im Output?
        $ausgabe = curl_exec($ch);
        curl_close($ch);
        parent::SendDebug('received:', $ausgabe, 0);
        return $ausgabe;
    }

    private function WriteRemoteControllerInfoProfile(String $RemoteControllerInfo){
        $codes = json_decode($RemoteControllerInfo, true)['result'][1];

        $ass = [];
        foreach ($codes as $key => $code){
            $ass[]= [$key, $code['name'],  '', -1];
        }

        var_dump ($ass);
        $this->CreateProfileIntegerAss('STV.RemoteKey', '', '', '', 0, 0, $ass);

}
    private function SetValueInteger($Ident, $Value)
    {
        $ID = $this->GetIDForIdent($Ident);
        if (GetValueInteger($ID) <> $Value)
        {
            SetValueInteger($ID, intval($Value));
            return true;
        }
        return false;
    }

    private function SetValueString($Ident, $Value)
    {
        $ID = $this->GetIDForIdent($Ident);
        if (GetValueString($ID) <> $Value)
        {
            SetValueString($ID, strval($Value));
            return true;
        }
        return false;
    }

    private function GetAuthorizationParams()
    {
        $Nickname = $this->ReadPropertyString('Nickname');
        $uuid = $this->ReadPropertyString('UUID');
        return  [ ['clientid' => $uuid,
                     'nickname' => $Nickname,
                     'level' => 'private'
        ]
            ,
            [ ['function' => 'WOL',
               'value' => 'yes']
            ]];
    }

    private function checkProfileType($ProfileName, $VarType)
    {
        $profile = IPS_GetVariableProfile($ProfileName);
        if ($profile['ProfileType'] != $VarType) {
            throw new Exception('Variable profile type does not match for already existing profile "'.$ProfileName.'". The existing profile has to be deleted manually.'.PHP_EOL);
        }
    }

    private function CreateProfileInteger($ProfileName, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $StepSize, $Digits)
    {
        if (!IPS_VariableProfileExists($ProfileName)) {
            IPS_CreateVariableProfile($ProfileName, 1);

            $this->SendDebug('Variablenprofil angelegt: ', $ProfileName, 0);
            IPS_LogMessage('Sony TV', 'Variablenprofil angelegt: '.$ProfileName);
        } else {
            $this->checkProfileType($ProfileName, IPSVarType::vtInteger);
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
        $this->RegisterPropertyString('Nickname', 'Symcon (' . gethostname() . ')');
        $this->RegisterPropertyString('UUID', uniqid());
        $this->RegisterPropertyString('Cookie', '');

        // interne Properties
        $this->RegisterPropertyString('RemoteControllerInfo', '');
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
            $this->CreateProfileIntegerAss(
                'STV.RemoteKey', '', '', '', 0, 0,
                [
                    [0, '- noch nicht ermittelt -', '', -1],
                ]
            );
        }
        $this->RegisterVariableInteger('PowerStatus', 'Power Status', 'STV.PowerStatus', 10);
        $this->RegisterVariableInteger('SpeakerVolume', 'Speaker Volume', '', 20);
        $this->RegisterVariableInteger('HeadphoneVolume', 'Headphone Volume', '', 30);
        $this->RegisterVariableInteger('SendRemoteKey', 'Sende FB Taste', 'STV.RemoteKey', 40);

        // Aktivieren der Statusvariablen
        $this->EnableAction('PowerStatus');
        $this->EnableAction('SendRemoteKey');
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
            } elseif ($this->GetPowerStatus() > 0){
                $this->SetStatus(IS_ACTIVE);
            } else {
                $this->SetStatus(IS_INACTIVE);
            }

        } else {
            $this->SetStatus(self::STATUS_INST_IP_IS_INVALID); //IP Adresse ist ungültig
        }


    }

}
