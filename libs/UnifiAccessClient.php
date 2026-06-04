<?php

declare(strict_types=1);

/**
 * HTTP-Client für die UniFi Access Developer API (Besucher).
 */
class UnifiAccessClient
{
    public const PIN_REMARK_PREFIX = 'PIN:';

    /** @var array<int, string> */
    private const VISITOR_LIST_EXPAND = ['pin_code', 'resource', 'schedule'];

    private const TOKEN_HELP = 'API-Token unter UniFi OS → Access → Einstellungen → Allgemein → Erweitert → API anlegen '
        . '(nicht unter Einstellungen → Control Plane → Integrationen). '
        . 'Benötigte Berechtigungen: view:space, view:policy, view:visitor, edit:visitor, edit:credential.';

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
     * Prüft Host, Port und Token. Nutzt zuerst einen leichtgewichtigen Endpunkt (view:space),
     * optional mit Hinweis wenn view:policy für Zugangsprofile fehlt.
     */
    public function testConnection(): string
    {
        $topology = $this->probe('GET', '/door_groups/topology');

        if (!$topology['ok']) {
            if ($this->isAuthOrPermissionError($topology['httpCode'])) {
                throw new RuntimeException($this->authErrorMessage($topology['message']));
            }

            throw new RuntimeException($topology['message']);
        }

        $policies = $this->probe('GET', '/access_policies');

        if (!$policies['ok']) {
            if ($this->isAuthOrPermissionError($policies['httpCode'])) {
                return 'Verbindung OK. Berechtigung view:policy fehlt – Zugangsprofile (GetAccessProfiles) sind nicht verfügbar. '
                    . self::TOKEN_HELP;
            }

            throw new RuntimeException($policies['message']);
        }

        return 'Verbindung erfolgreich.';
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
                'expand[]' => self::VISITOR_LIST_EXPAND,
            ]);

            foreach ($response['data'] ?? [] as $visitor) {
                if (!is_array($visitor)) {
                    continue;
                }

                $visitor = $this->enrichVisitorWithPin($visitor);

                if (self::pinFromRemark($this->visitorRemarks($visitor)) === $pin) {
                    return $visitor;
                }
            }

            $total = (int) ($response['pagination']['total'] ?? 0);
            $page++;
        } while (($page - 1) * $pageSize < $total);

        return null;
    }

    /**
     * @return array<int, array{
     *     id: string,
     *     first_name: string,
     *     last_name: string,
     *     pin: string|null,
     *     pin_code_token?: string,
     *     start_time: int,
     *     end_time: int,
     *     status: string,
     *     access_policy_ids: array<int, string>,
     *     resources: array<int, array{id: string, type: string, name?: string}>
     * }>
     */
    public function getAllVisitors(): array
    {
        $page = 1;
        $pageSize = 100;
        $visitors = [];

        do {
            $response = $this->request('GET', '/visitors', null, [
                'page_num' => (string) $page,
                'page_size' => (string) $pageSize,
                'expand[]' => self::VISITOR_LIST_EXPAND,
            ]);

            foreach ($response['data'] ?? [] as $visitor) {
                if (!is_array($visitor)) {
                    continue;
                }

                $visitors[] = $this->normalizeVisitorEntry($this->enrichVisitorWithPin($visitor));
            }

            $total = (int) ($response['pagination']['total'] ?? 0);
            $page++;
        } while (($page - 1) * $pageSize < $total);

        return $visitors;
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
            'expand[]' => self::VISITOR_LIST_EXPAND,
        ]);

        return $response['data'] ?? [];
    }

    /**
     * Resolves plaintext PIN: remarks (PIN:xxx), then GET /visitors/:id, then GET /visitors/:id/pin_codes.
     * List entries often have empty remarks; pin_code expand only exposes token (hash).
     *
     * @param array<string, mixed> $visitor
     * @return array<string, mixed>
     */
    private function enrichVisitorWithPin(array $visitor): array
    {
        $visitorId = $visitor['id'] ?? null;

        if ($visitorId === null || $visitorId === '') {
            return $visitor;
        }

        $visitorId = (string) $visitorId;
        $pin = self::pinFromRemark($this->visitorRemarks($visitor));

        if ($pin === null || !$this->hasPinCodeObject($visitor)) {
            $visitor = array_merge($visitor, $this->getVisitor($visitorId));
            $pin = self::pinFromRemark($this->visitorRemarks($visitor));
        }

        if ($pin === null) {
            $pin = $this->plainPinFromPinCodeObject($visitor['pin_code'] ?? null);
        }

        if ($pin === null) {
            $pinResource = $this->fetchVisitorPinCodeResource($visitorId);

            if ($pinResource !== null) {
                $visitor['pin_code'] = array_merge(
                    is_array($visitor['pin_code'] ?? null) ? $visitor['pin_code'] : [],
                    $pinResource
                );
                $pin = $this->plainPinFromPinCodeObject($pinResource);
            }
        }

        return $visitor;
    }

    /**
     * @param array<string, mixed> $visitor
     */
    private function visitorRemarks(array $visitor): ?string
    {
        if (!array_key_exists('remarks', $visitor)) {
            return null;
        }

        $remarks = (string) $visitor['remarks'];

        return $remarks !== '' ? $remarks : null;
    }

    /**
     * @param array<string, mixed> $visitor
     */
    private function hasPinCodeObject(array $visitor): bool
    {
        return isset($visitor['pin_code']) && is_array($visitor['pin_code']) && $visitor['pin_code'] !== [];
    }

    /**
     * Undocumented read endpoint; returns null on 404 or missing permission.
     *
     * @return array<string, mixed>|null
     */
    private function fetchVisitorPinCodeResource(string $visitorId): ?array
    {
        try {
            $response = $this->request('GET', '/visitors/' . $visitorId . '/pin_codes');
            $data = $response['data'] ?? null;

            if (is_array($data)) {
                return $data;
            }

            if (is_string($data) && $data !== '') {
                return ['pin_code' => $data];
            }
        } catch (RuntimeException) {
            // GET not supported or no PIN assigned
        }

        return null;
    }

    /**
     * @param mixed $pinCode
     */
    private function plainPinFromPinCodeObject($pinCode): ?string
    {
        if (!is_array($pinCode)) {
            return null;
        }

        foreach (['pin_code', 'code', 'value', 'pin'] as $key) {
            if (!isset($pinCode[$key]) || !is_string($pinCode[$key]) || $pinCode[$key] === '') {
                continue;
            }

            $value = $pinCode[$key];

            if (preg_match('/^[a-f0-9]{64}$/i', $value) === 1) {
                continue;
            }

            return $value;
        }

        return null;
    }

    /**
     * @param mixed $pinCode
     */
    private function pinCodeTokenFromObject($pinCode): ?string
    {
        if (!is_array($pinCode) || !isset($pinCode['token'])) {
            return null;
        }

        $token = (string) $pinCode['token'];

        return $token !== '' ? $token : null;
    }

    /**
     * @param array<string, mixed> $visitor
     * @return array{
     *     id: string,
     *     first_name: string,
     *     last_name: string,
     *     pin: string|null,
     *     pin_code_token?: string,
     *     start_time: int,
     *     end_time: int,
     *     status: string,
     *     access_policy_ids: array<int, string>,
     *     resources: array<int, array{id: string, type: string, name?: string}>
     * }
     */
    private function normalizeVisitorEntry(array $visitor): array
    {
        $accessPolicyIds = [];

        foreach ($visitor['access_policy_ids'] ?? [] as $policyId) {
            if (is_string($policyId) || is_int($policyId)) {
                $accessPolicyIds[] = (string) $policyId;
            }
        }

        if ($accessPolicyIds === [] && isset($visitor['access_policies']) && is_array($visitor['access_policies'])) {
            foreach ($visitor['access_policies'] as $policy) {
                if (is_array($policy) && isset($policy['id'])) {
                    $accessPolicyIds[] = (string) $policy['id'];
                }
            }
        }

        $resources = [];

        foreach ($visitor['resources'] ?? [] as $resource) {
            if (!is_array($resource) || !isset($resource['id'])) {
                continue;
            }

            $entry = [
                'id' => (string) $resource['id'],
                'type' => (string) ($resource['type'] ?? ''),
            ];

            if (isset($resource['name'])) {
                $entry['name'] = (string) $resource['name'];
            }

            $resources[] = $entry;
        }

        $remarks = $this->visitorRemarks($visitor);
        $pin = self::pinFromRemark($remarks);

        if ($pin === null) {
            $pin = $this->plainPinFromPinCodeObject($visitor['pin_code'] ?? null);
        }

        $entry = [
            'id' => (string) ($visitor['id'] ?? ''),
            'first_name' => (string) ($visitor['first_name'] ?? ''),
            'last_name' => (string) ($visitor['last_name'] ?? ''),
            'pin' => $pin,
            'start_time' => (int) ($visitor['start_time'] ?? 0),
            'end_time' => (int) ($visitor['end_time'] ?? 0),
            'status' => (string) ($visitor['status'] ?? ''),
            'access_policy_ids' => $accessPolicyIds,
            'resources' => $resources,
        ];

        $pinCodeToken = $this->pinCodeTokenFromObject($visitor['pin_code'] ?? null);

        if ($pinCodeToken !== null && $pin === null) {
            $entry['pin_code_token'] = $pinCodeToken;
        }

        return $entry;
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
            $message = 'API-Fehler (HTTP ' . $httpCode . '): ' . ($decoded['msg'] ?? $raw);

            if ($this->isAuthOrPermissionError($httpCode) && $path === '/access_policies') {
                $message .= ' ' . self::TOKEN_HELP;
            }

            throw new RuntimeException($message);
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
     * @return array{ok: bool, httpCode: int, message: string}
     */
    private function probe(string $method, string $path): array
    {
        try {
            $this->request($method, $path);

            return ['ok' => true, 'httpCode' => 200, 'message' => ''];
        } catch (RuntimeException $e) {
            return [
                'ok' => false,
                'httpCode' => $this->httpCodeFromMessage($e->getMessage()),
                'message' => $e->getMessage(),
            ];
        }
    }

    private function isAuthOrPermissionError(int $httpCode): bool
    {
        return $httpCode === 401 || $httpCode === 403;
    }

    private function httpCodeFromMessage(string $message): int
    {
        if (preg_match('/HTTP (\d+)/', $message, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function authErrorMessage(string $detail): string
    {
        return 'Authentifizierung fehlgeschlagen (' . $detail . '). ' . self::TOKEN_HELP;
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
