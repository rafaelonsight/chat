<?php

namespace App\Filament\Revenda\Pages;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ConsultaCnpj;
use App\Services\CriarCliente;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * A tela do dono do produto: cadastrar e acompanhar os clientes.
 *
 * Formulario escrito a mao, seguindo o Cadastro: o comportamento importante aqui (o convite,
 * o desempate de slug, o tenant certo) e logica, nao configuracao de formulario.
 *
 * Mora no painel 'revenda' (admin.virtus.chat), e nao no painel do produto — ver
 * RevendaPanelProvider. O $navigationGroup antigo ('Revenda') virou o nome do painel
 * inteiro, entao nao faz mais sentido aqui dentro.
 */
class Clientes extends Page
{
    use \App\Filament\TraduzirNavegacao;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-building';

    protected static ?string $navigationLabel = 'Clientes';

    protected static ?string $title = 'Clientes';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'clientes';

    protected string $view = 'filament.revenda.pages.clientes';

    public int $etapaCadastro = 1;
    public function avancarCadastro(): void {
        if ($this->etapaCadastro === 1) $this->validate(['nome'=>'required|string|max:120','documento'=>['nullable','string','max:32',$this->regraDocumento()],'email'=>'nullable|email']);
        if ($this->etapaCadastro === 2) $this->validate(['fuso_horario'=>'required|timezone','receita.cep'=>'nullable|regex:/^\d{5}-?\d{3}$/']);
        $this->etapaCadastro = min(3, $this->etapaCadastro + 1);
    }
    public function voltarCadastro(): void { $this->etapaCadastro = max(1, $this->etapaCadastro - 1); }

    public string $email_resumos = '';
    public string $nome = '';

    public string $documento = '';

    public string $email = '';

    public string $telefone = '';

    public string $fuso_horario = 'America/Sao_Paulo';

    public string $responsavel_nome = '';

    public string $responsavel_email = '';

    /** Campos vindos da Receita, guardados para o cliente ja achar o cadastro preenchido. */
    public array $receita = [];

    public string $ultimo_cnpj_buscado = '';

    // Resultado do ultimo cadastro. Fica na tela ate o proximo, porque o link do convite e a
    // unica coisa que o operador precisa levar dali para fora.
    public ?string $clienteCriado = null;

    public ?string $linkDoConvite = null;

    public bool $emailEnviado = false;

    public ?string $falhaDeEmail = null;

