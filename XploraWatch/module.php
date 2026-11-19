<?php

declare(strict_types=1);

/**
 * Xplora Watch
 *
 * One instance per watch. Reads the last location of the watch through the
 * Xplora Cloud instance: position, accuracy, address, safe zone, battery and
 * charging state. Optionally calculates the distance to a home location and
 * asks the watch for a new location when the last one is too old.
 */
class XploraWatch extends IPSModule
{
    private const DATA_TO_PARENT = '{690D7FA6-98B2-422C-A627-9FBFF58D1604}';
    private const LOCATION_CONTROL = '{45E97A63-F870-408A-B259-2933F7EABF74}';
    private const WEBHOOK_CONTROL = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';
    private const MIN_INTERVAL = 60;
    private const FIRST_UPDATE_DELAY = 5;
    private const MAX_ACCURACY_BONUS = 200;
    private const COORDINATE_DIGITS = 7;
    private const GEOCODER = 'https://nominatim.openstreetmap.org/search';
    private const REVERSE_GEOCODER = 'https://nominatim.openstreetmap.org/reverse';
    private const PLACE_CACHE_DISTANCE = 300;
    private const USER_AGENT = 'User-Agent: SymconXplora (+https://github.com/cbeham1/SymconXplora)';

    private const LOCATE_UPDATE_DELAY = 60;

    private const STATUS_NO_WARD = 201;
    private const STATUS_REQUEST_FAILED = 202;

    public function Create(): void
    {
        parent::Create();

        $this->ConnectParent('{C71DBF5D-F172-4659-A8F5-EB8C943ED849}');

        $this->RegisterPropertyString('WardID', '');
        $this->RegisterPropertyInteger('UpdateInterval', 300);
        $this->RegisterPropertyInteger('LocateRequestAge', 0);
        $this->RegisterPropertyBoolean('UseSymconLocation', true);
        $this->RegisterPropertyString('HomeLocation', '{"latitude":0,"longitude":0}');
        $this->RegisterPropertyString('HomeAddress', '');
        $this->RegisterPropertyInteger('HomeRadius', 150);
        $this->RegisterPropertyString('Place2Address', '');
        $this->RegisterPropertyString('Place2Name', '');
        $this->RegisterPropertyString('Place2Location', '{"latitude":0,"longitude":0}');
        $this->RegisterPropertyInteger('Place2Radius', 150);
        $this->RegisterPropertyBoolean('ShowMap', true);
        $this->RegisterPropertyBoolean('ShowTile', true);
        $this->RegisterPropertyBoolean('InstanceTile', true);
        $this->RegisterPropertyBoolean('TileRoute', true);
        $this->RegisterPropertyBoolean('ShowMiniTile', false);
        $this->RegisterPropertyBoolean('ShowPlaceName', true);
        $this->RegisterPropertyBoolean('TileMap', true);
        $this->RegisterPropertyInteger('TileTheme', 0);
        $this->RegisterPropertyBoolean('ShowHistory', false);
        $this->RegisterPropertyInteger('HistoryDays', 15);

        $this->RegisterAttributeString('History', '[]');
        $this->RegisterAttributeString('HookToken', '');

        $this->RegisterAttributeInteger('LastLocateRequest', 0);
        $this->RegisterAttributeString('PlaceCache', '{}');
        $this->RegisterAttributeString('TileData', '');

        $this->RegisterTimer('Update', 0, 'XPLW_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();
        $home = $this->GetHome() !== null;

        // The instance itself can be placed in the tile visualization (HTML-SDK, not available in older Symcon versions):
        // status tile with button "Locate"
        if (method_exists($this, 'SetVisualizationType')) {
            $this->SetVisualizationType($this->ReadPropertyBoolean('InstanceTile') ? 1 : 0);
        }
        if ($this->InstanceTileActive() && IPS_GetKernelRunlevel() === KR_READY) {
            $this->UpdateVisualizationValue(json_encode($this->InstanceTileMessage()));
        }

        $this->MaintainVariable('Tile', $this->Translate('Status tile'), VARIABLETYPE_STRING, '~HTMLBox', 0, $this->ReadPropertyBoolean('ShowTile'));
        $this->MaintainVariable('MiniTile', $this->Translate('Mini tile'), VARIABLETYPE_STRING, '~HTMLBox', 0, $this->ReadPropertyBoolean('ShowMiniTile'));
        $this->MaintainVariable('Battery', $this->Translate('Battery'), VARIABLETYPE_INTEGER, '~Battery.100', 1, true);
        $this->MaintainVariable('Charging', $this->Translate('Charging'), VARIABLETYPE_BOOLEAN, '', 2, true);
        $this->MaintainVariable('AtHome', $this->Translate('At home'), VARIABLETYPE_BOOLEAN, '~Presence', 3, $home);
        $this->MaintainVariable('Distance', $this->Translate('Distance to home'), VARIABLETYPE_FLOAT, 'XPL.Kilometer', 4, $home);

        // Second place (e.g. school, work, second home): variable names follow the configured name
        $place2 = $this->GetPlace2() !== null;
        $place2Name = $this->GetPlace2Name();
        $this->MaintainVariable('AtPlace2', sprintf($this->Translate('At %s'), $place2Name), VARIABLETYPE_BOOLEAN, '~Presence', 4, $place2);
        $this->MaintainVariable('DistancePlace2', sprintf($this->Translate('Distance to %s'), $place2Name), VARIABLETYPE_FLOAT, 'XPL.Kilometer', 4, $place2);
        if ($place2) {
            $this->SetNameIfChanged('AtPlace2', sprintf($this->Translate('At %s'), $place2Name));
            $this->SetNameIfChanged('DistancePlace2', sprintf($this->Translate('Distance to %s'), $place2Name));
        }
        $this->MaintainVariable('InSafeZone', $this->Translate('In safe zone'), VARIABLETYPE_BOOLEAN, '', 5, true);
        $this->MaintainVariable('SafeZone', $this->Translate('Safe zone'), VARIABLETYPE_STRING, '', 6, true);
        $this->MaintainVariable('Address', $this->Translate('Address'), VARIABLETYPE_STRING, '', 7, true);
        $this->MaintainVariable('Latitude', $this->Translate('Latitude'), VARIABLETYPE_FLOAT, 'XPL.Degree', 8, true);
        $this->MaintainVariable('Longitude', $this->Translate('Longitude'), VARIABLETYPE_FLOAT, 'XPL.Degree', 9, true);
        $this->MaintainVariable('Accuracy', $this->Translate('Accuracy'), VARIABLETYPE_INTEGER, 'XPL.Meter', 10, true);
        $this->MaintainVariable('LocateType', $this->Translate('Location type'), VARIABLETYPE_STRING, '', 11, true);
        $this->MaintainVariable('LocateTime', $this->Translate('Location time'), VARIABLETYPE_INTEGER, '~UnixTimestamp', 12, true);
        $this->MaintainVariable('Map', $this->Translate('Map'), VARIABLETYPE_STRING, '~HTMLBox', 20, $this->ReadPropertyBoolean('ShowMap'));
        $this->MaintainVariable('History', $this->Translate('Location history'), VARIABLETYPE_STRING, '~HTMLBox', 21, $this->ReadPropertyBoolean('ShowHistory'));

        // Location history: page via WebHook, protected by a random token in the URL
        if ($this->ReadPropertyBoolean('ShowHistory')) {
            if ($this->ReadAttributeString('HookToken') === '') {
                $this->WriteAttributeString('HookToken', bin2hex(random_bytes(16)));
            }
            if (IPS_GetKernelRunlevel() === KR_READY) {
                $this->RegisterHook();
            } else {
                $this->RegisterMessage(0, IPS_KERNELSTARTED);
            }
            $this->SetValueIfChanged('History', '<iframe src="' . $this->HookPath() . '?t=' . $this->ReadAttributeString('HookToken')
                . '" style="width:100%;height:100%;min-height:560px;border:0;border-radius:6px"></iframe>');
        }

        if ($this->ReadPropertyString('WardID') === '') {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatus(self::STATUS_NO_WARD);
            return;
        }
        $this->SetStatus(IS_ACTIVE);

        // First update shortly after the changes are applied (not directly: during kernel start and while the
        // configurator creates the instance the data flow may not be ready yet). Update() sets the regular interval.
        $this->SetTimerInterval('Update', self::FIRST_UPDATE_DELAY * 1000);
    }

    public function Destroy(): void
    {
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->UnregisterHook();
        }
        parent::Destroy();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ($Message === IPS_KERNELSTARTED && $this->ReadPropertyBoolean('ShowHistory')) {
            $this->RegisterHook();
        }
    }

