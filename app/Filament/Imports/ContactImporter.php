<?php

namespace App\Filament\Imports;

use App\Models\Contact;
use App\Models\ContactField;
use App\Models\Tag;
use App\Services\CampoDoContato;
use App\Services\Etiquetador;
use App\Support\PhoneNumber;
use App\Support\TenantContext;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Model;

/**
 * Importa contatos de planilha.
 *
 * Usa o importador do Filament de proposito: mapa de colunas, processamento em fila,
 * contagem e CSV das linhas rejeitadas ja vem de graca e testados. O que e nosso — e
 * o que ninguem mais poderia escrever — sao as regras: telefone e a identidade,
 * duplicado tem politica, e campo personalizado passa pela MESMA critica do chatbot.
 */
class ContactImporter extends Importer
{
    protected static ?string $model = Contact::class;

    /** Prefixo das colunas de campo personalizado, para separa-las das colunas reais. */
    private const PERSONALIZADO = 'campo_';

    public static function getColumns(): array
    {
        $colunas = [
            ImportColumn::make('telefone_e164')
                ->label('Telefone')
                ->requiredMapping()
                // guess: o cabecalho da planilha do cliente nunca se chama
                // "telefone_e164". Sem os apelidos, todo mundo mapeia a mao.
                ->guess(['telefone', 'celular', 'whatsapp', 'whats', 'fone', 'phone', 'numero', 'número'])
                ->example('84 99614-3373')
                ->helperText('Obrigatório. É por ele que a conversa é identificada — sem telefone válido a linha é recusada.'),

            ImportColumn::make('nome')
                ->label('Nome')
                ->guess(['nome', 'name', 'cliente', 'razao social', 'razão social', 'nome completo'])
                ->example('Maria Souza')
                // Celula vazia NAO apaga o que ja existe. Planilha quase sempre vem
                // com colunas incompletas, e importar nao pode ser um jeito de
                // limpar cadastro sem querer.
                ->ignoreBlankState(),

            ImportColumn::make('email')
                ->label('E-mail')
                ->guess(['email', 'e-mail', 'mail'])
                ->rules(['nullable', 'email'])
                ->ignoreBlankState(),

            ImportColumn::make('instagram')
                ->label('Instagram')
                ->guess(['instagram', 'insta', 'arroba'])
                ->ignoreBlankState(),

            ImportColumn::make('cep')
                ->label('CEP')
                ->guess(['cep', 'codigo postal', 'código postal'])
                ->ignoreBlankState(),

            ImportColumn::make('logradouro')
                ->label('Logradouro')
                ->guess(['logradouro', 'endereco', 'endereço', 'rua', 'avenida'])
                ->ignoreBlankState(),

            ImportColumn::make('numero')
                ->label('Número')
                ->guess(['numero', 'número', 'num', 'nº'])
                ->ignoreBlankState(),

            ImportColumn::make('complemento')
                ->label('Complemento')
                ->guess(['complemento', 'compl', 'apto', 'apartamento'])
                ->ignoreBlankState(),

            ImportColumn::make('bairro')
                ->label('Bairro')
                ->guess(['bairro', 'distrito'])
                ->ignoreBlankState(),

            ImportColumn::make('cidade')
                ->label('Cidade')
                ->guess(['cidade', 'municipio', 'município', 'localidade'])
                ->ignoreBlankState(),

            ImportColumn::make('uf')
                ->label('UF')
                ->guess(['uf', 'estado'])
                ->ignoreBlankState(),
        ];

        // Campos personalizados entram como coluna mapeavel. Sem isto, quem criou
        // "Contrato" em Configuracoes teria de preencher contato por contato.
        foreach (ContactField::orderBy('ordem')->orderBy('nome')->get() as $campo) {
            $colunas[] = ImportColumn::make(self::PERSONALIZADO.$campo->id)
                ->label($campo->nome)
                ->guess([$campo->nome])
                ->ignoreBlankState()
                // Nao e coluna do contato: gravar direto no modelo estouraria. O
                // valor e gravado no afterSave, quando o contato ja tem id.
                ->fillRecordUsing(fn () => null);
        }

        return $colunas;
    }

    public static function getOptionsFormComponents(): array
    {
        return [
            Select::make('duplicados')
                ->label('Quando o telefone já existir')
                ->options([
                    'atualizar' => 'Atualizar o contato (não apaga o que já está preenchido)',
                    'ignorar'   => 'Pular a linha e listar como não importada',
                ])
                ->default('atualizar')
                ->native(false)
                ->required()
                ->helperText('Atualizar é o padrão: planilha nova quase sempre é complemento, não substituição.'),

            Select::make('etiquetas')
                ->label('Aplicar etiquetas a todos os importados')
                ->multiple()
                ->options(fn () => Tag::orderBy('nome')->pluck('nome', 'id')->all())
                ->helperText('Opcional. Fica registrado que veio da importação, e dá para achar depois quem entrou nesta leva.'),
        ];
    }

