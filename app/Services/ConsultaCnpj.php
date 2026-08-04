<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Consulta dados cadastrais de CNPJ na BrasilAPI, que serve a base publica da
 * Receita Federal. Sem chave e sem cadastro — mas com limite por IP, por isso o
 * resultado fica em cache: reabrir a tela de cadastro nao gasta consulta.
 *
 * O digito verificador e conferido aqui antes de sair da maquina. Um CNPJ
 * digitado errado nao merece uma chamada de rede nem um "nao encontrado" que
 * parece falha do fornecedor.
 */
class ConsultaCnpj
{
    public function __construct(
        private readonly string $url,
        private readonly int $timeout,
        private readonly int $cacheHoras,
    ) {}

    public static function digitos(?string $documento): string
    {
        return preg_replace('/\D/', '', (string) $documento) ?? '';
    }

    public static function valido(?string $documento): bool
    {
        $cnpj = self::digitos($documento);

        // Repetido tipo 11111111111111 passa na conta dos digitos, mas nao existe.
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $base = substr($cnpj, 0, 12);
        $primeiro = self::digitoVerificador($base);
        $segundo = self::digitoVerificador($base.$primeiro);

        return $cnpj === $base.$primeiro.$segundo;
    }

    /** Modulo 11 da Receita: peso ciclico de 2 a 9, da direita para a esquerda. */
    private static function digitoVerificador(string $base): int
    {
        $soma = 0;
        $peso = 2;

        for ($i = strlen($base) - 1; $i >= 0; $i--) {
            $soma += (int) $base[$i] * $peso;
            $peso = $peso === 9 ? 2 : $peso + 1;
        }

        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }

    public static function formatar(?string $documento): string
    {
        $cnpj = self::digitos($documento);

        if (strlen($cnpj) !== 14) {
            return (string) $documento;
        }

        return vsprintf('%s.%s.%s/%s-%s', [
            substr($cnpj, 0, 2), substr($cnpj, 2, 3), substr($cnpj, 5, 3),
            substr($cnpj, 8, 4), substr($cnpj, 12, 2),
        ]);
    }

    /**
     * @return array{ok: bool, erro: ?string, dados: array<string, ?string>}
     */
    public function consultar(?string $documento): array
    {
        $cnpj = self::digitos($documento);

        if (! self::valido($cnpj)) {
            return $this->falha('CNPJ inválido — confira os números.');
        }

        $cacheados = Cache::get($this->chave($cnpj));

        if (is_array($cacheados)) {
            return ['ok' => true, 'erro' => null, 'dados' => $cacheados];
        }

        try {
            $resposta = Http::timeout($this->timeout)
                ->acceptJson()
                ->get(rtrim($this->url, '/').'/'.$cnpj);
        } catch (\Throwable $e) {
            Log::warning('consulta de cnpj falhou', ['cnpj' => $cnpj, 'erro' => $e->getMessage()]);

            return $this->falha('Não foi possível falar com a Receita agora. Tente de novo em instantes.');
        }

        if ($resposta->status() === 404) {
            return $this->falha('CNPJ não encontrado na base da Receita Federal.');
        }

        // A BrasilAPI limita por IP. Dizer isso e melhor que "erro desconhecido":
        // o proximo passo do usuario e esperar, nao conferir o numero de novo.
        if ($resposta->status() === 429) {
            return $this->falha('Muitas consultas seguidas. Espere um minuto e tente de novo.');
        }

        if (! $resposta->successful()) {
            Log::warning('consulta de cnpj devolveu status inesperado', [
                'cnpj' => $cnpj, 'status' => $resposta->status(),
            ]);

            return $this->falha('A consulta falhou (erro '.$resposta->status().'). Tente de novo em instantes.');
        }

        $dados = $this->normalizar($resposta->json() ?? []);

        Cache::put($this->chave($cnpj), $dados, now()->addHours($this->cacheHoras));

        return ['ok' => true, 'erro' => null, 'dados' => $dados];
    }

    /**
     * Traduz o payload da Receita para os campos do cadastro. Guardar o json cru
     * seria mais facil e inutil: a tela precisa de nomes estaveis.
     *
     * @param  array<string, mixed>  $j
     * @return array<string, ?string>
     */
    private function normalizar(array $j): array
    {
        // A Receita separa o tipo do nome da rua: "AVENIDA" + "REPUBLICA DO CHILE".
        $logradouro = trim(implode(' ', array_filter([
            $this->texto($j['descricao_tipo_de_logradouro'] ?? null),
            $this->texto($j['logradouro'] ?? null),
        ])));

        $numero = $this->texto($j['numero'] ?? null);

        // Parte dos registros ja traz o numero dentro do nome da rua ("PAULISTA
        // 37", com numero 37 no campo proprio). Sem tirar, o endereco sai
        // "Avenida Paulista 37, n. 37".
        if ($numero !== null && $logradouro !== '') {
            $logradouro = trim(preg_replace(
                '/[\s,]+'.preg_quote($numero, '/').'$/', '', $logradouro
            ) ?? $logradouro);
        }

        return [
            'razao_social'        => $this->texto($j['razao_social'] ?? null),
            'nome_fantasia'       => $this->texto($j['nome_fantasia'] ?? null),
            'email'               => $this->texto($j['email'] ?? null),
            'telefone'            => $this->telefone($j['ddd_telefone_1'] ?? null),
            'cep'                 => self::digitos($this->texto($j['cep'] ?? null)) ?: null,
            'logradouro'          => $logradouro ?: null,
            'numero'              => $numero,
            'complemento'         => $this->texto($j['complemento'] ?? null),
            'bairro'              => $this->texto($j['bairro'] ?? null),
            'cidade'              => $this->texto($j['municipio'] ?? null),
            'uf'                  => $this->texto($j['uf'] ?? null),
            'natureza_juridica'   => $this->texto($j['natureza_juridica'] ?? null),
            'cnae_principal'      => $this->cnae($j),
            'situacao_cadastral'  => $this->texto($j['descricao_situacao_cadastral'] ?? null),
            'porte'               => $this->texto($j['porte'] ?? null),
            'data_abertura'       => $this->texto($j['data_inicio_atividade'] ?? null),
        ];
    }

    /** @param array<string, mixed> $j */
    private function cnae(array $j): ?string
    {
        $codigo = $this->texto($j['cnae_fiscal'] ?? null);
        $descricao = $this->texto($j['cnae_fiscal_descricao'] ?? null);

        if (! $codigo && ! $descricao) {
            return null;
        }

        return trim(($codigo ? $codigo.' — ' : '').$descricao);
    }

    /** '2121660000' vira '(21) 2166-0000'; celular de 11 digitos tambem. */
    private function telefone(mixed $bruto): ?string
    {
        $numero = self::digitos(is_scalar($bruto) ? (string) $bruto : null);

        return match (strlen($numero)) {
            10 => sprintf('(%s) %s-%s', substr($numero, 0, 2), substr($numero, 2, 4), substr($numero, 6)),
            11 => sprintf('(%s) %s-%s', substr($numero, 0, 2), substr($numero, 2, 5), substr($numero, 7)),
            default => $numero ?: null,
        };
    }

    /** A Receita devolve string vazia onde nao tem dado; vazio virando null evita gravar ''. */
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

    private function chave(string $cnpj): string
    {
        return 'cnpj:'.$cnpj;
    }
}
