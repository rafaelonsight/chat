<?php

namespace App\Livewire\Crm;

use App\Models\BookingPage;
use App\Models\Channel;
use App\Models\User;
use App\Support\DataPtBr;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Os links de agendamento: onde se decide o que o cliente vai poder escolher.
 *
 * O QUE ISSO TIRA DO CAMINHO e o vaivem. "Que horas voce pode?" / "terca as 14?" / "nao, so
 * depois das 16" gasta quatro mensagens e meia hora para marcar meia hora.
 *
 * MAIS DE UM LINK POR CONTA, de proposito. "Visita tecnica, 1h" e "Retorno, 15min" tem
 * duracao, texto e ate dono diferentes; com um link so, a primeira vez que alguem precisasse
 * do segundo teria de desmontar o primeiro.
 *
 * DENTRO DA AGENDA, e nao ao lado dela. Configurar quando se aceita visita e olhar a semana
 * sao a mesma cabeca no mesmo minuto; separar em dois itens de menu faria a pessoa procurar
 * num lugar o que ela estava vendo no outro.
 */
class LinksDeAgendamento extends Component
{

    public bool $formAberto = false;

    public ?int $editando = null;

    public string $titulo = '';

    public string $slugPublico = '';

    public string $descricao = '';

    public string $local = '';

    public ?int $user_id = null;

    public ?int $channel_id = null;

    public int $duracao_min = 30;

    public int $intervalo_min = 0;

    public int $antecedencia_horas = 2;

    public int $janela_dias = 30;

    public ?int $limite_dia = null;

    public bool $ativa = true;

    /** 0..6 => ['ativo' => bool, 'de1', 'ate1', 'de2', 'ate2'] */
    public array $horarios = [];

    public function mount(): void
    {
        $this->user_id = auth()->id();
        $this->horarios = $this->horariosPadrao();
    }

    // ------------------------------------------------------------------ form

    public function novo(): void
    {
        $this->reset(['editando', 'titulo', 'slugPublico', 'descricao', 'local', 'channel_id', 'limite_dia']);

        $this->user_id = auth()->id();
        $this->duracao_min = 30;
        $this->intervalo_min = 0;
        $this->antecedencia_horas = 2;
        $this->janela_dias = 30;
        $this->ativa = true;
        $this->horarios = $this->horariosPadrao();
        $this->formAberto = true;
    }

    public function editar(int $id): void
    {
        $p = BookingPage::find($id);

        if (! $p) {
            return;
        }

        $this->editando = $p->id;
        $this->titulo = $p->titulo;
        $this->slugPublico = $p->slug;
        $this->descricao = (string) $p->descricao;
        $this->local = (string) $p->local;
        $this->user_id = $p->user_id;
        $this->channel_id = $p->channel_id;
        $this->duracao_min = $p->duracao_min;
        $this->intervalo_min = $p->intervalo_min;
        $this->antecedencia_horas = $p->antecedencia_horas;
        $this->janela_dias = $p->janela_dias;
        $this->limite_dia = $p->limite_dia;
        $this->ativa = $p->ativa;
        $this->horarios = $this->deFaixas($p->disponibilidade ?? []);
        $this->formAberto = true;
    }

    public function salvar(): void
    {
        $this->validate([
            'titulo'             => 'required|string|max:120',
            'user_id'            => 'required|integer',
            'duracao_min'        => 'required|integer|min:5|max:480',
            'intervalo_min'      => 'required|integer|min:0|max:240',
            'antecedencia_horas' => 'required|integer|min:0|max:720',
            'janela_dias'        => 'required|integer|min:1|max:365',
            'limite_dia'         => 'nullable|integer|min:1|max:50',
            'local'              => 'nullable|string|max:160',
            'descricao'          => 'nullable|string|max:1000',
        ], [], ['user_id' => 'responsável', 'duracao_min' => 'duração']);

        $faixas = $this->paraFaixas();

        if ($faixas === []) {
            $this->addError('horarios', 'Marque pelo menos um dia com horário, senão o link não tem o que oferecer.');

            return;
        }

        $dados = [
            'user_id'            => $this->user_id,
            'channel_id'         => $this->channel_id ?: null,
            'titulo'             => trim($this->titulo),
            'descricao'          => trim($this->descricao) ?: null,
            'local'              => trim($this->local) ?: null,
            'duracao_min'        => $this->duracao_min,
            'intervalo_min'      => $this->intervalo_min,
            'antecedencia_horas' => $this->antecedencia_horas,
            'janela_dias'        => $this->janela_dias,
            'limite_dia'         => $this->limite_dia ?: null,
            'disponibilidade'    => $faixas,
            'ativa'              => $this->ativa,
        ];

        if ($this->editando) {
            $pagina = BookingPage::findOrFail($this->editando);

            // O slug so muda se a pessoa mexeu nele: trocar sozinho quebraria o link que ja
            // esta na assinatura de e-mail de alguem.
            $pedido = Str::slug($this->slugPublico);

            if ($pedido !== '' && $pedido !== $pagina->slug) {
                $dados['slug'] = $this->slugUnico($pedido, $pagina->id);
            }

            $pagina->update($dados);
        } else {
            BookingPage::create($dados + [
                'tenant_id' => auth()->user()->tenant_id,
                'slug'      => BookingPage::slugLivre($this->slugPublico ?: $this->titulo),
            ]);
        }

        $this->formAberto = false;
        $this->editando = null;
    }