    /**
     * Operador e quem opera o PRODUTO, nao quem administra uma conta. A diferenca e o motivo
     * desta tela existir separada: administrador de cliente que chegasse aqui veria o nome e o
     * CNPJ de todos os outros clientes.
     */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->operador;
    }

    // Mesma regra do canAccess: sem isto o item apareceria no menu e so barraria no clique.
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** Igual ao Cadastro: CNPJ completo dispara a consulta sozinho, CPF fica quieto. */
    public function abrirConta(int $id): void {
        abort_unless(auth()->user()?->operador && auth()->user()?->super_admin,403);
        $conta=Tenant::findOrFail($id);
        session()->put(\App\Support\TenantContext::CHAVE_SESSAO_SUPORTE,$conta->id);
        $this->redirect('/admin');
    }

    public function updatedDocumento(): void
    {
        $cnpj = ConsultaCnpj::digitos($this->documento);
        if ($this->ultimo_cnpj_buscado && $cnpj !== $this->ultimo_cnpj_buscado) {
            $this->receita = [];
            $this->ultimo_cnpj_buscado = '';
        }

        if (strlen($cnpj) !== 14 || $cnpj === $this->ultimo_cnpj_buscado) {
            return;
        }

        $this->buscarCnpj();
    }

    public function updatedReceita($value, $key): void
    {
        if ($key === 'cep' && \App\Services\ConsultaCep::valido((string) $value)) $this->buscarCep();
    }

    public function buscarCep(): void
    {
        $r = app(\App\Services\ConsultaCep::class)->consultar($this->receita['cep'] ?? '');
        if (! $r['ok']) {
            Notification::make()->warning()->title(__('ui_f29cc585ab9b'))->body($r['erro'])->send();
            return;
        }
        foreach (['logradouro', 'bairro', 'cidade', 'uf'] as $campo) $this->receita[$campo] = $r['dados'][$campo] ?? '';
        Notification::make()->success()->title(__('ui_2ca1166e38da'))->body(__('ui_27fee093459a'))->send();
    }

    public function buscarCnpj(): void
    {
        $cnpj = ConsultaCnpj::digitos($this->documento);

        if (strlen($cnpj) !== 14) {
            Notification::make()->warning()
                ->title(__('ui_787cc4e190a8'))
                ->body(__('ui_7954a82c1189'))
                ->send();

            return;
        }

        $this->ultimo_cnpj_buscado = $cnpj;

        $resultado = app(ConsultaCnpj::class)->consultar($cnpj);

        if (! $resultado['ok']) {
            Notification::make()->danger()
                ->title(__('ui_2570b91d5fcb'))
                ->body($resultado['erro'])
                ->send();

            return;
        }

        $d = $resultado['dados'];

        $this->receita = array_intersect_key($d, array_flip([
            'razao_social', 'nome_fantasia', 'cep', 'logradouro', 'numero', 'complemento',
            'bairro', 'cidade', 'uf', 'natureza_juridica', 'cnae_principal',
            'situacao_cadastral', 'porte', 'data_abertura',
        ]));
        $this->receita['cnpj_consultado_em'] = now();

        // Nome, e-mail e telefone so entram se estiverem vazios: quem digitou algo digitou por
        // saber, e o cadastro da Receita costuma ter telefone antigo.
        $this->nome = trim($this->nome) ?: (string) ($d['nome_fantasia'] ?? $d['razao_social'] ?? '');
        $this->email = trim($this->email) ?: (string) ($d['email'] ?? '');
        $this->telefone = trim($this->telefone) ?: (string) ($d['telefone'] ?? '');

        Notification::make()->success()
            ->title(__('ui_9583df459866'))
            ->body(__('ui_3c26281667d7'))
            ->send();
    }

    public function criar(): void
    {
        $this->validate([
            'nome'              => 'required|string|max:120',
            'documento'         => ['nullable', 'string', 'max:32', $this->regraDocumento()],
            'email_resumos'     => 'nullable|email|max:160',
            'email'             => 'nullable|email|max:160',
            'telefone'          => 'nullable|string|max:32',
            'fuso_horario'      => 'required|timezone',
            'responsavel_nome'  => 'required|string|max:120',
            // A tabela users tem e-mail unico em TODO o sistema, porque a entrada e por
            // e-mail e nao por conta. Validar aqui troca um erro de banco por uma frase.
            'responsavel_email' => 'required|email|max:160|unique:users,email',
        ], [
            'responsavel_email.unique' => 'Já existe um usuário com este e-mail no sistema.',
        ], [
            'nome'              => 'nome do cliente',
            'documento'         => 'CNPJ',
            'fuso_horario'      => 'fuso horário',
            'responsavel_nome'  => 'nome do responsável',
            'responsavel_email' => 'e-mail do responsável',
        ]);

        $r = app(CriarCliente::class)->criar([
            'nome'              => $this->nome,
            'documento'         => $this->documento,
            'email'             => $this->email,
            'telefone'          => $this->telefone,
            'fuso_horario'      => $this->fuso_horario,
            'responsavel_nome'  => $this->responsavel_nome,
            'responsavel_email' => $this->responsavel_email,
            'receita'           => array_merge($this->receita, ['email_resumos'=>$this->email_resumos ?: null]),
        ]);

        $this->clienteCriado = $r['cliente']->nome;
        $this->linkDoConvite = $r['link'];
        $this->emailEnviado = $r['email_enviado'];
        $this->falhaDeEmail = $r['falha_de_email'];

        $this->limparFormulario();
        $this->etapaCadastro = 1;

        Notification::make()->success()
            ->title(__('ui_cb17edcaf874'))
            ->body($r['email_enviado']
                ? 'O convite foi enviado para '.$r['responsavel']->email.'.'
                : 'O e-mail não saiu. Copie o link do convite abaixo.')
            ->send();
    }

    /** Reenvia o convite ao responsavel de um cliente — link expirado e o caso comum. */
    public function reenviar(int $clienteId): void
    {
        $responsavel = User::withoutGlobalScope('tenant')
            ->where('tenant_id', $clienteId)
            ->where('admin', true)
            ->orderBy('id')
            ->first();

        if (! $responsavel) {
            Notification::make()->warning()
                ->title(__('ui_90007872f377'))
                ->body(__('ui_0ff33f5ab297'))
                ->send();

            return;
        }

        $convite = app(CriarCliente::class)->convidar($responsavel);

        $this->clienteCriado = Tenant::find($clienteId)?->nome;
        $this->linkDoConvite = $convite['link'];
        $this->emailEnviado = $convite['enviado'];
        $this->falhaDeEmail = $convite['falha'];

        Notification::make()->success()
            ->title(__('ui_305f206c4215').$responsavel->email)
            ->body($convite['enviado'] ? 'Enviado por e-mail.' : 'O e-mail não saiu; use o link.')
            ->send();
    }

    /**
     * A lista.
     *
     * Tudo aqui atravessa o escopo global de tenant com withoutGlobalScope. Sem isso as
     * contagens seriam filtradas pelo tenant do OPERADOR e todo cliente apareceria zerado —
     * uma tela dizendo que ninguem usa o sistema.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function clientes(): Collection
    {
        $meu = auth()->user()?->tenant_id;

        return Tenant::query()->orderBy('nome')->get()->map(fn (Tenant $t) => [
            'id'        => $t->id,
            'nome'      => $t->nome,
            'documento' => ConsultaCnpj::formatar($t->documento),
            'email'     => $t->email,
            'usuarios'  => User::withoutGlobalScope('tenant')->where('tenant_id', $t->id)->count(),
            'canais'    => Channel::withoutGlobalScope('tenant')->where('tenant_id', $t->id)->count(),
            'contatos'  => Contact::withoutGlobalScope('tenant')->where('tenant_id', $t->id)->count(),
            'criado'    => $t->created_at?->format('d/m/Y'),
            'eu'        => $t->id === $meu,
        ]);
    }

    private function limparFormulario(): void
    {
        $this->email_resumos = '';
        $this->nome = '';
        $this->documento = '';
        $this->email = '';
        $this->telefone = '';
        $this->responsavel_nome = '';
        $this->responsavel_email = '';
        $this->receita = [];
        $this->ultimo_cnpj_buscado = '';
        $this->fuso_horario = 'America/Sao_Paulo';
    }

    private function regraDocumento(): callable
    {
        return function (string $atributo, mixed $valor, callable $falhar): void {
            $digitos = ConsultaCnpj::digitos(is_scalar($valor) ? (string) $valor : null);

            if (strlen($digitos) === 14 && ! ConsultaCnpj::valido($digitos)) {
                $falhar('O CNPJ informado não é válido.');
            }
        };
    }

    /** @return array<string, string> */
    public function fusos(): array
    {
        return [
            'America/Sao_Paulo'  => 'Brasília (GMT-3)',
            'America/Manaus'     => 'Manaus (GMT-4)',
            'America/Rio_Branco' => 'Rio Branco (GMT-5)',
            'America/Fortaleza'  => 'Fortaleza (GMT-3)',
            'America/Belem'      => 'Belém (GMT-3)',
            'America/Recife'     => 'Recife (GMT-3)',
            'America/Cuiaba'     => 'Cuiabá (GMT-4)',
            'America/Noronha'    => 'Fernando de Noronha (GMT-2)',
        ];
    }
}
