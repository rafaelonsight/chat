<?php

namespace App\Filament\Pages;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Meeting;
use App\Services\Video\Chamada;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * As reunioes por video.
 *
 * A CHAMADA NASCEU DENTRO DA CONVERSA, e continua sendo de la que ela mais vai sair — o caso
 * comum e o atendimento que empacou no texto. Esta tela existe para os casos em que NAO ha
 * conversa aberta: reuniao da equipe, e o cliente que ligou no telefone e precisa de um link
 * agora.
 *
 * E TAMBEM E O HISTORICO. Sem uma lista, a unica forma de voltar a uma sala aberta era achar a
 * mensagem com o link no meio da conversa — e quem abriu a sala pelo menu nao tinha conversa
 * nenhuma onde procurar.
 */
class Reunioes extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedVideoCamera;

    protected static string|UnitEnum|null $navigationGroup = 'CRM';

    protected static ?string $navigationLabel = 'Reuniões';

    protected static ?string $title = 'Reuniões';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'reunioes';

    protected string $view = 'filament.pages.reunioes';

    public string $titulo = '';

    public string $buscaContato = '';

    public ?int $contact_id = null;

    public ?string $recado = null;

    /**
     * O numero no menu: quantas salas estao ABERTAS agora.
     *
     * Sala aberta e sala que pode ter alguem esperando dentro, e esse e o unico numero aqui que
     * pede acao. Contar reuniao do mes seria enfeite que acende para sempre.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! app(Chamada::class)->disponivel()) {
            return null;
        }

        $n = Meeting::abertas()->where('comecou_em', '>', now()->subHours(Meeting::HORAS_ATE_EXPIRAR))->count();

        return $n > 0 ? (string) $n : null;
    }

    // ------------------------------------------------------------------ acoes

    /**
     * Abre uma sala.
     *
     * Com contato escolhido, ela nasce presa a conversa dele e o link sai pelo WhatsApp — e o
     * mesmo caminho do botao dentro do atendimento, pelas mesmas regras. Sem contato, e so um
     * link para mandar a mao.
     */
    public function abrir(Chamada $chamada): void
    {
        $this->recado = null;

        if (! $chamada->disponivel()) {
            $this->recado = 'A chamada de vídeo não está configurada neste servidor.';

            return;
        }

        $conversa = $this->conversaDoContato();

        $reuniao = $conversa
            ? $chamada->paraConversa($conversa, auth()->id())
            : $chamada->avulsa($this->titulo, auth()->id());

        if ($conversa) {
            $this->recado = match ($chamada->avisar($reuniao)) {
                Chamada::JANELA_FECHADA => 'A janela de 24 horas fechou neste canal: copie o link e mande por fora.',
                Chamada::FALHOU => 'Não consegui mandar o link pela conversa. Copie e mande por fora.',
                default => 'Link enviado na conversa de '.$reuniao->contact?->nomeExibicao().'.',
            };
        }

        $this->reset(['titulo', 'buscaContato', 'contact_id']);

        // Nova aba, e nao troca de tela: quem abriu a sala pode querer copiar o link para
        // outra pessoa antes de entrar.
        $this->dispatch('abrir-sala', url: $reuniao->url());
    }

    public function escolherContato(int $id): void
    {
        $contato = Contact::find($id);

        if (! $contato) {
            return;
        }

        $this->contact_id = $contato->id;
        $this->buscaContato = $contato->nomeExibicao();
    }

    public function tirarContato(): void
    {
        $this->contact_id = null;
        $this->buscaContato = '';
    }

    public function encerrar(int $id, Chamada $chamada): void
    {
        $reuniao = Meeting::find($id);

        if (! $reuniao) {
            return;
        }

        $chamada->encerrar($reuniao);

        $this->recado = 'Reunião encerrada.';
    }

    // ------------------------------------------------------------------ dados

    /**
     * A conversa aberta do contato escolhido.
     *
     * Nao abre conversa nova: se nao ha nenhuma, a sala nasce avulsa e o link vai a mao.
     * Abrir conversa por conta propria colocaria na caixa de entrada um atendimento que
     * ninguem pediu.
     */
    private function conversaDoContato(): ?Conversation
    {
        if (! $this->contact_id) {
            return null;
        }

        return Conversation::where('contact_id', $this->contact_id)
            ->where('status', '!=', Conversation::ARQUIVADA)
            ->latest('ultima_msg_em')
            ->first();
    }

    public function getViewData(): array
    {
        $candidatos = $this->contact_id || mb_strlen(trim($this->buscaContato)) < 2
            ? collect()
            : Contact::ativos()->where('nome', 'ilike', '%'.trim($this->buscaContato).'%')
                ->orderBy('nome')->limit(8)->get();

        $base = fn () => Meeting::with(['contact', 'criador'])->withCount('participants');

        return [
            'disponivel' => app(Chamada::class)->disponivel(),
            'candidatos' => $candidatos,

            // Aberta e nao vencida: sala que pode ter alguem esperando dentro.
            'abertas'    => $base()->abertas()
                ->where('comecou_em', '>', now()->subHours(Meeting::HORAS_ATE_EXPIRAR))
                ->latest('comecou_em')->get(),

            'passadas'   => $base()
                ->where(fn ($q) => $q->where('status', Meeting::ENCERRADA)
                    ->orWhere('comecou_em', '<=', now()->subHours(Meeting::HORAS_ATE_EXPIRAR)))
                ->latest('comecou_em')->limit(20)->get(),
        ];
    }
}
