<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MetaTemplate extends Model
{
    use BelongsToTenant;

    public const APROVADO = 'APPROVED';

    protected $fillable = [
        'tenant_id', 'meta_waba_id', 'meta_id', 'nome', 'idioma', 'categoria', 'status',
        'cabecalho', 'corpo', 'rodape', 'componentes', 'variaveis',
        'suportado', 'motivo_nao_suportado', 'sincronizado_em',
    ];

    protected $casts = [
        'componentes'     => 'array',
        'variaveis'       => 'integer',
        'suportado'       => 'boolean',
        'sincronizado_em' => 'datetime',
    ];

    /** So estes podem ser oferecidos ao atendente: aprovado E de formato que sabemos montar. */
    public function scopeEnviaveis(Builder $query): Builder
    {
        return $query->where('status', self::APROVADO)->where('suportado', true);
    }

    public function aprovado(): bool
    {
        return $this->status === self::APROVADO;
    }

    public function podeEnviar(): bool
    {
        return $this->aprovado() && $this->suportado;
    }

    /**
     * Por que este template nao pode ser usado, em uma frase para o atendente.
     *
     * Devolve null quando pode. "Nao disponivel" sem motivo vira pergunta para o suporte
     * no dia seguinte.
     */
    public function porQueNaoPodeEnviar(): ?string
    {
        if (! $this->aprovado()) {
            return match ($this->status) {
                'DRAFT' => 'rascunho: solicite aprovação da Meta',
                'PENDING'  => 'aguardando aprovação da Meta',
                'REJECTED' => 'reprovado pela Meta',
                'PAUSED'   => 'pausado pela Meta por qualidade',
                'DISABLED' => 'desativado pela Meta',
                default    => 'não aprovado ('.mb_strtolower((string) $this->status).')',
            };
        }

        return $this->suportado ? null : $this->motivo_nao_suportado;
    }

    /**
     * O texto como o cliente vai ler, com os valores nos lugares dos {{n}}.
     *
     * Serve para MOSTRAR na tela antes de enviar. O que vai para a Meta e o nome do
     * template mais a lista de parametros — nunca este texto montado.
     */
    public function renderizar(array $valores = []): string
    {
        $texto = (string) $this->corpo;

        $nomes = $this->nomesParametros();
        $substituicoes = [];
        foreach (array_values($valores) as $i => $valor) {
            $substituicoes[$nomes[$i] ?? (string) ($i + 1)] = (string) $valor;
        }
        $texto = preg_replace_callback('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*|\d+)\s*\}\}/',
            fn ($m) => $substituicoes[$m[1]] ?? $m[0], $texto) ?? $texto;

        return trim(($this->cabecalho ? $this->cabecalho."\n\n" : '').$texto);
    }

    /**
     * Le o template como a Meta o descreve e decide o que dele sabemos montar.
     *
     * Classificar na SINCRONIZACAO, e nao na hora do envio, e a diferenca entre o
     * atendente saber antes e descobrir com o cliente esperando. E o motivo vem escrito:
     * "nao suportado" sem dizer o que falta vira suporte na semana seguinte.
     *
     * @param  array<string, mixed>  $bruto  o template como veio da API
     * @return array<string, mixed>
     */
    public static function analisar(array $bruto): array
    {
        $cabecalho = null;
        $corpo = '';
        $rodape = null;
        $motivo = null;

        foreach ((array) ($bruto['components'] ?? []) as $componente) {
            $tipo = mb_strtoupper((string) ($componente['type'] ?? ''));

            if ($tipo === 'BODY') {
                $corpo = (string) ($componente['text'] ?? '');

                continue;
            }

            if ($tipo === 'FOOTER') {
                $rodape = (string) ($componente['text'] ?? '');

                continue;
            }

            if ($tipo === 'HEADER') {
                $formato = mb_strtoupper((string) ($componente['format'] ?? 'TEXT'));

                if ($formato !== 'TEXT') {
                    // Cabecalho de midia exige subir o arquivo antes e mandar o id de volta
                    // — outro fluxo, que ainda nao existe aqui.
                    $motivo ??= 'cabeçalho de '.mb_strtolower($formato).': exige enviar o arquivo antes';

                    continue;
                }

                $cabecalho = (string) ($componente['text'] ?? '');

                if (str_contains($cabecalho, '{{')) {
                    $motivo ??= 'cabeçalho com variável';
                }

                continue;
            }

            if ($tipo === 'BUTTONS') {
                foreach ((array) ($componente['buttons'] ?? []) as $botao) {
                    $tipoBotao = mb_strtoupper((string) ($botao['type'] ?? ''));

                    // Botao de URL fixa e de resposta rapida nao pedem parametro: o
                    // template sai igual sempre. Os outros pedem, e ai muda o envio.
                    if (! in_array($tipoBotao, ['URL', 'PHONE_NUMBER', 'QUICK_REPLY'], true)) {
                        $motivo ??= 'botão do tipo '.mb_strtolower($tipoBotao);
                    }

                    if (str_contains((string) ($botao['url'] ?? ''), '{{')) {
                        $motivo ??= 'botão com URL variável';
                    }
                }

                continue;
            }

            // Componente que eu nao conheco entra como nao suportado COM O NOME DELE. Sem o
            // nome, o dia em que a Meta criar um formato novo vira investigacao as cegas.
            $motivo ??= 'componente '.mb_strtolower($tipo).' ainda não tratado';
        }

        if (trim($corpo) === '') {
            $motivo ??= 'template sem corpo de texto';
        }

        if (preg_match('/\{\{\s*\d+\s*\}\}/', $corpo) && preg_match('/\{\{\s*[a-zA-Z_]\w*\s*\}\}/', $corpo)) {
            $motivo ??= 'mistura de parâmetros nomeados e posicionais';
        }

        return [
            'cabecalho'            => $cabecalho,
            'corpo'                => $corpo,
            'rodape'               => $rodape,
            'variaveis'            => self::maiorVariavel($corpo),
            'suportado'            => $motivo === null,
            'motivo_nao_suportado' => $motivo,
        ];
    }

    /** Ordem dos parâmetros nomeados; posicionais conservam o maior índice. */
    public function nomesParametros(): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', (string) $this->corpo, $achados);
        return array_values(array_unique($achados[1]));
    }

    private static function maiorVariavel(string $corpo): int
    {
        preg_match_all('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', $corpo, $nomes);
        if ($nomes[1]) {
            return count(array_unique($nomes[1]));
        }
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $corpo, $achados);
        return $achados[1] ? max(array_map('intval', $achados[1])) : 0;
    }
}
