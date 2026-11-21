<?php

declare(strict_types=1);

/**
 * Xplora Cloud
 *
 * Holds the login to an Xplora parent account and forwards GraphQL requests
 * of the child instances (Xplora Watch, Xplora Configurator) to the Xplora cloud.
 *
 * The Xplora API is not officially documented. Endpoint, keys and queries are
 * taken from the open source projects pyxplora_api (Ludy87, MIT) and
 * ha-xplora-watch (acdcnow). Xplora may change the API at any time.
 */
class XploraCloud extends IPSModule
{
    private const ENDPOINT = 'https://api.prod.myxplora.com/api/';
    private const API_KEY = '98fa41bc50c84e2f833e3997aedfe1cf';
    private const API_SECRET = '6d129c656ef64bf0becd3a370bbe068d';
    private const SESSION_MAX_AGE = 30 * 86400;
    /** The watch list only comes with the login; the configurator logs in again when it is older than this. */
    private const WARDS_MAX_AGE = 300;
    private const PAUSE_AFTER_THROTTLING = 3600;
    private const WATCH_MODULE = '{DF4743D6-EF1E-4698-8F22-BE7930F405FE}';
    /** Automatic marker colors of the overview tile (distinct from the status colors green/orange/purple). */
    private const OVERVIEW_COLORS = ['#3d9bff', '#ff4f9a', '#f2c81d', '#19c3c9', '#a07cff', '#ff7a2f', '#7ed957'];

    private const STATUS_LOGIN_FAILED = 201;
    private const STATUS_THROTTLED = 202;
    private const STATUS_NO_CONNECTION = 203;

    private const ERROR_AUTH = 401;
    private const ERROR_THROTTLED = 429;

    private const QUERY_SIGN_IN = 'mutation signInWithEmailOrPhone($countryPhoneNumber: String, $phoneNumber: String, $password: String!, $emailAddress: String, $client: ClientType!, $userLang: String!, $timeZone: String!, $clientId: String) {'
        . ' signInWithEmailOrPhone(countryPhoneNumber: $countryPhoneNumber, phoneNumber: $phoneNumber, password: $password, emailAddress: $emailAddress, client: $client, userLang: $userLang, timeZone: $timeZone, clientId: $clientId) {'
        . ' __typename id token refreshToken issueDate expireDate valid'
        . ' user { __typename id name children { __typename id ward { __typename id name phoneNumber } } } } }';
    private const QUERY_LAST_LOCATE = 'query WatchLastLocate($uid: String!) { watchLastLocate(uid: $uid) {'
        . ' __typename tm lat lng rad country city addr poi battery isCharging isAdjusted locateType step distance isInSafeZone safeZoneLabel batteryTm } }';
    private const QUERY_ASK_LOCATE = 'query AskWatchLocate($uid: String!) { askWatchLocate(uid: $uid) }';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('CountryCode', '43');
        $this->RegisterPropertyString('PhoneNumber', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('Language', 'de-DE');

        $this->RegisterAttributeString('Auth', '');
        $this->RegisterAttributeInteger('AuthTime', 0);
        $this->RegisterAttributeString('CredentialHash', '');
        $this->RegisterAttributeString('Wards', '[]');
        $this->RegisterAttributeInteger('PauseUntil', 0);

        // Overview tile: the cloud instance itself as tile with all watches on one map
        $this->RegisterPropertyBoolean('OverviewTile', false);
        $this->RegisterPropertyInteger('OverviewTheme', 0);
        $this->RegisterAttributeString('Overview', '{}');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // A changed login invalidates the stored session
        $hash = md5($this->ReadPropertyString('Email') . '|' . $this->ReadPropertyString('CountryCode') . '|'
            . $this->ReadPropertyString('PhoneNumber') . '|' . $this->ReadPropertyString('Password'));
        if ($hash !== $this->ReadAttributeString('CredentialHash')) {
            $this->WriteAttributeString('CredentialHash', $hash);
            $this->WriteAttributeString('Auth', '');
            $this->WriteAttributeInteger('AuthTime', 0);
            $this->WriteAttributeString('Wards', '[]');
            $this->WriteAttributeInteger('PauseUntil', 0);
        }

        // Overview tile (HTML-SDK, not available in older Symcon versions)
        if (method_exists($this, 'SetVisualizationType')) {
            $this->SetVisualizationType($this->ReadPropertyBoolean('OverviewTile') ? 1 : 0);
        }
        $this->UpdateOverview();

        $this->SetStatus($this->HasCredentials() ? IS_ACTIVE : IS_INACTIVE);
    }

