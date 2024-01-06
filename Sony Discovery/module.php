<?php /** @noinspection ALL */

/** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

class SonyDiscovery extends IPSModule
{

    private const PROPERTY_TARGET_CATEGORY_ID = 'targetCategoryID';

    private const WS_DISCOVERY = [
        'TIMEOUT'           => 2,
        'MULTICAST_ADDRESS' => '239.255.255.250',
        'MULTICAST_PORT'    => 1900,
        'ST'                => 'urn:schemas-sony-com:service:ScalarWebAPI:1'
    ];

    private const MODID_SONY_TV = '{3B91F3E3-FB8F-4E3C-A4BB-4E5C92BBCD58}';

    private const MAX_RECEIVE_SIZE      = 2048;
    private const RECEIVE_TIMEOUT_SECS  = 2;
    private const RECEIVE_TIMEOUT_USECS = 100000;
    private const MULTICAST_TTL         = 4;


    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger(self::PROPERTY_TARGET_CATEGORY_ID, 0);

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

    private function getPathOfCategory(int $categoryId): array
    {
        if ($categoryId === 0) {
            return [];
        }

        $path[]   = IPS_GetName($categoryId);
        $parentId = IPS_GetObject($categoryId)['ParentID'];

        while ($parentId > 0) {
            $path[]   = IPS_GetName($parentId);
            $parentId = IPS_GetObject($parentId)['ParentID'];
        }

        return array_reverse($path);
    }

    /**
     * Liefert alle Geräte.
     *
     * @return array configlist all devices
     * @throws \JsonException
     */
    private function getDeviceValues(): array
    {
        $configuredDevices   = $this->getConfiguredDevices();
        $discoveredDevices   = $this->getDiscoveredDevices();
        $configurationValues = $this->getDeviceConfig($discoveredDevices, $configuredDevices);

        // Check configured, but not discovered (i.e. offline) devices
        $this->checkConfiguredDevices($configuredDevices, $configurationValues);

        return $configurationValues;
    }

    private function getConfiguredDevices(): array
    {
        $devices = IPS_GetInstanceListByModuleID(self::MODID_SONY_TV);
        $message = json_encode($devices, JSON_THROW_ON_ERROR);
        $this->logDebug('Configured Devices', $message);
        return $devices;
    }

    private function getDiscoveredDevices(): array
    {
        $devices = $this->DiscoverDevices();
        $message = json_encode($devices, JSON_THROW_ON_ERROR);
        $this->logDebug('Discovered Devices', $message);
        return $devices;
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
                        ],
                        'location'      => $this->getPathOfCategory($this->ReadPropertyInteger(self::PROPERTY_TARGET_CATEGORY_ID))
                    ],
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
        $SendData = $this->buildMessage();
        $socket   = $this->createAndConfigureSocket();

        if (!$socket) {
            return [];
        }

        $this->logDebug('Search', $SendData);
        if ($this->sendDataToSocket($socket, $SendData) === false) {
            return [];
        }

        $device_info = $this->receiveDevicesInfo($socket);
        //print_r($device_info);

        // CLOSE SOCKET
        socket_close($socket);

        // zum Test wird der Eintrag verdoppelt und eine abweichende IP eingesetzt
        //$device_info[]=$device_info[0];
        //$device_info[1]['host']='192.168.178.34';

        return $device_info;
    }

    // Extracted method for building message
    private function buildMessage(): string
    {
        $message = [
            'M-SEARCH * HTTP/1.1',
            'HOST: ' . self::WS_DISCOVERY['MULTICAST_ADDRESS'] . ':' . self::WS_DISCOVERY['MULTICAST_PORT'],
            'MAN: "ssdp:discover"',
            'MX: ' . self::WS_DISCOVERY['TIMEOUT'],
            'ST: ' . self::WS_DISCOVERY['ST']
        ];
        return implode("\r\n", $message) . "\r\n\r\n";
    }

    // Extracted method for creating and configuring socket
    private function createAndConfigureSocket()
    {
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket) {
            $this->logDebug('----' . __FUNCTION__, 'ST: ' . self::WS_DISCOVERY['ST']);
            socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, true);
            socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, true);
            socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => self::RECEIVE_TIMEOUT_SECS, 'usec' => self::RECEIVE_TIMEOUT_USECS]);
            socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_TTL, self::MULTICAST_TTL);
        }
        return $socket;
    }

    private function sendDataToSocket($socket, string $SendData): bool
    {
        return (bool)@socket_sendto(
            $socket,
            $SendData,
            strlen($SendData),
            0,
            self::WS_DISCOVERY['MULTICAST_ADDRESS'],
            self::WS_DISCOVERY['MULTICAST_PORT']
        );
    }

    private function receiveDevicesInfo($socket): array
    {
        $devicesInfo  = [];
        $ipAddress    = '';
        $port         = 0;
        $timeoutLimit = time() + self::WS_DISCOVERY['TIMEOUT'];

        // Loop until the timeout limit
        do {
            $buffer        = null;
            $receivedBytes = @socket_recvfrom($socket, $buffer, self::MAX_RECEIVE_SIZE, 0, $ipAddress, $port);

            // Check if bytes are received, break the loop if false.
            if ((bool)$receivedBytes === false) {
                break;
            }

            $this->logDebug(sprintf('Receive (%s:%s)', $ipAddress, $port), (string)$buffer);

            if (!is_null($buffer)) {
                $device = $this->parseHeaderData($buffer);
                $this->logDebug('header', json_encode($device, JSON_THROW_ON_ERROR));

                // Check if Server key exists and Fedora is found in its value
                if (isset($device['SERVER']) && (strpos($device['SERVER'], 'Fedora') !== false)) {
                    $locationInfo = $this->getDeviceInfoFromLocation($device['LOCATION']);
                    // Add to existing device info array
                    $devicesInfo[] = [
                        'host'         => $ipAddress,
                        'manufacturer' => $locationInfo['manufacturer'],
                        'modelName'    => $locationInfo['modelName']
                    ];
                }
            }
        } while (time() < $timeoutLimit);

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
            $headerLineParts                                                   = explode(':', $headerLine);
            $parsedHeaderData[strtoupper(trim(array_shift($headerLineParts)))] = trim(implode(':', $headerLineParts));
        }
        return $parsedHeaderData;
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
        $elements = $this->formElements();
        $actions  = $this->formActions();
        $status   = [];

        $configurationForm = json_encode(compact('elements', 'actions', 'status'), JSON_THROW_ON_ERROR);
        $this->logDebug('FORM', $configurationForm);
        $this->logDebug('FORM', json_last_error_msg());
        return $configurationForm;
    }

    /**
     * return form elements
     *
     * @return array
     */
    private function formElements(): array
    {
        return [
            [
                'type'    => 'SelectCategory',
                'name'    => 'targetCategoryID',
                'caption' => 'Target Category'
            ]
        ];
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
