<?php

declare(strict_types=1);

namespace Notyza;

/**
 * The official PHP client of the Notyza API: NFS-e, NF-e, NFC-e and MDF-e, and signed webhooks.
 *
 * Thin by design: each method is one route of /v1 (docs: /llms-full.txt, OpenAPI: /v1/openapi.json); the errors carry
 * the API's own `code` and Portuguese message; `Notyza::verifyWebhook` checks the `Notyza-Signature` header. PHP 8.1+,
 * ext-curl and ext-json only.
 *
 *   $notyza = new \Notyza\Notyza(getenv('NOTYZA_API_KEY'));
 *   $nota = $notyza->issueNota(['id' => 'pedido-1001', 'serviceCode' => '040101', 'description' => 'Consulta', 'valueCents' => 25000]);
 */
final class Notyza
{
    public const VERSION = '0.1.0';

    private string $baseUrl;

    /** @var callable(string, string, array<string,string>, ?string, float): array{0:int,1:string} */
    private $transport;

    /**
     * @param callable(string, string, array<string,string>, ?string, float): array{0:int,1:string}|null $transport For tests.
     */
    public function __construct(
        private string $apiKey,
        string $baseUrl = 'https://api.notyza.com.br',
        private float $timeout = 60.0,
        ?callable $transport = null,
    ) {
        if (!preg_match('/^ek_(test|live)_/', $apiKey)) {
            throw new \InvalidArgumentException('Notyza: a chave precisa ser ek_test_… ou ek_live_….');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport ?? [self::class, 'curl'];
    }

    /** "test" (no fiscal value) or "live". */
    public function mode(): string
    {
        return str_starts_with($this->apiKey, 'ek_live_') ? 'live' : 'test';
    }

    /**
     * Any route of /v1. Returns the JSON plus `httpStatus`; throws NotyzaException on 4xx/5xx except those in $accept.
     *
     * @param array<string,mixed>|null $body
     * @param int[] $accept
     * @return array<string,mixed>
     */
    public function request(string $method, string $path, ?array $body = null, array $accept = []): array
    {
        $headers = ['authorization' => 'Bearer ' . $this->apiKey, 'accept' => 'application/json', 'user-agent' => 'notyza-php/' . self::VERSION];
        $payload = null;
        if ($body !== null) {
            $headers['content-type'] = 'application/json';
            $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        [$status, $raw] = ($this->transport)($method, $this->baseUrl . $path, $headers, $payload, $this->timeout);
        $json = json_decode($raw === '' ? '{}' : $raw, true);
        if (!is_array($json)) {
            $json = ['error' => substr($raw, 0, 500)];
        }
        if ($status >= 400 && !in_array($status, $accept, true)) {
            throw new NotyzaException($status, (string) ($json['code'] ?? 'http_error'), (string) ($json['error'] ?? "A API respondeu {$status}."), $json);
        }
        return $json + ['httpStatus' => $status];
    }

    /** @param array<string,scalar|null> $params */
    private static function query(array $params): string
    {
        $clean = array_filter($params, static fn ($value) => $value !== null && $value !== '');
        return $clean ? '?' . http_build_query($clean) : '';
    }

    private static function id(string $value): string
    {
        return rawurlencode($value);
    }

    // NFS-e, and every document through the generic routes. A document answers 200 issued, 202 with the government
    // (ask again with the same id), 422 refused (`errors[].explanation`, `errors[].fix`).

    /** @param array<string,mixed> $nota */
    public function issueNota(array $nota): array
    {
        return $this->request('POST', '/v1/notas', $nota, [422]);
    }

    public function getNota(string $id, ?string $issuer = null): array
    {
        return $this->request('GET', '/v1/notas/' . self::id($id) . self::query(['issuer' => $issuer]));
    }

    /** @param array<string,scalar|null> $filters */
    public function listNotas(array $filters = []): array
    {
        return $this->request('GET', '/v1/notas' . self::query($filters));
    }

    /** @param array<string,mixed> $body */
    public function cancelNota(string $id, array $body = []): array
    {
        return $this->request('POST', '/v1/notas/' . self::id($id) . '/cancel', $body, [422]);
    }

    /** Why the government refused: `nota` (an id of the account) or `code`. @param array<string,mixed> $body */
    public function diagnose(array $body): array
    {
        return $this->request('POST', '/v1/diagnose', $body);
    }

    /** @param array<string,mixed> $nfe */
    public function issueNfe(array $nfe): array
    {
        return $this->request('POST', '/v1/nfe', $nfe, [422]);
    }

    /** @param array<string,mixed> $nfce */
    public function issueNfce(array $nfce): array
    {
        return $this->request('POST', '/v1/nfce', $nfce, [422]);
    }

    /** Inutilização of a range of the series: $model is "nfe" or "nfce"; 200 voided, 422 refused by SEFAZ. */
    public function voidNumbers(string $model, int $from, int $to, string $reason, ?string $issuer = null): array
    {
        return $this->request('POST', "/v1/{$model}/void", array_filter(['issuer' => $issuer, 'from' => $from, 'to' => $to, 'reason' => $reason], static fn ($value) => $value !== null), [422]);
    }

    /** @param array<string,mixed> $mdfe */
    public function issueMdfe(array $mdfe): array
    {
        return $this->request('POST', '/v1/mdfe', $mdfe, [422]);
    }

    /** @param array<string,mixed> $body */
    public function closeMdfe(string $id, array $body = []): array
    {
        return $this->request('POST', '/v1/mdfe/' . self::id($id) . '/close', $body);
    }

    /** CT-e: the document of each road freight of a carrier in the Simples. 200 authorized, 202 still open, 422 refused. */
    /** @param array<string,mixed> $cte */
    public function issueCte(array $cte): array
    {
        return $this->request('POST', '/v1/cte', $cte, [422]);
    }

    /** A carta de correção: [['group' => ..., 'field' => ..., 'value' => ...]]; never values, parties or dates. */
    /** @param list<array<string,mixed>> $corrections */
    public function correctCte(string $id, array $corrections, ?string $issuer = null): array
    {
        return $this->request('POST', '/v1/cte/' . self::id($id) . '/correction', array_filter(['issuer' => $issuer, 'corrections' => $corrections], static fn ($value) => $value !== null));
    }

    /** The CT-e of complement: the value that was missing of an authorized freight. */
    /** @param array<string,mixed> $body */
    public function complementCte(string $id, array $body): array
    {
        return $this->request('POST', '/v1/cte/' . self::id($id) . '/complement', $body, [422]);
    }

    /** The substitute CT-e: the same freight with what was wrong fixed (after the tomador's disagreement). */
    /** @param array<string,mixed> $body */
    public function substituteCte(string $id, array $body): array
    {
        return $this->request('POST', '/v1/cte/' . self::id($id) . '/substitute', $body, [422]);
    }

    /** The tomador's side: the service of a CT-e it paid for (of another carrier) was not as agreed. */
    public function cteDisagreement(string $key, string $reason, ?string $issuer = null): array
    {
        return $this->request('POST', '/v1/cte/disagreement', array_filter(['issuer' => $issuer, 'key' => $key, 'reason' => $reason], static fn ($value) => $value !== null));
    }

    /** @param array<string,mixed> $body */
    public function createIssuer(array $body): array
    {
        return $this->request('POST', '/v1/issuers', $body);
    }

    public function issuerChecklist(string $id): array
    {
        return $this->request('GET', '/v1/issuers/' . self::id($id) . '/checklist');
    }

    /** A one-use panel link (30 minutes) where the person sends the .pfx and its password. */
    public function certificateLink(string $id): array
    {
        return $this->request('POST', '/v1/issuers/' . self::id($id) . '/certificate-link');
    }

    /** @param string[] $events */
    public function createWebhook(string $url, array $events = []): array
    {
        return $this->request('POST', '/v1/webhooks', $events ? ['url' => $url, 'events' => $events] : ['url' => $url]);
    }

    /** A city's situation in the NFS-e Nacional (no key needed on the API side). */
    public function city(string $cityCode): array
    {
        return $this->request('GET', '/v1/public/cities/' . self::id($cityCode));
    }

    /**
     * Checks `Notyza-Signature` (`t=<unix>,v1=<hex>[,v1=<hex>]`): HMAC-SHA256 of "<t>.<raw body>" with the webhook's
     * secret; refuses signatures older than $toleranceSeconds. Pass the raw body exactly as received (php://input).
     */
    public static function verifyWebhook(string $signatureHeader, string $rawBody, string $secret, int $toleranceSeconds = 300, ?int $now = null): bool
    {
        $parts = array_map('trim', explode(',', $signatureHeader));
        $timestamp = null;
        foreach ($parts as $part) {
            if (str_starts_with($part, 't=') && ctype_digit(substr($part, 2))) {
                $timestamp = (int) substr($part, 2);
            }
        }
        if ($timestamp === null || abs(($now ?? time()) - $timestamp) > $toleranceSeconds) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        foreach ($parts as $part) {
            if (str_starts_with($part, 'v1=') && hash_equals($expected, substr($part, 3))) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,string> $headers
     * @return array{0:int,1:string}
     */
    private static function curl(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $handle = curl_init($url);
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($handle);
        if ($raw === false) {
            $message = curl_error($handle);
            curl_close($handle);
            throw new NotyzaException(0, 'network_error', "Sem resposta da API: {$message}", []);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return [$status, (string) $raw];
    }
}

/** An answer the API refused (4xx/5xx): `code` is stable, the message is the sentence to show. */
final class NotyzaException extends \RuntimeException
{
    /** @param array<string,mixed> $body */
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message, public readonly array $body)
    {
        parent::__construct($message, $status);
    }
}
