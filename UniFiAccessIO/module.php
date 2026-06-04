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
        return file_get_contents(__DIR__ . '/form.json');
    }

    public function TestConnection(): bool
    {
        try {
            $message = $this->getClient()->testConnection();
            $this->SendDebug('TestConnection', $message, 0);

            return true;
        } catch (Throwable $e) {
            $this->SendDebug('TestConnection', $e->getMessage(), 0);

            return false;
        }
    }

    /**
     * Türgruppen (Gebäude „All Doors“ und benutzerdefinierte Gruppen) für Ressourcenauswahl.
     *
     * @return array<int, array{id: string, name: string, type: string}>
     */
    public function GetDoorGroups(): array
    {
        return $this->getClient()->getDoorGroups();
    }

    /**
     * Besucher anlegen: Booking-ID in remarks, PIN über Credential-Ressource.
     *
     * @return array<string, mixed>
     */
    public function CreateVisitor(
        string $bookingId,
        string $pin,
        string $firstName,
        string $lastName,
        string $doorGroupId,
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
            $bookingId,
            $pin,
            $firstName,
            $lastName,
            $doorGroupId,
            $startTime,
            $endTime,
            $extra
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function FindVisitorByBookingId(string $bookingId): ?array
    {
        return $this->getClient()->findVisitorByBookingId($bookingId);
    }

    /**
     * @deprecated Nutze FindVisitorByBookingId; sucht nach Klartext-PIN (Legacy remarks PIN:)
     *
     * @return array<string, mixed>|null
     */
    public function FindVisitorByPin(string $pin): ?array
    {
        return $this->getClient()->findVisitorByPin($pin);
    }

    /**
     * Alle Besucher als normalisierte Liste (paginiert über die API).
     *
     * @return array<int, array<string, mixed>>
     */
    public function GetAllVisitors(): array
    {
        return $this->getClient()->getAllVisitors();
    }

    /**
     * Besucher ändern. Optional neue PIN ($pin): wird über DELETE+PUT pin_codes gesetzt.
     *
     * @return array<string, mixed>
     */
    public function UpdateVisitor(
        string $bookingId,
        string $firstName,
        string $lastName,
        string $doorGroupId,
        int $startTime = 0,
        int $endTime = 0,
        string $email = '',
        string $mobilePhone = '',
        ?string $pin = null
    ): array {
        $extra = [];

        if ($email !== '') {
            $extra['email'] = $email;
        }

        if ($mobilePhone !== '') {
            $extra['mobile_phone'] = $mobilePhone;
        }

        return $this->getClient()->updateVisitor(
            $bookingId,
            $firstName,
            $lastName,
            $doorGroupId,
            $startTime,
            $endTime,
            $extra,
            $pin
        );
    }

    public function DeleteVisitor(string $bookingId): bool
    {
        return $this->getClient()->deleteVisitor($bookingId);
    }

    /**
     * @return array<string, mixed>
     */
    public function CreateQrCode(string $bookingId): array
    {
        return $this->getClient()->createQrCode($bookingId);
    }

    public function DownloadQrCode(string $bookingId, string $targetPath): string
    {
        return $this->getClient()->downloadQrCode($bookingId, $targetPath);
    }

    /** @deprecated Alias für CreateVisitor (PIN dient als Booking-ID; Parameter 4 = $doorGroupId) */
    public function CreateUser(
        string $pin,
        string $firstName,
        string $lastName,
        string $doorGroupId,
        int $startTime = 0,
        int $endTime = 0,
        string $email = ''
    ): array {
        return $this->CreateVisitor($pin, $pin, $firstName, $lastName, $doorGroupId, $startTime, $endTime, $email);
    }

    /** @deprecated Alias für FindVisitorByPin */
    public function FindUserByPin(string $pin): ?array
    {
        return $this->FindVisitorByPin($pin);
    }

    /** @deprecated Alias für UpdateVisitor (erster Parameter = frühere PIN-UID als Booking-ID; Parameter 4 = $doorGroupId) */
    public function UpdateUser(
        string $pin,
        string $firstName,
        string $lastName,
        string $doorGroupId,
        int $startTime = 0,
        int $endTime = 0,
        string $email = ''
    ): array {
        return $this->UpdateVisitor($pin, $firstName, $lastName, $doorGroupId, $startTime, $endTime, $email);
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
