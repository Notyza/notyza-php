<?php

declare(strict_types=1);

// The PHP SDK's tests, without PHPUnit: `php tests/run.php` (the CI runs it in the php:8.3-cli image).
require __DIR__ . '/../src/Notyza.php';

use Notyza\Notyza;
use Notyza\NotyzaException;

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
};

/** A transport that answers by "METHOD /path" and records the calls. */
$calls = [];
$fake = static function (array $answers) use (&$calls): callable {
    return static function (string $method, string $url, array $headers, ?string $body, float $timeout) use ($answers, &$calls): array {
        $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body === null ? null : json_decode($body, true)];
        $path = parse_url($url, PHP_URL_PATH);
        [$status, $payload] = $answers["{$method} {$path}"] ?? [404, ['error' => 'Rota não encontrada.', 'code' => 'not_found']];
        return [$status, json_encode($payload)];
    };
};

try {
    new Notyza('sk_x');
    $check(false, 'refuses a key that is not a Notyza key');
} catch (InvalidArgumentException) {
    $check(true, 'refuses a key that is not a Notyza key');
}

$notyza = new Notyza('ek_test_abc', 'https://api.notyza.test/', 60.0, $fake([
    'POST /v1/notas' => [200, ['id' => 'pedido-1', 'status' => 'issued']],
    'POST /v1/nfe' => [422, ['status' => 'rejected']],
    'POST /v1/issuers' => [403, ['error' => 'O plano Grátis permite 1 empresa.', 'code' => 'plan_company_limit']],
    'GET /v1/notas' => [200, ['notas' => []]],
    'POST /v1/nfce/void' => [200, ['status' => 'voided']],
]));
$nota = $notyza->issueNota(['id' => 'pedido-1', 'valueCents' => 25000]);
$check($nota['status'] === 'issued' && $nota['httpStatus'] === 200, 'issues and answers 200');
$check($calls[0]['url'] === 'https://api.notyza.test/v1/notas' && $calls[0]['headers']['authorization'] === 'Bearer ek_test_abc', 'sends the key to the right route');
$check($calls[0]['body'] === ['id' => 'pedido-1', 'valueCents' => 25000], 'sends the body as JSON');
$check($notyza->issueNfe(['id' => 'p2'])['httpStatus'] === 422, 'a refusal (422) is an answer, not an exception');
try {
    $notyza->createIssuer(['cnpj' => '11222333000181']);
    $check(false, 'throws NotyzaException with the API code');
} catch (NotyzaException $error) {
    $check($error->status === 403 && $error->errorCode === 'plan_company_limit' && $error->getMessage() === 'O plano Grátis permite 1 empresa.', 'throws NotyzaException with the API code');
}
$notyza->listNotas(['model' => 'mdfe', 'status' => 'issued', 'limit' => 10]);
$check(end($calls)['url'] === 'https://api.notyza.test/v1/notas?model=mdfe&status=issued&limit=10', 'builds the query string');
$notyza->voidNumbers('nfce', 10, 12, 'Falha do sistema: números pulados');
$check(end($calls)['body'] === ['from' => 10, 'to' => 12, 'reason' => 'Falha do sistema: números pulados'], 'voids numbers');

$secret = 'whsec_teste';
$body = '{"type":"nota.issued","data":{"id":"pedido-1"}}';
$sign = static fn (int $t, string $key) => "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$body}", $key);
$check(Notyza::verifyWebhook($sign(1000, $secret), $body, $secret, 300, 1010), 'accepts the right signature');
$check(Notyza::verifyWebhook($sign(1000, 'whsec_novo') . ',v1=' . explode('v1=', $sign(1000, $secret))[1], $body, $secret, 300, 1010), 'accepts during a secret rotation');
$check(!Notyza::verifyWebhook($sign(1000, 'outro'), $body, $secret, 300, 1010), 'refuses a wrong secret');
$check(!Notyza::verifyWebhook($sign(1000, $secret), $body . ' ', $secret, 300, 1010), 'refuses a changed body');
$check(!Notyza::verifyWebhook($sign(1000, $secret), $body, $secret, 300, 1301), 'refuses an old signature');

echo $failures === 0 ? 'all passed' . PHP_EOL : "{$failures} failed" . PHP_EOL;
exit($failures === 0 ? 0 : 1);