    /**
     * Acha o contato pelo telefone — ou devolve null para criar.
     *
     * Roda ANTES da validacao e do preenchimento, entao e aqui que o telefone e
     * normalizado: a planilha traz "(84) 99614-3373", "84996143373" e
     * "+5584996143373" para a mesma pessoa, e sem normalizar antes de comparar a
     * deduplicacao nao acha nada e o cadastro duplica.
     */
    public function resolveRecord(): ?Model
    {
        // Job de fila nao tem usuario logado, e o TenantContext cai para
        // auth()->user() — que aqui e nulo. Sem esta linha o contato nasceria sem
        // conta, ou o escopo global esconderia o duplicado e tudo viraria novo.
        TenantContext::set((int) $this->import->user?->tenant_id);

        $bruto = $this->data['telefone_e164'] ?? null;
        $e164 = PhoneNumber::toE164(is_string($bruto) ? $bruto : (string) $bruto);

        if ($e164 === null) {
            // Recusa a LINHA com motivo legivel, em vez de criar contato sem
            // telefone: contato sem telefone nao serve para nada num chat.
            throw new RowImportFailedException('Telefone inválido ou vazio: '.($bruto ?: '(em branco)'));
        }

        $this->data['telefone_e164'] = $e164;

        // Pelas duas grafias: planilha com o nono digito e contato que chegou pelo canal
        // oficial sem ele sao a MESMA pessoa, e comparar so uma grafia duplicava o cadastro.
        $existente = Contact::acharPorTelefone($e164);

        if ($existente && ($this->options['duplicados'] ?? 'atualizar') === 'ignorar') {
            throw new RowImportFailedException('Já existe contato com este telefone.');
        }

        // Instancia NOVA, e nunca null: o Filament trata null como "pule esta linha".
        // Devolver null aqui faria toda importacao relatar sucesso sem criar contato
        // nenhum — falha silenciosa, a pior especie. Foi teste que pegou.
        return $existente ?? new Contact();
    }

    /**
     * Critica os campos personalizados ANTES de salvar.
     *
     * Pela MESMA regra do chatbot: CPF com digito errado, data impossivel, opcao
     * fora da lista. E antes de salvar de proposito — recusar depois deixaria o
     * contato gravado e o campo nao, e ninguem saberia qual metade entrou.
     */
    protected function afterValidate(): void
    {
        $catalogo = app(CampoDoContato::class);

        foreach ($this->camposPersonalizadosDaLinha() as $campoId => $valor) {
            $chave = CampoDoContato::PERSONALIZADO.'.'.$campoId;

            if (! CampoDoContato::existe($chave)) {
                continue;
            }

            if ($catalogo->naoCabem($chave, [$valor]) !== []) {
                $rotulo = CampoDoContato::rotulo($chave) ?? 'campo personalizado';

                throw new RowImportFailedException("Valor inválido em \"{$rotulo}\": {$valor}");
            }
        }
    }

    protected function afterSave(): void
    {
        $contato = $this->record;

        if (! $contato instanceof Contact) {
            return;
        }

        $catalogo = app(CampoDoContato::class);

        foreach ($this->camposPersonalizadosDaLinha() as $campoId => $valor) {
            $catalogo->gravar($contato, CampoDoContato::PERSONALIZADO.'.'.$campoId, $valor);
        }

        $etiquetas = array_filter((array) ($this->options['etiquetas'] ?? []));

        if ($etiquetas !== []) {
            // Pelo Etiquetador, com origem IMPORTACAO: o painel do contato passa a
            // dizer "Aplicada na importação", e nao "sem origem registrada".
            app(Etiquetador::class)->aplicar(
                $contato,
                array_map('intval', $etiquetas),
                Etiquetador::IMPORTACAO,
                $this->import->user_id,
            );
        }
    }

    /**
     * Valores de campo personalizado desta linha, indexados pelo id do campo.
     *
     * @return array<int, string>
     */
    private function camposPersonalizadosDaLinha(): array
    {
        $saida = [];

        foreach ($this->data as $coluna => $valor) {
            if (! str_starts_with((string) $coluna, self::PERSONALIZADO)) {
                continue;
            }

            $texto = trim((string) $valor);

            if ($texto === '') {
                continue;
            }

            $saida[(int) substr((string) $coluna, strlen(self::PERSONALIZADO))] = $texto;
        }

        return $saida;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $ok = number_format($import->successful_rows, 0, ',', '.');
        $corpo = "{$ok} contato(s) importado(s).";

        $falhas = $import->getFailedRowsCount();

        if ($falhas) {
            // Diz quantas e onde ver. Importacao que engole linha rejeitada faz o
            // usuario descobrir a falta meses depois, procurando um cliente.
            $corpo .= ' '.number_format($falhas, 0, ',', '.').' linha(s) não entraram — baixe a planilha de rejeitadas para ver o motivo de cada uma.';
        }

        return $corpo;
    }
}
