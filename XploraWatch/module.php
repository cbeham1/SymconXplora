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

    /** Status tile: at home / on the way, distance, battery, last location; optional light map of the location as background. */
    private function TileHTML(array $d): string
    {
        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $ok = '#2ecc71';
        $warn = '#f5a623';
        $bad = '#e74c3c';
        $info = '#4aa3ff';

        if ($d['atHome'] !== null) {
            $status = $this->Translate($d['atHome'] ? 'At home' : 'On the way');
            $color = $d['atHome'] ? $ok : $warn;
        } else {
            $status = $d['zone'] !== '' ? $d['zone'] : $this->Translate('Located');
            $color = $info;
        }
        $sub = [];
        if ($d['distance'] !== null && !$d['atHome']) {
            $sub[] = $d['distance'] < 1000
                ? sprintf($this->Translate('%d m away'), (int) round($d['distance']))
                : sprintf($this->Translate('%s km away'), number_format($d['distance'] / 1000, 1, $this->Translate('.'), ''));
        }
        if ($d['zone'] !== '' && $d['atHome'] !== null) {
            $sub[] = $d['zone'];
        }
        $when = $d['time'] <= 0 ? '–' : (date('Ymd', $d['time']) === date('Ymd') ? date('H:i', $d['time']) : date('d.m. H:i', $d['time']));

        $battery = '';
        if ($d['battery'] !== null) {
            $bc = $d['battery'] <= 20 ? $bad : ($d['battery'] <= 40 ? $warn : $ok);
            $battery = '<div class="xb"><i style="width:' . $d['battery'] . '%;background:' . $bc . '"></i></div>'
                . '<div class="xl"><span>' . $e($this->Translate('Battery')) . '</span><b style="color:' . $bc . '">'
                . $d['battery'] . ' %' . ($d['charging'] ? ' ⚡' : '') . '</b></div>';
        }

        $map = '';
        if ($this->ReadPropertyBoolean('TileMap')) {
            // 3x3 tiles (zoom 15) of the Esri "Light/Dark Gray Canvas" maps (no key needed), shifted so that the location is at 50 % / 30 % of the tile
            $zoom = 15;
            $n = 2 ** $zoom;
            $x = ($d['lng'] + 180) / 360 * $n;
            $latRad = deg2rad($d['lat']);
            $y = (1 - log(tan($latRad) + 1 / cos($latRad)) / M_PI) / 2 * $n;
            $tx = (int) floor($x);
            $ty = (int) floor($y);
            $px = (int) round(256 + ($x - $tx) * 256);
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
                $tiles = '';
                for ($j = -1; $j <= 1; $j++) {
                    for ($i = -1; $i <= 1; $i++) {
                        $tiles .= '<img alt="" src="https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/' . $layer . '/MapServer/tile/'
                            . $zoom . '/' . ($ty + $j) . '/' . ($tx + $i) . '">';
                    }
                }
                $map .= '<div class="xt ' . $class . '" style="left:calc(50% - ' . $px . 'px);top:calc(30% - ' . $py . 'px)">' . $tiles . '</div>';
            }
            $map = '<div class="xm">' . $map . '</div>'
                . '<div class="xo"></div><div class="xp" style="background:' . $color . ';box-shadow:0 0 0 6px ' . $color . '55"></div>'
                . '<div class="xa">Esri, HERE, Garmin, © OpenStreetMap</div>';
        }
        $icon = $map !== '' ? '' : '<svg class="xi" viewBox="0 0 24 24" style="stroke:' . $color . '"><rect x="6.5" y="6" width="11" height="12" rx="2.5"/>'
            . '<path d="M9 6l.6-3h4.8l.6 3M9 18l.6 3h4.8l.6-3M12 9.5V12l1.8 1.2"/></svg>';

        // Colors of both themes as CSS variables
        $dark = '--fg:#e9eef3;--name:#c9d2db;--sub:#9aa5b1;--track:rgba(255,255,255,.13);--att:#c9d2db;'
            . '--o1:rgba(24,28,33,.15);--o2:rgba(24,28,33,.35);--o3:rgba(24,28,33,.9)';
        $light = '--fg:#1f2933;--name:#3e4a56;--sub:#5b6672;--track:rgba(0,0,0,.1);--att:#333;'
            . '--o1:rgba(255,255,255,.1);--o2:rgba(255,255,255,.35);--o3:rgba(255,255,255,.92)';
        $theme = $this->ReadPropertyInteger('TileTheme');
        $vars = $theme === 2 ? '.xw{' . $light . '}.xt.d{display:none}' : '.xw{' . $dark . '}.xt.l{display:none}';
        if ($theme === 0) {
            $vars .= '@media (prefers-color-scheme: light){.xw{' . $light . '}.xt.d{display:none}.xt.l{display:grid}}';
        }

        $css = '<style>'
            . 'html,body{margin:0;height:100%;background:transparent;font-family:"Segoe UI",Arial,sans-serif}'
            . '.xw{position:relative;height:100%;min-height:240px;box-sizing:border-box;border:1px solid ' . $color . '66;border-radius:12px;overflow:hidden;color:var(--fg);background:' . $color . '12}'
            . '.xm{position:absolute;inset:0;overflow:hidden}'
            . '.xt{position:absolute;width:768px;height:768px;display:grid;grid-template-columns:repeat(3,256px)}'
            . '.xt img{width:256px;height:256px;display:block}'
            . '.xo{position:absolute;inset:0;background:linear-gradient(180deg,var(--o1) 0%,var(--o2) 40%,var(--o3) 70%)}'
            . '.xp{position:absolute;left:50%;top:30%;width:14px;height:14px;margin:-7px 0 0 -7px;border-radius:50%;border:2px solid #fff}'
            . '.xa{position:absolute;right:6px;top:4px;font-size:9px;color:var(--att);opacity:.7}'
            . '.xc{position:relative;height:100%;box-sizing:border-box;padding:14px 16px;display:flex;flex-direction:column;align-items:center;justify-content:' . ($map !== '' ? 'flex-end' : 'center') . ';text-align:center}'
            . '.xi{width:46px;height:auto;fill:none;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round;margin-bottom:6px}'
            . '.xn{font-size:19px;font-weight:bold;color:var(--name)}'
            . '.xs{font-size:34px;font-weight:bold;margin:2px 0;line-height:1.15}'
            . '.xu{color:var(--sub);font-size:14px;margin-top:3px;min-height:18px}'
            . '.xb{width:100%;height:12px;border-radius:6px;background:var(--track);overflow:hidden;margin-top:14px}'
            . '.xb i{display:block;height:100%}'
            . '.xl{width:100%;display:flex;justify-content:space-between;font-size:15px;color:var(--name);margin-top:6px}'
            . $vars
            . '</style>';

        return $css . '<div class="xw">' . $map . '<div class="xc">' . $icon
            . '<div class="xn">' . $e($this->GetWatchName()) . '</div>'
            . '<div class="xs" style="color:' . $color . '">' . $e($status) . '</div>'
            . '<div class="xu">' . $e(implode(' · ', $sub)) . '</div>'
            . $battery
            . '<div class="xl"><span>' . $e($this->Translate('Location')) . '</span><span>' . $e($when . ($d['type'] !== '' ? ' · ' . $d['type'] : '')) . '</span></div>'
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
