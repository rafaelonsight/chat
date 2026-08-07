<?php

namespace App\Filament\Pages;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tag;
use App\Models\Tenant;
use App\Services\BusinessHours;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;
use Illuminate\Support\Carbon;

class Relatorios extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    // Grupo proprio, e nao item solto, por dois motivos: no Filament itens sem
    // grupo formam um bloco que sempre vem ANTES dos grupos — Relatorios acabaria
    // entre Atendimento e CRM. E porque o nome e plural: os proximos relatorios
    // entram aqui.
    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?string $navigationLabel = 'Visão geral';

    protected static ?string $title = 'Relatórios';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'relatorios';

    protected string $view = 'filament.pages.relatorios';

    public int $dias = 30;

    /**
     * Etiqueta do recorte. Texto e nao int porque vem de um <select>, que manda ''
     * para "todas" — e '' num int tipado explode antes de chegar aqui.
     */
    public ?string $etiqueta = null;

    // Dado de gestao: atendente nao ve.
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->admin;
    }

    public function periodo(int $dias): void
    {
        $this->dias = in_array($dias, [7, 30, 90], true) ? $dias : 30;
    }

    public function updatedEtiqueta(): void
    {
        $id = (int) $this->etiqueta;

        // Etiqueta apagada noutra aba volta para "todas": relatorio inteiro zerado
        // sem explicacao parece defeito do sistema, nao filtro sem resultado.
        $this->etiqueta = $id > 0 && Tag::whereKey($id)->exists() ? (string) $id : null;
    }

    private function etiquetaId(): ?int
    {
        $id = (int) $this->etiqueta;

        return $id > 0 ? $id : null;
    }

    /**
     * Base de conversas do relatorio, ja com o recorte da etiqueta.
     *
     * Todo numero da pagina sai daqui: se o filtro valesse so para alguns cartoes, o
     * gestor compararia "conversas do Financeiro" com "mensagens de todos" sem
     * perceber, e a conta nao fecharia.
     */
    private function conversas(): \Illuminate\Database\Eloquent\Builder
    {
        $q = Conversation::query();

        if ($id = $this->etiquetaId()) {
            $q->whereHas('tags', fn ($t) => $t->whereKey($id));
        }

        return $q;
    }

    private function mensagens(): \Illuminate\Database\Eloquent\Builder
    {
        $q = Message::query();

        if ($id = $this->etiquetaId()) {
            $q->whereHas('conversation.tags', fn ($t) => $t->whereKey($id));
        }

        return $q;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportar')
                ->label('Exportar CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => $this->exportar()),
        ];
    }

    /**
     * Planilha das conversas do recorte que esta na tela.
     *
     * COM O MESMO RECORTE, e nao a base inteira: exportar tudo quando a tela mostra o
     * Financeiro dos ultimos 7 dias faria o gestor comparar planilha com tela e concluir que
     * um dos dois esta errado.
     */
    public function exportar(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $desde = now()->subDays($this->dias);

        // O recorte vai no NOME do arquivo. Planilha baixada como "relatorio.csv" vira um
        // arquivo na pasta de downloads que ninguem sabe de que periodo era.
        $nome = sprintf(
            'conversas-%dd%s-%s.csv',
            $this->dias,
            $this->etiquetaId() ? '-etiqueta'.$this->etiquetaId() : '',
            now()->format('Y-m-d'),
        );

        $consulta = $this->conversas()
            ->where('conversations.created_at', '>=', $desde)
            ->withCount('messages')
            ->with(['contact', 'channel', 'atendente', 'team'])
            ->orderBy('conversations.created_at');

        return response()->streamDownload(function () use ($consulta) {
            $saida = fopen('php://output', 'w');

            // BOM: sem ele o Excel em portugues abre acento como caractere estranho, e a
            // primeira conclusao de quem recebe e "o sistema exportou errado".
            fwrite($saida, "\xEF\xBB\xBF");

            // Ponto e virgula: Excel em portugues nao separa por virgula, e a planilha
            // chegaria com tudo numa coluna so.
            $ponto = fn (array $linha) => fputcsv($saida, $linha, ';');

            $ponto([
                'ID', 'Aberta em', 'Contato', 'Telefone', 'Canal', 'Situação',
                'Atendente', 'Equipe', 'Mensagens', 'Não lidas', 'Veio de', 'ID do anúncio',
            ]);

            // chunk: relatorio de 90 dias nao pode carregar tudo na memoria de uma vez.
            $consulta->chunk(500, function ($conversas) use ($ponto) {
                foreach ($conversas as $c) {
                    $ponto([
                        $c->id,
                        $c->created_at?->format('d/m/Y H:i'),
                        $c->contact?->nomeExibicao(),
                        $c->contact?->telefoneDiscavel(),
                        $c->channel?->nome,
                        $c->rotuloStatus(),
                        $c->atendente?->name,
                        $c->team?->nome,
                        $c->messages_count,
                        $c->nao_lidas,
                        $c->origemResumo(),
                        $c->origem_id,
                    ]);
                }
            });

            fclose($saida);
        }, $nome, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function getViewData(): array
    {
        $desde = now()->subDays($this->dias);

        $doPeriodo = fn () => $this->conversas()->where('conversations.created_at', '>=', $desde);
        $msgsPeriodo = fn () => $this->mensagens()->where('messages.created_at', '>=', $desde);

        $resumo = [
            'dias'       => $this->dias,
            'conversas'  => $doPeriodo()->count(),
            'encerradas' => $doPeriodo()->where('status', Conversation::ARQUIVADA)->count(),
            'abertas'    => $this->conversas()->where('status', '!=', Conversation::ARQUIVADA)->count(),
            'na_fila'    => $this->conversas()->where('status', Conversation::NOVA)->count(),
            'mensagens'  => $msgsPeriodo()->count(),
            'recebidas'  => $msgsPeriodo()->where('direcao', 'in')->count(),
            'enviadas'   => $msgsPeriodo()->where('direcao', 'out')->count(),
        ];

        // Satisfacao: media e QUANTAS responderam. A media sozinha engana — 5,0 com duas
        // respostas nao e a mesma coisa que 4,6 com duzentas, e quem le so a media toma
        // decisao com base em duas pessoas.
        $notas = $doPeriodo()->whereNotNull('satisfacao');
        $resumo['satisfacao_base'] = (clone $notas)->count();
        $resumo['satisfacao'] = $resumo['satisfacao_base'] > 0
            ? round((float) (clone $notas)->avg('satisfacao'), 1)
            : null;

        // Quantas pesquisas sairam, para dar o retorno: 3 respostas em 100 perguntas nao e
        // "nota 5", e sim uma pesquisa que ninguem responde.
        $resumo['pesquisas_enviadas'] = $doPeriodo()->whereNotNull('pesquisa_enviada_em')->count();

        $porCanal = $this->conversas()
            ->where('conversations.created_at', '>=', $desde)
            ->join('channels', 'channels.id', '=', 'conversations.channel_id')
            ->selectRaw('channels.nome as canal')
            ->selectRaw('count(*) as conversas')
            ->selectRaw("count(*) filter (where conversations.status = 'arquivada') as encerradas")
            ->groupBy('channels.nome')
            ->orderByDesc('conversas')
            ->get();

        $porAtendente = $this->conversas()
            ->where('conversations.created_at', '>=', $desde)
            ->leftJoin('users', 'users.id', '=', 'conversations.atendente_id')
            ->selectRaw("coalesce(users.name, 'sem atendente') as atendente")
            ->selectRaw('count(*) as conversas')
            ->selectRaw("count(*) filter (where conversations.status = 'arquivada') as encerradas")
            ->groupBy('users.name')
            ->orderByDesc('conversas')
            ->get();

        $porEquipe = $this->conversas()
            ->where('conversations.created_at', '>=', $desde)
            ->leftJoin('teams', 'teams.id', '=', 'conversations.team_id')
            ->selectRaw("coalesce(teams.nome, 'sem equipe') as equipe")
            ->selectRaw('count(*) as conversas')
            ->selectRaw("count(*) filter (where conversations.status = 'arquivada') as encerradas")
            ->groupBy('teams.nome')
            ->orderByDesc('conversas')
            ->get();

        // ETIQUETA DA CONVERSA, e nao mais a do contato. Antes esta linha contava as
        // conversas de quem tem a etiqueta HOJE: se o cliente virou "Fechado" em agosto, o
        // numero de julho encolhia sozinho. Numero que muda depois de o mes fechar e numero em
        // que ninguem confia — era o motivo inteiro de separar as duas coisas.
        //
        // Conversa com duas etiquetas conta nas duas, entao a soma da coluna passa do total —
        // esta escrito na tela, porque numero que nao fecha e lido como erro.
        $porEtiqueta = $this->conversas()
            ->where('conversations.created_at', '>=', $desde)
            ->join('conversation_tag', 'conversation_tag.conversation_id', '=', 'conversations.id')
            ->join('tags', 'tags.id', '=', 'conversation_tag.tag_id')
            // O escopo global cobre conversations; o join em tags passa por fora dele,
            // e vazamento entre contas num relatorio e invisivel para quem le.
            ->where('tags.tenant_id', auth()->user()?->tenant_id)
            ->selectRaw('tags.nome as etiqueta')
            ->selectRaw('tags.cor as cor')
            ->selectRaw('count(*) as conversas')
            ->selectRaw("count(*) filter (where conversations.status = 'arquivada') as encerradas")
            ->groupBy('tags.nome', 'tags.cor')
            ->orderByDesc('conversas')
            ->get();

        // Sem esta linha nao da para saber a COBERTURA: cem conversas etiquetadas
        // parecem otimo ate se descobrir que houve mil.
        $semEtiqueta = $this->conversas()
            ->where('conversations.created_at', '>=', $desde)
            ->whereDoesntHave('tags')
            ->count();

        // So as de conversa: e por elas que este relatorio agrupa, e oferecer uma etiqueta
        // de contato no menu daria zero resultado sem explicar por que.
        $etiquetas = Tag::deConversa()->orderBy('nome')->get();
        $etiquetaEscolhida = $this->etiquetaId()
            ? $etiquetas->firstWhere('id', $this->etiquetaId())
            : null;

        $primeiraResposta = $this->tempoAteAPrimeiraResposta($desde);

        $conta = Tenant::find(auth()->user()?->tenant_id);
        $emHorarioUtil = BusinessHours::paraConta($conta)?->configurado() ?? false;

        return compact(
            'resumo', 'porCanal', 'porAtendente', 'porEquipe', 'primeiraResposta', 'emHorarioUtil',
            'porEtiqueta', 'semEtiqueta', 'etiquetas', 'etiquetaEscolhida',
        );
    }

    // Tempo entre a primeira mensagem do cliente e a primeira resposta nossa.
    // Conversa sem resposta fica FORA da media de proposito: entraria como zero
    // e mascararia justamente o problema que a metrica existe para revelar —
    // ela aparece separada, como "sem resposta".
    private function tempoAteAPrimeiraResposta(Carbon $desde): array
    {
        // O escopo global de tenant ja filtra messages.tenant_id: nao precisa de
        // join nem de SQL montado com concatenacao.
        $linhas = $this->mensagens()
            ->where('messages.created_at', '>=', $desde)
            ->groupBy('conversation_id')
            ->selectRaw('conversation_id')
            ->selectRaw("min(case when direcao = 'in' then created_at end) as entrada")
            ->selectRaw("min(case when direcao = 'out' then created_at end) as saida")
            ->get();

        $conta = Tenant::find(auth()->user()?->tenant_id);
        $horas = BusinessHours::paraConta($conta);

        $segundos = [];
        $semResposta = 0;

        foreach ($linhas as $linha) {
            if (! $linha->entrada) {
                continue; // conversa que nos iniciamos: nao ha espera do cliente
            }

            if (! $linha->saida) {
                $semResposta++;

                continue;
            }

            $entrada = Carbon::parse($linha->entrada);
            $saida = Carbon::parse($linha->saida);

            if ($saida->lessThan($entrada)) {
                continue;
            }

            // So o tempo dentro do horario de atendimento. Sem isto, mensagem das
            // 23h respondida as 8h35 aparece como 9h35 de espera e pune a equipe
            // pela noite. Sem grade configurada, o servico devolve o relogio de
            // parede — a metrica nao muda ate a conta configurar o horario.
            $segundos[] = $horas
                ? $horas->minutosUteisEntre($entrada, $saida) * 60
                : $entrada->diffInSeconds($saida);
        }

        return [
            'media'        => $segundos === [] ? null : (int) round(array_sum($segundos) / count($segundos)),
            'base'         => count($segundos),
            'sem_resposta' => $semResposta,
        ];
    }

    public static function formatarDuracao(?int $segundos): string
    {
        if ($segundos === null) {
            return '—';
        }

        if ($segundos < 60) {
            return $segundos.'s';
        }

        if ($segundos < 3600) {
            return intdiv($segundos, 60).'min';
        }

        return round($segundos / 3600, 1).'h';
    }
}
