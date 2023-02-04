<?php
/** @noinspection PhpUnused */
/** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

class SonyDiscovery extends IPSModule
{

    private const PROPERTY_TARGET_CATEGORY_ID = 'targetCategoryID';
    /**
     * The maximum number of seconds that will be allowed for the discovery request.
     */
    private const WS_DISCOVERY_TIMEOUT = 2;

    /**
     * The multicast address to use in the socket for the discovery request.
     */
    private const WS_DISCOVERY_MULTICAST_ADDRESS = '239.255.255.250';

    /**
     * The port that will be used in the socket for the discovery request.
     */
    private const WS_DISCOVERY_MULTICAST_PORT = 1900;

    private const WS_DISCOVERY_ST = 'urn:schemas-sony-com:service:ScalarWebAPI:1';

    private const MODID_SONY_TV = '{3B91F3E3-FB8F-4E3C-A4BB-4E5C92BBCD58}';

    public function Create()
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
    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->SetStatus(IS_ACTIVE);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
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
    private function Get_ConfiguratorValues(): array
    {
        $config_values = [];

        $configuredDevices = IPS_GetInstanceListByModuleID(self::MODID_SONY_TV);
        $this->SendDebug('configured devices', json_encode($configuredDevices, JSON_THROW_ON_ERROR), 0);

        $discoveredDevices = $this->DiscoverDevices();
        $this->SendDebug('discovered devices', json_encode($discoveredDevices, JSON_THROW_ON_ERROR), 0);

        foreach ($discoveredDevices as $device) {
            $instanceID   = 0;
            $name         = $device['friendlyName'];
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
                'instanceID'   => $instanceID,
                'name'         => $name,
                'host'         => $host,
                'model'        => $model,
                'manufacturer' => $manufacturer,
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


    /**
     * @throws \JsonException
     */
    private function DiscoverDevices(): array
    {
        // BUILD MESSAGE
        $message  = [
            'M-SEARCH * HTTP/1.1',
            'HOST: 239.255.255.250:1900',
            'MAN: "ssdp:discover"',
            'MX: 2',                    // maximum amount of seconds it takes for a device to respond
            'ST: ' . self::WS_DISCOVERY_ST       //This defines the devices we would like to discover on the network.
        ];
        $SendData = implode("\r\n", $message) . "\r\n\r\n";

        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->SendDebug('----' . __FUNCTION__, 'ST: ' . self::WS_DISCOVERY_ST, 0);
        if (!$socket) {
            return [];
        }

        socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, true);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, true);
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 100000]);
        socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_TTL, 4);

        $this->SendDebug('Search', $SendData, 0);
        if (@socket_sendto($socket, $SendData, strlen($SendData), 0, self::WS_DISCOVERY_MULTICAST_ADDRESS, self::WS_DISCOVERY_MULTICAST_PORT)
            === false) {
            return [];
        }

        // RECEIVE RESPONSE
        $device_info      = [];
        $IPAddress        = '';
        $Port             = 0;
        $discoveryTimeout = time() + self::WS_DISCOVERY_TIMEOUT;

        do {
            $buf   = null;
            $bytes = @socket_recvfrom($socket, $buf, 2048, 0, $IPAddress, $Port);
            if ((bool)$bytes === false) {
                break;
            }
            $this->SendDebug(sprintf('Receive (%s:%s)', $IPAddress, $Port), (string)$buf, 0);

            if (!is_null($buf)) {
                $device = $this->parseHeader($buf);
                $this->SendDebug('header', json_encode($device, JSON_THROW_ON_ERROR), 0);
                if (isset($device['SERVER']) && (strpos($device['SERVER'], 'Fedora') >= 0)) {
                    $locationInfo  = $this->GetDeviceInfoFromLocation($device['LOCATION']);
                    $device_info[] = [
                        'host'         => $IPAddress,
                        'friendlyName' => $locationInfo['friendlyName'],
                        'manufacturer' => $locationInfo['manufacturer'],
                        'modelName'    => $locationInfo['modelName']
                    ];
                }
            }
        } while (time() < $discoveryTimeout);

        // CLOSE SOCKET
        socket_close($socket);

        // zum Test wird der Eintrag verdoppelt und eine abweichende IP eingesetzt
        //$denon_info[]=$denon_info[0];
        //$denon_info[1]['host']='192.168.178.34';

        return $device_info;
    }

    private function parseHeader(string $Data): array
    {
        $Lines = explode("\r\n", $Data);
        array_shift($Lines);
        array_pop($Lines);
        $Header = [];
        foreach ($Lines as $Line) {
            $line_array                                         = explode(':', $Line);
            $Header[strtoupper(trim(array_shift($line_array)))] = trim(implode(':', $line_array));
        }
        return $Header;
    }

    private function GetDeviceInfoFromLocation(string $location): array
    {
        $manufacturer = '';
        $friendlyName = 'Name';
        $modelName    = 'Model';

        $description = $this->GetXML($location);
        $xml         = @simplexml_load_string($description);
        if ($xml) {
            $manufacturer = (string)$xml->device->manufacturer;
            $friendlyName = (string)$xml->device->friendlyName;
            $modelName    = (string)$xml->device->modelName;
        }
        return ['manufacturer' => $manufacturer, 'friendlyName' => $friendlyName, 'modelName' => $modelName];
    }


    private function GetXML(string $url): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2); //timeout after 2 seconds
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);   //get status code
        $result      = curl_exec($ch);
        $this->SendDebug('Get XML:', sprintf('URL: %s, Status: %s, result: %s', $url, $status_code, $result), 0);
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
        // return current form
        $Form = json_encode([
                                'elements' => $this->FormElements(),
                                'actions'  => $this->FormActions(),
                                'status'   => []
                            ], JSON_THROW_ON_ERROR);
        $this->SendDebug('FORM', $Form, 0);
        $this->SendDebug('FORM', json_last_error_msg(), 0);
        return $Form;
    }

    /**
     * return form elements
     *
     * @return array
     */
    private function FormElements(): array
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
    private function FormActions(): array
    {
        return [
            [
                'name'     => 'DenonDiscovery',
                'type'     => 'Configurator',
                'rowCount' => 20,
                'add'      => false,
                'delete'   => true,
                'sort'     => [
                    'column'    => 'name',
                    'direction' => 'ascending'
                ],
                'columns'  => [
                    [
                        'caption' => 'ID',
                        'name'    => 'id',
                        'width'   => '200px',
                        'visible' => false
                    ],
                    [
                        'caption' => 'name',
                        'name'    => 'name',
                        'width'   => 'auto'
                    ],
                    [
                        'caption' => 'manufacturer',
                        'name'    => 'manufacturer',
                        'width'   => '250px'
                    ],
                    [
                        'caption' => 'model',
                        'name'    => 'model',
                        'width'   => '250px'
                    ],
                    [
                        'caption' => 'host',
                        'name'    => 'host',
                        'width'   => '250px'
                    ]
                ],
                'values'   => $this->Get_ConfiguratorValues()
            ]
        ];
    }

}
