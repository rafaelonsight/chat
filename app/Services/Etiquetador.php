<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

/**
 * Aplica e remove etiquetas de contato.
 *
 * Existe como servico, e nao solto no controlador, porque a etiqueta vai ser
 * aplicada por tres caminhos diferentes — mao do atendente, chatbot e agente de
 * IA — e todos precisam registrar a MESMA coisa: quem colocou e por qual origem.
 * Sem isso, quando uma etiqueta aparecer errada, ninguem sabe de onde veio.
 */
class Etiquetador
{
    public const MANUAL = 'manual';

    public const CHATBOT = 'chatbot';

    public const AGENTE = 'agente';

    public const IMPORTACAO = 'importacao';

    public const CAMPANHA = 'campanha';

    /** Como cada origem se le numa frase. */
    public const ROTULOS = [
        self::MANUAL     => 'à mão',
        self::CHATBOT    => 'pelo chatbot',
        self::AGENTE     => 'por um funcionário digital',
        self::IMPORTACAO => 'na importação',
        self::CAMPANHA   => 'por uma campanha',
    ];

    /**
     * Frase pronta de como a etiqueta chegou no contato.
     *
     * A origem e gravada desde o inicio e nunca sobrescrita, justamente para dar esta
     * resposta — "quem pos isso aqui?". Sem mostrar em algum lugar, o cuidado de
     * gravar nao serve para nada.
     */
    public static function comoFoi(?string $origem, ?string $quem, mixed $quando): string
    {
        // Origem desconhecida e dito, nao escondido: linha antiga sem origem existe, e
        // inventar "a mao" seria afirmar algo que ninguem verificou.
        $partes = ['Aplicada '.(self::ROTULOS[$origem] ?? 'sem origem registrada')];

        if ($quem) {
            $partes[] = 'por '.$quem;
        }

        if ($quando) {
            $partes[] = 'em '.\Illuminate\Support\Carbon::parse($quando)->format('d/m/Y H:i');
        }

        return implode(' ', $partes);
    }

    /**
     * @param  array<int, int>  $tagIds
     */
    public function aplicar(Contact $contato, array $tagIds, string $origem = self::MANUAL, ?int $usuarioId = null): int
    {
        $validos = Tag::whereKey($tagIds)->pluck('id')->all();

        if ($validos === []) {
            return 0;
        }

        $agora = now();
        $aplicadas = 0;

        DB::transaction(function () use ($contato, $validos, $origem, $usuarioId, $agora, &$aplicadas) {
            foreach ($validos as $id) {
                // Ja tem: nao reescreve. A primeira aplicacao e a que conta, senao
                // o rastro de origem seria sobrescrito por quem passou depois.
                if ($contato->tags()->whereKey($id)->exists()) {
                    continue;
                }

                $contato->tags()->attach($id, [
                    'origem'       => $origem,
                    'aplicado_por' => $usuarioId,
                    'created_at'   => $agora,
                ]);

                $aplicadas++;
            }
        });

        return $aplicadas;
    }

    /**
     * @param  array<int, int>  $tagIds
     */
    public function remover(Contact $contato, array $tagIds): int
    {
        return $contato->tags()->detach(Tag::whereKey($tagIds)->pluck('id')->all());
    }

    /**
     * Deixa o contato com exatamente estas etiquetas. E o que a tela de edicao
     * precisa; o chatbot usa aplicar/remover, que sao incrementais.
     *
     * @param  array<int, int>  $tagIds
     */
    public function sincronizar(Contact $contato, array $tagIds, string $origem = self::MANUAL, ?int $usuarioId = null): void
    {
        $validos = Tag::whereKey($tagIds)->pluck('id')->all();

        $contato->tags()->detach(array_diff($contato->tags()->pluck('tags.id')->all(), $validos));
        $this->aplicar($contato, $validos, $origem, $usuarioId);
    }
}
