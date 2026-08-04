<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Busca endereco por CEP na ViaCEP. Duas armadilhas do servico, tratadas aqui:
 *
 * 1. CEP inexistente NAO devolve 404 — devolve 200 com {"erro": "true"}, e o
 *    "true" e string. Confiar no status faria endereco vazio parecer sucesso.
 * 2. O campo "complemento" da ViaCEP e faixa postal ("ate 600 - lado par"),
 *    nao complemento de endereco. Nunca vai para o complemento do contato:
 *    aquele campo e do apartamento de quem mora la.
 */
class ConsultaCep
{
    public function __construct(
        private readonly string $url,
        private readonly int $timeout,
        private readonly int $cacheHoras,
    ) {}

    public static function digitos(?string $cep): string
    {
        return preg_replace('/\D/', '', (string) $cep) ?? '';
    }

    public static function valido(?string $cep): bool
    {
        $digitos = self::digitos($cep);

        // 00000000 e afins existem no formato e nao existem no mapa.
        return strlen($digitos) === 8 && ! preg_match('/^(\d)\1{7}$/', $digitos);
    }

    public static function formatar(?string $cep): string
    {
        $digitos = self::digitos($cep);

        return strlen($digitos) === 8
            ? substr($digitos, 0, 5).'-'.substr($digitos, 5)
            : (string) $cep;
    }

    /**
     * @return array{ok: bool, erro: ?string, dados: array<string, ?string>}
     */
    public function consultar(?string $cep): array
    {
        $digitos = self::digitos($cep);

        if (! self::valido($digitos)) {
            return $this->falha('CEP inválido — são 8 dígitos.');
        }

        $cacheados = Cache::get($this->chave($digitos));

        if (is_array($cacheados)) {
            return ['ok' => true, 'erro' => null, 'dados' => $cacheados];
        }

        try {
            $resposta = Http::timeout($this->timeout)
                ->acceptJson()
                ->get(rtrim($this->url, '/').'/'.$digitos.'/json/');
        } catch (\Throwable $e) {
            Log::warning('consulta de cep falhou', ['cep' => $digitos, 'erro' => $e->getMessage()]);

            return $this->falha('Não foi possível consultar o CEP agora. Preencha à mão ou tente de novo.');
        }

        if (! $resposta->successful()) {
            return $this->falha('A consulta de CEP falhou (erro '.$resposta->status().').');
        }

        $json = $resposta->json();

        if (! is_array($json) || filled($json['erro'] ?? null)) {
            return $this->falha('CEP não encontrado.');
        }

        $dados = [
            'cep'        => $digitos,
            'logradouro' => $this->texto($json['logradouro'] ?? null),
            'bairro'     => $this->texto($json['bairro'] ?? null),
            'cidade'     => $this->texto($json['localidade'] ?? null),
            'uf'         => $this->texto($json['uf'] ?? null),
        ];

        // CEP nao muda: cache longo e o que evita consultar o mesmo bairro
        // dezenas de vezes num dia de cadastro em massa.
        Cache::put($this->chave($digitos), $dados, now()->addHours($this->cacheHoras));

        return ['ok' => true, 'erro' => null, 'dados' => $dados];
    }

    private function texto(mixed $valor): ?string
    {
        if (! is_scalar($valor)) {
            return null;
        }

        return trim((string) $valor) ?: null;
    }

    /** @return array{ok: bool, erro: string, dados: array<string, ?string>} */
    private function falha(string $erro): array
    {
        return ['ok' => false, 'erro' => $erro, 'dados' => []];
    }

    private function chave(string $cep): string
    {
        return 'cep:'.$cep;
    }
}
