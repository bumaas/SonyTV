<?php /** @noinspection ALL */

/** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

class SonyDiscovery extends IPSModule
{
    private const MODID_SSDP = '{FFFFA648-B296-E785-96ED-065F7CEE6F29}';

    private const PROPERTY_TARGET_CATEGORY_ID = 'targetCategoryID';

    private const MODID_SONY_TV = '{3B91F3E3-FB8F-4E3C-A4BB-4E5C92BBCD58}';



    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        //we will wait until the kernel is ready
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    /**
     * Interne Funktion des SDK.
     */
    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->SetStatus(IS_ACTIVE);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if (($Message === IPS_KERNELMESSAGE) && ($Data[0] === KR_READY)) {
            $this->ApplyChanges();
        }
    }

    /**
     * Liefert alle Geräte.
     *
     * @return array configlist all devices
     * @throws \JsonException
     */
    private function getDeviceValues(): array
    {
        $configuredDevices = $this->getConfiguredDevices();
        $this->logDevices('Configured Devices', $configuredDevices);

        $discoveredDevices = $this->getDiscoveredDevices();
        $this->logDevices('Discovered Devices', $discoveredDevices);

        $configurationValues = $this->getDeviceConfig($discoveredDevices, $configuredDevices);
        // Check configured, but not discovered (i.e. offline) devices
        $this->checkConfiguredDevices($configuredDevices, $configurationValues);

        return $configurationValues;
    }

    private function getConfiguredDevices(): array
    {
        return IPS_GetInstanceListByModuleID(self::MODID_SONY_TV);
    }

    private function getDiscoveredDevices(): array
    {
        return $this->DiscoverDevices();
    }

    private function logDevices(string $title, array $devices): void
    {
        $message = json_encode($devices, JSON_THROW_ON_ERROR);
        $this->logDebug($title, $message);
    }

    private function logDebug(string $title, string $message): void
    {
        $this->SendDebug($title, $message, 0);
    }

    private function getDeviceConfig($devices, $configuredDevices): array
    {
        $config_values = [];
        foreach ($devices as $device) {
            $instanceID   = 0;
            $host         = $device['host'];
            $model        = $device['modelName'];
            $manufacturer = $device['manufacturer'];

            foreach ($configuredDevices as $deviceID) {
                if ($host === IPS_GetProperty($deviceID, 'Host')) {
                    //device is already configured
                    $instanceID = $deviceID;
                }
            }

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

    private function checkConfiguredDevices($configuredDevices, &$config_values): void
    {
        foreach ($configuredDevices as $id) {
            if (!in_array($id, array_column($config_values, 'instanceID'), true)) {
                $config_values [] = [
                    'host'         => IPS_GetProperty($id, 'Host'),
                    'manufacturer' => $this->translate('unknown'),
                    'model'        => $this->translate('unknown'),
                    'instanceID'   => $id,
                    'create'       => []
                ];
            }
        }
    }


    /**
     * @throws \JsonException
     */
    private function DiscoverDevices(): array
    {

        $ssdp_id     = IPS_GetInstanceListByModuleID(self::MODID_SSDP)[0];
        $searchTarget = 'urn:schemas-sony-com:service:ScalarWebAPI:1';
        $devices     = YC_SearchDevices($ssdp_id, $searchTarget);
        $device_info = $this->receiveDevicesInfo($devices);
        //print_r($device_info);

        // zum Test wird der Eintrag verdoppelt und eine abweichende IP eingesetzt
        //$device_info[]=$device_info[0];
        //$device_info[1]['host']='192.168.178.34';

        return $device_info;
    }


    private function receiveDevicesInfo(array $devices): array
    {
        $devicesInfo = [];

        foreach ($devices as $device) {
            // Check if Server key exists and Fedora is found in its value
            if (isset($device['Server']) && (strpos($device['Server'], 'Fedora') !== false)) {
                $locationInfo = $this->getDeviceInfoFromLocation($device['Location']);
                // Add to existing device info array
                $devicesInfo[] = [
                    'host'         => $device['IPv4'],
                    'manufacturer' => $locationInfo['manufacturer'],
                    'modelName'    => $locationInfo['modelName']
                ];
            }
        }

        return $devicesInfo;
    }

    /**
     * Parses header data and returns an array with parsed values.
     *
     * @param string $headerData The header data to parse.
     *
     * @return array The parsed header data as an associative array, where the keys are the uppercase header names
     *   and the values are the trimmed header values.
     *
     */
    private function parseHeaderData(string $headerData): array
    {
        $headerLines = explode("\r\n", $headerData);
        array_shift($headerLines);
        array_pop($headerLines);
        $parsedHeaderData = [];
        foreach ($headerLines as $headerLine) {
            $headerInfo                           = $this->parseHeaderLine($headerLine);
            $parsedHeaderData[$headerInfo['key']] = $headerInfo['value'];
        }
        return $parsedHeaderData;
    }

    private function parseHeaderLine(string $headerLine): array
    {
        $headerLineParts = explode(':', $headerLine);
        return [
            'key'   => strtoupper(trim(array_shift($headerLineParts))),
            'value' => trim(implode(':', $headerLineParts))
        ];
    }

    private function getDeviceInfoFromLocation(string $location): array
    {
        // default device info
        $deviceInfo = ['manufacturer' => '', 'modelName' => 'Model'];

        $deviceDescriptionXML = $this->getXML($location);
        $deviceInfoXML        = @simplexml_load_string($deviceDescriptionXML);

        if ($deviceInfoXML) {
            $deviceInfo['manufacturer'] = (string)$deviceInfoXML->device->manufacturer;
            $deviceInfo['modelName']    = (string)$deviceInfoXML->device->modelName;
        }

        return $deviceInfo;
    }


    private function GetXML(string $url): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2); //timeout after 2 seconds
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);   //get status code
        $result      = curl_exec($ch);
        $this->logDebug('Get XML:', sprintf('URL: %s, Status: %s, result: %s', $url, $status_code, $result));
        curl_close($ch);
        return $result;
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
        $elements = [];
        $actions  = $this->formActions();
        $status   = [];

        $configurationForm = json_encode(compact('elements', 'actions', 'status'), JSON_THROW_ON_ERROR);
        $this->logDebug('FORM', $configurationForm);
        $this->logDebug('FORM', json_last_error_msg());
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
        return [
            [
                'name'     => 'DenonDiscovery',
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
                'values'   => $this->getDeviceValues()
            ]
        ];
    }

}
