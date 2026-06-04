<?php

declare(strict_types=1);

/**
 * HTTP-Client für die UniFi Access Developer API (Besucher).
 */
class UnifiAccessClient
{
    public const PIN_REMARK_PREFIX = 'PIN:';

    private string $host;

    private int $port;

    private string $token;

    private bool $verifySsl;

    public function __construct(string $host, int $port, string $token, bool $verifySsl = false)
    {
        $this->host = rtrim($host, '/');
        $this->port = $port;
        $this->token = $token;
        $this->verifySsl = $verifySsl;
    }

    public static function pinRemark(string $pin): string
    {
        return self::PIN_REMARK_PREFIX . $pin;
    }

    public static function pinFromRemark(?string $remarks): ?string
    {
        if ($remarks === null || $remarks === '') {
            return null;
        }

        if (str_starts_with($remarks, self::PIN_REMARK_PREFIX)) {
            return substr($remarks, strlen(self::PIN_REMARK_PREFIX));
        }

        return null;
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function getAccessProfiles(): array
    {
        $response = $this->request('GET', '/access_policies');
        $profiles = [];

        foreach ($response['data'] ?? [] as $policy) {
            if (!isset($policy['id'], $policy['name'])) {
                continue;
            }

            $profiles[] = [
                'id' => (string) $policy['id'],
                'name' => (string) $policy['name'],
            ];
        }

        return $profiles;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function createVisitor(
        string $pin,
        string $firstName,
        string $lastName,
        string $accessPolicyId,
        int $startTime,
        int $endTime,
        array $extra = []
    ): array {
        $resources = $this->resourcesFromAccessPolicy($accessPolicyId);

        $body = array_merge([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'remarks' => self::pinRemark($pin),
            'start_time' => $startTime > 0 ? $startTime : time(),
            'end_time' => $endTime > 0 ? $endTime : (time() + 86400 * 365),
            'visit_reason' => 'Others',
            'resources' => $resources,
        ], $extra);

        $response = $this->request('POST', '/visitors', $body);
        $visitor = $response['data'] ?? [];
        $visitorId = $visitor['id'] ?? null;

        if ($visitorId === null) {
            throw new RuntimeException('Besucher konnte nicht angelegt werden: ' . ($response['msg'] ?? 'unbekannter Fehler'));
        }

        $this->assignPinCode((string) $visitorId, $pin);

        return $this->getVisitor((string) $visitorId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findVisitorByPin(string $pin): ?array
    {
        $needle = self::pinRemark($pin);
        $page = 1;
        $pageSize = 100;

        do {
            $response = $this->request('GET', '/visitors', null, [
                'page_num' => (string) $page,
                'page_size' => (string) $pageSize,
                'expand[]' => ['pin_code'],
            ]);

            foreach ($response['data'] ?? [] as $visitor) {
                if (($visitor['remarks'] ?? '') === $needle) {
                    return $visitor;
                }
            }

            $total = (int) ($response['pagination']['total'] ?? 0);
            $page++;
        } while (($page - 1) * $pageSize < $total);

        return null;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function updateVisitor(
        string $pin,
        string $firstName,
        string $lastName,
        string $accessPolicyId,
        int $startTime,
        int $endTime,
        array $extra = []
    ): array {
        $visitor = $this->findVisitorByPin($pin);

        if ($visitor === null) {
            throw new RuntimeException('Kein Besucher mit PIN ' . $pin . ' gefunden.');
        }

        $visitorId = (string) $visitor['id'];
        $body = array_merge([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'remarks' => self::pinRemark($pin),
            'resources' => $this->resourcesFromAccessPolicy($accessPolicyId),
        ], $extra);

        if ($startTime > 0) {
            $body['start_time'] = $startTime;
        }

        if ($endTime > 0) {
            $body['end_time'] = $endTime;
        }

        $this->request('PUT', '/visitors/' . $visitorId, $body);

        return $this->getVisitor($visitorId);
    }

    public function deleteVisitor(string $pin): bool
    {
        $visitor = $this->findVisitorByPin($pin);

        if ($visitor === null) {
            return false;
        }

        $visitorId = (string) $visitor['id'];

        try {
            $this->request('DELETE', '/visitors/' . $visitorId . '/pin_codes');
        } catch (RuntimeException $e) {
            // PIN war evtl. nicht gesetzt
        }

        $this->request('DELETE', '/visitors/' . $visitorId);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function createQrCode(string $pin): array
    {
        $visitorId = $this->visitorIdFromPin($pin);
        $this->request('PUT', '/visitors/' . $visitorId . '/qr_codes');

        return $this->getVisitor($visitorId);
    }

    public function downloadQrCode(string $pin, string $targetPath): string
    {
        $visitorId = $this->visitorIdFromPin($pin);
        $binary = $this->request('GET', '/credentials/qr_codes/download/' . $visitorId, null, [], true);

        $directory = dirname($targetPath);
        if ($directory !== '' && !is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (file_put_contents($targetPath, $binary) === false) {
            throw new RuntimeException('QR-Code konnte nicht gespeichert werden: ' . $targetPath);
        }

        return $targetPath;
    }

    /**
     * @return array<string, mixed>
     */
    public function getVisitor(string $visitorId): array
    {
        $response = $this->request('GET', '/visitors/' . $visitorId, null, [
            'expand[]' => ['pin_code', 'resource', 'schedule'],
        ]);

        return $response['data'] ?? [];
    }

    private function visitorIdFromPin(string $pin): string
    {
        $visitor = $this->findVisitorByPin($pin);

        if ($visitor === null || !isset($visitor['id'])) {
            throw new RuntimeException('Kein Besucher mit PIN ' . $pin . ' gefunden.');
        }

        return (string) $visitor['id'];
    }

    private function assignPinCode(string $visitorId, string $pin): void
    {
        $this->request('PUT', '/visitors/' . $visitorId . '/pin_codes', [
            'pin_code' => $pin,
        ]);
    }

    /**
     * @return array<int, array{id: string, type: string}>
     */
    private function resourcesFromAccessPolicy(string $accessPolicyId): array
    {
        $response = $this->request('GET', '/access_policies/' . $accessPolicyId);
        $policy = $response['data'] ?? [];
        $resources = [];

        foreach ($policy['resources'] ?? [] as $resource) {
            if (!isset($resource['id'], $resource['type'])) {
                continue;
            }

            $resources[] = [
                'id' => (string) $resource['id'],
                'type' => (string) $resource['type'],
            ];
        }

        if ($resources === []) {
            throw new RuntimeException('Zugangsprofil ' . $accessPolicyId . ' enthält keine Ressourcen (Türen/Türgruppen).');
        }

        return $resources;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $query
     * @return array<string, mixed>|string
     */
    private function request(string $method, string $path, ?array $body = null, array $query = [], bool $binary = false)
    {
        $url = $this->baseUrl() . $path;

        if ($query !== []) {
            $url .= '?' . $this->buildQueryString($query);
        }

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
        ];

        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException('cURL konnte nicht initialisiert werden.');
        }

        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $this->verifySsl);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, $this->verifySsl ? 2 : 0);

        if ($body !== null) {
            $payload = json_encode($body, JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
        }

        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($raw === false) {
            throw new RuntimeException('API-Anfrage fehlgeschlagen: ' . $error);
        }

        if ($binary) {
            if ($httpCode < 200 || $httpCode >= 300) {
                throw new RuntimeException('QR-Download fehlgeschlagen (HTTP ' . $httpCode . ').');
            }

            return $raw;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('API-Fehler (HTTP ' . $httpCode . '): ' . ($decoded['msg'] ?? $raw));
        }

        if (($decoded['code'] ?? '') !== 'SUCCESS') {
            throw new RuntimeException('API meldet Fehler: ' . ($decoded['msg'] ?? 'unbekannt'));
        }

        return $decoded;
    }

    private function baseUrl(): string
    {
        return sprintf('https://%s:%d/api/v1/developer', $this->host, $this->port);
    }

    /**
     * @param array<string, int|string|array<int, string>> $query
     */
    private function buildQueryString(array $query): string
    {
        $parts = [];

        foreach ($query as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $parts[] = rawurlencode($key) . '=' . rawurlencode((string) $item);
                }

                continue;
            }

            $parts[] = rawurlencode($key) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $parts);
    }
}
