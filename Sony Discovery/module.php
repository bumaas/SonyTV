<?php

/** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

class SonyDiscovery extends IPSModuleStrict
{
    private const string MODID_SSDP    = '{FFFFA648-B296-E785-96ED-065F7CEE6F29}';
    private const string MODID_SONY_TV = '{3B91F3E3-FB8F-4E3C-A4BB-4E5C92BBCD58}';

    private const string DISCOVERY_SEARCHTARGET = 'urn:schemas-sony-com:service:ScalarWebAPI:1';
    private const string BUFFER_DEVICES         = 'Devices';
    private const string BUFFER_SEARCHACTIVE    = 'SearchActive';
    private const string TIMER_LOADDEVICES      = 'LoadDevicesTimer';

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        //we will wait until the kernel is ready
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        $this->SetBuffer(self::BUFFER_DEVICES, json_encode([], JSON_THROW_ON_ERROR));
        $this->SetBuffer(self::BUFFER_SEARCHACTIVE, json_encode(false, JSON_THROW_ON_ERROR));
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->SetStatus(IS_ACTIVE);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if (($Message === IPS_KERNELMESSAGE) && ($Data[0] === KR_READY)) {
            $this->ApplyChanges();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        $this->SendDebug(__FUNCTION__, sprintf('Ident: %s, Value: %s', $Ident, $Value), 0);

        if ($Ident === 'loadDevices') {
            $this->loadDevices();
        }
    }

    /**
     * Liefert alle Geräte.
     *
     * @return void
     * @throws \JsonException
     */
    private function loadDevices(): void
    {
        $configuredDevices = $this->getConfiguredDevices();
        $this->logDevices('Configured Devices', $configuredDevices);

        $discoveredDevices = $this->getDiscoveredDevices();
        $this->logDevices('Discovered Devices', $discoveredDevices);

        $configurationValues = $this->getDeviceConfig($discoveredDevices, $configuredDevices);
        // Check configured, but not discovered (i.e., offline) devices
        $this->checkConfiguredDevices($configuredDevices, $configurationValues);
        $configurationValuesEncoded = json_encode($configurationValues, JSON_THROW_ON_ERROR);
        $this->SendDebug(__FUNCTION__, '$configurationValues: ' . $configurationValuesEncoded, 0);

        $this->SetBuffer(self::BUFFER_SEARCHACTIVE, json_encode(false, JSON_THROW_ON_ERROR));
        $this->SendDebug(__FUNCTION__, 'SearchActive deactivated', 0);

        $this->SetBuffer(self::BUFFER_DEVICES, $configurationValuesEncoded);
        $this->UpdateFormField('configurator', 'values', $configurationValuesEncoded);
        $this->UpdateFormField('searchingInfo', 'visible', false);
    }

    private function getConfiguredDevices(): array
    {
        return IPS_GetInstanceListByModuleID(self::MODID_SONY_TV);
    }

    private function getDiscoveredDevices(): array
    {
        $ssdpInstanceIDs = IPS_GetInstanceListByModuleID(self::MODID_SSDP);
        if (count($ssdpInstanceIDs) === 0) {
            $this->SendDebug(__FUNCTION__, 'SSDP Instance not found', 0);
            return [];
        }

        $ssdpID = $ssdpInstanceIDs[0];
        $devices     = YC_SearchDevices($ssdpID, self::DISCOVERY_SEARCHTARGET);
        $deviceInfo = $this->receiveDevicesInfo($devices);

        //print_r($device_info);

        // zum Test wird der Eintrag verdoppelt und eine abweichende IP eingesetzt
        //$device_info[]=$device_info[0];
        //$device_info[1]['host']='192.168.178.34';

        return $deviceInfo;
    }

    private function logDevices(string $title, array $devices): void
    {
        $this->SendDebug($title, json_encode($devices, JSON_THROW_ON_ERROR), 0);
    }

    private function getDeviceConfig(array $devices, array $configuredDevices): array
    {
        $config_values = [];

        // Erstelle ein Mapping von Host zu InstanceID (O(n))
        $hostToInstanceID = [];
        foreach ($configuredDevices as $deviceID) {
            $host                    = IPS_GetProperty($deviceID, 'Host');
            $hostToInstanceID[$host] = $deviceID;
        }

        foreach ($devices as $device) {
            $host         = $device['host'];
            $model        = $device['modelName'];
            $manufacturer = $device['manufacturer'];

            // Schneller Zugriff über das Mapping (O(1))
            $instanceID = $hostToInstanceID[$host] ?? 0;

            $config_values[] = [
                'host'         => $host,
                'manufacturer' => $manufacturer,
                'model'        => $model,
                'instanceID'   => $instanceID,
                'create'       => [
                    [
                        'moduleID'      => self::MODID_SONY_TV,
                        'configuration' => [
                            'Host' => $host
                        ]
                    ]
                ]
            ];
        }
        return $config_values;
    }

    private function checkConfiguredDevices(array $configuredDevices, array &$config_values): void
    {
        $discoveredInstanceIDs = array_flip(array_column($config_values, 'instanceID'));

        foreach ($configuredDevices as $id) {
            if (!isset($discoveredInstanceIDs[$id])) {
                $config_values [] = [
                    'host'         => IPS_GetProperty($id, 'Host'),
                    'manufacturer' => $this->Translate('unknown'),
                    'model'        => $this->Translate('unknown'),
                    'instanceID'   => $id,
                    'create'       => []
                ];
            }
        }
    }

    private function receiveDevicesInfo(array $devices): array
    {
        $devicesInfo = [];

        foreach ($devices as $device) {
            // Check if the Server key exists and Fedora is found in its value
            if (isset($device['Server']) && (str_contains($device['Server'], 'Fedora'))) {
                $locationInfo = $this->getDeviceInfoFromLocation($device['Location']);
                // Add to an existing device info array
                $devicesInfo[] = [
                    'host'         => $device['IPv4'],
                    'manufacturer' => $locationInfo['manufacturer'],
                    'modelName'    => $locationInfo['modelName']
                ];
            }
        }

        return $devicesInfo;
    }

    private function getDeviceInfoFromLocation(string $location): array
    {
        // default device info
        $deviceInfo = ['manufacturer' => 'Sony', 'modelName' => 'Model'];

        $deviceDescriptionXML = $this->fetchXml($location);
        if ($deviceDescriptionXML === '') {
            return $deviceInfo;
        }

        $xml = @simplexml_load_string($deviceDescriptionXML);
        if ($xml instanceof SimpleXMLElement) {
            $deviceInfo['manufacturer'] = (string)($xml->device->manufacturer ?? 'Sony');
            $deviceInfo['modelName']    = (string)($xml->device->modelName ?? 'Bravia TV');
        }

        return $deviceInfo;
    }

    private function fetchXml(string $url): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT        => 2,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $result    = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError !== '') {
            $this->SendDebug(__FUNCTION__, 'CURL Error: ' . $curlError, 0);
            return '';
        }

        if ($httpCode !== 200) {
            $this->SendDebug(__FUNCTION__, 'HTTP Status Code: ' . $httpCode, 0);
            return '';
        }

        return (string)$result;
    }

    /***********************************************************
     * Configuration Form
     ***********************************************************/

    /**
     * build configuration form.
     *
     * @return string
     * @throws \JsonException
     */
    public function GetConfigurationForm(): string
    {
        $this->SendDebug(__FUNCTION__, 'Start', 0);
        $this->SendDebug(__FUNCTION__, 'SearchActive: ' . $this->GetBuffer(self::BUFFER_SEARCHACTIVE), 0);

        // Do not start a new search if a search is currently active
        if (!json_decode($this->GetBuffer(self::BUFFER_SEARCHACTIVE), false, 512, JSON_THROW_ON_ERROR)) {
            $this->SetBuffer(self::BUFFER_SEARCHACTIVE, json_encode(true, JSON_THROW_ON_ERROR));

            // Start device search in a timer, not prolonging the execution of GetConfigurationForm
            $this->SendDebug(__FUNCTION__, 'RegisterOnceTimer', 0);
            $this->RegisterOnceTimer(self::TIMER_LOADDEVICES, 'IPS_RequestAction($_IPS["TARGET"], "loadDevices", "");');
        }

        $elements = [];
        $actions  = $this->formActions();
        $status   = [];

        $configurationForm = json_encode(compact('elements', 'actions', 'status'), JSON_THROW_ON_ERROR);
        $this->SendDebug('FORM', $configurationForm, 0);
        $this->SendDebug('FORM', json_last_error_msg(), 0);
        return $configurationForm;
    }

    /**
     * return form actions
     *
     * @return array
     * @throws \JsonException
     */
    private function formActions(): array
    {
        $devices = json_decode($this->GetBuffer(self::BUFFER_DEVICES), false, 512, JSON_THROW_ON_ERROR);

        return [
            // Inform user that the search for devices could take a while if no devices were found yet
            [
                'name'          => 'searchingInfo',
                'type'          => 'ProgressBar',
                'caption'       => 'The configurator is currently searching for devices. This could take a while...',
                'indeterminate' => true,
                'visible'       => count($devices) === 0
            ],

            [
                'name'     => 'configurator',
                'type'     => 'Configurator',
                'rowCount' => 20,
                'add'      => false,
                'delete'   => true,
                'sort'     => [
                    'column'    => 'host',
                    'direction' => 'ascending'
                ],
                'columns'  => [
                    [
                        'caption' => 'host',
                        'name'    => 'host',
                        'width'   => '250px'
                    ],
                    [
                        'caption' => 'manufacturer',
                        'name'    => 'manufacturer',
                        'width'   => '250px'
                    ],
                    [
                        'caption' => 'model',
                        'name'    => 'model',
                        'width'   => 'auto'
                    ]
                ],
                'values'   => $devices
            ]
        ];
    }
}
