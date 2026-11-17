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
    private const MIN_INTERVAL = 60;
    private const FIRST_UPDATE_DELAY = 5;
    private const MAX_ACCURACY_BONUS = 200;

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
        $this->RegisterPropertyInteger('HomeRadius', 150);
        $this->RegisterPropertyBoolean('ShowMap', true);

        $this->RegisterAttributeInteger('LastLocateRequest', 0);

        $this->RegisterTimer('Update', 0, 'XPLW_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();
        $home = $this->GetHome() !== null;

        $this->MaintainVariable('Battery', $this->Translate('Battery'), VARIABLETYPE_INTEGER, '~Battery.100', 1, true);
        $this->MaintainVariable('Charging', $this->Translate('Charging'), VARIABLETYPE_BOOLEAN, '', 2, true);
        $this->MaintainVariable('AtHome', $this->Translate('At home'), VARIABLETYPE_BOOLEAN, '~Presence', 3, $home);
        $this->MaintainVariable('Distance', $this->Translate('Distance to home'), VARIABLETYPE_FLOAT, 'XPL.Kilometer', 4, $home);
        $this->MaintainVariable('InSafeZone', $this->Translate('In safe zone'), VARIABLETYPE_BOOLEAN, '', 5, true);
        $this->MaintainVariable('SafeZone', $this->Translate('Safe zone'), VARIABLETYPE_STRING, '', 6, true);
        $this->MaintainVariable('Address', $this->Translate('Address'), VARIABLETYPE_STRING, '', 7, true);
        $this->MaintainVariable('Latitude', $this->Translate('Latitude'), VARIABLETYPE_FLOAT, 'XPL.Degree', 8, true);
        $this->MaintainVariable('Longitude', $this->Translate('Longitude'), VARIABLETYPE_FLOAT, 'XPL.Degree', 9, true);
        $this->MaintainVariable('Accuracy', $this->Translate('Accuracy'), VARIABLETYPE_INTEGER, 'XPL.Meter', 10, true);
        $this->MaintainVariable('LocateType', $this->Translate('Location type'), VARIABLETYPE_STRING, '', 11, true);
        $this->MaintainVariable('LocateTime', $this->Translate('Location time'), VARIABLETYPE_INTEGER, '~UnixTimestamp', 12, true);
        $this->MaintainVariable('Map', $this->Translate('Map'), VARIABLETYPE_STRING, '~HTMLBox', 20, $this->ReadPropertyBoolean('ShowMap'));

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
        if ($home !== null) {
            $distance = $this->Distance($home[0], $home[1], $latitude, $longitude);
            $this->SetValueIfChanged('Distance', round($distance / 1000, 2));
            $this->SetValueIfChanged('AtHome', $distance <= $this->ReadPropertyInteger('HomeRadius') + min($accuracy, self::MAX_ACCURACY_BONUS));
        }
        if ($this->ReadPropertyBoolean('ShowMap')) {
            $this->SetValueIfChanged('Map', $this->MapHTML($latitude, $longitude));
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

    private function MapHTML(float $latitude, float $longitude): string
    {
        $box = sprintf('%.5F,%.5F,%.5F,%.5F', $longitude - 0.0045, $latitude - 0.0025, $longitude + 0.0045, $latitude + 0.0025);
        $marker = sprintf('%.6F,%.6F', $latitude, $longitude);
        return '<iframe style="width:100%;height:100%;min-height:400px;border:0" loading="lazy" referrerpolicy="no-referrer" '
            . 'src="https://www.openstreetmap.org/export/embed.html?bbox=' . $box . '&amp;layer=mapnik&amp;marker=' . $marker . '"></iframe>';
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
            IPS_SetVariableProfileDigits('XPL.Degree', 6);
            IPS_SetVariableProfileText('XPL.Degree', '', '°');
        }
        if (!IPS_VariableProfileExists('XPL.Meter')) {
            IPS_CreateVariableProfile('XPL.Meter', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('XPL.Meter', '', ' m');
        }
    }
}
