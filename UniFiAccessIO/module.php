<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/UnifiAccessClient.php';

class UniFiAccessIO extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 12445);
        $this->RegisterPropertyString('ApiToken', '');
        $this->RegisterPropertyBoolean('VerifySSL', false);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function GetConfigurationForm()
    {
        $this->UpdateFormField('Host', 'validate', IPS_ValidateLength(1, 255));
        $this->UpdateFormField('ApiToken', 'validate', IPS_ValidateLength(1, 512));

        return json_encode([
            'elements' => [
                [
                    'type' => 'Label',
                    'label' => 'Verbindung zur UniFi Access API (nur Besucher). API-Token unter Access → Einstellungen → API.',
                ],
                [
                    'name' => 'Host',
                    'type' => 'ValidationTextBox',
                    'caption' => 'Host (IP oder Hostname der UDM)',
                ],
                [
                    'name' => 'Port',
                    'type' => 'NumberSpinner',
                    'caption' => 'API-Port',
                    'minimum' => 1,
                    'maximum' => 65535,
                ],
                [
                    'name' => 'ApiToken',
                    'type' => 'PasswordTextBox',
                    'caption' => 'API-Token',
                ],
                [
                    'name' => 'VerifySSL',
                    'type' => 'CheckBox',
                    'caption' => 'SSL-Zertifikat prüfen',
                ],
            ],
            'actions' => [
                [
                    'type' => 'Test',
                    'label' => 'Verbindung testen',
                    'onClick' => 'UAF_TestConnection();',
                ],
            ],
        ]);
    }

    public function TestConnection(): bool
    {
        try {
            $this->getClient()->getAccessProfiles();
            $this->SendDebug('TestConnection', 'Verbindung erfolgreich.', 0);

            return true;
        } catch (Throwable $e) {
            $this->SendDebug('TestConnection', $e->getMessage(), 0);

            return false;
        }
    }

    /**
     * Zugangsprofile (Access Policies) mit ID und Name.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function GetAccessProfiles(): array
    {
        return $this->getClient()->getAccessProfiles();
    }

    /**
     * Besucher anlegen inkl. PIN und Zugangsprofil (Ressourcen aus Policy).
     *
     * @return array<string, mixed>
     */
    public function CreateVisitor(
        string $pin,
        string $firstName,
        string $lastName,
        string $accessPolicyId,
        int $startTime = 0,
        int $endTime = 0,
        string $email = '',
        string $mobilePhone = ''
    ): array {
        $extra = [];

        if ($email !== '') {
            $extra['email'] = $email;
        }

        if ($mobilePhone !== '') {
            $extra['mobile_phone'] = $mobilePhone;
        }

        return $this->getClient()->createVisitor(
            $pin,
            $firstName,
            $lastName,
            $accessPolicyId,
            $startTime,
            $endTime,
            $extra
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function FindVisitorByPin(string $pin): ?array
    {
        return $this->getClient()->findVisitorByPin($pin);
    }

    /**
     * @return array<string, mixed>
     */
    public function UpdateVisitor(
        string $pin,
        string $firstName,
        string $lastName,
        string $accessPolicyId,
        int $startTime = 0,
        int $endTime = 0,
        string $email = '',
        string $mobilePhone = ''
    ): array {
        $extra = [];

        if ($email !== '') {
            $extra['email'] = $email;
        }

        if ($mobilePhone !== '') {
            $extra['mobile_phone'] = $mobilePhone;
        }

        return $this->getClient()->updateVisitor(
            $pin,
            $firstName,
            $lastName,
            $accessPolicyId,
            $startTime,
            $endTime,
            $extra
        );
    }

    public function DeleteVisitor(string $pin): bool
    {
        return $this->getClient()->deleteVisitor($pin);
    }

    /**
     * @return array<string, mixed>
     */
    public function CreateQrCode(string $pin): array
    {
        return $this->getClient()->createQrCode($pin);
    }

    public function DownloadQrCode(string $pin, string $targetPath): string
    {
        return $this->getClient()->downloadQrCode($pin, $targetPath);
    }

    /** @deprecated Alias für CreateVisitor */
    public function CreateUser(
        string $pin,
        string $firstName,
        string $lastName,
        string $accessPolicyId,
        int $startTime = 0,
        int $endTime = 0,
        string $email = ''
    ): array {
        return $this->CreateVisitor($pin, $firstName, $lastName, $accessPolicyId, $startTime, $endTime, $email);
    }

    /** @deprecated Alias für FindVisitorByPin */
    public function FindUserByPin(string $pin): ?array
    {
        return $this->FindVisitorByPin($pin);
    }

    /** @deprecated Alias für UpdateVisitor */
    public function UpdateUser(
        string $pin,
        string $firstName,
        string $lastName,
        string $accessPolicyId,
        int $startTime = 0,
        int $endTime = 0,
        string $email = ''
    ): array {
        return $this->UpdateVisitor($pin, $firstName, $lastName, $accessPolicyId, $startTime, $endTime, $email);
    }

    /** @deprecated Alias für DeleteVisitor */
    public function DeleteUser(string $pin): bool
    {
        return $this->DeleteVisitor($pin);
    }

    private function getClient(): UnifiAccessClient
    {
        $host = (string) $this->ReadPropertyString('Host');
        $token = (string) $this->ReadPropertyString('ApiToken');

        if ($host === '' || $token === '') {
            throw new Exception('Host und API-Token müssen in der Instanz konfiguriert sein.');
        }

        return new UnifiAccessClient(
            $host,
            (int) $this->ReadPropertyInteger('Port'),
            $token,
            (bool) $this->ReadPropertyBoolean('VerifySSL')
        );
    }
}
