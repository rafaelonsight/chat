<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatbotAction extends Model
{
    use BelongsToTenant;

    // O que existe hoje. A paleta cresce, mas cada tipo novo precisa de motor E
    // de tela — declarar aqui sem implementar seria prometer o que nao funciona.
    public const MENSAGEM = 'mensagem';

    public const MENU = 'menu';

    public const PERGUNTA = 'pergunta';

    public const ESPERAR = 'esperar';

    public const TRANSFERIR = 'transferir';

    public const CONCLUIR = 'concluir';

    public const CONDICIONAL = 'condicional';

    public const ETIQUETA = 'etiqueta';

    public const TIPOS = [
        self::MENSAGEM    => 'Enviar mensagem',
        self::MENU        => 'Enviar menu',
        self::PERGUNTA    => 'Enviar pergunta',
        self::ESPERAR     => 'Esperar alguns segundos',
        self::CONDICIONAL => 'Enviar condicional',
        self::ETIQUETA    => 'Adicionar/remover etiquetas',
        self::TRANSFERIR  => 'Transferir atendimento',
        self::CONCLUIR    => 'Concluir atendimento',
    ];

    /** Acoes que interrompem o passo esperando o cliente. */
    public const AGUARDAM_RESPOSTA = [self::MENU, self::PERGUNTA];

    /** Acoes que encerram o fluxo: nada roda depois delas. */
    public const ENCERRAM = [self::TRANSFERIR, self::CONCLUIR];

    protected $fillable = ['tenant_id', 'chatbot_id', 'step_id', 'ordem', 'tipo', 'config'];

    protected $casts = ['config' => 'array', 'ordem' => 'integer'];

    protected $attributes = ['ordem' => 0];

    public function step(): BelongsTo
    {
        return $this->belongsTo(ChatbotStep::class, 'step_id');
    }

    public function rotulo(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    public function cfg(string $chave, mixed $padrao = null): mixed
    {
        return data_get($this->config, $chave, $padrao);
    }

    public function aguardaResposta(): bool
    {
        return in_array($this->tipo, self::AGUARDAM_RESPOSTA, true);
    }

    public function encerra(): bool
    {
        return in_array($this->tipo, self::ENCERRAM, true);
    }

    /** Uma linha curta do que a acao faz, para o cartao no canvas. */
    public function resumo(): string
    {
        return match ($this->tipo) {
            self::MENSAGEM    => (string) $this->cfg('texto', ''),
            self::MENU        => (string) $this->cfg('texto', ''),
            self::PERGUNTA    => (string) $this->cfg('texto', ''),
            self::ESPERAR     => $this->cfg('segundos', 0).' segundos',
            self::CONDICIONAL => 'se '.$this->cfg('campo', '?').' '.$this->cfg('operador', '=').' '.$this->cfg('valor', ''),
            self::TRANSFERIR  => $this->step?->chatbot
                ? (Team::find($this->cfg('team_id'))?->nome ?? 'qualquer atendente')
                : '',
            self::CONCLUIR    => (string) $this->cfg('aviso', ''),
            self::ETIQUETA    => trim(
                (count($this->cfg('adicionar', [])) ? '+'.count($this->cfg('adicionar', [])).' etiqueta(s)' : '')
                .' '
                .(count($this->cfg('remover', [])) ? '-'.count($this->cfg('remover', [])) : '')
            ) ?: 'nenhuma escolhida',
            default           => '',
        };
    }

    /** Handles de saida que esta acao cria no cartao. */
    public function handles(): array
    {
        return match ($this->tipo) {
            self::MENU => collect($this->cfg('opcoes', []))
                ->map(fn ($o) => ChatbotEdge::opcao((string) ($o['gatilho'] ?? '')))
                ->all(),
            self::CONDICIONAL => [ChatbotEdge::SIM, ChatbotEdge::NAO],
            default => [],
        };
    }
}
