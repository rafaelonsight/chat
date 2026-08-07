<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ContactFieldValue;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Storage;

/**
 * Os dois direitos da LGPD que dao trabalho de verdade: ACESSO e ELIMINACAO.
 *
 * O cliente escreve "me manda tudo que voces tem sobre mim" ou "apaga meus dados". Ate hoje a
 * resposta seria abrir o banco na mao — o que na pratica quer dizer nao responder.
 *
 * SOBRE APAGAR, UMA ESCOLHA QUE PRECISA ESTAR ESCRITA:
 *
 * Nao apago as linhas. Anonimizo.
 *
 * Apagar de verdade destruiria o registro do NEGOCIO junto com o dado pessoal: quantos
 * atendimentos existiram, quando, quanto tempo levaram, quanto a equipe respondeu. Isso nao e
 * dado do titular — e a operacao da empresa, e ela tem obrigacao de guardar registro fiscal e
 * contabil por anos. Alem disso, sumir com conversas inteiras faria os relatorios do mes
 * passado mudarem sozinhos, o que e a definicao de numero em que nao se confia.
 *
 * Entao some tudo que IDENTIFICA e tudo que o titular ESCREVEU OU RECEBEU: nome, telefone,
 * e-mail, endereco, campos personalizados, texto de toda mensagem, transcricao de audio e os
 * arquivos no disco. Fica a carcaca: houve uma conversa, tal dia, com tantas mensagens.
 *
 * Isso e o que a LGPD chama de anonimizacao, e o Art. 16 permite guardar para cumprimento de
 * obrigacao legal. A tela DIZ isso ao operador antes de ele confirmar, com essas palavras.
 */
class DadosDoContato
{
    /** Tudo que existe sobre esta pessoa, num arquivo. */
    public function exportar(Contact $contato): array
    {
        $conversas = Conversation::withoutGlobalScope('tenant')
            ->where('contact_id', $contato->id)
            ->with(['messages', 'events.user', 'channel'])
            ->orderBy('created_at')
            ->get();

        return [
            '_leia_me' => 'Este arquivo contém todos os dados pessoais que temos sobre este '
                .'contato, gerado em '.now()->format('d/m/Y H:i').'. Direito de acesso, '
                .'Art. 18, II da LGPD.',

            'cadastro' => [
                'nome'      => $contato->nome,
                'telefone'  => $contato->telefone_e164,
                'email'     => $contato->email,
                'instagram' => $contato->instagram,
                'endereco'  => array_filter([
                    'cep'         => $contato->cep,
                    'logradouro'  => $contato->logradouro,
                    'numero'      => $contato->numero,
                    'complemento' => $contato->complemento,
                    'bairro'      => $contato->bairro,
                    'cidade'      => $contato->cidade,
                    'uf'          => $contato->uf,
                ]),
                'cadastrado_em' => $contato->created_at?->format('d/m/Y H:i'),
            ],

            'campos_personalizados' => $contato->fieldValues()
                ->with('field')
                ->get()
                ->mapWithKeys(fn ($v) => [$v->field?->nome ?? 'campo' => $v->valor])
                ->all(),

            'etiquetas' => $contato->tags->pluck('nome')->all(),

            'conversas' => $conversas->map(fn (Conversation $c) => [
                'aberta_em'  => $c->created_at?->format('d/m/Y H:i'),
                'canal'      => $c->channel?->nome,
                'situacao'   => $c->status,
                'satisfacao' => $c->satisfacao,
                'mensagens'  => $c->messages->map(fn (Message $m) => array_filter([
                    'quando'      => $m->created_at?->format('d/m/Y H:i:s'),
                    'de'          => $m->entrada() ? 'cliente' : 'empresa',
                    'tipo'        => $m->tipo,
                    'texto'       => $m->corpo,
                    'legenda'     => $m->legenda,
                    'arquivo'     => $m->media_nome,
                    'transcricao' => $m->transcricao,
                    'apagada'     => $m->apagada() ? 'sim' : null,
                ], fn ($v) => $v !== null && $v !== ''))->all(),
                // Nota interna NAO entra: e comunicacao da equipe sobre o atendimento, nao
                // dado fornecido pelo titular. Entra o que ele escreveu e o que recebeu.
            ])->all(),
        ];
    }

    public function nomeDoArquivo(Contact $contato): string
    {
        $base = preg_replace('/[^a-z0-9]+/i', '-', (string) ($contato->nome ?: 'contato'));

        return 'dados-'.mb_strtolower(trim((string) $base, '-')).'-'.now()->format('Y-m-d').'.json';
    }

    /**
     * Tira o que identifica e o que o titular escreveu. Mantem a carcaca do atendimento.
     *
     * @return array{mensagens: int, arquivos: int, conversas: int}
     */
    public function anonimizar(Contact $contato): array
    {
        $conversas = Conversation::withoutGlobalScope('tenant')
            ->where('contact_id', $contato->id)->pluck('id');

        $mensagens = Message::withoutGlobalScope('tenant')->whereIn('conversation_id', $conversas)->get();

        $arquivos = 0;

        foreach ($mensagens as $m) {
            if ($m->media_path && Storage::disk('local')->exists($m->media_path)) {
                Storage::disk('local')->delete($m->media_path);
                $arquivos++;
            }
        }

        Message::withoutGlobalScope('tenant')->whereIn('conversation_id', $conversas)->update([
            'corpo'          => null,
            'legenda'        => null,
            'transcricao'    => null,
            'media_path'     => null,
            'media_nome'     => null,
            'remetente_nome' => null,
            'remetente_jid'  => null,
        ]);

        ContactFieldValue::where('contact_id', $contato->id)->delete();
        $contato->tags()->detach();

        // Telefone e jid precisam continuar UNICOS e o jid nao aceita nulo: as colunas tem
        // indice, e varios contatos anonimizados com o mesmo valor derrubariam o segundo
        // pedido de exclusao — que falharia justamente numa obrigacao legal. O id serve de
        // sufixo porque ele ja e unico e nao diz nada sobre a pessoa.
        $contato->forceFill([
            'nome'            => 'Contato removido',
            'telefone_e164'   => 'removido-'.$contato->id,
            'jid'             => 'removido-'.$contato->id,
            'email'           => null,
            'instagram'       => null,
            'cep'             => null,
            'logradouro'      => null,
            'numero'          => null,
            'complemento'     => null,
            'bairro'          => null,
            'cidade'          => null,
            'uf'              => null,
            'bloqueado_em'    => now(),
            'bloqueio_motivo' => 'Dados removidos a pedido do titular (LGPD)',
            'anonimizado_em'  => now(),
        ])->save();

        return [
            'mensagens' => $mensagens->count(),
            'arquivos'  => $arquivos,
            'conversas' => $conversas->count(),
        ];
    }
}
