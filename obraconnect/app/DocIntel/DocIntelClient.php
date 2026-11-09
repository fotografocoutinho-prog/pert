<?php

namespace App\DocIntel;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente do CODA DocIntel (motor próprio de leitura de faturas e guias). Usa a chave de serviço
 * (X-API-Key) e o pedido síncrono /documents/analyze: o ficheiro vai, o resultado vem na resposta.
 * O DocIntel guarda uma cópia do ficheiro (a referência externa diz de que empresa e documento é).
 */
class DocIntelClient
{
    public function enabled(): bool
    {
        return filled(config('obraconnect.docintel.url')) && filled(config('obraconnect.docintel.key'));
    }

    /**
     * @return array<string, mixed> o resultado (DocumentResult do DocIntel), com document_id
     *
     * @throws DocIntelException
     */
    public function analyze(string $absolutePath, string $filename, string $externalRef): array
    {
        if (! $this->enabled()) {
            throw new DocIntelException('A leitura automática não está configurada (DOCINTEL_URL e DOCINTEL_KEY).');
        }
        $contents = @file_get_contents($absolutePath);
        if ($contents === false) {
            throw new DocIntelException('O ficheiro original não foi encontrado.');
        }

        try {
            $response = Http::withHeaders(['X-API-Key' => (string) config('obraconnect.docintel.key')])
                ->acceptJson()
                ->timeout(max(10, (int) config('obraconnect.docintel.timeout')))
                ->attach('file', $contents, $filename)
                ->post(config('obraconnect.docintel.url').'/api/v1/documents/analyze', ['external_ref' => mb_substr($externalRef, 0, 120)]);
        } catch (ConnectionException $e) {
            throw new DocIntelException('O DocIntel não respondeu: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            $message = $response->json('error.message') ?? $response->json('detail') ?? $response->reason();
            $message = is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE);

            throw new DocIntelException(match ($response->status()) {
                401, 403 => 'O DocIntel recusou a chave (DOCINTEL_KEY).',
                413 => 'O ficheiro é grande demais para o DocIntel.',
                415 => 'O DocIntel não reconhece o ficheiro como PDF nem como imagem.',
                default => "O DocIntel devolveu um erro ({$response->status()}): {$message}",
            });
        }

        $result = $response->json('result');
        if (! is_array($result)) {
            throw new DocIntelException('O DocIntel não devolveu o resultado da leitura.');
        }

        return $result + ['document_id' => $response->json('document_id')];
    }
}
