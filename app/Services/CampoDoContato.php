<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ContactField;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Traduz "guardar a resposta no campo X" em gravacao de verdade no cadastro.
 *
 * Um lugar so para os dois mundos: as COLUNAS do contato (nome, e-mail, CEP...) e
 * os campos PERSONALIZADOS que o provedor cria em Configuracoes. O chatbot, o
 * formulario e qualquer coisa que venha depois falam a mesma lingua — a chave
 * "contato.email" ou "personalizado.7".
 *
 * A regra que da sentido ao resto: resposta que nao passa na validacao NAO e
 * gravada. CPF com digito errado guardado no cadastro faz o provedor cobrar a
 * pessoa errada, e um cadastro errado e mais caro que um cadastro vazio — o vazio
 * ao menos se ve.
 */
class CampoDoContato
{
    public const CONTATO = 'contato';

    public const PERSONALIZADO = 'personalizado';

    /**
     * Campos que sao coluna do contato.
     *
     * telefone_e164 fica FORA de proposito: e por ele que a conversa e
     * identificada. Deixar o cliente reescrever o proprio numero pelo chatbot
     * apontaria a conversa para outra pessoa — ou para ninguem.
     */
    public const PADRAO = [
        'nome'        => 'Nome',
        'email'       => 'E-mail',
        'instagram'   => 'Instagram',
        'cep'         => 'CEP',
        'logradouro'  => 'Logradouro',
        'numero'      => 'Número',
        'complemento' => 'Complemento',
        'bairro'      => 'Bairro',
        'cidade'      => 'Cidade',
        'uf'          => 'UF',
    ];

    public function __construct(private readonly ConsultaCep $cep) {}

    // ================================================================ catalogo

    /**
     * Para o <select> do construtor, em dois grupos.
     *
     * Os personalizados saem da tabela a cada chamada de proposito: campo criado
     * agora tem de aparecer no fluxo agora. Guardar em cache faria o provedor criar
     * o campo e nao o encontrar.
     *
     * @return array<string, array<string, string>>
     */
    public static function agrupadas(): array
    {
        $padrao = [];

        foreach (self::PADRAO as $coluna => $rotulo) {
            $padrao[self::CONTATO.'.'.$coluna] = $rotulo;
        }

        $personalizados = [];

        foreach (ContactField::orderBy('ordem')->orderBy('nome')->get() as $campo) {
            $personalizados[self::PERSONALIZADO.'.'.$campo->id] = $campo->nome.' — '.$campo->rotuloTipo();
        }

        return [
            'Campos do cadastro'    => $padrao,
            'Campos personalizados' => $personalizados,
        ];
    }

    /** @return array<string, string> chave => rotulo, tudo junto */
    public static function todas(): array
    {
        $saida = [];

        foreach (self::agrupadas() as $opcoes) {
            $saida += $opcoes;
        }

        return $saida;
    }

    public static function existe(string $chave): bool
    {
        return array_key_exists($chave, self::todas());
    }

    public static function rotulo(string $chave): ?string
    {
        [$grupo, $id] = self::partes($chave);

        if ($grupo === self::CONTATO) {
            return self::PADRAO[$id] ?? null;
        }

        if ($grupo === self::PERSONALIZADO) {
            return ContactField::find((int) $id)?->nome;
        }

        return null;
    }

    /**
     * Nome do marcador {{...}} para citar a resposta depois.
     *
     * Sai do campo escolhido para que ninguem precise inventar duas vezes: quem
     * escolheu "CPF" ja pode escrever {{cpf}} na mensagem seguinte.
     */
    public static function marcador(string $chave): string
    {
        [$grupo, $id] = self::partes($chave);

        // A coluna JA e um bom marcador: {{email}}, {{cidade}}.
        if ($grupo === self::CONTATO) {
            return array_key_exists($id, self::PADRAO) ? $id : '';
        }

        $campo = ContactField::find((int) $id);

        return $campo ? Str::slug($campo->nome, '_') : '';
    }

    // ================================================================= gravar

    /**
     * Grava a resposta no campo.
     *
     * Tres desfechos, e o terceiro e o que evita derrubar atendimento:
     *
     *   ok = true                  gravou.
     *   ok = false, erro = texto   culpa do cliente; o texto e o que dizer a ele.
     *   ok = false, erro = null    culpa nossa (campo apagado do cadastro depois de
     *                              o fluxo ter sido montado). Nao da para pedir de
     *                              novo: o cliente nao tem como consertar isso.
     *
     * @return array{ok: bool, erro: ?string}
     */
    public function gravar(Contact $contato, string $chave, string $valor): array
    {
        $valor = trim($valor);
        [$grupo, $id] = self::partes($chave);

        return match ($grupo) {
            self::CONTATO       => $this->gravarColuna($contato, $id, $valor),
            self::PERSONALIZADO => $this->gravarPersonalizado($contato, (int) $id, $valor),
            default             => self::nossaCulpa(),
        };
    }

