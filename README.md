# Notyza para PHP

O cliente oficial da API do [Notyza](https://notyza.com.br): NFS-e, NF-e, NFC-e e MDF-e para empresas do Simples Nacional, e a
conferência dos webhooks assinados. PHP 8.1+, só ext-curl e ext-json.

## Instalar

Enquanto o pacote não está no Packagist, aponte o Composer para este repositório:

```json
{
  "repositories": [{ "type": "vcs", "url": "https://github.com/notyza/notyza-php" }],
  "require": { "notyza/notyza-php": "^0.1" }
}
```

## Emitir

```php
$notyza = new \Notyza\Notyza(getenv('NOTYZA_API_KEY'));

$nota = $notyza->issueNota([
    'id' => 'pedido-1001', // o id do seu pedido: o mesmo id nunca emite duas vezes
    'serviceCode' => '040101',
    'description' => 'Consulta médica',
    'valueCents' => 25000,
]);
if ($nota['httpStatus'] === 422) {
    foreach ($nota['errors'] as $error) {
        echo $error['explanation'], ' ', $error['fix'], PHP_EOL;
    }
}
```

Também: `issueNfe`, `issueNfce`, `issueMdfe`, `closeMdfe`, `voidNumbers`, `createIssuer`, `issuerChecklist`,
`certificateLink`, `createWebhook`, `diagnose` e `city`. Qualquer outra rota: `$notyza->request($method, $path, $body)`.

## Webhooks

```php
// O corpo cru, exatamente como chegou.
$ok = \Notyza\Notyza::verifyWebhook($_SERVER['HTTP_NOTYZA_SIGNATURE'] ?? '', file_get_contents('php://input'), getenv('NOTYZA_WEBHOOK_SECRET'));
if (!$ok) {
    http_response_code(401);
    exit;
}
```

## Respostas

- **200**: emitida. **202**: ainda com o governo; pergunte de novo com o mesmo `id` (nunca emite duas vezes) ou espere o webhook `nota.issued`.
- **422**: recusada. Vem como resposta, não como erro: mostre `errors[].explanation` e `errors[].fix` (a causa e como corrigir, em português).
- Qualquer outro 4xx/5xx vira exceção com o `code` estável da API (`plan_company_limit`, `payment_required`…) e a frase para mostrar.
- Chave `ek_test_…`: produção restrita do governo, sem valor fiscal. `ek_live_…`: notas de verdade.

Documentação completa: https://notyza.com.br/docs (também em `/llms-full.txt` para assistentes de IA). OpenAPI: https://api.notyza.com.br/v1/openapi.json.

## Licença

MIT.
