<?php

namespace App\Services;

use App\Models\BusinessHour;
use App\Models\Channel;
use App\Models\MessageTemplate;
use App\Models\Tag;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;

/**
 * O que falta configurar nesta conta.
 *
 * Existe porque a conta de um cliente novo nasce vazia e ele nao tem como saber o que o vazio
 * significa. Ele abre a caixa de entrada, nao ha nada, e nao da para distinguir "ninguem me
 * mandou mensagem hoje" de "o WhatsApp nunca foi conectado". As duas telas sao identicas.
 *
 * DUAS FAIXAS, E A DIFERENCA ENTRE ELAS E O PONTO.
 *
 * ESSENCIAL e o que impede o sistema de funcionar. So isso conta para o alerta no menu.
 *
 * RECOMENDADO e o que melhora o dia a dia e pode ser legitimamente dispensado — um consultorio
 * de uma pessoa so nao precisa de equipes, e cobrar isso dele para sempre transformaria o
 * alerta em ruido. Alarme que nunca apaga e alarme que se aprende a ignorar; ja tive esse
 * problema aqui com o diagnostico e nao repito.
 */
class PrimeirosPassos
{
    public const ESSENCIAL = 'essencial';

    public const RECOMENDADO = 'recomendado';

    /**
     * @return list<array{chave:string, titulo:string, porque:string, url:string,
     *                    feito:bool, peso:string, acao:string}>
     */
    public function passos(): array
    {
        $conta = $this->conta();

        return [
            [
                'chave'  => 'canal',
                'peso'   => self::ESSENCIAL,
                'titulo' => 'Conectar o WhatsApp',
                'porque' => 'Enquanto não houver um canal conectado, nenhuma mensagem entra nem sai. '
                    .'A caixa de entrada fica vazia sem dizer por quê.',
                'acao'   => 'Conectar',
                'url'    => route('filament.admin.resources.channels.index'),
                'feito'  => Channel::where('status', 'open')->exists(),
            ],
            [
                'chave'  => 'usuarios',
                'peso'   => self::RECOMENDADO,
                'titulo' => 'Convidar quem vai atender',
                'porque' => 'Enquanto só você tem acesso, toda conversa espera por você.',
                'acao'   => 'Convidar',
                'url'    => route('filament.admin.resources.users.index'),
                'feito'  => User::count() > 1,
            ],
            [
                'chave'  => 'equipes',
                'peso'   => self::RECOMENDADO,
                'titulo' => 'Separar em equipes',
                'porque' => 'Equipe é o que faz a conversa cair no grupo certo — vendas, suporte, '
                    .'financeiro — em vez de todo mundo ver tudo. A Triagem já existe: é onde a '
                    .'conversa nova espera até ser direcionada.',
                'acao'   => 'Criar equipe',
                'url'    => route('filament.admin.resources.teams.index'),
                /*
                 * A TRIAGEM NAO CONTA. Ela vem de fabrica em toda licenca, entao contar como
                 * "equipe criada" faria este passo nascer concluido — e passo que comeca pronto
                 * nao ensina nada, so mente sobre o quanto a conta esta configurada.
                 */
                'feito'  => Team::where('padrao', false)->exists(),
            ],
            [
                'chave'  => 'horario',
                'peso'   => self::RECOMENDADO,
                'titulo' => 'Dizer o horário de atendimento',
                'porque' => 'Sem horário definido, o sistema trata madrugada e domingo como expediente '
                    .'normal — e ninguém avisa o cliente que só respondem amanhã.',
                'acao'   => 'Definir horário',
                'url'    => route('filament.admin.pages.horario-atendimento'),
                'feito'  => BusinessHour::exists(),
            ],
            [
                'chave'  => 'etiquetas',
                'peso'   => self::RECOMENDADO,
                'titulo' => 'Criar as etiquetas',
                'porque' => 'É o que permite achar depois: orçamento pendente, aguardando pagamento, '
                    .'resolvido. Sem elas, o histórico vira uma pilha só.',
                'acao'   => 'Criar etiquetas',
                'url'    => route('filament.admin.resources.tags.index'),
                'feito'  => Tag::exists(),
            ],
            [
                'chave'  => 'modelos',
                'peso'   => self::RECOMENDADO,
                'titulo' => 'Escrever as respostas prontas',
                'porque' => 'As mesmas cinco perguntas chegam todo dia. Resposta pronta é a diferença '
                    .'entre digitar de novo e responder em um clique.',
                'acao'   => 'Escrever',
                'url'    => route('filament.admin.resources.message-templates.index'),
                'feito'  => MessageTemplate::exists(),
            ],
            [
                'chave'  => 'cadastro',
                'peso'   => self::RECOMENDADO,
                'titulo' => 'Completar o cadastro da empresa',
                'porque' => 'Basta o CNPJ: razão social e endereço vêm da Receita sozinhos.',
                'acao'   => 'Completar',
                'url'    => route('filament.admin.pages.cadastro'),
                'feito'  => filled($conta?->documento),
            ],
        ];
    }

    /** Quantos ESSENCIAIS ainda faltam. E o unico numero que vira alerta. */
    public function faltamEssenciais(): int
    {
        return count(array_filter(
            $this->passos(),
            fn (array $p) => $p['peso'] === self::ESSENCIAL && ! $p['feito'],
        ));
    }

    public function faltamRecomendados(): int
    {
        return count(array_filter(
            $this->passos(),
            fn (array $p) => $p['peso'] === self::RECOMENDADO && ! $p['feito'],
        ));
    }

    private function conta(): ?Tenant
    {
        $id = auth()->user()?->tenant_id;

        return $id ? Tenant::find($id) : null;
    }
}