    /** @return array{ok: bool, erro: ?string} */
    private function gravarColuna(Contact $contato, string $coluna, string $valor): array
    {
        if (! array_key_exists($coluna, self::PADRAO)) {
            return self::nossaCulpa();
        }

        if ($coluna === 'cep') {
            return $this->gravarCep($contato, $valor);
        }

        switch ($coluna) {
            case 'nome':
                // Duas letras: "Ana" passa, "a" nao. Nome de uma letra e quase
                // sempre o cliente testando o robo.
                if (mb_strlen($valor) < 2) {
                    return self::pedirDeNovo('Preciso do seu nome completo, por favor.');
                }
                break;

            case 'email':
                if (! filter_var($valor, FILTER_VALIDATE_EMAIL)) {
                    return self::pedirDeNovo('Esse e-mail não parece certo. Pode conferir e mandar de novo?');
                }

                // Minusculo sempre: e-mail nao diferencia caixa, e guardar
                // "Joao@X.com" e "joao@x.com" como dois cadastros e duplicata.
                $valor = mb_strtolower($valor);
                break;

            case 'instagram':
                $valor = ltrim($valor, '@');

                if ($valor === '') {
                    return self::pedirDeNovo('Qual é o seu @ no Instagram?');
                }
                break;

            case 'uf':
                $valor = mb_strtoupper($valor);

                if (! preg_match('/^[A-Z]{2}$/', $valor)) {
                    return self::pedirDeNovo('A UF tem duas letras, como RN ou SP.');
                }
                break;
        }

        if ($valor === '') {
            return self::pedirDeNovo('Não entendi. Pode escrever de novo?');
        }

        $contato->update([$coluna => $valor]);

        return self::gravou();
    }

    /** @return array{ok: bool, erro: ?string} */
    private function gravarCep(Contact $contato, string $valor): array
    {
        if (! ConsultaCep::valido($valor)) {
            return self::pedirDeNovo('O CEP tem 8 dígitos. Pode mandar de novo?');
        }

        $digitos = ConsultaCep::digitos($valor);
        $contato->update(['cep' => $digitos]);

        // Endereco de graca: o cliente ja deu os 8 digitos, nao ha razao para
        // perguntar rua e bairro depois.
        $consulta = $this->cep->consultar($digitos);

        if ($consulta['ok']) {
            $completar = [];

            foreach (['logradouro', 'bairro', 'cidade', 'uf'] as $coluna) {
                // SO o que esta vazio. Endereco que uma pessoa digitou vale mais
                // que o dos Correios: ela pode ter corrigido o nome da rua ou
                // posto a referencia que o entregador usa.
                if (blank($contato->{$coluna}) && filled($consulta['dados'][$coluna] ?? null)) {
                    $completar[$coluna] = $consulta['dados'][$coluna];
                }
            }

            if ($completar !== []) {
                $contato->update($completar);
            }
        }

        // ViaCEP fora do ar NAO invalida a resposta: o CEP do cliente esta certo e
        // guardado. Cobrar dele por indisponibilidade nossa seria absurdo.
        return self::gravou();
    }

    /** @return array{ok: bool, erro: ?string} */
    private function gravarPersonalizado(Contact $contato, int $id, string $valor): array
    {
        $campo = ContactField::find($id);

        if (! $campo) {
            // Campo apagado em Configuracoes depois de o fluxo ter sido montado.
            return self::nossaCulpa();
        }

        if ($valor === '') {
            return self::pedirDeNovo('Não entendi. Pode escrever de novo?');
        }

        $erro = $this->criticar($campo, $valor);

        if ($erro !== null) {
            return self::pedirDeNovo($erro);
        }

        // Campo vazio e APAGADO, nunca guardado como string vazia — a mesma regra
        // do formulario, para "nunca preenchido" nao virar "preenchido com nada".
        $contato->fieldValues()->updateOrCreate(
            ['contact_field_id' => $campo->id],
            ['valor' => $this->paraBanco($campo, $valor)],
        );

        return self::gravou();
    }

    // ============================================================== validacao

    /** Mensagem para o cliente, ou null quando a resposta serve. */
    private function criticar(ContactField $campo, string $valor): ?string
    {
        return match ($campo->tipo) {
            ContactField::CPF_CNPJ => ContactField::cpfCnpjValido($valor)
                ? null
                : 'Esse CPF/CNPJ não parece válido. Pode conferir os números e mandar de novo?',

            ContactField::CEP => ConsultaCep::valido($valor)
                ? null
                : 'O CEP tem 8 dígitos. Pode mandar de novo?',

            ContactField::INTEIRO => preg_match('/^-?\d+$/', $valor) === 1
                ? null
                : 'Preciso de um número inteiro, só os dígitos.',

            ContactField::DECIMAL => is_numeric(str_replace(',', '.', $valor))
                ? null
                : 'Preciso de um número. Use vírgula para os centavos, como 149,90.',

            ContactField::DATA, ContactField::DATA_HORA => $this->comoData($valor) !== null
                ? null
                : 'Não entendi a data. Escreva no formato 31/12/2026.',

            ContactField::BOOLEANO => $this->comoBooleano($valor) !== null
                ? null
                : 'Responda com sim ou não, por favor.',

            ContactField::LINK => filter_var($valor, FILTER_VALIDATE_URL) !== false
                ? null
                : 'Isso não parece um link. Ele começa com https://',

            ContactField::LISTA => $this->casarOpcao($campo, $valor) !== null
                ? null
                : $this->avisoDasOpcoes($campo),

            ContactField::MULTISELECAO => $this->casarVarias($campo, $valor) !== null
                ? null
                : $this->avisoDasOpcoes($campo),

            default => null,
        };
    }