    /**
     * HTML-SDK: overview tile with all watches of this account on one map (colored markers with legend).
     * The watches send their data after each update (function "Overview"), updates arrive via handleMessage().
     */
    public function GetVisualizationTile(): string
    {
        $texts = json_encode([
            'map'      => $this->Translate('Map'),
            'sat'      => $this->Translate('Satellite'),
            'all'      => $this->Translate('Show all'),
            'battery'  => $this->Translate('Battery'),
            'now'      => $this->Translate('now'),
            'min'      => $this->Translate('%d min'),
            'h'        => $this->Translate('%d h'),
            'd'        => $this->Translate('%d d'),
            'ago'      => $this->Translate('%s ago'),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        $initial = json_encode($this->OverviewMessage(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return strtr((string) file_get_contents(__DIR__ . '/overview.html'), [
            '%TEXTS%'   => $texts,
            '%THEME%'   => (string) $this->ReadPropertyInteger('OverviewTheme'),
            '%INITIAL%' => json_encode($initial, JSON_HEX_TAG),
        ]);
    }

    /**
     * Requests of the children. Buffer is a JSON object with the keys
     * "Function" (GetWards, GetLastLocation, RequestLocation) and "WardID".
     * Returns a JSON object with either "result" or "error".
     */
    public function ForwardData($JSONString): string
    {
        $data = json_decode($JSONString, true);
        $request = json_decode((string) ($data['Buffer'] ?? ''), true);
        if (!is_array($request)) {
            return json_encode(['error' => $this->Translate('Invalid request')]);
        }
        $this->SendDebug('ForwardData', json_encode($request), 0);

        try {
            $wardID = (string) ($request['WardID'] ?? '');
            switch ((string) ($request['Function'] ?? '')) {
                case 'GetWards':
                    $result = $this->GetWardList(true);
                    break;
                case 'GetLastLocation':
                    $this->CheckWardID($wardID);
                    $result = $this->Query(self::QUERY_LAST_LOCATE, ['uid' => $wardID], 'WatchLastLocate')['watchLastLocate'] ?? null;
                    break;
                case 'RequestLocation':
                    $this->CheckWardID($wardID);
                    $result = $this->Query(self::QUERY_ASK_LOCATE, ['uid' => $wardID], 'AskWatchLocate')['askWatchLocate'] ?? null;
                    break;
                case 'Overview':
                    // Data of a watch for the overview tile (no cloud request)
                    $this->StoreOverviewEntry((int) ($request['InstanceID'] ?? 0), (array) ($request['Entry'] ?? []));
                    $result = true;
                    break;
                default:
                    throw new RuntimeException($this->Translate('Unknown function'));
            }
        } catch (RuntimeException $e) {
            $this->SendDebug('Error', $e->getMessage(), 0);
            return json_encode(['error' => $e->getMessage()]);
        }
        return json_encode(['result' => $result]);
    }

    /** Button "Test connection": logs in and lists the watches of the account. */
    public function TestConnection(): string
    {
        if (!$this->HasCredentials()) {
            return $this->Translate('Please enter e-mail address or phone number and password.');
        }
        try {
            $this->Login();
        } catch (RuntimeException $e) {
            return $this->Translate('Login failed') . ': ' . $e->getMessage();
        }
        $names = array_column($this->GetWardList(), 'Name');
        return $this->Translate('Login successful') . "\n" . $this->Translate('Watches') . ': '
            . ($names === [] ? '-' : implode(', ', $names));
    }

    private function OverviewActive(): bool
    {
        return $this->ReadPropertyBoolean('OverviewTile') && method_exists($this, 'UpdateVisualizationValue');
    }

    private function StoreOverviewEntry(int $instanceID, array $entry): void
    {
        if ($instanceID <= 0 || !$this->OverviewActive()) {
            return;
        }
        $stored = json_decode($this->ReadAttributeString('Overview'), true) ?: [];
        $stored[(string) $instanceID] = $entry;
        $this->WriteAttributeString('Overview', json_encode($stored));
        $this->UpdateOverview();
    }

    private function UpdateOverview(): void
    {
        if ($this->OverviewActive() && IPS_GetKernelRunlevel() === KR_READY) {
            $this->UpdateVisualizationValue(json_encode($this->OverviewMessage()));
        }
    }

    /**
     * Message for the overview tile: all watch instances connected to this cloud instance that have sent data,
     * sorted by name, each with its marker color (own setting of the watch or automatic from the palette).
     */
    private function OverviewMessage(): array
    {
        $stored = json_decode($this->ReadAttributeString('Overview'), true) ?: [];
        $watches = [];
        foreach (IPS_GetInstanceListByModuleID(self::WATCH_MODULE) as $id) {
            if (IPS_GetInstance($id)['ConnectionID'] !== $this->InstanceID || !isset($stored[(string) $id])) {
                continue;
            }
            $entry = (array) $stored[(string) $id];
            $entry['id'] = $id;
            $entry['name'] = (string) preg_replace('/^Xplora\s+/u', '', IPS_GetName($id));
            $watches[] = $entry;
        }
        usort($watches, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        $auto = 0;
        foreach ($watches as &$watch) {
            $color = (int) ($watch['markerColor'] ?? -1);
            $watch['markerColor'] = $color >= 0 ? sprintf('#%06x', $color) : self::OVERVIEW_COLORS[$auto++ % count(self::OVERVIEW_COLORS)];
        }
        unset($watch);
        if ($watches === []) {
            return ['empty' => $this->Translate('No watch data yet. The watches send their location after the next update.')];
        }
        return ['watches' => $watches];
    }

    private function HasCredentials(): bool
    {
        return $this->ReadPropertyString('Password') !== ''
            && ($this->ReadPropertyString('Email') !== '' || $this->ReadPropertyString('PhoneNumber') !== '');
    }

    private function CheckWardID(string $wardID): void
    {
        if ($wardID === '') {
            throw new RuntimeException($this->Translate('No watch ID configured'));
        }
    }

    /**
     * Watch list of the account. It is only delivered with the login, so with $refresh
     * (configurator) a new login is made when the stored list is older than WARDS_MAX_AGE.
     * Watches added in the app later then appear without waiting for the session to expire.
     */
    private function GetWardList(bool $refresh = false): array
    {
        $wards = json_decode($this->ReadAttributeString('Wards'), true);
        $hasList = is_array($wards) && $wards !== [] && $this->ReadAttributeString('Auth') !== '';
        if (!$hasList) {
            $this->Login();
        } elseif ($refresh && (time() - $this->ReadAttributeInteger('AuthTime')) > self::WARDS_MAX_AGE) {
            try {
                $this->Login();
            } catch (RuntimeException $e) {
                // Keep the stored list if the cloud is not reachable or throttled
                $this->SendDebug('GetWardList', 'Refresh failed, using stored list: ' . $e->getMessage(), 0);
            }
        }
        $wards = json_decode($this->ReadAttributeString('Wards'), true);
        return is_array($wards) ? $wards : [];
    }

    /** GraphQL request with the stored session; logs in again once if the session is no longer valid. */
    private function Query(string $query, array $variables, string $operation): array
    {
        $auth = $this->ReadAttributeString('Auth');
        if ($auth === '' || (time() - $this->ReadAttributeInteger('AuthTime')) > self::SESSION_MAX_AGE) {
            $auth = $this->Login();
        }
        try {
            return $this->Call($auth, $query, $variables, $operation);
        } catch (RuntimeException $e) {
            if ($e->getCode() !== self::ERROR_AUTH) {
                throw $e;
            }
            return $this->Call($this->Login(), $query, $variables, $operation);
        }
    }

    private function Login(): string
    {
        if (!$this->HasCredentials()) {
            $this->SetStatus(IS_INACTIVE);
            throw new RuntimeException($this->Translate('No login data configured'));
        }
        $email = trim($this->ReadPropertyString('Email'));
        $variables = [
            'countryPhoneNumber' => $email === '' ? trim($this->ReadPropertyString('CountryCode'), '+ ') : null,
            'phoneNumber'        => $email === '' ? ltrim(trim($this->ReadPropertyString('PhoneNumber')), '0') : null,
            'password'           => md5($this->ReadPropertyString('Password')),
            'userLang'           => $this->ReadPropertyString('Language'),
            'timeZone'           => date_default_timezone_get(),
            'emailAddress'       => $email === '' ? null : $email,
            'client'             => 'APP',
            'clientId'           => null,
        ];
        try {
            $data = $this->Call('Open ' . self::API_KEY . ':' . self::API_SECRET, self::QUERY_SIGN_IN, $variables, 'signInWithEmailOrPhone');
        } catch (RuntimeException $e) {
            if ($e->getCode() === self::ERROR_AUTH) {
                $this->SetStatus(self::STATUS_LOGIN_FAILED);
            }
            throw $e;
        }
        $signIn = $data['signInWithEmailOrPhone'] ?? null;
        if (!is_array($signIn) || empty($signIn['token'])) {
            $this->SetStatus(self::STATUS_LOGIN_FAILED);
            throw new RuntimeException($this->Translate('Login rejected, please check the login data'));
        }
        $auth = 'Bearer ' . $signIn['token'] . ':' . self::API_SECRET;

        $wards = [];
        foreach ((array) ($signIn['user']['children'] ?? []) as $child) {
            $ward = (array) ($child['ward'] ?? []);
            if (!empty($ward['id'])) {
                $wards[] = ['WardID' => (string) $ward['id'], 'Name' => (string) ($ward['name'] ?? $ward['id'])];
            }
        }
        $this->WriteAttributeString('Auth', $auth);
        $this->WriteAttributeInteger('AuthTime', time());
        $this->WriteAttributeString('Wards', json_encode($wards));
        $this->SendDebug('Login', 'OK, ' . count($wards) . ' watch(es)', 0);
        $this->SetStatus(IS_ACTIVE);
        return $auth;
    }

    /** Raw GraphQL call. Throws RuntimeException; code 401 = session invalid, 429 = throttled. */
    private function Call(string $auth, string $query, array $variables, string $operation): array
    {
        $pauseUntil = $this->ReadAttributeInteger('PauseUntil');
        if ($pauseUntil > time()) {
            throw new RuntimeException(sprintf($this->Translate('Paused after throttling by Xplora until %s'), date('H:i', $pauseUntil)), self::ERROR_THROTTLED);
        }

        $body = ['query' => $query, 'variables' => $variables, 'operationName' => $operation];
        $headers = [
            'H-Date: ' . gmdate('D, d M Y H:i:s') . ' GMT',
            'H-Tid: ' . time(),
            'Content-Type: application/json; charset=UTF-8',
            'H-BackDoor-Authorization: ' . $auth,
            'Accept-Language: ' . $this->ReadPropertyString('Language'),
            'User-Agent: okhttp/5.3.2',
        ];
        $curl = curl_init(self::ENDPOINT);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        $this->SendDebug('Response ' . $operation, 'HTTP ' . $status . ' ' . (is_string($raw) ? $raw : $error), 0);

        if (!is_string($raw)) {
            $this->SetStatus(self::STATUS_NO_CONNECTION);
            throw new RuntimeException($this->Translate('Connection error') . ': ' . $error);
        }
        if ($status === 401 || $status === 403) {
            throw new RuntimeException('HTTP ' . $status, self::ERROR_AUTH);
        }
        if ($status === 429) {
            $this->WriteAttributeInteger('PauseUntil', time() + self::PAUSE_AFTER_THROTTLING);
            $this->SetStatus(self::STATUS_THROTTLED);
            throw new RuntimeException($this->Translate('Throttled by Xplora (HTTP 429)'), self::ERROR_THROTTLED);
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new RuntimeException($this->Translate('Invalid response') . ' (HTTP ' . $status . ')');
        }
        if (!empty($json['errors'])) {
            $message = (string) ($json['errors'][0]['message'] ?? 'GraphQL error');
            $code = (string) ($json['errors'][0]['extensions']['code'] ?? '');
            $isAuth = preg_match('/auth|token|login|unauthori|expired|forbidden|E000004/i', $message . ' ' . $code) === 1;
            throw new RuntimeException($operation . ': ' . $message, $isAuth ? self::ERROR_AUTH : 0);
        }
        if ($status >= 400) {
            throw new RuntimeException('HTTP ' . $status);
        }
        if ($this->GetStatus() !== IS_ACTIVE) {
            $this->SetStatus(IS_ACTIVE);
        }
        return (array) ($json['data'] ?? []);
    }
}
