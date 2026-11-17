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
        $this->RegisterPropertyBoolean('ShowTile', true);
        $this->RegisterPropertyBoolean('TileMap', true);
        $this->RegisterPropertyInteger('TileTheme', 0);
        $this->RegisterPropertyBoolean('ShowHistory', false);
        $this->RegisterPropertyInteger('HistoryDays', 15);

        $this->RegisterAttributeString('History', '[]');
        $this->RegisterAttributeString('HookToken', '');

        $this->RegisterAttributeInteger('LastLocateRequest', 0);

        $this->RegisterTimer('Update', 0, 'XPLW_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();
        $home = $this->GetHome() !== null;

        $this->MaintainVariable('Tile', $this->Translate('Status tile'), VARIABLETYPE_STRING, '~HTMLBox', 0, $this->ReadPropertyBoolean('ShowTile'));
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
        if ($this->ReadPropertyBoolean('ShowMap')) {
            $this->SetValueIfChanged('Map', $this->MapHTML($latitude, $longitude));
        }
        if ($this->ReadPropertyBoolean('ShowHistory')) {
            $this->AddHistoryPoint($time, $latitude, $longitude, $accuracy, (string) ($location['locateType'] ?? ''));
        }
        if ($this->ReadPropertyBoolean('ShowTile')) {
            $this->SetValueIfChanged('Tile', $this->TileHTML([
                'battery'  => isset($location['battery']) ? max(0, min(100, (int) $location['battery'])) : null,
                'charging' => (bool) ($location['isCharging'] ?? false),
                'atHome'   => $atHome,
                'distance' => $distance,
                'zone'     => (bool) ($location['isInSafeZone'] ?? false) ? (string) ($location['safeZoneLabel'] ?? '') : '',
                'time'     => $time,
                'type'     => (string) ($location['locateType'] ?? ''),
                'lat'      => $latitude,
                'lng'      => $longitude,
                'address'  => $address,
                'accuracy' => $accuracy,
            ]));
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
            $merged[(int) $p[0]] = [(int) $p[0], round((float) $p[1], 6), round((float) $p[2], 6), (int) ($p[3] ?? 0), (string) ($p[4] ?? '')];
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
                'stays'      => $this->Translate('Stays'),
                'atHome'     => $this->Translate('at home'),
                'kmFromHome' => $this->Translate('%s km from home'),
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
        $info = '#4aa3ff';
        $dec = $this->Translate('.');

        if ($d['atHome'] !== null) {
            $status = $this->Translate($d['atHome'] ? 'At home' : 'On the way');
            $color = $d['atHome'] ? $ok : $warn;
        } else {
            $status = $d['zone'] !== '' ? $d['zone'] : $this->Translate('Located');
            $color = $info;
        }
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
            // Theme 0 = automatic (prefers-color-scheme), 1 = dark, 2 = light; automatic contains both maps
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
            $vars .= '@media (prefers-color-scheme: light){.xw{' . $light . '}.xt.d{display:none}.xt.l{display:grid}}';
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
            . '</div></div>';
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
        $points[] = [$time, round($latitude, 6), round($longitude, 6), $accuracy, $type];
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
            IPS_SetVariableProfileDigits('XPL.Degree', 6);
            IPS_SetVariableProfileText('XPL.Degree', '', '°');
        }
        if (!IPS_VariableProfileExists('XPL.Meter')) {
            IPS_CreateVariableProfile('XPL.Meter', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('XPL.Meter', '', ' m');
        }
    }
}