    /** Actions of the instance tile (HTML-SDK). */
    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case 'Locate':
                if ($this->RequestLocation()) {
                    // Read the new location as soon as the watch should have sent it; Update() sets the regular interval again
                    $this->SetTimerInterval('Update', self::LOCATE_UPDATE_DELAY * 1000);
                }
                break;
            default:
                throw new Exception('Invalid Ident');
        }
    }

    /**
     * HTML-SDK: instance tile. The page renders the data of InstanceTileMessage() itself (map over the whole tile,
     * battery ring, status, value boxes); updates arrive via handleMessage() without reloading.
     */
    public function GetVisualizationTile(): string
    {
        $texts = json_encode([
            'locate'   => $this->Translate('Request location'),
            'pending'  => $this->Translate('Location requested …'),
            'battery'  => $this->Translate('Battery'),
            'charging' => $this->Translate('charging'),
            'now'      => $this->Translate('now'),
            'min'      => $this->Translate('%d min'),
            'h'        => $this->Translate('%d h'),
            'd'        => $this->Translate('%d d'),
            'ago'      => $this->Translate('%s ago'),
            'address'  => $this->Translate('Address'),
            'dec'      => $this->Translate('.'),
            'tracking' => $this->Translate('Signal tracking'),
            'map'      => $this->Translate('Map'),
            'sat'      => $this->Translate('Satellite'),
            'center'   => $this->Translate('Center'),
            'histToday'=> $this->Translate('Day route'),
            'live'     => $this->Translate('Live'),
            'awaySince'=> $this->Translate('away since %s'),
            'homeSince'=> $this->Translate('at home since %s'),
            'route'    => $this->Translate('Route today'),
            'away'     => $this->Translate('Away from home today'),
            'locs'     => $this->Translate('%d locations'),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        $initial = json_encode($this->InstanceTileMessage(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return strtr((string) file_get_contents(__DIR__ . '/tile.html'), [
            '%TEXTS%'   => $texts,
            '%THEME%'   => (string) $this->ReadPropertyInteger('TileTheme'),
            '%INITIAL%' => json_encode($initial, JSON_HEX_TAG),
        ]);
    }

    public function ReceiveData($JSONString): string
    {
        // The cloud instance does not push data
        return '';
    }

    /** Reads the last location of the watch and updates the variables. */
    public function Update(): bool
    {
        $wardID = $this->ReadPropertyString('WardID');
        if ($wardID === '') {
            return false;
        }
        $this->SetTimerInterval('Update', max(self::MIN_INTERVAL, $this->ReadPropertyInteger('UpdateInterval')) * 1000);
        if (!$this->HasActiveParent()) {
            $this->SendDebug('Update', 'Cloud instance not active', 0);
            return false;
        }
        $location = $this->Request('GetLastLocation');
        if (!is_array($location)) {
            return false;
        }

        if (isset($location['battery'])) {
            $this->SetValueIfChanged('Battery', max(0, min(100, (int) $location['battery'])));
        }
        $this->SetValueIfChanged('Charging', (bool) ($location['isCharging'] ?? false));

        $time = $this->ToUnixTime($location['tm'] ?? 0);
        $latitude = (float) ($location['lat'] ?? 0);
        $longitude = (float) ($location['lng'] ?? 0);
        if ($latitude == 0.0 && $longitude == 0.0) {
            return true;
        }
        $accuracy = (int) round((float) ($location['rad'] ?? 0));
        $address = trim((string) ($location['addr'] ?? ''));
        $poi = trim((string) ($location['poi'] ?? ''));
        if ($poi !== '' && !str_contains($address, $poi)) {
            $address = $poi . ', ' . $address;
        }

        $this->SetValueIfChanged('Latitude', $latitude);
        $this->SetValueIfChanged('Longitude', $longitude);
        $this->SetValueIfChanged('Accuracy', $accuracy);
        $this->SetValueIfChanged('Address', $address);
        $this->SetValueIfChanged('LocateType', (string) ($location['locateType'] ?? ''));
        $this->SetValueIfChanged('LocateTime', $time);
        $this->SetValueIfChanged('InSafeZone', (bool) ($location['isInSafeZone'] ?? false));
        $this->SetValueIfChanged('SafeZone', (string) ($location['safeZoneLabel'] ?? ''));

        $home = $this->GetHome();
        $distance = null;
        $atHome = null;
        if ($home !== null) {
            $distance = $this->Distance($home[0], $home[1], $latitude, $longitude);
            $atHome = $distance <= $this->ReadPropertyInteger('HomeRadius') + min($accuracy, self::MAX_ACCURACY_BONUS);
            $this->SetValueIfChanged('Distance', round($distance / 1000, 2));
            $this->SetValueIfChanged('AtHome', $atHome);
        }
        $place2 = $this->GetPlace2();
        $distance2 = null;
        $atPlace2 = null;
        if ($place2 !== null) {
            $distance2 = $this->Distance($place2[0], $place2[1], $latitude, $longitude);
            $atPlace2 = $distance2 <= $this->ReadPropertyInteger('Place2Radius') + min($accuracy, self::MAX_ACCURACY_BONUS);
            $this->SetValueIfChanged('DistancePlace2', round($distance2 / 1000, 2));
            $this->SetValueIfChanged('AtPlace2', $atPlace2);
        }
        if ($this->ReadPropertyBoolean('ShowMap')) {
            $this->SetValueIfChanged('Map', $this->MapHTML($latitude, $longitude, $accuracy, $home));
        }
        if ($this->ReadPropertyBoolean('ShowHistory')) {
            $this->AddHistoryPoint($time, $latitude, $longitude, $accuracy, (string) ($location['locateType'] ?? ''));
        }
        $tileData = [
            'battery'   => isset($location['battery']) ? max(0, min(100, (int) $location['battery'])) : null,
            'charging'  => (bool) ($location['isCharging'] ?? false),
            'atHome'    => $atHome,
            'distance'  => $distance,
            'atPlace2'  => $atPlace2,
            'distance2' => $distance2,
            'zone'      => (bool) ($location['isInSafeZone'] ?? false) ? (string) ($location['safeZoneLabel'] ?? '') : '',
            'time'      => $time,
            'type'      => (string) ($location['locateType'] ?? ''),
            'lat'       => $latitude,
            'lng'       => $longitude,
            'address'   => $address,
            'accuracy'  => $accuracy,
            'place'     => '',
        ];
        // Name of the town/village instead of "On the way" (only needed for the tiles and only when away)
        if (($this->ReadPropertyBoolean('ShowTile') || $this->ReadPropertyBoolean('ShowMiniTile') || $this->ReadPropertyBoolean('InstanceTile'))
            && $this->ReadPropertyBoolean('ShowPlaceName') && $atHome === false && $atPlace2 !== true) {
            $tileData['place'] = $this->NearestPlace($latitude, $longitude, trim((string) ($location['city'] ?? '')));
        }
        if ($this->ReadPropertyBoolean('ShowTile')) {
            $this->SetValueIfChanged('Tile', $this->TileHTML($tileData));
        }
        if ($this->ReadPropertyBoolean('ShowMiniTile')) {
            $this->SetValueIfChanged('MiniTile', $this->MiniTileHTML($tileData));
        }
        if ($this->InstanceTileActive()) {
            $fresh = $tileData['time'] > $this->ReadAttributeInteger('LastLocateRequest');
            $this->WriteAttributeString('TileData', json_encode($tileData));
            $this->UpdateVisualizationValue(json_encode($this->InstanceTileMessage($fresh)));
        }

        // Ask the watch for a new location if the last one is too old (result arrives with a later update)
        $maxAge = $this->ReadPropertyInteger('LocateRequestAge') * 60;
        if ($maxAge > 0 && (time() - $time) > $maxAge
            && (time() - $this->ReadAttributeInteger('LastLocateRequest')) >= $maxAge) {
            $this->RequestLocation();
        }
        return true;
    }

    /** Asks the watch to determine its location now. Costs some battery of the watch. */
    public function RequestLocation(): bool
    {
        $this->WriteAttributeInteger('LastLocateRequest', time());
        return $this->Request('RequestLocation') !== null;
    }

    /**
     * Button "Find": looks up an address (OpenStreetMap Nominatim) and writes the coordinates into the
     * location field $Field of the configuration form (HomeLocation or Place2Location).
     * The value is saved with "Apply changes". Returns a message for the user.
     */
    public function FindLocation(string $Field, string $Address): string
    {
        if (!in_array($Field, ['HomeLocation', 'Place2Location'], true)) {
            return $this->Translate('Unknown location field');
        }
        $Address = trim($Address);
        if ($Address === '') {
            return $this->Translate('Please enter an address.');
        }
        $url = self::GEOCODER . '?' . http_build_query(['format' => 'jsonv2', 'limit' => 1, 'q' => $Address]);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 15,
            // Nominatim requires an identifying User-Agent
            CURLOPT_HTTPHEADER     => [self::USER_AGENT, 'Accept-Language: ' . $this->Translate('en-US')],
        ]);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        $this->SendDebug('FindLocation', 'HTTP ' . $status . ' ' . (is_string($raw) ? $raw : $error), 0);

        $result = is_string($raw) && $status === 200 ? json_decode($raw, true) : null;
        if (!is_array($result)) {
            return $this->Translate('Address search failed') . ' (' . ($error !== '' ? $error : 'HTTP ' . $status) . ')';
        }
        if ($result === [] || !isset($result[0]['lat'], $result[0]['lon'])) {
            return $this->Translate('Address not found');
        }
        $latitude = round((float) $result[0]['lat'], self::COORDINATE_DIGITS);
        $longitude = round((float) $result[0]['lon'], self::COORDINATE_DIGITS);
        $this->UpdateFormField($Field, 'value', json_encode(['latitude' => $latitude, 'longitude' => $longitude]));
        return sprintf($this->Translate('Found: %s'), (string) ($result[0]['display_name'] ?? $Address)) . "\n"
            . number_format($latitude, self::COORDINATE_DIGITS, '.', '') . ', ' . number_format($longitude, self::COORDINATE_DIGITS, '.', '') . "\n"
            . $this->Translate('Please apply the changes to save the location.');
    }

    /**
     * Imports history points, e.g. from an earlier script.
     * $Points: JSON list of [timestamp, latitude, longitude, accuracy, type]; accuracy and type are optional.
     * Points are merged with the stored ones (same timestamp is kept once), sorted and limited to the history days.
     * Returns the number of stored points afterwards.
     */
    public function ImportHistory(string $Points): int
    {
        $new = json_decode($Points, true);
        if (!is_array($new)) {
            return -1;
        }
        $stored = json_decode($this->ReadAttributeString('History'), true) ?: [];
        $merged = [];
        foreach (array_merge($stored, $new) as $p) {
            if (!is_array($p) || !isset($p[0], $p[1], $p[2]) || (int) $p[0] <= 0) {
                continue;
            }
            $merged[(int) $p[0]] = [(int) $p[0], round((float) $p[1], self::COORDINATE_DIGITS), round((float) $p[2], self::COORDINATE_DIGITS), (int) ($p[3] ?? 0), (string) ($p[4] ?? '')];
        }
        ksort($merged);
        $limit = time() - max(1, $this->ReadPropertyInteger('HistoryDays')) * 86400;
        $points = array_values(array_filter($merged, static fn (array $p): bool => $p[0] >= $limit));
        $this->WriteAttributeString('History', json_encode($points));
        return count($points);
    }

    /** WebHook: delivers the history page if the token matches. */
    protected function ProcessHookData()
    {
        if (!$this->ReadPropertyBoolean('ShowHistory') || !hash_equals($this->ReadAttributeString('HookToken'), (string) ($_GET['t'] ?? ''))) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        $days = max(1, $this->ReadPropertyInteger('HistoryDays'));
        $limit = time() - $days * 86400;
        $stored = json_decode($this->ReadAttributeString('History'), true) ?: [];
        $points = array_values(array_filter($stored, static fn (array $p): bool => $p[0] >= $limit));
        $data = [
            'name'   => $this->GetWatchName(),
            'p'      => $points,
            'home'   => $this->GetHome(),
            'radius' => $this->ReadPropertyInteger('HomeRadius'),
            'place2' => $this->GetPlace2() === null ? null : [
                'pos'    => $this->GetPlace2(),
                'radius' => $this->ReadPropertyInteger('Place2Radius'),
                'name'   => $this->GetPlace2Name(),
            ],
            'days'   => $days,
            'theme'  => $this->ReadPropertyInteger('TileTheme'),
            'lang'   => $this->Translate('en-US'),
            't'      => [
                'today'      => $this->Translate('Today'),
                'prev'       => $this->Translate('Previous day'),
                'next'       => $this->Translate('Next day'),
                'map'        => $this->Translate('Map'),
                'satellite'  => $this->Translate('Satellite'),
                'center'     => $this->Translate('Center'),
                'play'       => $this->Translate('Replay'),
                'empty'      => $this->Translate('No locations on this day'),
                'distance'   => $this->Translate('Distance (straight line)'),
                'away'       => $this->Translate('Away from home'),
                'points'     => $this->Translate('Locations'),
                'period'     => $this->Translate('Period'),
                'title'      => $this->Translate('Day route'),
                'back'       => $this->Translate('Back to live view'),
                'stays'      => $this->Translate('Stays'),
                'atHome'     => $this->Translate('at home'),
                'fromHome'   => $this->Translate('%s from home'),
                'stay'       => $this->Translate('stay'),
            ],
        ];
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        echo str_replace('/*__DATA__*/null', $json, (string) file_get_contents(__DIR__ . '/history.html'));
    }

    private function Request(string $function)
    {
        $response = $this->SendDataToParent(json_encode([
            'DataID' => self::DATA_TO_PARENT,
            'Buffer' => json_encode(['Function' => $function, 'WardID' => $this->ReadPropertyString('WardID')]),
        ]));
        $data = json_decode((string) $response, true);
        if (!is_array($data) || isset($data['error'])) {
            $message = is_array($data) ? (string) $data['error'] : $this->Translate('No response from the cloud instance');
            $this->SendDebug($function, $message, 0);
            if ($this->GetStatus() !== self::STATUS_REQUEST_FAILED) {
                $this->LogMessage($function . ': ' . $message, KL_WARNING);
                $this->SetStatus(self::STATUS_REQUEST_FAILED);
            }
            return null;
        }
        if ($this->GetStatus() !== IS_ACTIVE) {
            $this->SetStatus(IS_ACTIVE);
        }
        $this->SendDebug($function, json_encode($data['result'] ?? null), 0);
        return $data['result'] ?? null;
    }

    private function SetValueIfChanged(string $ident, $value): void
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id === false) {
            return;
        }
        // Write if changed, and once initially so that unchanged default values (e.g. false) get a timestamp
        if ($this->GetValue($ident) !== $value || IPS_GetVariable($id)['VariableUpdated'] === 0) {
            $this->SetValue($ident, $value);
        }
    }

    /** Home location (Symcon location or own setting) or null if not set. */
    private function GetHome(): ?array
    {
        $json = $this->ReadPropertyString('HomeLocation');
        if ($this->ReadPropertyBoolean('UseSymconLocation')) {
            // Location of the project (Core instance "Location Control"), read only
            $ids = IPS_GetInstanceListByModuleID(self::LOCATION_CONTROL);
            $json = $ids === [] ? '' : (string) IPS_GetProperty($ids[0], 'Location');
        }
        return $this->ParseLocation($json);
    }

    /** Second place (e.g. school, work, second home) or null if not set. */
    private function GetPlace2(): ?array
    {
        return $this->ParseLocation($this->ReadPropertyString('Place2Location'));
    }

    /** Name of the second place, "Second place" if no name is set. */
    private function GetPlace2Name(): string
    {
        $name = trim($this->ReadPropertyString('Place2Name'));
        return $name !== '' ? $name : $this->Translate('Second place');
    }

    /** SelectLocation JSON → [latitude, longitude] or null if empty / 0,0. */
    private function ParseLocation(string $json): ?array
    {
        $location = json_decode($json, true);
        if (!is_array($location) || !isset($location['latitude'], $location['longitude'])) {
            return null;
        }
        $latitude = (float) $location['latitude'];
        $longitude = (float) $location['longitude'];
        if ($latitude == 0.0 && $longitude == 0.0) {
            return null;
        }
        return [$latitude, $longitude];
    }

    /** Renames a variable of the instance when the configured name changed. */
    private function SetNameIfChanged(string $ident, string $name): void
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id !== false && IPS_GetName($id) !== $name) {
            IPS_SetName($id, $name);
        }
    }

    /** Great circle distance in meters. */
    private function Distance(float $latitude1, float $longitude1, float $latitude2, float $longitude2): float
    {
        $deltaLatitude = deg2rad($latitude2 - $latitude1);
        $deltaLongitude = deg2rad($longitude2 - $longitude1);
        $a = sin($deltaLatitude / 2) ** 2 + cos(deg2rad($latitude1)) * cos(deg2rad($latitude2)) * sin($deltaLongitude / 2) ** 2;
        return 2 * 6371000.0 * asin(min(1.0, sqrt($a)));
    }

    /** The location time is delivered in seconds or milliseconds. */
    private function ToUnixTime($value): int
    {
        $time = (int) $value;
        return $time > 20000000000 ? intdiv($time, 1000) : $time;
    }

    /**
     * Map with switch map / satellite and a button that centers the location again.
     * Map = OpenStreetMap embed page (with buildings; the OSM tile servers themselves block HTML boxes, which the tile
     * visualization shows as data URL without referrer). Satellite = Leaflet with Esri World Imagery (no key needed),
     * only loaded when it is chosen.
     */
    private function MapHTML(float $latitude, float $longitude, int $accuracy, ?array $home): string
    {
        $box = sprintf('%.5F,%.5F,%.5F,%.5F', $longitude - 0.0045, $latitude - 0.0025, $longitude + 0.0045, $latitude + 0.0025);
        $marker = sprintf('%.6F,%.6F', $latitude, $longitude);
        $data = json_encode([
            'pos'  => [round($latitude, 7), round($longitude, 7)],
            'acc'  => $accuracy,
            'home' => $home === null ? null : [$home[0], $home[1]],
            't'    => ['map' => $this->Translate('Map'), 'sat' => $this->Translate('Satellite'), 'center' => $this->Translate('Center')],
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        return '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">'
            . '<style>html,body{margin:0;height:100%}#xosm,#xmap{position:absolute;inset:0;min-height:400px;width:100%;height:100%;border:0}#xmap{display:none}'
            . '.xb{position:absolute;bottom:24px;left:10px;z-index:1000;display:flex;gap:6px}'
            . '.xb button{background:#fff;color:#1f2933;border:1px solid #cfd6dd;border-radius:8px;padding:5px 10px;font:600 13px "Segoe UI",Arial,sans-serif;cursor:pointer;box-shadow:0 1px 4px rgba(0,0,0,.3)}'
            . '.xb button.on{background:#e08a00;border-color:#e08a00;color:#fff}.xhm{font-size:20px;line-height:22px;text-align:center}</style>'
            . '<iframe id="xosm" loading="lazy" referrerpolicy="no-referrer" '
            . 'src="https://www.openstreetmap.org/export/embed.html?bbox=' . $box . '&amp;layer=mapnik&amp;marker=' . $marker . '"></iframe>'
            . '<div id="xmap"></div><div class="xb"><button id="xo"></button><button id="xs"></button><button id="xc"></button></div>'
            . '<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>'
            . '<script>(function(){var D=' . $data . ',g=function(i){return document.getElementById(i);},m=null,sat=false;'
            . 'g("xo").textContent=D.t.map;g("xs").textContent=D.t.sat;g("xc").textContent="\u2316 "+D.t.center;'
            . 'function init(){m=L.map("xmap",{zoomControl:false}).setView(D.pos,17);L.control.zoom({position:"topright"}).addTo(m);'
            . 'L.tileLayer("https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}",{maxZoom:19,attribution:"\u00a9 Esri"}).addTo(m);'
            . 'L.tileLayer("https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}",{maxZoom:19}).addTo(m);'
            . 'if(D.acc>0){L.circle(D.pos,{radius:D.acc,color:"#e08a00",weight:1,fillOpacity:0.12}).addTo(m);}'
            . 'L.circleMarker(D.pos,{radius:8,color:"#fff",weight:2,fillColor:"#e08a00",fillOpacity:1}).addTo(m);'
            . 'if(D.home){L.marker(D.home,{icon:L.divIcon({className:"xhm",html:"\ud83c\udfe0",iconSize:[24,24]}),interactive:false}).addTo(m);}}'
            // The chosen view is remembered in the browser if possible (the variable is rewritten with every new location)
            . 'function lay(s){sat=s;g("xosm").style.display=s?"none":"block";g("xmap").style.display=s?"block":"none";'
            . 'if(s){if(!m){init();}else{m.invalidateSize();}}g("xo").className=s?"":"on";g("xs").className=s?"on":"";'
            . 'try{localStorage.setItem("xplSat",s?"1":"0");}catch(e){}}'
            . 'var s0=false;try{s0=localStorage.getItem("xplSat")==="1";}catch(e){}lay(s0);'
            . 'g("xo").onclick=function(){lay(false);};g("xs").onclick=function(){lay(true);};'
            . 'g("xc").onclick=function(){if(sat){m.setView(D.pos,17);}else{var f=g("xosm");f.src=f.src;}};'
            . '})();</script>';
    }

    /**
     * Name of the town/village at the location: the city delivered by Xplora, otherwise reverse geocoding
     * (OpenStreetMap Nominatim). The result is cached and only looked up again after moving more than 300 m.
     */
    private function NearestPlace(float $latitude, float $longitude, string $city): string
    {
        if ($city !== '') {
            return $city;
        }
        $cache = json_decode($this->ReadAttributeString('PlaceCache'), true);
        if (is_array($cache) && isset($cache['lat'], $cache['lng'], $cache['name'])
            && $this->Distance((float) $cache['lat'], (float) $cache['lng'], $latitude, $longitude) <= self::PLACE_CACHE_DISTANCE) {
            return (string) $cache['name'];
        }
        $url = self::REVERSE_GEOCODER . '?' . http_build_query(['format' => 'jsonv2', 'zoom' => 14, 'lat' => $latitude, 'lon' => $longitude]);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [self::USER_AGENT, 'Accept-Language: ' . $this->Translate('en-US')],
        ]);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $this->SendDebug('NearestPlace', 'HTTP ' . $status . ' ' . (is_string($raw) ? $raw : ''), 0);
        $result = is_string($raw) && $status === 200 ? json_decode($raw, true) : null;
        if (!is_array($result)) {
            // Lookup failed: keep the last known name, if any
            return is_array($cache) ? (string) ($cache['name'] ?? '') : '';
        }
        $address = (array) ($result['address'] ?? []);
        $name = '';
        foreach (['village', 'town', 'city', 'hamlet', 'suburb', 'municipality', 'county'] as $key) {
            if (!empty($address[$key])) {
                $name = (string) $address[$key];
                break;
            }
        }
        $this->WriteAttributeString('PlaceCache', json_encode(['lat' => $latitude, 'lng' => $longitude, 'name' => $name]));
        return $name;
    }

    /** Status text and color: second place (purple), at home (green), on the way (orange), located without home (blue). */
    private function StatusInfo(array $d): array
    {
        if ($d['atPlace2'] === true && $d['atHome'] !== true) {
            return [$this->GetPlace2Name(), '#9b6bdf'];
        }
        if ($d['atHome'] === false && ($d['place'] ?? '') !== '') {
            return [$d['place'], '#f5a623'];
        }
        if ($d['atHome'] !== null) {
            return [$this->Translate($d['atHome'] ? 'At home' : 'On the way'), $d['atHome'] ? '#2ecc71' : '#f5a623'];
        }
        return [$d['zone'] !== '' ? $d['zone'] : $this->Translate('Located'), '#4aa3ff'];
    }

    /**
     * Mini tile for a small tile of the tile visualization: status (colored) and battery in the first line,
     * distance from home and age of the location plus a battery bar in the second line.
     * The age ("15 min") is refreshed in the browser every 30 seconds.
     */
    private function MiniTileHTML(array $d): string
    {
        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        [$status, $color] = $this->StatusInfo($d);

        $info = '';
        if ($d['distance'] !== null && $d['atHome'] !== true) {
            $info = $d['distance'] < 1000
                ? sprintf('%d m', (int) round($d['distance']))
                : number_format($d['distance'] / 1000, 0, '', '') . ' km';
        }

        $battery = '';
        $bar = '';
        if ($d['battery'] !== null) {
            $bc = $d['battery'] <= 20 ? '#e74c3c' : ($d['battery'] <= 40 ? '#f5a623' : '#2ecc71');
            $battery = '<b class="cb" style="color:' . $bc . '">' . ($d['charging'] ? '⚡' : '') . $d['battery'] . ' %</b>';
            $bar = '<span class="cr"><span style="width:' . $d['battery'] . '%;background:' . $bc . '"></span></span>';
        }

        $texts = json_encode([
            'now' => $this->Translate('now'),
            'min' => $this->Translate('%d min'),
            'h'   => $this->Translate('%d h'),
            'd'   => $this->Translate('%d d'),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

        $dark = '--fg:#e8edf2;--sub:#9aa5b1;--track:rgba(255,255,255,.15)';
        $light = '--fg:#1f2933;--sub:#5b6672;--track:rgba(0,0,0,.12)';
        $theme = $this->ReadPropertyInteger('TileTheme');
        $vars = '.cw{' . ($theme === 2 ? $light : $dark) . '}';
        if ($theme === 0) {
            $vars .= '@media (prefers-color-scheme: light){.cw:not(.vd){' . $light . '}}.cw.vl{' . $light . '}';
        }

        return '<style>'
            . 'html,body{margin:0;height:100%;background:transparent;font-family:"Segoe UI",Arial,sans-serif;overflow:hidden}'
            . '.cw{height:100%;box-sizing:border-box;container-type:size;display:flex;flex-direction:column;justify-content:flex-end;gap:4px;color:var(--fg);padding:0 2px 2px}'
            . '.cl{display:flex;justify-content:space-between;align-items:center;gap:8px;min-width:0}'
            . '.cs{font-weight:bold;font-size:clamp(18px,min(17cqw,40cqh),32px);line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
            . '.cb{font-size:clamp(16px,min(14cqw,34cqh),28px);line-height:1.1;white-space:nowrap;flex:none}'
            . '.ci{color:var(--sub);font-size:clamp(13px,min(10.5cqw,25cqh),18px);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
            . '.cr{flex:none;width:clamp(36px,26cqw,72px);height:6px;border-radius:3px;background:var(--track);overflow:hidden}'
            . '.cr span{display:block;height:100%;border-radius:3px}'
            . $vars
            . '</style>'
            . '<div class="cw">'
            . '<div class="cl"><span class="cs" style="color:' . $color . '">' . $e($status) . '</span>' . $battery . '</div>'
            . '<div class="cl"><span class="ci" id="ci" data-info="' . $e($info) . '" data-ts="' . (int) $d['time'] . '">'
            . $e(trim($info . ($info !== '' ? ' · ' : '') . $this->Age($d['time']))) . '</span>' . $bar . '</div>'
            . '</div>'
            . ($theme === 0 ? $this->ThemeScript('cw') : '')
            . '<script>(function(){var T=' . $texts . ',el=document.getElementById("ci");'
            . 'function age(t){var s=Math.floor(Date.now()/1000)-t;if(t<=0)return"";if(s<60)return T.now;if(s<3600)return T.min.replace("%d",Math.floor(s/60));'
            . 'if(s<86400)return T.h.replace("%d",Math.floor(s/3600));return T.d.replace("%d",Math.floor(s/86400));}'
            . 'function upd(){var i=el.getAttribute("data-info"),a=age(+el.getAttribute("data-ts"));el.textContent=i&&a?i+" · "+a:(i||a);}'
            . 'upd();setInterval(upd,30000);})();</script>';
    }

    /** Age of a location as short text for the mini tile, e.g. "15 min". */
    private function Age(int $time): string
    {
        if ($time <= 0) {
            return '';
        }
        $s = max(0, time() - $time);
        if ($s < 60) {
            return $this->Translate('now');
        }
        if ($s < 3600) {
            return sprintf($this->Translate('%d min'), intdiv($s, 60));
        }
        if ($s < 86400) {
            return sprintf($this->Translate('%d h'), intdiv($s, 3600));
        }
        return sprintf($this->Translate('%d d'), intdiv($s, 86400));
    }

    /**
     * Status tile (same look as other status tiles): header with watch name and status chip, battery ring,
     * status and distance, location time and accuracy, address; optional map of the location in the background.
     * Sizes follow the tile size (container query units).
     */
    private function TileHTML(array $d): string
    {
        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $ok = '#2ecc71';
        $warn = '#f5a623';
        $bad = '#e74c3c';
        $dec = $this->Translate('.');

        [$status, $color] = $this->StatusInfo($d);
        $sub = '';
        if ($d['distance'] !== null && !$d['atHome']) {
            $sub = $d['distance'] < 1000
                ? sprintf($this->Translate('%d m from home'), (int) round($d['distance']))
                : sprintf($this->Translate('%s km from home'), number_format($d['distance'] / 1000, 1, $dec, ''));
        } elseif ($d['zone'] !== '' && $d['atHome'] !== null) {
            $sub = $d['zone'];
        }
        $when = $d['time'] <= 0 ? '–' : (date('Ymd', $d['time']) === date('Ymd') ? date('H:i', $d['time']) : date('d.m. H:i', $d['time']));
        $type = strtoupper($d['type']) === 'WIFI' ? 'WLAN' : $d['type'];

        // Battery ring
        $ring = '';
        if ($d['battery'] !== null) {
            $bc = $d['battery'] <= 20 ? $bad : ($d['battery'] <= 40 ? $warn : $ok);
            $circ = 2 * M_PI * 42;
            $ring = '<svg class="xr" viewBox="0 0 100 100"><circle cx="50" cy="50" r="42" fill="none" stroke="var(--track)" stroke-width="9"/>'
                . '<circle cx="50" cy="50" r="42" fill="none" stroke="' . $bc . '" stroke-width="9" stroke-linecap="round" stroke-dasharray="' . round($circ, 1)
                . '" stroke-dashoffset="' . round($circ * (1 - $d['battery'] / 100), 1) . '" transform="rotate(-90 50 50)"/>'
                . '<text x="50" y="50" text-anchor="middle" dominant-baseline="central" fill="var(--fg)" font-size="22" font-weight="bold">' . $d['battery'] . '%</text>'
                . '<text x="50" y="71" text-anchor="middle" fill="var(--sub)" font-size="10">' . ($d['charging'] ? '⚡ ' : '') . $e($this->Translate('Battery')) . '</text></svg>';
        }

        // Value tiles: location time/type and accuracy (or safe zone)
        $tiles = '<div class="xv"><span>' . $e($this->Translate('Location')) . '</span><b>' . $e($when . ($type !== '' ? ' · ' . $type : '')) . '</b></div>';
        if ($d['zone'] !== '') {
            $tiles .= '<div class="xv"><span>' . $e($this->Translate('Safe zone')) . '</span><b>' . $e($d['zone']) . '</b></div>';
        } else {
            $tiles .= '<div class="xv"><span>' . $e($this->Translate('Accuracy')) . '</span><b>± ' . (int) $d['accuracy'] . ' m</b></div>';
        }
        $address = $d['address'] !== ''
            ? '<div class="xl"><span>' . $e($this->Translate('Address')) . '</span><b>' . $e($d['address']) . '</b></div>'
            : '';

        $map = '';
        if ($this->ReadPropertyBoolean('TileMap')) {
            // 5x3 tiles (zoom 15) of the Esri "Light/Dark Gray Canvas" maps (no key needed), shifted so that the location is at 50 % / 24 % of the tile
            $zoom = 15;
            $n = 2 ** $zoom;
            $x = ($d['lng'] + 180) / 360 * $n;
            $latRad = deg2rad($d['lat']);
            $y = (1 - log(tan($latRad) + 1 / cos($latRad)) / M_PI) / 2 * $n;
            $tx = (int) floor($x);
            $ty = (int) floor($y);
            $px = (int) round(512 + ($x - $tx) * 256);
            $py = (int) round(256 + ($y - $ty) * 256);
            // Theme 0 = automatic (design of the visualization, see ThemeScript), 1 = dark, 2 = light; automatic contains both maps
            $theme = $this->ReadPropertyInteger('TileTheme');
            $layers = ['d' => 'World_Dark_Gray_Base', 'l' => 'World_Light_Gray_Base'];
            if ($theme === 1) {
                unset($layers['l']);
            } elseif ($theme === 2) {
                unset($layers['d']);
            }
            foreach ($layers as $class => $layer) {
                $images = '';
                for ($j = -1; $j <= 1; $j++) {
                    for ($i = -2; $i <= 2; $i++) {
                        $images .= '<img alt="" src="https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/' . $layer . '/MapServer/tile/'
                            . $zoom . '/' . ($ty + $j) . '/' . ($tx + $i) . '">';
                    }
                }
                $map .= '<div class="xt ' . $class . '" style="left:calc(50% - ' . $px . 'px);top:calc(24% - ' . $py . 'px)">' . $images . '</div>';
            }
            $map = '<div class="xm">' . $map . '</div><div class="xo"></div>'
                . '<div class="xp" style="background:' . $color . ';box-shadow:0 0 0 6px ' . $color . '44"></div>'
                . '<div class="xa">Esri, HERE, Garmin, © OpenStreetMap</div>';
        }

        $icon = '<svg class="xi" viewBox="0 0 24 24"><rect x="6.5" y="6" width="11" height="12" rx="2.5"/><path d="M9 6l.6-3h4.8l.6 3M9 18l.6 3h4.8l.6-3M12 9.5V12l1.8 1.2"/></svg>';

        $dark = '--fg:#e8edf2;--name:#e8edf2;--sub:#9aa5b1;--track:rgba(255,255,255,.09);--box:rgba(255,255,255,.07);--line:rgba(255,255,255,.08);--att:#c9d2db;'
            . '--bg:#1d2228;--card:linear-gradient(160deg,#252b32,#1a1e23);--o1:rgba(29,34,40,.15);--o2:rgba(29,34,40,.8);--o3:#1d2228';
        $light = '--fg:#1f2933;--name:#1f2933;--sub:#5b6672;--track:rgba(0,0,0,.08);--box:rgba(0,0,0,.05);--line:rgba(0,0,0,.08);--att:#333;'
            . '--bg:#ffffff;--card:linear-gradient(160deg,#ffffff,#f1f4f8);--o1:rgba(255,255,255,.1);--o2:rgba(255,255,255,.85);--o3:#ffffff';
        $theme = $this->ReadPropertyInteger('TileTheme');
        $vars = $theme === 2 ? '.xw{' . $light . '}.xt.d{display:none}' : '.xw{' . $dark . '}.xt.l{display:none}';
        if ($theme === 0) {
            $lightMap = '.xw .xt.d{display:none}.xw .xt.l{display:grid}';
            $vars .= '@media (prefers-color-scheme: light){.xw:not(.vd){' . $light . '}' . str_replace('.xw ', '.xw:not(.vd) ', $lightMap) . '}'
                . '.xw.vl{' . $light . '}' . str_replace('.xw ', '.xw.vl ', $lightMap);
        }

        $css = '<style>'
            . 'html,body{margin:0;height:100%;background:transparent;font-family:"Segoe UI",Arial,sans-serif;overflow:hidden}'
            . '.xw{position:relative;height:100%;min-height:240px;box-sizing:border-box;container-type:size;border:1px solid var(--line);border-top:3px solid ' . $color . ';border-radius:12px;overflow:hidden;color:var(--fg);background:var(--card)}'
            . '.xw *{box-sizing:border-box}'
            . '.xm{position:absolute;inset:0;overflow:hidden}'
            . '.xt{position:absolute;width:1280px;height:768px;display:grid;grid-template-columns:repeat(5,256px)}'
            . '.xt img{width:256px;height:256px;display:block}'
            . '.xo{position:absolute;inset:0;background:linear-gradient(180deg,var(--o1) 0%,var(--o1) 22%,var(--o2) 42%,var(--o3) 58%)}'
            . '.xp{position:absolute;left:50%;top:24%;width:14px;height:14px;margin:-7px 0 0 -7px;border-radius:50%;border:2px solid #fff}'
            . '.xa{position:absolute;right:8px;top:calc(46% - 14px);font-size:9px;color:var(--att);opacity:.6}'
            . '.xc{position:relative;height:100%;display:flex;flex-direction:column;gap:clamp(6px,2.2cqh,12px);padding:clamp(10px,3cqh,16px) clamp(12px,4cqw,18px)}'
            . '.xh{display:flex;justify-content:space-between;align-items:center;gap:8px}'
            . '.xh b{display:flex;align-items:center;gap:8px;font-size:clamp(15px,4.8cqw,19px);color:var(--name);min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
            . '.xi{width:clamp(20px,6cqw,26px);height:auto;flex:none;fill:none;stroke:' . $color . ';stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}'
            . '.xch{border:1px solid ' . $color . '66;background:' . $color . '22;color:' . $color . ';border-radius:20px;padding:3px 11px;font-size:clamp(11px,3.2cqw,13px);font-weight:bold;white-space:nowrap}'
            . '.xg{flex:1}'
            . '.xhero{display:flex;align-items:center;gap:clamp(10px,4cqw,18px)}'
            . '.xr{width:clamp(72px,min(26cqw,22cqh),130px);height:auto;flex:none}'
            . '.xs{font-size:clamp(22px,min(9cqw,8cqh),40px);font-weight:bold;line-height:1.1;color:' . $color . '}'
            . '.xu{color:var(--sub);font-size:clamp(12px,3.6cqw,15px);margin-top:3px}'
            . '.xvs{display:grid;grid-template-columns:1fr 1fr;gap:clamp(6px,1.8cqh,10px)}'
            . '.xv{background:var(--box);border-radius:10px;padding:clamp(6px,1.8cqh,10px) 12px;display:flex;flex-direction:column;min-width:0}'
            . '.xv span{color:var(--sub);font-size:clamp(11px,3.2cqw,13px)}'
            . '.xv b{font-size:clamp(14px,4.6cqw,19px);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
            . '.xl{display:flex;justify-content:space-between;gap:12px;border-top:1px solid var(--line);padding-top:clamp(5px,1.5cqh,8px);font-size:clamp(12px,3.6cqw,15px)}'
            . '.xl span{color:var(--sub);flex:none}.xl b{text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
            . $vars
            . '</style>';

        return $css . '<div class="xw">' . $map . '<div class="xc">'
            . '<div class="xh"><b>' . $icon . $e($this->GetWatchName()) . '</b><span class="xch">' . $e($status) . '</span></div>'
            . '<div class="xg"></div>'
            . '<div class="xhero">' . $ring . '<div><div class="xs">' . $e($status) . '</div>' . ($sub !== '' ? '<div class="xu">' . $e($sub) . '</div>' : '') . '</div></div>'
            . '<div class="xvs">' . $tiles . '</div>'
            . $address
            . '</div></div>'
            . ($theme === 0 ? $this->ThemeScript('xw') : '');
    }

    private function InstanceTileActive(): bool
    {
        return $this->ReadPropertyBoolean('InstanceTile') && method_exists($this, 'UpdateVisualizationValue');
    }

    /**
     * Message for the instance tile: texts and values of the last location (rendered by tile.html).
     * $fresh = the location is newer than the last location request of the tile.
     */
    private function InstanceTileMessage(bool $fresh = false): array
    {
        $d = json_decode($this->ReadAttributeString('TileData'), true);
        if (!is_array($d)) {
            $text = $this->ReadPropertyString('WardID') === '' ? $this->Translate('No watch ID configured') : $this->Translate('No location yet');
            return ['empty' => $text, 'fresh' => $fresh];
        }
        $dec = $this->Translate('.');
        $km = static fn (float $m): string => $m < 1000 ? sprintf('%d m', (int) round($m)) : number_format($m / 1000, $m < 10000 ? 1 : 0, $dec, '') . ' km';
        [$status, $color] = $this->StatusInfo($d);

        $sub = '';
        if (($d['distance'] ?? null) !== null && $d['atHome'] !== true) {
            $sub = sprintf($this->Translate('%s from home'), $km((float) $d['distance']));
        } elseif ($d['zone'] !== '') {
            $sub = $d['zone'];
        }
        $type = strtoupper($d['type']) === 'WIFI' ? 'WLAN' : $d['type'];

        // Value boxes: icon, caption, value (empty values are left out)
        $values = [];
        if (($d['distance'] ?? null) !== null) {
            $values[] = ['home', $this->Translate('Distance to home'), $d['atHome'] ? $this->Translate('At home') : $km((float) $d['distance'])];
        }
        if (($d['distance2'] ?? null) !== null) {
            $values[] = ['pin', sprintf($this->Translate('Distance to %s'), $this->GetPlace2Name()), $d['atPlace2'] ? sprintf($this->Translate('At %s'), $this->GetPlace2Name()) : $km((float) $d['distance2'])];
        }
        $values[] = ['clock', $this->Translate('Location time'), $d['time'] > 0 ? (date('Ymd', $d['time']) === date('Ymd') ? date('H:i', $d['time']) : date('d.m. H:i', $d['time'])) : '–', 'age'];
        if ($type !== '') {
            $values[] = ['signal', $this->Translate('Location type'), $type];
        }
        $values[] = ['target', $this->Translate('Accuracy'), $d['accuracy'] > 0 ? '± ' . (int) $d['accuracy'] . ' m' : '–'];
        $values[] = ['shield', $this->Translate('Safe zone'), $d['zone'] !== '' ? $d['zone'] : $this->Translate('outside')];

        return [
            'fresh'    => $fresh,
            'name'     => $this->GetWatchName(),
            'status'   => $status,
            'color'    => $color,
            'sub'      => $sub,
            'battery'  => $d['battery'],
            'charging' => (bool) $d['charging'],
            'time'     => (int) $d['time'],
            'values'   => $values,
            'address'  => $d['address'],
            'lat'      => (float) $d['lat'],
            'lng'      => (float) $d['lng'],
            'accuracy' => (int) $d['accuracy'],
            'atHome'   => $d['atHome'],
            'home'     => $this->GetHome(),
            'radius'   => $this->ReadPropertyInteger('HomeRadius'),
            'place2'   => $this->GetPlace2() === null ? null : ['pos' => $this->GetPlace2(), 'name' => $this->GetPlace2Name()],
            // Locations of the last 36 hours: track of today, "away since" and statistics of today (computed in the tile)
            'points'   => $this->ReadPropertyBoolean('ShowHistory') ? $this->RecentPoints(36 * 3600) : [],
            'history'  => $this->ReadPropertyBoolean('ShowHistory') ? $this->HookPath() . '?t=' . $this->ReadAttributeString('HookToken') : null,
            // Day route shown in the tile, otherwise only a button "Signal tracking" that opens the history in the whole tile
            'route'    => $this->ReadPropertyBoolean('TileRoute'),
        ];
    }

    /** Recorded locations of the last $seconds as [time, latitude, longitude, accuracy]. */
    private function RecentPoints(int $seconds): array
    {
        $limit = time() - $seconds;
        $points = [];
        foreach (json_decode($this->ReadAttributeString('History'), true) ?: [] as $p) {
            if ($p[0] >= $limit) {
                $points[] = [$p[0], $p[1], $p[2], $p[3]];
            }
        }
        return $points;
    }

    /** Automatic design: follows the card/text colors that the tile visualization passes to HTML content, otherwise prefers-color-scheme. */
    private function ThemeScript(string $class): string
    {
        return '<script>(function(){var s=getComputedStyle(document.documentElement);'
            . 'function l(n){var v=s.getPropertyValue(n).replace(/[^0-9a-f]/gi,"");if(v.length<6){return -1;}'
            . 'v=v.length===8&&/^ff/i.test(v)?v.slice(2):v.slice(0,6);var c=parseInt(v,16);return 0.299*(c>>16)+0.587*(c>>8&255)+0.114*(c&255);}'
            . 'var b=l("--card-color"),f=l("--content-color");if(b<0){return;}'
            . 'document.querySelectorAll(".' . $class . '").forEach(function(e){e.classList.add(b<(f<0?128:f)?"vd":"vl");});})();</script>';
    }

    /** Adds a location to the history (only new location times) and removes points older than the retention. */
    private function AddHistoryPoint(int $time, float $latitude, float $longitude, int $accuracy, string $type): void
    {
        if ($time <= 0) {
            return;
        }
        $points = json_decode($this->ReadAttributeString('History'), true) ?: [];
        $last = $points === [] ? null : $points[count($points) - 1];
        if ($last !== null && (int) $last[0] >= $time) {
            return;
        }
        $points[] = [$time, round($latitude, self::COORDINATE_DIGITS), round($longitude, self::COORDINATE_DIGITS), $accuracy, $type];
        $limit = time() - max(1, $this->ReadPropertyInteger('HistoryDays')) * 86400;
        $points = array_values(array_filter($points, static fn (array $p): bool => $p[0] >= $limit));
        $this->WriteAttributeString('History', json_encode($points));
    }

    private function HookPath(): string
    {
        return '/hook/XploraWatch' . $this->InstanceID;
    }

    /** Registers the WebHook of this instance at the WebHook Control. */
    private function RegisterHook(): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::WEBHOOK_CONTROL);
        if ($ids === []) {
            $this->LogMessage($this->Translate('WebHook Control not found, the location history is not available'), KL_WARNING);
            return;
        }
        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true) ?: [];
        foreach ($hooks as $hook) {
            if ($hook['Hook'] === $this->HookPath() && $hook['TargetID'] === $this->InstanceID) {
                return;
            }
        }
        $hooks = array_values(array_filter($hooks, fn (array $hook): bool => $hook['Hook'] !== $this->HookPath()));
        $hooks[] = ['Hook' => $this->HookPath(), 'TargetID' => $this->InstanceID];
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }

    private function UnregisterHook(): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::WEBHOOK_CONTROL);
        if ($ids === []) {
            return;
        }
        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true) ?: [];
        $rest = array_values(array_filter($hooks, fn (array $hook): bool => $hook['Hook'] !== $this->HookPath()));
        if (count($rest) !== count($hooks)) {
            IPS_SetProperty($ids[0], 'Hooks', json_encode($rest));
            IPS_ApplyChanges($ids[0]);
        }
    }

    /** Name of the watch = instance name without a leading "Xplora ". */
    private function GetWatchName(): string
    {
        return (string) preg_replace('/^Xplora\s+/u', '', IPS_GetName($this->InstanceID));
    }

    private function RegisterProfiles(): void
    {
        if (!IPS_VariableProfileExists('XPL.Kilometer')) {
            IPS_CreateVariableProfile('XPL.Kilometer', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits('XPL.Kilometer', 2);
            IPS_SetVariableProfileText('XPL.Kilometer', '', ' km');
            IPS_SetVariableProfileIcon('XPL.Kilometer', 'Distance');
        }
        if (!IPS_VariableProfileExists('XPL.Degree')) {
            IPS_CreateVariableProfile('XPL.Degree', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('XPL.Degree', '', '°');
        }
        // Also updates profiles created by older versions (6 digits)
        if (IPS_GetVariableProfile('XPL.Degree')['Digits'] !== self::COORDINATE_DIGITS) {
            IPS_SetVariableProfileDigits('XPL.Degree', self::COORDINATE_DIGITS);
        }
        if (!IPS_VariableProfileExists('XPL.Meter')) {
            IPS_CreateVariableProfile('XPL.Meter', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('XPL.Meter', '', ' m');
        }
    }
}
