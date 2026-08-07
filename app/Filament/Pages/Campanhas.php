<?php

namespace App\Filament\Pages;

use App\Jobs\DispararCampanha;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Channel;
use App\Models\MetaTemplate;
use App\Models\Tag;
use App\Services\Disparador;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Campanhas: a mesma mensagem para muita gente.
 *
 * A TELA E TAO SOBRE FREIO QUANTO O CODIGO. Ela mostra quantos ficaram DE FORA antes de
 * disparar, avisa em voz alta o risco do canal por QR, e nao deixa comecar sem publico. Um
 * botao "disparar" sem esses avisos seria uma armadilha bonita.
 */
class Campanhas extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Aplicações';

    protected static ?string $navigationLabel = 'Campanhas';

    protected static ?string $title = 'Campanhas';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'campanhas';

    protected string $view = 'filament.pages.campanhas';

    public ?int $editando = null;

    public bool $formAberto = false;

    // ---- campos do formulario
    public string $nome = '';

    public ?int $channel_id = null;

    public string $publico = 'etiqueta';

    public ?int $tag_id = null;

    public string $corpo = '';

    public ?int $meta_template_id = null;

    public array $template_valores = [];

    public int $por_minuto = 6;

    public int $hora_inicio = 9;

    public int $hora_fim = 20;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->admin;
    }

    public function mount(): void
    {
        $this->channel_id = Channel::query()->value('id');
    }

    // ------------------------------------------------------------------ form

    public function novaCampanha(): void
    {
        $this->reset(['editando', 'nome', 'publico', 'tag_id', 'corpo', 'meta_template_id', 'template_valores']);
        $this->por_minuto = 6;
        $this->hora_inicio = 9;
        $this->hora_fim = 20;
        $this->channel_id = Channel::query()->value('id');
        $this->formAberto = true;
    }

    public function editar(int $id): void
    {
        $c = Campaign::findOrFail($id);

        if (! $c->editavel()) {
            $this->addError('campanha', 'Campanha que já começou a disparar não se edita — crie outra.');

            return;
        }

        $this->editando = $c->id;
        $this->nome = $c->nome;
        $this->channel_id = $c->channel_id;
        $this->publico = $c->publico;
        $this->tag_id = $c->tag_id;
        $this->corpo = (string) $c->corpo;
        $this->meta_template_id = $c->meta_template_id;
        $this->template_valores = (array) $c->template_valores;
        $this->por_minuto = $c->por_minuto;
        $this->hora_inicio = $c->hora_inicio;
        $this->hora_fim = $c->hora_fim;
        $this->formAberto = true;
    }

    public function salvar(): void
    {
        $canal = Channel::find($this->channel_id);
        $exigeTemplate = (bool) $canal?->exigeJanela();

        $this->validate([
            'nome'             => 'required|string|max:80',
            'channel_id'       => 'required|integer',
            'publico'          => 'required|in:etiqueta,todos',
            'tag_id'           => 'required_if:publico,etiqueta|nullable|integer',
            'corpo'            => $exigeTemplate ? 'nullable' : 'required|string|max:1000',
            'meta_template_id' => $exigeTemplate ? 'required|integer' : 'nullable',
            'por_minuto'       => 'required|integer|min:1|max:30',
            'hora_inicio'      => 'required|integer|min:0|max:23',
            'hora_fim'         => 'required|integer|min:1|max:23|gt:hora_inicio',
        ], [], [
            'tag_id'           => 'etiqueta',
            'meta_template_id' => 'template',
            'hora_fim'         => 'hora final',
        ]);

        $dados = [
            'nome'             => trim($this->nome),
            'channel_id'       => $this->channel_id,
            'publico'          => $this->publico,
            'tag_id'           => $this->publico === 'etiqueta' ? $this->tag_id : null,
            'corpo'            => $exigeTemplate ? null : trim($this->corpo),
            'meta_template_id' => $exigeTemplate ? $this->meta_template_id : null,
            'template_valores' => $exigeTemplate ? array_values($this->template_valores) : null,
            'por_minuto'       => $this->por_minuto,
            'hora_inicio'      => $this->hora_inicio,
            'hora_fim'         => $this->hora_fim,
        ];

        if ($this->editando) {
            Campaign::findOrFail($this->editando)->update($dados);
        } else {
            Campaign::create($dados + [
                'tenant_id'  => auth()->user()->tenant_id,
                'criada_por' => auth()->id(),
                'status'     => Campaign::RASCUNHO,
            ]);
        }

        $this->formAberto = false;
        $this->editando = null;

        Notification::make()->title('Campanha salva')->success()->send();
    }

    // --------------------------------------------------------------- disparo

    public function iniciar(int $id): void
    {
        $campanha = Campaign::findOrFail($id);

        if ($campanha->rodando()) {
            return;
        }

        $disparador = app(Disparador::class);
        $entraram = $disparador->montarFila($campanha);

        // Sem publico nao ha campanha. Deixar comecar e ver "concluida, 0 enviadas" faria
        // qualquer um achar que o sistema quebrou.
        if ($entraram === 0 && $campanha->recipients()->count() === 0) {
            $this->addError('campanha', 'Ninguém entra nesta campanha. Confira a etiqueta e quem pediu para sair.');

            return;
        }

        $campanha->forceFill([
            'status'      => Campaign::ENVIANDO,
            'iniciada_em' => $campanha->iniciada_em ?? now(),
        ])->save();

        DispararCampanha::dispatch($campanha->id);

        Notification::make()->title('Campanha iniciada')->success()->send();
    }

    public function pausar(int $id): void
    {
        Campaign::findOrFail($id)->forceFill(['status' => Campaign::PAUSADA])->save();
    }

    public function retomar(int $id): void
    {
        $campanha = Campaign::findOrFail($id);
        $campanha->forceFill(['status' => Campaign::ENVIANDO])->save();

        DispararCampanha::dispatch($campanha->id);
    }

    public function cancelar(int $id): void
    {
        // Cancelar NAO apaga o que ja foi: quem recebeu, recebeu. Apagar as linhas deixaria
        // sem resposta a pergunta "mandamos para essa pessoa?", que e a primeira que alguem
        // faz quando um cliente reclama.
        Campaign::findOrFail($id)->forceFill([
            'status'       => Campaign::CANCELADA,
            'concluida_em' => now(),
        ])->save();
    }

    public function excluir(int $id): void
    {
        $campanha = Campaign::findOrFail($id);

        if ($campanha->recipients()->where('status', CampaignRecipient::ENVIADA)->exists()) {
            $this->addError('campanha', 'Esta campanha já enviou mensagens; o registro fica para consulta.');

            return;
        }

        $campanha->delete();
    }

    // ----------------------------------------------------------------- dados

    public function getViewData(): array
    {
        $disparador = app(Disparador::class);

        $campanhas = Campaign::with(['channel', 'tag'])
            ->withCount([
                'recipients',
                'recipients as enviadas_count' => fn ($q) => $q->where('status', CampaignRecipient::ENVIADA),
                'recipients as puladas_count'  => fn ($q) => $q->where('status', CampaignRecipient::PULADA),
                'recipients as falharam_count' => fn ($q) => $q->where('status', CampaignRecipient::FALHOU),
            ])
            ->latest('id')
            ->get();

        // Previa do publico enquanto se escolhe. Mostra os dois numeros: o total e quantos
        // ficam de fora — a diferenca e o que faz alguem perguntar por que.
        $previa = null;

        if ($this->formAberto) {
            $rascunho = new Campaign([
                'publico' => $this->publico,
                'tag_id'  => $this->publico === 'etiqueta' ? $this->tag_id : null,
            ]);
            $rascunho->tenant_id = auth()->user()->tenant_id;

            $previa = $disparador->contagem($rascunho);
        }

        $canal = Channel::find($this->channel_id);

        return [
            'campanhas'     => $campanhas,
            'canais'        => Channel::orderBy('nome')->get(),
            'etiquetas'     => Tag::deContato()->orderBy('nome')->get(),
            'templates'     => MetaTemplate::enviaveis()->orderBy('nome')->get(),
            'previa'        => $previa,
            'exigeTemplate' => (bool) $canal?->exigeJanela(),
            'canalPorQr'    => $canal && ! $canal->exigeJanela(),
        ];
    }
}
