<?php

use App\Filament\Resources\Tickets\Pages\CreateTicket;
use App\Models\ClientAccount;
use App\Models\ClientProject;
use App\Models\ClientRequester;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketTask;
use App\Models\User;
use App\Support\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'CLISP', 'slug' => 'clisp-demandas']);
    TenantContext::set($this->tenant->id);

    $this->rafael = User::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Rafael',
        'email' => 'rafael@demandas.test',
        'password' => 'segredo123',
        'admin' => true,
    ]);

    $this->client = ClientAccount::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Provedor A',
        'type' => ClientAccount::DIRECT,
    ]);

    $this->actingAs($this->rafael);
});

function criarTicketDoTeste($context, array $overrides = []): Ticket
{
    return Ticket::create(array_merge([
        'tenant_id' => $context->tenant->id,
        'client_account_id' => $context->client->id,
        'title' => 'Falha no envio de mensagens',
        'description' => 'O canal recebe, mas não envia.',
        'category' => 'support',
    ], $overrides));
}

it('gera protocolo global e toda demanda nasce recebida', function () {
    $first = criarTicketDoTeste($this, ['status' => Ticket::COMPLETED]);
    $second = criarTicketDoTeste($this);

    expect($first->protocol)->toMatch('/^VRT-\d{4}-\d{6}$/')
        ->and($first->status)->toBe(Ticket::RECEIVED)
        ->and((int) substr($second->protocol, -6))->toBe((int) substr($first->protocol, -6) + 1);
});

it('a sequência do protocolo é global entre contas', function () {
    $first = criarTicketDoTeste($this);

    $otherTenant = Tenant::create(['nome' => 'Outra', 'slug' => 'outra-demandas']);
    TenantContext::set($otherTenant->id);
    $otherClient = ClientAccount::create(['tenant_id' => $otherTenant->id, 'name' => 'Outro cliente']);
    $second = Ticket::create([
        'tenant_id' => $otherTenant->id,
        'client_account_id' => $otherClient->id,
        'title' => 'Outra demanda',
        'description' => 'Teste',
    ]);

    expect((int) substr($second->protocol, -6))->toBe((int) substr($first->protocol, -6) + 1);
});

it('o escopo global impede leitura de tickets de outro tenant', function () {
    criarTicketDoTeste($this);

    $otherTenant = Tenant::create(['nome' => 'Outra', 'slug' => 'isolamento-demandas']);
    TenantContext::set($otherTenant->id);

    expect(Ticket::count())->toBe(0);
});

it('cliente final só pode apontar para parceiro da mesma conta', function () {
    $partner = ClientAccount::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'CLISP Parceira',
        'type' => ClientAccount::PARTNER,
    ]);

    $endClient = ClientAccount::create([
        'tenant_id' => $this->tenant->id,
        'parent_id' => $partner->id,
        'name' => 'Provedor final',
        'type' => ClientAccount::END_CLIENT,
    ]);

    expect($endClient->parent->is($partner))->toBeTrue();
});

it('não aceita projeto ou solicitante de outro cliente', function () {
    $other = ClientAccount::create(['tenant_id' => $this->tenant->id, 'name' => 'Outro cliente']);
    $project = ClientProject::create([
        'tenant_id' => $this->tenant->id,
        'client_account_id' => $other->id,
        'name' => 'Implantação',
    ]);
    $requester = ClientRequester::create([
        'tenant_id' => $this->tenant->id,
        'client_account_id' => $other->id,
        'name' => 'Fernando',
    ]);

    expect(fn () => criarTicketDoTeste($this, ['client_project_id' => $project->id]))
        ->toThrow(DomainException::class)
        ->and(fn () => criarTicketDoTeste($this, ['requester_id' => $requester->id]))
        ->toThrow(DomainException::class);
});

it('registra criação, mudança e mensagens na auditoria', function () {
    $ticket = criarTicketDoTeste($this);
    $ticket->update(['status' => Ticket::IN_PROGRESS]);
    TicketMessage::create([
        'tenant_id' => $this->tenant->id,
        'ticket_id' => $ticket->id,
        'visibility' => 'partner',
        'body' => 'CLISP, precisamos confirmar o cenário.',
    ]);

    expect($ticket->events()->pluck('event')->all())
        ->toContain('created', 'updated', 'message_created');
});

it('tarefas e checklist ficam vinculados ao ticket', function () {
    $ticket = criarTicketDoTeste($this);
    $task = TicketTask::create([
        'tenant_id' => $this->tenant->id,
        'ticket_id' => $ticket->id,
        'responsible_id' => $this->rafael->id,
        'title' => 'Validar canal',
    ]);
    $task->checklist()->create([
        'tenant_id' => $this->tenant->id,
        'text' => 'Conferir conexão',
        'position' => 1,
    ]);

    expect($ticket->tasks)->toHaveCount(1)
        ->and($task->checklist)->toHaveCount(1);
});

it('aceita etiqueta própria de demanda', function () {
    $tag = Tag::create([
        'tenant_id' => $this->tenant->id,
        'nome' => 'Urgente no suporte',
        'cor' => 'vermelho',
        'contexto' => Tag::TICKET,
    ]);
    $ticket = criarTicketDoTeste($this);
    $ticket->tags()->attach($tag->id, ['applied_by' => $this->rafael->id, 'created_at' => now()]);

    expect($ticket->tags)->toHaveCount(1)
        ->and($ticket->tags->first()->contexto)->toBe(Tag::TICKET);
});

it('ticket não pode ser apagado', function () {
    $ticket = criarTicketDoTeste($this);

    expect(fn () => $ticket->delete())->toThrow(DomainException::class)
        ->and(Ticket::count())->toBe(1);
});

it('a tela interna cria demanda recebida com protocolo', function () {
    Livewire::actingAs($this->rafael)
        ->test(CreateTicket::class)
        ->fillForm([
            'client_account_id' => $this->client->id,
            'title' => 'Solicitação interna',
            'description' => 'Registrada por telefone.',
            'category' => 'support',
            'status' => Ticket::COMPLETED,
            'priority' => 'normal',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Ticket::first()->status)->toBe(Ticket::RECEIVED)
        ->and(Ticket::first()->protocol)->toStartWith('VRT-');
});