    /** O valor como vai para o banco, no formato que o exibir() espera de volta. */
    private function paraBanco(ContactField $campo, string $valor): string
    {
        return match ($campo->tipo) {
            ContactField::CPF_CNPJ     => ContactField::soDigitos($valor),
            ContactField::CEP          => ConsultaCep::digitos($valor),
            ContactField::DECIMAL      => str_replace(',', '.', $valor),
            ContactField::DATA         => $this->comoData($valor)?->format('Y-m-d') ?? $valor,
            ContactField::DATA_HORA    => $this->comoData($valor)?->format('Y-m-d H:i:s') ?? $valor,
            ContactField::BOOLEANO     => $this->comoBooleano($valor) ? '1' : '0',
            ContactField::LISTA        => $this->casarOpcao($campo, $valor) ?? $valor,
            ContactField::MULTISELECAO => json_encode($this->casarVarias($campo, $valor) ?? []),
            default                    => $valor,
        };
    }

    /**
     * Data no jeito brasileiro ANTES de qualquer coisa.
     *
     * O parse solto do PHP le 03/12 como 12 de marco, no formato americano. No
     * Brasil isso e 3 de dezembro: nove meses de diferenca, sem erro nenhum na
     * tela. Por isso os formatos sao explicitos, e o format() de volta confirma
     * que o texto casou de verdade — createFromFormat aceita 32/13/2026 e
     * "conserta" para 2027 se ninguem conferir.
     */
    private function comoData(string $valor): ?Carbon
    {
        foreach (['d/m/Y H:i', 'd/m/Y', 'd-m-Y', 'Y-m-d H:i', 'Y-m-d'] as $formato) {
            try {
                $data = Carbon::createFromFormat($formato, $valor);
            } catch (\Throwable) {
                continue;
            }

            if ($data instanceof Carbon && $data->format($formato) === $valor) {
                return $data;
            }
        }

        return null;
    }

    private function comoBooleano(string $valor): ?bool
    {
        return match (self::simplificar($valor)) {
            'sim', 's', '1', 'ok', 'claro', 'positivo', 'isso' => true,
            'nao', 'n', '0', 'negativo'                        => false,
            default                                            => null,
        };
    }

    /** A opcao oficial que casa com o que o cliente escreveu, ou null. */
    private function casarOpcao(ContactField $campo, string $valor): ?string
    {
        $procurado = self::simplificar($valor);

        foreach ((array) ($campo->opcoes ?? []) as $opcao) {
            if (self::simplificar((string) $opcao) === $procurado) {
                return (string) $opcao;
            }
        }

        return null;
    }

    /**
     * Varias opcoes separadas por virgula. Null se QUALQUER uma nao existir:
     * guardar metade da resposta seria pior que recusar, porque ninguem saberia
     * que faltou pedaco.
     *
     * @return array<int, string>|null
     */
    private function casarVarias(ContactField $campo, string $valor): ?array
    {
        $pedacos = array_filter(array_map('trim', explode(',', $valor)), fn ($p) => $p !== '');

        if ($pedacos === []) {
            return null;
        }

        $casadas = [];

        foreach ($pedacos as $pedaco) {
            $oficial = $this->casarOpcao($campo, $pedaco);

            if ($oficial === null) {
                return null;
            }

            $casadas[] = $oficial;
        }

        return array_values(array_unique($casadas));
    }

    private function avisoDasOpcoes(ContactField $campo): string
    {
        $opcoes = array_values((array) ($campo->opcoes ?? []));

        // Listar as validas em vez de so dizer "invalido": o cliente nao adivinha o
        // que o provedor cadastrou.
        return $opcoes === []
            ? 'Não entendi. Pode escrever de novo?'
            : 'Não achei essa opção. As possíveis são: '.implode(', ', $opcoes).'.';
    }

    /** Sem acento, sem caixa, sem espaco nas pontas — para comparar respostas. */
    private static function simplificar(string $valor): string
    {
        return mb_strtolower(trim(Str::ascii($valor)));
    }

    /** @return array{0: string, 1: string} */
    private static function partes(string $chave): array
    {
        $pedacos = explode('.', $chave, 2);

        return [$pedacos[0] ?? '', $pedacos[1] ?? ''];
    }

    /** @return array{ok: bool, erro: ?string} */
    private static function gravou(): array
    {
        return ['ok' => true, 'erro' => null];
    }

    /** @return array{ok: bool, erro: ?string} */
    private static function pedirDeNovo(string $motivo): array
    {
        return ['ok' => false, 'erro' => $motivo];
    }

    /** @return array{ok: bool, erro: ?string} */
    private static function nossaCulpa(): array
    {
        return ['ok' => false, 'erro' => null];
    }
}
