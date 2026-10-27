<?php

namespace App\Services;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Aplica e remove etiquetas de contato, atendimento e tarefas.
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
    public function aplicar(Model $alvo, array $tagIds, string $origem = self::MANUAL, ?int $usuarioId = null): int
    {
        // So etiqueta do contexto certo. O filtro esta AQUI, e nao so no menu da tela, porque
        // o id chega de fora — do navegador, do chatbot, da importacao — e etiqueta no lugar
        // errado e exatamente o que estraga o relatorio historico.
        $validos = Tag::where('tenant_id',$alvo->tenant_id)->whereKey($tagIds)->where('contexto', self::contextoDe($alvo))->pluck('id')->all();

        if ($validos === []) {
            return 0;
        }

        $agora = now();
        $aplicadas = 0;

        DB::transaction(function () use ($alvo, $validos, $origem, $usuarioId, $agora, &$aplicadas) {
            DB::table($alvo->getTable())->where('id',$alvo->id)->where('tenant_id',$alvo->tenant_id)->lockForUpdate()->first();
            foreach ($validos as $id) {
                // Ja tem: nao reescreve. A primeira aplicacao e a que conta, senao
                // o rastro de origem seria sobrescrito por quem passou depois.
                if ($alvo->tags()->whereKey($id)->exists()) {
                    continue;
                }

                $alvo->tags()->attach($id, [
                    'origem'       => $origem,
                    'aplicado_por' => $usuarioId,
                    'created_at'   => $agora,
                ]);

                $this->registrar($alvo,$id,'aplicar',$origem,$usuarioId??auth()->id());
                $aplicadas++;
            }
        });

        return $aplicadas;
    }

    /**
     * @param  array<int, int>  $tagIds
     */
    public function remover(Model $alvo,array $tagIds,string $origem=self::MANUAL,?int $usuarioId=null): int {
        $validos=Tag::where('tenant_id',$alvo->tenant_id)->where('contexto',self::contextoDe($alvo))->whereKey($tagIds)->pluck('id')->all();
        return DB::transaction(function()use($alvo,$validos,$origem,$usuarioId){
            $presentes=$alvo->tags()->whereIn('tags.id',$validos)->pluck('tags.id')->all();
            foreach($presentes as $id)$this->registrar($alvo,$id,'remover',$origem,$usuarioId??auth()->id());
            return $alvo->tags()->detach($presentes);
        });
    }
    private function registrar(Model $alvo,int $id,string $acao,string $origem,?int $usuarioId): void {
        $tag=Tag::where('tenant_id',$alvo->tenant_id)->findOrFail($id);
        DB::table('etiqueta_movimentos')->insert(['tenant_id'=>$alvo->tenant_id,'tag_id'=>$id,'etiqueta_nome'=>$tag->nome,'contexto'=>$tag->contexto,'alvo_tipo'=>$alvo->getMorphClass(),'alvo_id'=>$alvo->id,'acao'=>$acao,'origem'=>$origem,'usuario_id'=>$usuarioId,'created_at'=>now()]);
    }

    /**
     * Deixa o contato com exatamente estas etiquetas. E o que a tela de edicao
     * precisa; o chatbot usa aplicar/remover, que sao incrementais.
     *
     * @param  array<int, int>  $tagIds
     */
    public function sincronizar(Model $alvo, array $tagIds, string $origem = self::MANUAL, ?int $usuarioId = null): void
    {
        $validos = Tag::where('tenant_id',$alvo->tenant_id)->whereKey($tagIds)->where('contexto', self::contextoDe($alvo))->pluck('id')->all();

        $this->remover($alvo,array_diff($alvo->tags()->pluck('tags.id')->all(),$validos),$origem,$usuarioId);
        $this->aplicar($alvo, $validos, $origem, $usuarioId);
    }

    /**
     * Que contexto de etiqueta este alvo aceita.
     *
     * Derivado do tipo do alvo, e nao passado por parametro: quem chama nao pode escolher
     * errado se nao tem escolha.
     */
    private static function contextoDe(Model $alvo): string
    {
        return match(true){$alvo instanceof \App\Models\Conversation=>Tag::CONVERSA,$alvo instanceof \App\Models\BoardItem=>Tag::ITEM,default=>Tag::CONTATO};
    }
}
