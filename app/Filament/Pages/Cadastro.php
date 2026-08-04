<?php

namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Services\ConsultaCnpj;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

// Formulario escrito a mao de proposito: os campos nao justificam depender da
// API de forms do Filament, e assim o comportamento fica todo testavel aqui.
class Cadastro extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $navigationParentItem = 'Conta';

    protected static ?string $navigationLabel = 'Cadastro';

    protected static ?string $title = 'Cadastro da conta';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'cadastro';

    protected string $view = 'filament.pages.cadastro';

    public string $nome = '';

    public string $razao_social = '';

    public string $nome_fantasia = '';

    public string $documento = '';

    public string $email = '';

    public string $telefone = '';

    public string $cep = '';

    public string $logradouro = '';

    public string $numero = '';

    public string $complemento = '';

    public string $bairro = '';

    public string $cidade = '';

    public string $uf = '';

    // Vindos da Receita e nao editaveis: mostrar em input daria a entender que
    // corrigir aqui muda algo la.
    public string $natureza_juridica = '';

    public string $cnae_principal = '';

    public string $situacao_cadastral = '';

    public string $porte = '';

    public string $data_abertura = '';

    public string $fuso_horario = 'America/Sao_Paulo';

    // Guarda o ultimo CNPJ consultado para o disparo automatico nao repetir a
    // busca (e o aviso na tela) a cada vez que o campo perde o foco.
    public string $ultimo_cnpj_buscado = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->admin;
    }

    public function mount(): void
    {
        $conta = $this->conta();

        if (! $conta) {
            return;
        }

        foreach ($this->campos() as $campo) {
            $this->{$campo} = (string) $conta->{$campo};
        }

        $this->fuso_horario = (string) ($conta->fuso_horario ?: 'America/Sao_Paulo');
        $this->ultimo_cnpj_buscado = ConsultaCnpj::digitos($this->documento);
    }

    /** @return list<string> */
    private function campos(): array
    {
        return [
            'nome', 'razao_social', 'nome_fantasia', 'documento', 'email', 'telefone',
            'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf',
            'natureza_juridica', 'cnae_principal', 'situacao_cadastral', 'porte',
            'data_abertura',
        ];
    }

    // Sempre a conta do usuario logado: nao existe caminho para alcancar outra.
    private function conta(): ?Tenant
    {
        $tenantId = auth()->user()?->tenant_id;

        return $tenantId ? Tenant::find($tenantId) : null;
    }

    /**
     * Disparo automatico: assim que o campo tem um CNPJ valido de 14 digitos, os
     * dados vem sozinhos. CPF nao tem consulta publica, entao fica quieto — e
     * numero incompleto tambem, para nao acusar erro de quem ainda esta digitando.
     */
    public function updatedDocumento(): void
    {
        $cnpj = ConsultaCnpj::digitos($this->documento);

        if (strlen($cnpj) !== 14 || $cnpj === $this->ultimo_cnpj_buscado) {
            return;
        }

        $this->buscarCnpj();
    }

    public function buscarCnpj(): void
    {
        $cnpj = ConsultaCnpj::digitos($this->documento);

        if (strlen($cnpj) !== 14) {
            Notification::make()->warning()
                ->title('CNPJ incompleto')
                ->body('São 14 dígitos. Para CPF não existe consulta pública.')
                ->send();

            return;
        }

        $this->ultimo_cnpj_buscado = $cnpj;

        $resultado = app(ConsultaCnpj::class)->consultar($cnpj);

        if (! $resultado['ok']) {
            Notification::make()->danger()
                ->title('Não deu para puxar os dados')
                ->body($resultado['erro'])
                ->send();

            return;
        }

        $this->preencher($resultado['dados']);

        Notification::make()->success()
            ->title('Dados da Receita preenchidos')
            ->body('Confira e clique em Salvar.')
            ->send();
    }

    /**
     * Politica de sobrescrita: o que e da Receita (razao social, endereco,
     * situacao) vem dela, porque e a fonte oficial e o motivo de ter clicado.
     * O que e escolha da empresa (nome da conta, e-mail e telefone de contato)
     * so e preenchido se estiver vazio — o cadastro da Receita costuma ter
     * telefone antigo, e apagar o contato certo seria pior que nao preencher.
     *
     * @param  array<string, ?string>  $d
     */
    private function preencher(array $d): void
    {
        foreach (['razao_social', 'nome_fantasia', 'cep', 'logradouro', 'numero',
            'complemento', 'bairro', 'cidade', 'uf', 'natureza_juridica',
            'cnae_principal', 'situacao_cadastral', 'porte', 'data_abertura'] as $campo) {
            $this->{$campo} = (string) ($d[$campo] ?? '');
        }

        if (trim($this->nome) === '') {
            $this->nome = (string) ($d['nome_fantasia'] ?? '') ?: (string) ($d['razao_social'] ?? '');
        }

        if (trim($this->email) === '') {
            $this->email = (string) ($d['email'] ?? '');
        }

        if (trim($this->telefone) === '') {
            $this->telefone = (string) ($d['telefone'] ?? '');
        }
    }

    public function salvar(): void
    {
        $this->validate([
            'nome'         => 'required|string|max:120',
            'razao_social' => 'nullable|string|max:160',
            'nome_fantasia' => 'nullable|string|max:160',
            'documento'    => ['nullable', 'string', 'max:32', $this->regraDocumento()],
            'email'        => 'nullable|email|max:160',
            'telefone'     => 'nullable|string|max:32',
            'cep'          => 'nullable|string|max:9',
            'logradouro'   => 'nullable|string|max:160',
            'numero'       => 'nullable|string|max:20',
            'complemento'  => 'nullable|string|max:160',
            'bairro'       => 'nullable|string|max:120',
            'cidade'       => 'nullable|string|max:120',
            'uf'           => 'nullable|string|size:2',
            'fuso_horario' => 'required|timezone',
        ], [], [
            'nome'          => 'nome',
            'razao_social'  => 'razão social',
            'nome_fantasia' => 'nome fantasia',
            'fuso_horario'  => 'fuso horário',
        ]);

        $conta = $this->conta();

        if (! $conta) {
            return;
        }

        $dados = [];

        foreach ($this->campos() as $campo) {
            $dados[$campo] = trim($this->{$campo}) ?: null;
        }

        $dados['nome'] = trim($this->nome);
        $dados['cep'] = ConsultaCnpj::digitos($this->cep) ?: null;
        $dados['uf'] = strtoupper(trim($this->uf)) ?: null;
        $dados['data_abertura'] = $dados['data_abertura'] ?: null;
        $dados['fuso_horario'] = $this->fuso_horario;

        // Marca quando os dados da Receita entraram: sem isso nao se sabe se o
        // endereco na tela e de hoje ou de dois anos atras.
        if ($this->ultimo_cnpj_buscado !== '' && $this->situacao_cadastral !== '') {
            $dados['cnpj_consultado_em'] = now();
        }

        $conta->update($dados);

        Notification::make()->success()->title('Cadastro salvo')->send();
    }

    /** CNPJ de 14 digitos tem que fechar nos verificadores; CPF passa direto. */
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
    public function dadosReceita(): array
    {
        return array_filter([
            'Situação cadastral' => $this->situacao_cadastral,
            'Natureza jurídica'  => $this->natureza_juridica,
            'CNAE principal'     => $this->cnae_principal,
            'Porte'              => $this->porte,
            'Abertura'           => $this->data_abertura
                ? \Illuminate\Support\Carbon::parse($this->data_abertura)->format('d/m/Y')
                : '',
        ], fn (string $v) => $v !== '');
    }

    public function fusos(): array
    {
        return [
            'America/Sao_Paulo'   => 'Brasília (GMT-3)',
            'America/Manaus'      => 'Manaus (GMT-4)',
            'America/Rio_Branco'  => 'Rio Branco (GMT-5)',
            'America/Fortaleza'   => 'Fortaleza (GMT-3)',
            'America/Belem'       => 'Belém (GMT-3)',
            'America/Recife'      => 'Recife (GMT-3)',
            'America/Cuiaba'      => 'Cuiabá (GMT-4)',
            'America/Noronha'     => 'Fernando de Noronha (GMT-2)',
        ];
    }
}