    public function alternarAtiva(int $id): void
    {
        $p = BookingPage::find($id);

        $p?->update(['ativa' => ! $p->ativa]);
    }

    public function excluir(int $id): void
    {
        BookingPage::find($id)?->delete();

        $this->formAberto = false;
        $this->editando = null;
    }

    // ----------------------------------------------------------------- dados

    public function render()
    {
        return view('livewire.crm.links-de-agendamento', [
            'paginas' => BookingPage::with(['user', 'channel'])->orderBy('titulo')->get(),
            'pessoas' => User::orderBy('name')->get(),
            'canais'  => Channel::orderBy('nome')->get(),
            'dias'    => DataPtBr::DIAS_LONGOS,
        ]);
    }

    // ---------------------------------------------------------- conversao

    /**
     * Da tela para o banco.
     *
     * A tela edita DOIS TURNOS por dia porque quase todo negocio para para almocar, e uma
     * faixa so obrigaria a oferecer meio-dia. O banco guarda faixas soltas, que aguentam tres
     * turnos no dia em que alguem precisar, sem migration.
     */
    private function paraFaixas(): array
    {
        $faixas = [];

        foreach ($this->horarios as $dia => $h) {
            if (empty($h['ativo'])) {
                continue;
            }

            foreach ([['de1', 'ate1'], ['de2', 'ate2']] as [$de, $ate]) {
                $inicio = trim((string) ($h[$de] ?? ''));
                $fim = trim((string) ($h[$ate] ?? ''));

                if ($inicio !== '' && $fim !== '' && $inicio < $fim) {
                    $faixas[] = ['dia' => (int) $dia, 'de' => $inicio, 'ate' => $fim];
                }
            }
        }

        return $faixas;
    }

    private function deFaixas(array $faixas): array
    {
        $horarios = $this->horariosVazios();

        foreach ($faixas as $f) {
            $dia = (int) ($f['dia'] ?? -1);

            if (! array_key_exists($dia, $horarios)) {
                continue;
            }

            $slot = $horarios[$dia]['de1'] === '' ? '1' : '2';

            $horarios[$dia]['ativo'] = true;
            $horarios[$dia]['de'.$slot] = $f['de'];
            $horarios[$dia]['ate'.$slot] = $f['ate'];
        }

        return $horarios;
    }

    private function horariosVazios(): array
    {
        return collect(range(0, 6))
            ->mapWithKeys(fn ($d) => [$d => ['ativo' => false, 'de1' => '', 'ate1' => '', 'de2' => '', 'ate2' => '']])
            ->all();
    }

    /** Comercial, porque e o que a maioria vai querer e ninguem gosta de preencher grade. */
    private function horariosPadrao(): array
    {
        $horarios = $this->horariosVazios();

        foreach (range(1, 5) as $dia) {
            $horarios[$dia] = ['ativo' => true, 'de1' => '09:00', 'ate1' => '12:00', 'de2' => '13:00', 'ate2' => '18:00'];
        }

        return $horarios;
    }

    private function slugUnico(string $base, int $meuId): string
    {
        $slug = $base;
        $n = 1;

        while (BookingPage::withoutGlobalScope('tenant')->where('slug', $slug)->whereKeyNot($meuId)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}
