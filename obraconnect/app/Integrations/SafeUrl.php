<?php

namespace App\Integrations;

use App\Exceptions\ApiException;

/**
 * Endereços de webhooks: só https e só para a internet pública (nada de localhost, rede interna do
 * servidor ou endereços reservados), para o ObraConnect não ser usado para chegar a serviços internos.
 * Confirma-se ao gravar e outra vez a cada envio (o DNS pode mudar); o envio vai para o IP confirmado.
 */
final class SafeUrl
{
    /**
     * @return list<string> IPs confirmados (vazio quando a verificação de rede está desligada)
     */
    public static function assert(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if ($host === '' || ! in_array($scheme, ['https', 'http'], true)) {
            throw ApiException::unprocessable('Indique um endereço completo, a começar por https://.', 'WEBHOOK_URL_INVALID');
        }
        if ($scheme !== 'https' && ! config('obraconnect.integrations.allow_http')) {
            throw ApiException::unprocessable('O endereço tem de usar https://.', 'WEBHOOK_URL_INSECURE');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw ApiException::unprocessable('O endereço não pode levar utilizador e palavra-passe.', 'WEBHOOK_URL_INVALID');
        }
        if (! config('obraconnect.integrations.block_private_networks', true)) {
            return [];
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw ApiException::unprocessable('O endereço tem de ser público (não pode ser da rede interna).', 'WEBHOOK_URL_PRIVATE');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if ($ips === []) {
            throw ApiException::unprocessable('Não foi possível encontrar este endereço (DNS).', 'WEBHOOK_URL_UNRESOLVED');
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE) === false) {
                throw ApiException::unprocessable('O endereço tem de ser público (não pode ser da rede interna).', 'WEBHOOK_URL_PRIVATE');
            }
        }

        return $ips;
    }

    /**
     * Opção do cURL que obriga a ligação a ir para o IP confirmado (sem nova consulta ao DNS).
     *
     * @param  list<string>  $ips
     * @return array<string, mixed>
     */
    public static function pinnedOptions(string $url, array $ips): array
    {
        $parts = parse_url($url);
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if ($ips === [] || $host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return [];
        }
        $port = $parts['port'] ?? (strtolower($parts['scheme'] ?? '') === 'http' ? 80 : 443);
        $ip = str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0];

        return ['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]];
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }
        $ips = array_values(array_filter($ips));

        return $ips !== [] ? $ips : (gethostbynamel($host) ?: []);
    }
}
