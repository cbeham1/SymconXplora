<?php

declare(strict_types=1);

/**
 * Xplora Configurator
 *
 * Lists the watches of the Xplora account of the connected cloud instance
 * and creates an Xplora Watch instance for each watch on request.
 */
class XploraConfigurator extends IPSModule
{
    private const DATA_TO_PARENT = '{690D7FA6-98B2-422C-A627-9FBFF58D1604}';
    private const WATCH_MODULE = '{DF4743D6-EF1E-4698-8F22-BE7930F405FE}';

    public function Create(): void
    {
        parent::Create();

        $this->ConnectParent('{C71DBF5D-F172-4659-A8F5-EB8C943ED849}');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
    }

    public function ReceiveData($JSONString): string
    {
        return '';
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);

        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID === 0 || !$this->HasActiveParent()) {
            $form['actions'][0]['visible'] = true;
            $form['actions'][0]['caption'] = $this->Translate('The Xplora Cloud instance is not active. Please check its login data.');
            return json_encode($form);
        }

        // Existing watch instances on the same cloud instance
        $existing = [];
        foreach (IPS_GetInstanceListByModuleID(self::WATCH_MODULE) as $id) {
            if (IPS_GetInstance($id)['ConnectionID'] === $parentID) {
                $existing[(string) IPS_GetProperty($id, 'WardID')] = $id;
            }
        }

        $values = [];
        foreach ($this->GetWards() as $ward) {
            $wardID = (string) $ward['WardID'];
            $name = (string) $ward['Name'];
            $values[] = [
                'WardID'     => $wardID,
                'Name'       => $name,
                'instanceID' => $existing[$wardID] ?? 0,
                'create'     => [
                    'moduleID'      => self::WATCH_MODULE,
                    'name'          => 'Xplora ' . $name,
                    'configuration' => ['WardID' => $wardID],
                ],
            ];
            unset($existing[$wardID]);
        }
        // Instances whose watch is no longer in the account
        foreach ($existing as $wardID => $id) {
            $values[] = [
                'WardID'     => (string) $wardID,
                'Name'       => IPS_GetName($id),
                'instanceID' => $id,
            ];
        }
        $form['actions'][1]['values'] = $values;
        return json_encode($form);
    }

    private function GetWards(): array
    {
        $response = $this->SendDataToParent(json_encode([
            'DataID' => self::DATA_TO_PARENT,
            'Buffer' => json_encode(['Function' => 'GetWards']),
        ]));
        $data = json_decode((string) $response, true);
        if (!is_array($data) || isset($data['error'])) {
            $this->SendDebug('GetWards', is_array($data) ? (string) $data['error'] : 'no response', 0);
            return [];
        }
        return is_array($data['result'] ?? null) ? $data['result'] : [];
    }
}
