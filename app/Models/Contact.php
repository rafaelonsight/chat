<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Jid;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use BelongsToTenant;

    /*
     * NATUREZA e PAPEIS nao se confundem com o 'tipo' abaixo: tipo diz se o contato e uma pessoa
     * ou um grupo do WhatsApp (coisa do canal); natureza diz se e CPF ou CNPJ (coisa do
     * cadastro); papeis diz o que essa pessoa e para o negocio.
     */
    public const FISICA = 'fisica';

    public const JURIDICA = 'juridica';

    /** Os papeis possiveis. A pessoa pode ter varios: cliente que tambem fornece, tecnico que e colaborador. */
    public const PAPEIS = [
        'cliente' => 'Cliente',
        'colaborador' => 'Colaborador',
        'tecnico' => 'Técnico',
        'fornecedor' => 'Fornecedor',
        'vendedor' => 'Vendedor',
    ];

    public const PESSOA = 'pessoa';

    public const GRUPO = 'grupo';

    protected $fillable = [
        'tenant_id', 'jid', 'tipo', 'telefone_e164', 'nome', 'email', 'instagram',
        'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf',
        'arquivado_em', 'bloqueado_em', 'bloqueio_motivo',
        // O opt-out entra aqui e nao so no forceFill: a importacao de contatos vai
        // precisar dele no dia em que alguem trouxer uma lista com quem ja pediu saida,
        // e campo fora do fillable e descartado em SILENCIO — ja me custou tres rodadas
        // esta semana.
        'opt_out_em', 'opt_out_motivo',
        // A ficha completa: natureza, documento e papeis. Entram no fillable porque a
        // importacao de lista e a consulta de CNPJ preenchem por atribuicao em massa — e
        // campo de fora e descartado em silencio, como o comentario acima ja avisa.
        'natureza', 'documento', 'razao_social', 'nome_fantasia', 'papeis', 'nascimento',
    ];

    protected $casts = [
        'anonimizado_em' => 'datetime',
        'opt_out_em' => 'datetime',
        'arquivado_em' => 'datetime',
        'bloqueado_em' => 'datetime',
        'nascimento' => 'date',
        // Lista e nao texto: a pessoa pode ser cliente E fornecedora.
        'papeis' => 'array',
    ];

    protected $attributes = ['tipo' => self::PESSOA];

    public function ehJuridica(): bool
    {
        return $this->natureza === self::JURIDICA;
    }

    /** @return array<int, string> Os papeis por extenso, para a tela. */
    public function papeisPorExtenso(): array
    {
        return collect($this->papeis ?? [])
            ->map(fn (string $p) => self::PAPEIS[$p] ?? $p)
            ->values()
            ->all();
    }

    protected static function booted(): void
    {
        // O jid e NOT NULL e e a identidade real no WhatsApp. Derivar aqui cobre
        // todo caminho de criacao — inclusive o formulario do painel, que so
        // pede telefone.
        static::creating(function (Contact $contato) {
            if (! $contato->jid && $contato->telefone_e164) {
                $contato->jid = Jid::dePessoa($contato->telefone_e164);
            }
        });
    }

    /**
     * O mesmo perfil chega como "@fulano", "fulano" ou a url inteira. Normalizar
     * na escrita, e nao na tela, cobre importacao e chatbot pelo mesmo caminho —
     * senao o banco guarda tres formas do mesmo perfil e nenhuma busca casa.
     */
    protected function instagram(): Attribute
    {
        return Attribute::set(fn (?string $valor) => self::normalizarInstagram($valor));
    }

    public static function normalizarInstagram(?string $valor): ?string
    {
        $texto = trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        $texto = (string) preg_replace('#^https?://#i', '', $texto);
        $texto = (string) preg_replace('#^(www\.)?instagram\.com/#i', '', $texto);
        $texto = explode('?', $texto)[0];          // tira ?igshid=...
        $texto = trim($texto, "/ \t\n\r");
        $texto = ltrim($texto, '@');

        return strtolower($texto) ?: null;
    }

    /** Onde o perfil abre. Null quando nao ha instagram, para a tela nao criar link vazio. */
    public function instagramUrl(): ?string
    {
        return $this->instagram ? 'https://instagram.com/'.$this->instagram : null;
    }

    /** Os dados pessoais deste contato foram removidos a pedido dele. */
    /** Pediu para nao receber mais campanha. */
    public function saiuDaLista(): bool
    {
        return $this->opt_out_em !== null;
    }

    public function anonimizado(): bool
    {
        return $this->anonimizado_em !== null;
    }

    public function bloqueado(): bool
    {
        return $this->bloqueado_em !== null;
    }

    public function arquivado(): bool
    {
        return $this->arquivado_em !== null;
    }

    public function scopeAtivos(Builder $q): Builder
    {
        return $q->whereNull('arquivado_em')->whereNull('bloqueado_em');
    }

    public function fieldValues(): HasMany
    {
        return $this->hasMany(ContactFieldValue::class);
    }

    /** Valores dos campos personalizados, indexados pelo id do campo. */
    public function camposPersonalizados(): array
    {
        return $this->fieldValues()->pluck('valor', 'contact_field_id')->all();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)
            // created_at no pivot para a tela poder dizer QUANDO a etiqueta foi posta.
            ->withPivot(['origem', 'aplicado_por', 'created_at'])
            ->orderBy('tags.nome');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function eGrupo(): bool
    {
        return $this->tipo === self::GRUPO;
    }

    /**
     * Como esta pessoa se chama na tela — e o unico lugar que decide isso.
     *
     * A EMPRESA CADASTRADA PELO CNPJ entra sem 'nome' digitado a mao: quem preenche pelo documento
     * recebe razao social e fantasia da Receita, e mais nada. Sem os dois nesta ordem, ela
     * apareceria na caixa de entrada como um telefone.
     *
     * FANTASIA ANTES DE RAZAO SOCIAL porque e por ela que o cliente se reconhece: ninguem atende
     * dizendo "Comercio de Oculos Central LTDA". E o 'nome' vem antes das duas — se alguem
     * corrigiu a mao, a correcao vale mais que o dado da Receita.
     */
    public function nomeExibicao(): string
    {
        foreach ([$this->nome, $this->nome_fantasia, $this->razao_social] as $candidato) {
            if (filled($candidato)) {
                return $candidato;
            }
        }

        if ($this->eGrupo()) {
            // sem o assunto do grupo, ao menos identifica que e grupo
            return 'Grupo '.substr((string) $this->jid, 0, 8);
        }

        return (string) ($this->telefone_e164 ?: $this->jid);
    }

    /**
     * Iniciais para o avatar. Duas letras quando ha nome com sobrenome, uma
     * quando nao ha. Contato sem nome cai no '?': mostrar o primeiro digito do
     * telefone nao ajuda ninguem a reconhecer quem e.
     */
    public function iniciais(): string
    {
        $nome = trim((string) $this->nome);

        if ($nome === '') {
            return $this->eGrupo() ? 'G' : '?';
        }

        $partes = preg_split('/\s+/', $nome) ?: [];
        $partes = array_values(array_filter($partes, fn ($p) => $p !== ''));

        if (count($partes) === 1) {
            return mb_strtoupper(mb_substr($partes[0], 0, 1));
        }

        return mb_strtoupper(
            mb_substr($partes[0], 0, 1).mb_substr($partes[count($partes) - 1], 0, 1)
        );
    }

    /**
     * Cor do avatar, sorteada de forma estavel pelo jid. Todos cinza deixaria a
     * lista lisa; cor aleatoria a cada render faria o mesmo contato trocar de cor
     * entre paginas. Classes literais porque o Tailwind resolve no build.
     */
    public function corAvatar(): string
    {
        $paleta = [
            'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300',
            'bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-300',
            'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
            'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-300',
            'bg-teal-100 text-teal-700 dark:bg-teal-500/20 dark:text-teal-300',
            'bg-cyan-100 text-cyan-700 dark:bg-cyan-500/20 dark:text-cyan-300',
            'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300',
            'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300',
            'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300',
            'bg-pink-100 text-pink-700 dark:bg-pink-500/20 dark:text-pink-300',
        ];

        $semente = (string) ($this->jid ?: $this->telefone_e164 ?: $this->id);

        return $paleta[crc32($semente) % count($paleta)];
    }

    /** Endereco em uma linha, para a tabela e o painel. Null quando nao ha nada. */
    public function enderecoResumido(): ?string
    {
        $rua = trim(implode(', ', array_filter([
            $this->logradouro,
            $this->numero,
        ])));

        $cidade = trim(implode('/', array_filter([$this->cidade, $this->uf])));

        $linha = trim(implode(' — ', array_filter([$rua, $this->bairro, $cidade])));

        return $linha ?: null;
    }

    // Para onde a Evolution deve enviar. Grupo exige o JID completo; pessoa
    // aceita o telefone.
    public function destinoWhatsApp(): string
    {
        return $this->eGrupo()
            ? (string) $this->jid
            : (string) ($this->telefone_e164 ?: $this->jid);
    }

    /**
     * Acha o contato por qualquer uma das grafias do telefone.
     *
     * Procura pelas DUAS pontas — jid e telefone — porque as duas podem ter nascido de
     * provedores diferentes: o mesmo cliente e 554184919939 quando chega pela Meta e
     * 5541984919939 quando chega pela Evolution ou por planilha. Comparar so uma grafia
     * criava dois contatos da mesma pessoa, cada um com metade do historico.
     */
    public static function acharPorTelefone(?string $telefone): ?self
    {
        $formas = PhoneNumber::variantes($telefone);

        if ($formas === []) {
            return null;
        }

        $jids = array_map(fn (string $e164) => self::jidDoTelefone($e164), $formas);

        return self::query()
            ->where(fn ($q) => $q->whereIn('jid', $jids)->orWhereIn('telefone_e164', $formas))
            ->first();
    }

    /**
     * Acha por qualquer grafia, e cria se nao existir.
     *
     * @param  array<string, mixed>  $extra
     */
    public static function acharOuCriarPorTelefone(string $e164, array $extra = [], ?string $jid = null): self
    {
        $achado = self::acharPorTelefone($e164);

        if ($achado) {
            return $achado;
        }

        // createOrFirst e nao create: duas mensagens do mesmo contato no mesmo instante
        // batem no indice unico, e a corrida tem de terminar com quem venceu — nao com
        // erro na fila.
        return self::createOrFirst(
            ['jid' => $jid ?: self::jidDoTelefone($e164)],
            array_merge(['tipo' => self::PESSOA, 'telefone_e164' => $e164], $extra),
        );
    }

    /**
     * O telefone como um brasileiro disca, para mostrar e copiar.
     *
     * O valor GRAVADO continua sendo o que o provedor conhece: trocar o que esta no banco
     * mexeria no caminho de envio, e isso e uma decisao separada. Aqui e so exibicao — e
     * numero que o atendente nao consegue discar nao serve para exibir.
     */
    public function telefoneDiscavel(): ?string
    {
        return PhoneNumber::discavel($this->telefone_e164) ?: $this->telefone_e164;
    }

    public static function jidDoTelefone(string $e164): string
    {
        return Jid::dePessoa($e164);
    }
}
