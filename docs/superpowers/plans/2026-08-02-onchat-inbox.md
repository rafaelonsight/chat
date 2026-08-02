# OnChat — Inbox (fatia vertical) — Plano de Implementação

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: use `superpowers:subagent-driven-development` (recomendado) ou `superpowers:executing-plans` para implementar tarefa a tarefa. Os passos usam checkbox (`- [ ]`) para acompanhamento.

**Objetivo:** conectar um número de WhatsApp por QR Code, receber mensagem na tela em tempo real e responder por ali.

**Arquitetura:** webhook da Evolution grava payload cru e responde 200 na hora; job na fila normaliza e persiste; broadcast pelo Reverb atualiza a tela. Envio é o caminho inverso, com status atualizado por webhook. Multi-tenant por banco único com escopo global.

**Stack:** Laravel 13.23, Livewire 4.3, Filament 5.7, Horizon 5.48, Reverb 1.11, PostgreSQL 16, Redis, Evolution API 2.3.7.

## Restrições globais

- Trabalho direto em `/opt/onchat/app` no servidor, como usuário `onchat`. Todo comando: `su - onchat -c "cd /opt/onchat/app && <cmd>"`.
- **Nunca** rodar `migrate:fresh`, `migrate:reset` ou `db:wipe` — a base `onchat` divide o servidor PostgreSQL com a base `onsight`, que é produção de outro sistema.
- Testes usam a base `onchat_test` (criada na Tarefa 1), nunca a base de desenvolvimento.
- Evolution API: `http://127.0.0.1:8081`, chave em `EVOLUTION_API_KEY` no `.env`. Testes **nunca** chamam a API real — sempre `Http::fake()`.
- Colunas de domínio em português (`corpo`, `direcao`, `enviada_em`), seguindo o que o spec definiu.
- Telefones sempre em E.164 com `+` (`+5511999999999`).
- Commit ao fim de cada tarefa. Mensagens em português, imperativo.
- Após mexer em provider, config ou `.env`: `php artisan config:clear` e `sudo systemctl restart onchat-horizon` (jobs rodam com o código carregado na memória do worker).

---

## Estrutura de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `app/Support/TenantContext.php` | Guarda o tenant atual fora do ciclo HTTP (jobs) |
| `app/Models/Concerns/BelongsToTenant.php` | Escopo global + preenchimento de `tenant_id` |
| `app/Support/PhoneNumber.php` | Normalização E.164 |
| `app/Models/{Tenant,Channel,Contact,Conversation,Message,WebhookEvent}.php` | Modelos de domínio |
| `app/Services/EvolutionService.php` | Único ponto que fala HTTP com a Evolution |
| `app/Http/Controllers/EvolutionWebhookController.php` | Recebe, registra e enfileira |
| `app/Jobs/ProcessEvolutionWebhook.php` | Normaliza e persiste o que chegou |
| `app/Jobs/SendTextMessage.php` | Envia e atualiza status |
| `app/Events/MessageStored.php` | Broadcast para lista e janela |
| `app/Livewire/Inbox/{ConversationList,ConversationWindow,MessageComposer}.php` | Tela |
| `app/Filament/Resources/ChannelResource.php` | CRUD de canal + QR |

---

## Tarefa 1: Fundação de tenancy

**Arquivos:**
- Criar: `database/migrations/*_create_tenants_table.php`, `database/migrations/*_add_tenant_id_to_users_table.php`
- Criar: `app/Models/Tenant.php`, `app/Support/TenantContext.php`, `app/Models/Concerns/BelongsToTenant.php`
- Modificar: `app/Models/User.php`, `phpunit.xml`
- Teste: `tests/Feature/TenancyTest.php`

**Interfaces produzidas:**
- `TenantContext::set(?int): void`, `TenantContext::get(): ?int`, `TenantContext::forget(): void`
- `trait BelongsToTenant` — adiciona escopo global `tenant` e relação `tenant()`
- `Tenant` com `nome`, `slug`, e `users(): HasMany`

- [ ] **Passo 1: criar a base de testes**

```bash
su - postgres -c "psql -tAc \"select 1 from pg_database where datname='onchat_test'\"" | grep -q 1 \
  || su - postgres -c "createdb -O onchat onchat_test"
```

- [ ] **Passo 2: apontar o phpunit para ela**

Em `phpunit.xml`, dentro de `<php>`, substituir as linhas de `DB_CONNECTION`/`DB_DATABASE` por:

```xml
<env name="DB_CONNECTION" value="pgsql"/>
<env name="DB_DATABASE" value="onchat_test"/>
<env name="QUEUE_CONNECTION" value="sync"/>
<env name="CACHE_STORE" value="array"/>
<env name="SESSION_DRIVER" value="array"/>
<env name="BROADCAST_CONNECTION" value="null"/>
```

- [ ] **Passo 3: escrever o teste que falha**

`tests/Feature/TenancyTest.php`:

```php
<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;

it('preenche tenant_id automaticamente na criacao', function () {
    $tenant = Tenant::create(['nome' => 'Acme', 'slug' => 'acme']);
    TenantContext::set($tenant->id);

    $user = User::create([
        'name' => 'Fulano', 'email' => 'f@acme.test', 'password' => 'segredo123',
    ]);

    expect($user->tenant_id)->toBe($tenant->id);
});

it('nao deixa um tenant enxergar dado do outro', function () {
    $a = Tenant::create(['nome' => 'A', 'slug' => 'a']);
    $b = Tenant::create(['nome' => 'B', 'slug' => 'b']);

    TenantContext::set($a->id);
    User::create(['name' => 'De A', 'email' => 'a@t.test', 'password' => 'segredo123']);

    TenantContext::set($b->id);
    User::create(['name' => 'De B', 'email' => 'b@t.test', 'password' => 'segredo123']);

    expect(User::count())->toBe(1)
        ->and(User::first()->name)->toBe('De B');

    TenantContext::set($a->id);
    expect(User::first()->name)->toBe('De A');
});
```

- [ ] **Passo 4: rodar e confirmar que falha**

Run: `su - onchat -c "cd /opt/onchat/app && php artisan test --filter=TenancyTest"`
Esperado: FAIL — `Class "App\Models\Tenant" not found`

- [ ] **Passo 5: migrations**

```bash
su - onchat -c "cd /opt/onchat/app && php artisan make:migration create_tenants_table"
su - onchat -c "cd /opt/onchat/app && php artisan make:migration add_tenant_id_to_users_table"
```

`create_tenants_table`:

```php
Schema::create('tenants', function (Blueprint $table) {
    $table->id();
    $table->string('nome');
    $table->string('slug')->unique();
    $table->timestamps();
});
```

`add_tenant_id_to_users_table`:

```php
Schema::table('users', function (Blueprint $table) {
    $table->foreignId('tenant_id')->nullable()->after('id')
        ->constrained()->cascadeOnDelete();
});
```

- [ ] **Passo 6: TenantContext**

`app/Support/TenantContext.php`:

```php
<?php

namespace App\Support;

// Guarda o tenant atual. Em requisicao HTTP ele vem do usuario logado; em job
// de fila nao existe usuario, entao o job seta explicitamente antes de operar.
class TenantContext
{
    private const KEY = 'onchat.tenant_id';

    public static function set(?int $id): void
    {
        app()->instance(self::KEY, $id);
    }

    public static function get(): ?int
    {
        if (app()->bound(self::KEY)) {
            return app(self::KEY);
        }

        return auth()->user()?->tenant_id;
    }

    public static function forget(): void
    {
        app()->forgetInstance(self::KEY);
    }

    public static function runAs(int $id, callable $fn): mixed
    {
        $anterior = app()->bound(self::KEY) ? app(self::KEY) : null;
        self::set($id);

        try {
            return $fn();
        } finally {
            self::set($anterior);
        }
    }
}
```

- [ ] **Passo 7: a trait**

`app/Models/Concerns/BelongsToTenant.php`:

```php
<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            if ($id = TenantContext::get()) {
                $query->where($query->getModel()->getTable().'.tenant_id', $id);
            }
        });

        static::creating(function ($model) {
            if (empty($model->tenant_id) && $id = TenantContext::get()) {
                $model->tenant_id = $id;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
```

- [ ] **Passo 8: modelos**

`app/Models/Tenant.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = ['nome', 'slug'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
```

Em `app/Models/User.php`: adicionar `use App\Models\Concerns\BelongsToTenant;` no topo, `use BelongsToTenant;` no corpo da classe, e incluir `'tenant_id'` no `$fillable`.

- [ ] **Passo 9: rodar migrations e testes**

```bash
su - onchat -c "cd /opt/onchat/app && php artisan migrate --force"
su - onchat -c "cd /opt/onchat/app && php artisan test --filter=TenancyTest"
```
Esperado: 2 passed.

- [ ] **Passo 10: criar o tenant real e vincular o usuário**

```bash
su - onchat -c "cd /opt/onchat/app && php artisan tinker --execute=\"
\\\$t = App\\Models\\Tenant::firstOrCreate(['slug'=>'onsight'],['nome'=>'OnSight']);
App\\Models\\User::withoutGlobalScope('tenant')->whereNull('tenant_id')->update(['tenant_id'=>\\\$t->id]);
echo 'tenant '.\\\$t->id.' vinculado';
\""
```

- [ ] **Passo 11: commit**

```bash
su - onchat -c "cd /opt/onchat/app && git add -A && git commit -m 'Adiciona fundacao de tenancy com escopo global'"
```

---

## Tarefa 2: Canais e o serviço da Evolution

**Arquivos:**
- Criar: `database/migrations/*_create_channels_table.php`, `app/Models/Channel.php`, `app/Services/EvolutionService.php`
- Modificar: `config/services.php`, `app/Providers/AppServiceProvider.php`
- Teste: `tests/Feature/EvolutionServiceTest.php`

**Interfaces consumidas:** `BelongsToTenant`, `TenantContext` (Tarefa 1).

**Interfaces produzidas:**
- `Channel` com `nome`, `instance_name`, `webhook_secret`, `telefone_e164`, `status`, `conectado_em`, `ultimo_erro`; método `webhookUrl(): string`
- `EvolutionService::createInstance(string $instance, string $webhookUrl): array`
- `EvolutionService::connect(string $instance): array`
- `EvolutionService::connectionState(string $instance): array`
- `EvolutionService::sendText(string $instance, string $to, string $text): array`
- `EvolutionService::deleteInstance(string $instance): array`

- [ ] **Passo 1: teste que falha**

`tests/Feature/EvolutionServiceTest.php`:

```php
<?php

use App\Services\EvolutionService;
use Illuminate\Support\Facades\Http;

it('envia texto com a apikey no cabecalho', function () {
    Http::fake([
        '*/message/sendText/*' => Http::response(['key' => ['id' => 'ABC123']], 201),
    ]);

    $svc = new EvolutionService('http://127.0.0.1:8081', 'chave-de-teste');
    $r = $svc->sendText('t1-c1', '+5511999999999', 'ola');

    expect($r['key']['id'])->toBe('ABC123');

    Http::assertSent(fn ($req) => $req->hasHeader('apikey', 'chave-de-teste')
        && $req['number'] === '+5511999999999'
        && $req['text'] === 'ola');
});

it('cria instancia assinando os eventos necessarios', function () {
    Http::fake(['*/instance/create' => Http::response(['instance' => ['instanceName' => 't1-c1']], 201)]);

    (new EvolutionService('http://127.0.0.1:8081', 'k'))
        ->createInstance('t1-c1', 'https://chat.onsight.com.br/webhooks/evolution/1/seg');

    Http::assertSent(function ($req) {
        $eventos = $req['webhook']['events'];
        return in_array('MESSAGES_UPSERT', $eventos)
            && in_array('MESSAGES_UPDATE', $eventos)
            && in_array('CONNECTION_UPDATE', $eventos);
    });
});
```

- [ ] **Passo 2: rodar e confirmar falha**

Run: `su - onchat -c "cd /opt/onchat/app && php artisan test --filter=EvolutionServiceTest"`
Esperado: FAIL — `Class "App\Services\EvolutionService" not found`

- [ ] **Passo 3: o serviço**

`app/Services/EvolutionService.php`:

```php
<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

// Unico ponto do sistema que fala HTTP com a Evolution. Quando a Cloud API
// entrar, e daqui que a interface de driver vai ser extraida.
class EvolutionService
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {}

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['apikey' => $this->apiKey])
            ->acceptJson()
            ->timeout(20);
    }

    public function createInstance(string $instance, string $webhookUrl): array
    {
        return $this->client()->post('/instance/create', [
            'instanceName' => $instance,
            'integration'  => 'WHATSAPP-BAILEYS',
            'qrcode'       => true,
            'webhook'      => [
                'url'      => $webhookUrl,
                'byEvents' => false,
                'base64'   => true,
                'events'   => [
                    'MESSAGES_UPSERT',
                    'MESSAGES_UPDATE',
                    'CONNECTION_UPDATE',
                    'SEND_MESSAGE',
                ],
            ],
        ])->throw()->json();
    }

    public function connect(string $instance): array
    {
        return $this->client()->get("/instance/connect/{$instance}")->throw()->json();
    }

    public function connectionState(string $instance): array
    {
        return $this->client()->get("/instance/connectionState/{$instance}")->throw()->json();
    }

    public function deleteInstance(string $instance): array
    {
        return $this->client()->delete("/instance/delete/{$instance}")->json() ?? [];
    }

    public function sendText(string $instance, string $to, string $text): array
    {
        return $this->client()->post("/message/sendText/{$instance}", [
            'number' => $to,
            'text'   => $text,
        ])->throw()->json();
    }
}
```

- [ ] **Passo 4: registrar no container**

Em `config/services.php`, acrescentar:

```php
'evolution' => [
    'url' => env('EVOLUTION_BASE_URL', 'http://127.0.0.1:8081'),
    'key' => env('EVOLUTION_API_KEY'),
],
```

Em `app/Providers/AppServiceProvider.php`, dentro de `register()`:

```php
$this->app->singleton(\App\Services\EvolutionService::class, fn () => new \App\Services\EvolutionService(
    (string) config('services.evolution.url'),
    (string) config('services.evolution.key'),
));
```

- [ ] **Passo 5: rodar testes**

Run: `su - onchat -c "cd /opt/onchat/app && php artisan test --filter=EvolutionServiceTest"`
Esperado: 2 passed.

- [ ] **Passo 6: migration de canais**

```php
Schema::create('channels', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->string('tipo')->default('evolution');
    $table->string('nome');
    // nullable: so pode ser montado depois que o id existe (ver model)
    $table->string('instance_name')->nullable()->unique();
    $table->string('webhook_secret', 64);
    $table->string('telefone_e164')->nullable();
    $table->string('status')->default('desconectado');
    $table->timestamp('conectado_em')->nullable();
    $table->text('ultimo_erro')->nullable();
    $table->timestamps();

    $table->index(['tenant_id', 'status']);
});
```

- [ ] **Passo 7: o model**

`app/Models/Channel.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Channel extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'tipo', 'nome', 'instance_name',
        'webhook_secret', 'telefone_e164', 'status', 'conectado_em', 'ultimo_erro',
    ];

    protected $casts = ['conectado_em' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (Channel $c) {
            $c->webhook_secret ??= Str::random(48);
        });

        // instance_name so pode ser montado depois do id existir
        static::created(function (Channel $c) {
            if (! $c->instance_name) {
                $c->forceFill(['instance_name' => "t{$c->tenant_id}-c{$c->id}"])->saveQuietly();
            }
        });
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function webhookUrl(): string
    {
        return url("/webhooks/evolution/{$this->id}/{$this->webhook_secret}");
    }

    public function conectado(): bool
    {
        return $this->status === 'open';
    }
}
```

- [ ] **Passo 8: migrar e commitar**

```bash
su - onchat -c "cd /opt/onchat/app && php artisan migrate --force && php artisan test"
su - onchat -c "cd /opt/onchat/app && git add -A && git commit -m 'Adiciona canais e o servico da Evolution'"
```

---

## Tarefa 3: Domínio de conversa

**Arquivos:**
- Criar: migrations de `contacts`, `conversations`, `messages`, `webhook_events`
- Criar: `app/Models/{Contact,Conversation,Message,WebhookEvent}.php`, `app/Support/PhoneNumber.php`
- Teste: `tests/Unit/PhoneNumberTest.php`

**Interfaces produzidas:**
- `PhoneNumber::toE164(?string $bruto): ?string` — devolve `+5511999999999` ou `null`
- `Message::STATUS_*` constantes: `queued`, `sent`, `delivered`, `read`, `failed`

- [ ] **Passo 1: teste do normalizador**

`tests/Unit/PhoneNumberTest.php`:

```php
<?php

use App\Support\PhoneNumber;

it('normaliza numeros brasileiros para E.164', function (string $entrada, ?string $esperado) {
    expect(PhoneNumber::toE164($entrada))->toBe($esperado);
})->with([
    ['5511999998888',            '+5511999998888'],
    ['11999998888',              '+5511999998888'],
    ['(11) 99999-8888',          '+5511999998888'],
    ['+55 11 99999-8888',        '+5511999998888'],
    ['5511999998888@s.whatsapp.net', '+5511999998888'],
    ['551133334444',             '+551133334444'],
    ['123',                      null],
    ['',                         null],
]);
```

- [ ] **Passo 2: rodar e confirmar falha**

Run: `su - onchat -c "cd /opt/onchat/app && php artisan test --filter=PhoneNumberTest"`
Esperado: FAIL — classe não encontrada.

- [ ] **Passo 3: implementar**

`app/Support/PhoneNumber.php`:

```php
<?php

namespace App\Support;

class PhoneNumber
{
    // A Evolution entrega o remetente como JID (5511999998888@s.whatsapp.net).
    // Guardamos sempre E.164 com "+" para nunca depender do formato do gateway.
    public static function toE164(?string $bruto, string $ddi = '55'): ?string
    {
        if ($bruto === null || $bruto === '') {
            return null;
        }

        $digitos = preg_replace('/\D+/', '', explode('@', $bruto)[0]) ?? '';

        if ($digitos === '') {
            return null;
        }

        // Numero sem DDI: 10 digitos (fixo) ou 11 (movel com o 9)
        if (strlen($digitos) === 10 || strlen($digitos) === 11) {
            $digitos = $ddi.$digitos;
        }

        // BR com DDI: 55 + DDD(2) + 8 ou 9 digitos
        if (strlen($digitos) < 12 || strlen($digitos) > 13) {
            return null;
        }

        return '+'.$digitos;
    }
}
```

- [ ] **Passo 4: rodar**

Run: `su - onchat -c "cd /opt/onchat/app && php artisan test --filter=PhoneNumberTest"`
Esperado: 8 passed.

- [ ] **Passo 5: migrations**

```php
// contacts
Schema::create('contacts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->string('telefone_e164');
    $table->string('nome')->nullable();
    $table->timestamps();

    $table->unique(['tenant_id', 'telefone_e164']);
});

// conversations
Schema::create('conversations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
    $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
    $table->timestamp('ultima_msg_em')->nullable();
    $table->unsignedInteger('nao_lidas')->default(0);
    $table->timestamps();

    $table->unique(['channel_id', 'contact_id']);
    $table->index(['tenant_id', 'ultima_msg_em']);
});

// messages
Schema::create('messages', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
    $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
    $table->string('direcao', 3);            // in | out
    $table->string('tipo')->default('text');
    $table->text('corpo')->nullable();
    $table->string('external_id')->nullable();
    $table->string('status')->default('queued');
    $table->text('erro')->nullable();
    $table->timestamp('enviada_em')->nullable();
    $table->timestamps();

    $table->unique(['channel_id', 'external_id']);
    $table->index(['conversation_id', 'id']);
});

// webhook_events
Schema::create('webhook_events', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
    $table->string('evento')->nullable();
    $table->jsonb('payload');
    $table->timestamp('recebido_em');
    $table->timestamp('processado_em')->nullable();
    $table->text('erro')->nullable();

    $table->index(['channel_id', 'processado_em']);
});
```

Nota: o `unique(channel_id, external_id)` no PostgreSQL permite múltiplos `NULL`, então mensagens de saída ainda sem `external_id` não colidem entre si. É exatamente o comportamento desejado.

- [ ] **Passo 6: models**

`app/Models/Contact.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'telefone_e164', 'nome'];

    public function nomeExibicao(): string
    {
        return $this->nome ?: $this->telefone_e164;
    }
}
```

`app/Models/Conversation.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'channel_id', 'contact_id', 'ultima_msg_em', 'nao_lidas'];

    protected $casts = ['ultima_msg_em' => 'datetime'];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
```

`app/Models/Message.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use BelongsToTenant;

    public const STATUS_QUEUED    = 'queued';
    public const STATUS_SENT      = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_READ      = 'read';
    public const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'tenant_id', 'conversation_id', 'channel_id', 'direcao',
        'tipo', 'corpo', 'external_id', 'status', 'erro', 'enviada_em',
    ];

    protected $casts = ['enviada_em' => 'datetime'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function entrada(): bool
    {
        return $this->direcao === 'in';
    }
}
```

`app/Models/WebhookEvent.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Sem BelongsToTenant de proposito: o webhook chega sem usuario autenticado e
// precisa ser gravado antes de sabermos a que tenant pertence.
class WebhookEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'channel_id', 'evento', 'payload',
        'recebido_em', 'processado_em', 'erro',
    ];

    protected $casts = [
        'payload'       => 'array',
        'recebido_em'   => 'datetime',
        'processado_em' => 'datetime',
    ];
}
```

- [ ] **Passo 7: migrar, testar e commitar**

```bash
su - onchat -c "cd /opt/onchat/app && php artisan migrate --force && php artisan test"
su - onchat -c "cd /opt/onchat/app && git add -A && git commit -m 'Adiciona dominio de conversa e normalizacao de telefone'"
```

---

## Tarefa 4: Recebimento de mensagens

**Arquivos:**
- Criar: `app/Http/Controllers/EvolutionWebhookController.php`, `app/Jobs/ProcessEvolutionWebhook.php`, `app/Events/MessageStored.php`
- Modificar: `routes/web.php`, `bootstrap/app.php`, `routes/channels.php`
- Teste: `tests/Feature/WebhookRecebimentoTest.php`

**Interfaces consumidas:** `Channel::webhookUrl()`, `PhoneNumber::toE164()`, models da Tarefa 3.

**Interfaces produzidas:**
- Rota nomeada `webhooks.evolution` em `/webhooks/evolution/{channel}/{secret}`
- `ProcessEvolutionWebhook::dispatch(int $webhookEventId)`
- `MessageStored` — broadcast em `conversation.{id}` e `tenant.{id}.conversations`

- [ ] **Passo 1: teste que falha**

`tests/Feature/WebhookRecebimentoTest.php`:

```php
<?php

use App\Models\{Channel, Conversation, Message, Tenant};
use App\Support\TenantContext;

function payloadRecebida(string $de, string $texto, string $id): array
{
    return [
        'event' => 'messages.upsert',
        'data'  => [
            'key' => ['remoteJid' => $de.'@s.whatsapp.net', 'fromMe' => false, 'id' => $id],
            'pushName' => 'Cliente Teste',
            'message'  => ['conversation' => $texto],
            'messageTimestamp' => 1785648000,
        ],
    ];
}

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 't']);
    TenantContext::set($this->tenant->id);
    $this->channel = Channel::create(['nome' => 'Principal', 'tenant_id' => $this->tenant->id]);
    $this->channel->refresh();
});

it('cria contato, conversa e mensagem a partir do webhook', function () {
    $r = $this->postJson(
        "/webhooks/evolution/{$this->channel->id}/{$this->channel->webhook_secret}",
        payloadRecebida('5511999998888', 'ola mundo', 'MSG1')
    );

    $r->assertOk();

    expect(Message::count())->toBe(1);
    $m = Message::first();
    expect($m->corpo)->toBe('ola mundo')
        ->and($m->direcao)->toBe('in')
        ->and($m->external_id)->toBe('MSG1')
        ->and($m->conversation->contact->telefone_e164)->toBe('+5511999998888')
        ->and($m->conversation->nao_lidas)->toBe(1);
});

it('nao duplica quando o webhook e reentregue', function () {
    $url = "/webhooks/evolution/{$this->channel->id}/{$this->channel->webhook_secret}";
    $payload = payloadRecebida('5511999998888', 'ola', 'MSG1');

    $this->postJson($url, $payload)->assertOk();
    $this->postJson($url, $payload)->assertOk();

    expect(Message::count())->toBe(1)
        ->and(Conversation::count())->toBe(1);
});

it('recusa segredo invalido', function () {
    $this->postJson(
        "/webhooks/evolution/{$this->channel->id}/segredo-errado",
        payloadRecebida('5511999998888', 'ola', 'X')
    )->assertNotFound();

    expect(Message::count())->toBe(0);
});

it('atualiza status pelo evento de update', function () {
    $url = "/webhooks/evolution/{$this->channel->id}/{$this->channel->webhook_secret}";
    $this->postJson($url, payloadRecebida('5511999998888', 'ola', 'MSG1'))->assertOk();

    $this->postJson($url, [
        'event' => 'messages.update',
        'data'  => ['keyId' => 'MSG1', 'status' => 'READ'],
    ])->assertOk();

    expect(Message::first()->status)->toBe(Message::STATUS_READ);
});
```

- [ ] **Passo 2: rodar e confirmar falha**

Run: `su - onchat -c "cd /opt/onchat/app && php artisan test --filter=WebhookRecebimentoTest"`
Esperado: FAIL — 404 na rota.

- [ ] **Passo 3: liberar CSRF no webhook**

Em `bootstrap/app.php`, dentro de `->withMiddleware(function (Middleware $middleware) {`:

```php
$middleware->validateCsrfTokens(except: ['webhooks/*']);
```

- [ ] **Passo 4: rota**

Em `routes/web.php`:

```php
use App\Http\Controllers\EvolutionWebhookController;

Route::post('/webhooks/evolution/{channel}/{secret}', EvolutionWebhookController::class)
    ->name('webhooks.evolution');
```

- [ ] **Passo 5: controller**

`app/Http/Controllers/EvolutionWebhookController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessEvolutionWebhook;
use App\Models\Channel;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Grava o payload cru e devolve 200 na hora. Processar dentro do request faria
// o gateway tomar timeout e reentregar em cascata.
class EvolutionWebhookController extends Controller
{
    public function __invoke(Request $request, string $channel, string $secret): JsonResponse
    {
        $canal = Channel::withoutGlobalScope('tenant')->find($channel);

        abort_unless($canal && hash_equals($canal->webhook_secret, $secret), 404);

        $evento = WebhookEvent::create([
            'tenant_id'   => $canal->tenant_id,
            'channel_id'  => $canal->id,
            'evento'      => $request->input('event'),
            'payload'     => $request->all(),
            'recebido_em' => now(),
        ]);

        ProcessEvolutionWebhook::dispatch($evento->id);

        return response()->json(['ok' => true]);
    }
}
```

- [ ] **Passo 6: evento de broadcast**

`app/Events/MessageStored.php`:

```php
<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageStored implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.'.$this->message->conversation_id),
            new PrivateChannel('tenant.'.$this->message->tenant_id.'.conversations'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.stored';
    }

    public function broadcastWith(): array
    {
        return [
            'id'              => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'direcao'         => $this->message->direcao,
            'corpo'           => $this->message->corpo,
            'status'          => $this->message->status,
        ];
    }
}
```

- [ ] **Passo 7: o job**

`app/Jobs/ProcessEvolutionWebhook.php`:

```php
<?php

namespace App\Jobs;

use App\Events\MessageStored;
use App\Models\{Channel, Contact, Conversation, Message, WebhookEvent};
use App\Support\{PhoneNumber, TenantContext};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ProcessEvolutionWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [5, 15, 60];

    public function __construct(public int $webhookEventId) {}

    public function handle(): void
    {
        $evento = WebhookEvent::find($this->webhookEventId);

        if (! $evento || $evento->processado_em) {
            return;
        }

        $canal = Channel::withoutGlobalScope('tenant')->find($evento->channel_id);

        if (! $canal) {
            $evento->update(['processado_em' => now(), 'erro' => 'canal inexistente']);

            return;
        }

        TenantContext::runAs($canal->tenant_id, function () use ($evento, $canal) {
            try {
                match (strtolower((string) $evento->evento)) {
                    'messages.upsert'   => $this->mensagemRecebida($canal, $evento->payload),
                    'messages.update'   => $this->statusAtualizado($canal, $evento->payload),
                    'connection.update' => $this->conexaoAtualizada($canal, $evento->payload),
                    default             => null,
                };
                $evento->update(['processado_em' => now(), 'erro' => null]);
            } catch (\Throwable $e) {
                // Registra e encerra: payload inesperado nao pode derrubar a fila.
                $evento->update(['processado_em' => now(), 'erro' => $e->getMessage()]);
            }
        });
    }

    private function mensagemRecebida(Channel $canal, array $payload): void
    {
        $data = Arr::get($payload, 'data', []);

        if (Arr::get($data, 'key.fromMe')) {
            return; // eco do que nos mesmos enviamos
        }

        $telefone = PhoneNumber::toE164(Arr::get($data, 'key.remoteJid'));
        $externalId = Arr::get($data, 'key.id');

        if (! $telefone || ! $externalId) {
            throw new \RuntimeException('remetente ou id ausente no payload');
        }

        $texto = Arr::get($data, 'message.conversation')
            ?? Arr::get($data, 'message.extendedTextMessage.text');

        if ($texto === null) {
            return; // nesta fatia so tratamos texto
        }

        DB::transaction(function () use ($canal, $telefone, $externalId, $texto, $data) {
            $contato = Contact::firstOrCreate(
                ['tenant_id' => $canal->tenant_id, 'telefone_e164' => $telefone],
                ['nome' => Arr::get($data, 'pushName')],
            );

            $conversa = Conversation::firstOrCreate([
                'channel_id' => $canal->id,
                'contact_id' => $contato->id,
            ], ['tenant_id' => $canal->tenant_id]);

            $mensagem = Message::updateOrCreate(
                ['channel_id' => $canal->id, 'external_id' => $externalId],
                [
                    'tenant_id'       => $canal->tenant_id,
                    'conversation_id' => $conversa->id,
                    'direcao'         => 'in',
                    'tipo'            => 'text',
                    'corpo'           => $texto,
                    'status'          => Message::STATUS_DELIVERED,
                    'enviada_em'      => now(),
                ],
            );

            if ($mensagem->wasRecentlyCreated) {
                $conversa->increment('nao_lidas');
                $conversa->update(['ultima_msg_em' => now()]);
                broadcast(new MessageStored($mensagem));
            }
        });
    }

    private function statusAtualizado(Channel $canal, array $payload): void
    {
        $externalId = Arr::get($payload, 'data.keyId') ?? Arr::get($payload, 'data.key.id');
        $status = strtoupper((string) Arr::get($payload, 'data.status'));

        if (! $externalId) {
            return;
        }

        $novo = match ($status) {
            'DELIVERY_ACK', 'DELIVERED' => Message::STATUS_DELIVERED,
            'READ', 'PLAYED'            => Message::STATUS_READ,
            'SERVER_ACK', 'SENT'        => Message::STATUS_SENT,
            'ERROR'                     => Message::STATUS_FAILED,
            default                     => null,
        };

        if (! $novo) {
            return;
        }

        $mensagem = Message::where('channel_id', $canal->id)
            ->where('external_id', $externalId)
            ->first();

        if ($mensagem) {
            $mensagem->update(['status' => $novo]);
            broadcast(new MessageStored($mensagem));
        }
    }

    private function conexaoAtualizada(Channel $canal, array $payload): void
    {
        $estado = Arr::get($payload, 'data.state') ?? Arr::get($payload, 'data.connection');

        $canal->forceFill([
            'status'       => $estado ?: 'desconhecido',
            'conectado_em' => $estado === 'open' ? now() : $canal->conectado_em,
        ])->saveQuietly();
    }
}
```

- [ ] **Passo 8: autorização dos canais do Reverb**

Em `routes/channels.php`:

```php
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('conversation.{conversationId}', function (User $user, int $conversationId) {
    return Conversation::withoutGlobalScope('tenant')
        ->whereKey($conversationId)
        ->where('tenant_id', $user->tenant_id)
        ->exists();
});

Broadcast::channel('tenant.{tenantId}.conversations', function (User $user, int $tenantId) {
    return (int) $user->tenant_id === $tenantId;
});
```

- [ ] **Passo 9: teste da autorização dos canais**

O spec trata isso como inegociável: sem ele, o tempo real vira o vazamento entre
tenants que o escopo global evitou no banco.

`tests/Feature/CanaisBroadcastTest.php`:

```php
<?php

use App\Models\{Channel, Contact, Conversation, Tenant, User};
use App\Support\TenantContext;

function tenantCom(string $slug): array
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($t->id);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'U', 'email' => "u@{$slug}.test", 'password' => 'segredo123']);
    $c = Channel::create(['tenant_id' => $t->id, 'nome' => 'C']);
    $c->refresh();
    $ct = Contact::create(['tenant_id' => $t->id, 'telefone_e164' => '+5511999998888']);
    $cv = Conversation::create(['tenant_id' => $t->id, 'channel_id' => $c->id, 'contact_id' => $ct->id]);

    return [$t, $u, $cv];
}

it('autoriza o dono da conversa e nega o de outro tenant', function () {
    [, $uA, $cvA] = tenantCom('aa');
    [, $uB] = tenantCom('bb');

    $autorizar = fn (User $u, int $id) => $this->actingAs($u)
        ->postJson('/broadcasting/auth', [
            'socket_id'    => '1234.5678',
            'channel_name' => 'private-conversation.'.$id,
        ]);

    $autorizar($uA, $cvA->id)->assertOk();
    $autorizar($uB, $cvA->id)->assertForbidden();
});

it('nega o canal de lista de outro tenant', function () {
    [$tA, $uA] = tenantCom('cc');
    [$tB] = tenantCom('dd');

    $this->actingAs($uA)->postJson('/broadcasting/auth', [
        'socket_id'    => '1234.5678',
        'channel_name' => 'private-tenant.'.$tB->id.'.conversations',
    ])->assertForbidden();

    $this->actingAs($uA)->postJson('/broadcasting/auth', [
        'socket_id'    => '1234.5678',
        'channel_name' => 'private-tenant.'.$tA->id.'.conversations',
    ])->assertOk();
});
```

Para a rota `/broadcasting/auth` existir, garantir que `bootstrap/app.php` tenha
`->withBroadcasting()` ou que `routes/channels.php` esteja registrado — o
`install:broadcasting` do Laravel já faz isso; confirmar antes de rodar.

- [ ] **Passo 10: rodar testes**

Run: `su - onchat -c "cd /opt/onchat/app && php artisan test"`
Esperado: tudo passando (o `QUEUE_CONNECTION=sync` do phpunit faz o job rodar no mesmo request).

- [ ] **Passo 11: commit**

```bash
su - onchat -c "cd /opt/onchat/app && git add -A && git commit -m 'Adiciona recebimento de mensagens por webhook'"
sudo systemctl restart onchat-horizon
```

---

## Tarefa 5: Envio de mensagens

**Arquivos:**
- Criar: `app/Jobs/SendTextMessage.php`
- Teste: `tests/Feature/EnvioTest.php`

**Interfaces consumidas:** `EvolutionService::sendText()`, `Message`, `MessageStored`.

**Interfaces produzidas:** `SendTextMessage::dispatch(int $messageId)`

- [ ] **Passo 1: teste que falha**

`tests/Feature/EnvioTest.php`:

```php
<?php

use App\Jobs\SendTextMessage;
use App\Models\{Channel, Contact, Conversation, Message, Tenant};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 't']);
    TenantContext::set($this->tenant->id);
    $this->channel = Channel::create(['nome' => 'C', 'tenant_id' => $this->tenant->id]);
    $this->channel->refresh();
    $this->contact = Contact::create(['telefone_e164' => '+5511999998888']);
    $this->conversation = Conversation::create([
        'channel_id' => $this->channel->id,
        'contact_id' => $this->contact->id,
    ]);
});

it('envia e marca como sent guardando o external_id', function () {
    Http::fake(['*/message/sendText/*' => Http::response(['key' => ['id' => 'OUT1']], 201)]);

    $m = Message::create([
        'conversation_id' => $this->conversation->id,
        'channel_id'      => $this->channel->id,
        'direcao'         => 'out',
        'corpo'           => 'oi',
        'status'          => Message::STATUS_QUEUED,
    ]);

    (new SendTextMessage($m->id))->handle(app(App\Services\EvolutionService::class));

    $m->refresh();
    expect($m->status)->toBe(Message::STATUS_SENT)
        ->and($m->external_id)->toBe('OUT1')
        ->and($m->enviada_em)->not->toBeNull();
});

it('marca como failed quando a Evolution devolve erro', function () {
    Http::fake(['*/message/sendText/*' => Http::response(['message' => 'instancia fora'], 500)]);

    $m = Message::create([
        'conversation_id' => $this->conversation->id,
        'channel_id'      => $this->channel->id,
        'direcao'         => 'out',
        'corpo'           => 'oi',
        'status'          => Message::STATUS_QUEUED,
    ]);

    try {
        (new SendTextMessage($m->id))->handle(app(App\Services\EvolutionService::class));
    } catch (\Throwable) {
        // a ultima tentativa relanca; aqui so queremos ver o estado
    }

    expect($m->refresh()->status)->toBe(Message::STATUS_FAILED)
        ->and($m->erro)->not->toBeNull();
});
```

- [ ] **Passo 2: rodar e confirmar falha**

Run: `su - onchat -c "cd /opt/onchat/app && php artisan test --filter=EnvioTest"`
Esperado: FAIL — classe `SendTextMessage` não existe.

- [ ] **Passo 3: o job**

`app/Jobs/SendTextMessage.php`:

```php
<?php

namespace App\Jobs;

use App\Events\MessageStored;
use App\Models\Message;
use App\Services\EvolutionService;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Arr;

class SendTextMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [5, 15, 60];

    public function __construct(public int $messageId) {}

    // Sem isto, duas mensagens seguidas da mesma conversa podem chegar trocadas.
    public function middleware(): array
    {
        $m = Message::withoutGlobalScope('tenant')->find($this->messageId);

        return [(new WithoutOverlapping('conversa:'.($m?->conversation_id ?? 0)))->releaseAfter(5)];
    }

    public function handle(EvolutionService $evolution): void
    {
        $mensagem = Message::withoutGlobalScope('tenant')->find($this->messageId);

        if (! $mensagem || $mensagem->status !== Message::STATUS_QUEUED) {
            return;
        }

        TenantContext::runAs($mensagem->tenant_id, function () use ($mensagem, $evolution) {
            $canal = $mensagem->conversation->channel;
            $destino = $mensagem->conversation->contact->telefone_e164;

            try {
                $r = $evolution->sendText($canal->instance_name, $destino, (string) $mensagem->corpo);

                $mensagem->update([
                    'external_id' => Arr::get($r, 'key.id'),
                    'status'      => Message::STATUS_SENT,
                    'enviada_em'  => now(),
                    'erro'        => null,
                ]);
            } catch (\Throwable $e) {
                $mensagem->update([
                    'status' => Message::STATUS_FAILED,
                    'erro'   => mb_substr($e->getMessage(), 0, 500),
                ]);

                throw $e; // deixa o Horizon registrar e tentar de novo
            } finally {
                broadcast(new MessageStored($mensagem->refresh()));
            }
        });
    }
}
```

- [ ] **Passo 4: rodar e commitar**

```bash
su - onchat -c "cd /opt/onchat/app && php artisan test"
su - onchat -c "cd /opt/onchat/app && git add -A && git commit -m 'Adiciona envio de mensagens com controle de status'"
sudo systemctl restart onchat-horizon
```

---

## Tarefa 6: A tela do inbox

**Arquivos:**
- Criar: `app/Livewire/Inbox/{ConversationList,ConversationWindow,MessageComposer}.php` e as views em `resources/views/livewire/inbox/`
- Criar: `resources/views/layouts/inbox.blade.php`, `resources/views/inbox.blade.php`
- Modificar: `routes/web.php`, `resources/js/echo.js`, `resources/js/app.js`
- Teste: `tests/Feature/InboxTest.php`

**Interfaces consumidas:** models e `MessageStored` das tarefas anteriores.

- [ ] **Passo 1: teste que falha**

`tests/Feature/InboxTest.php`:

```php
<?php

use App\Livewire\Inbox\ConversationList;
use App\Livewire\Inbox\MessageComposer;
use App\Jobs\SendTextMessage;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function cenario(): array
{
    $t = Tenant::create(['nome' => 'T', 'slug' => 't'.uniqid()]);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'U', 'email' => uniqid().'@t.test', 'password' => 'segredo123']);
    $c = Channel::create(['tenant_id' => $t->id, 'nome' => 'C']);
    $c->refresh();
    $ct = Contact::create(['tenant_id' => $t->id, 'telefone_e164' => '+5511999998888', 'nome' => 'Cliente']);
    $cv = Conversation::create(['tenant_id' => $t->id, 'channel_id' => $c->id, 'contact_id' => $ct->id, 'ultima_msg_em' => now()]);

    return [$t, $u, $c, $ct, $cv];
}

it('lista apenas conversas do proprio tenant', function () {
    [$tA, $uA] = cenario();
    [$tB] = cenario();

    Livewire::actingAs($uA)
        ->test(ConversationList::class)
        ->assertViewHas('conversas', fn ($c) => $c->count() === 1 && $c->first()->tenant_id === $tA->id);
});

it('enfileira o envio e mostra a mensagem na hora', function () {
    Queue::fake();
    [, $u, , , $cv] = cenario();

    Livewire::actingAs($u)
        ->test(MessageComposer::class, ['conversationId' => $cv->id])
        ->set('corpo', 'ola cliente')
        ->call('enviar');

    $m = Message::where('conversation_id', $cv->id)->first();

    expect($m->corpo)->toBe('ola cliente')
        ->and($m->direcao)->toBe('out')
        ->and($m->status)->toBe(Message::STATUS_QUEUED);

    Queue::assertPushed(SendTextMessage::class);
});

it('exige autenticacao no inbox', function () {
    $this->get('/inbox')->assertRedirect('/login');
});
```

- [ ] **Passo 2: rodar e confirmar falha**

Run: `su - onchat -c "cd /opt/onchat/app && php artisan test --filter=InboxTest"`
Esperado: FAIL — componentes inexistentes.

- [ ] **Passo 3: gerar os componentes**

```bash
su - onchat -c "cd /opt/onchat/app && php artisan make:livewire Inbox/ConversationList"
su - onchat -c "cd /opt/onchat/app && php artisan make:livewire Inbox/ConversationWindow"
su - onchat -c "cd /opt/onchat/app && php artisan make:livewire Inbox/MessageComposer"
```

- [ ] **Passo 4: ConversationList**

`app/Livewire/Inbox/ConversationList.php`:

```php
<?php

namespace App\Livewire\Inbox;

use App\Models\Conversation;
use Livewire\Attributes\On;
use Livewire\Component;

class ConversationList extends Component
{
    public ?int $selecionada = null;

    public function getListeners(): array
    {
        return [
            'echo-private:tenant.'.auth()->user()->tenant_id.'.conversations,.message.stored' => 'recarregar',
        ];
    }

    #[On('conversa-selecionada')]
    public function selecionar(int $id): void
    {
        $this->selecionada = $id;
        Conversation::whereKey($id)->update(['nao_lidas' => 0]);
        $this->dispatch('abrir-conversa', conversationId: $id)->to(ConversationWindow::class);
    }

    public function recarregar(): void
    {
        // o render ja refaz a consulta; este metodo existe para o echo ter alvo
    }

    public function render()
    {
        return view('livewire.inbox.conversation-list', [
            'conversas' => Conversation::with('contact')
                ->orderByDesc('ultima_msg_em')
                ->limit(50)
                ->get(),
        ]);
    }
}
```

- [ ] **Passo 5: ConversationWindow**

`app/Livewire/Inbox/ConversationWindow.php`:

```php
<?php

namespace App\Livewire\Inbox;

use App\Models\Conversation;
use App\Models\Message;
use Livewire\Attributes\On;
use Livewire\Component;

class ConversationWindow extends Component
{
    public ?int $conversationId = null;
    public int $limite = 30;

    #[On('abrir-conversa')]
    public function abrir(int $conversationId): void
    {
        $this->conversationId = $conversationId;
        $this->limite = 30;
    }

    public function getListeners(): array
    {
        return $this->conversationId
            ? ['echo-private:conversation.'.$this->conversationId.',.message.stored' => '$refresh']
            : [];
    }

    public function carregarMais(): void
    {
        $this->limite += 30;
    }

    public function render()
    {
        $conversa = $this->conversationId
            ? Conversation::with('contact')->find($this->conversationId)
            : null;

        $mensagens = $conversa
            ? $conversa->messages()->orderByDesc('id')->limit($this->limite)->get()->reverse()->values()
            : collect();

        return view('livewire.inbox.conversation-window', compact('conversa', 'mensagens'));
    }
}
```

- [ ] **Passo 6: MessageComposer**

`app/Livewire/Inbox/MessageComposer.php`:

```php
<?php

namespace App\Livewire\Inbox;

use App\Jobs\SendTextMessage;
use App\Models\Conversation;
use App\Models\Message;
use Livewire\Attributes\On;
use Livewire\Component;

class MessageComposer extends Component
{
    public ?int $conversationId = null;
    public string $corpo = '';

    #[On('abrir-conversa')]
    public function abrir(int $conversationId): void
    {
        $this->conversationId = $conversationId;
        $this->corpo = '';
    }

    public function enviar(): void
    {
        $this->validate(['corpo' => 'required|string|max:4000']);

        $conversa = Conversation::findOrFail($this->conversationId);

        $mensagem = Message::create([
            'conversation_id' => $conversa->id,
            'channel_id'      => $conversa->channel_id,
            'direcao'         => 'out',
            'tipo'            => 'text',
            'corpo'           => $this->corpo,
            'status'          => Message::STATUS_QUEUED,
        ]);

        $conversa->update(['ultima_msg_em' => now()]);

        SendTextMessage::dispatch($mensagem->id);

        $this->corpo = '';
        $this->dispatch('mensagem-enfileirada');
    }

    public function render()
    {
        return view('livewire.inbox.message-composer');
    }
}
```

- [ ] **Passo 7: views**

`resources/views/livewire/inbox/conversation-list.blade.php`:

```blade
<div class="w-80 shrink-0 border-r border-slate-200 overflow-y-auto">
    <div class="p-4 border-b border-slate-200 font-semibold text-slate-700">Conversas</div>

    @forelse ($conversas as $conversa)
        <button wire:key="conv-{{ $conversa->id }}"
                wire:click="selecionar({{ $conversa->id }})"
                class="w-full text-left px-4 py-3 border-b border-slate-100 hover:bg-slate-50
                       {{ $selecionada === $conversa->id ? 'bg-emerald-50' : '' }}">
            <div class="flex items-center justify-between gap-2">
                <span class="font-medium text-slate-800 truncate">
                    {{ $conversa->contact->nomeExibicao() }}
                </span>
                @if ($conversa->nao_lidas > 0)
                    <span class="shrink-0 rounded-full bg-emerald-600 px-2 py-0.5 text-xs text-white">
                        {{ $conversa->nao_lidas }}
                    </span>
                @endif
            </div>
            <div class="text-xs text-slate-500">
                {{ $conversa->ultima_msg_em?->diffForHumans() }}
            </div>
        </button>
    @empty
        <p class="p-4 text-sm text-slate-500">Nenhuma conversa ainda.</p>
    @endforelse
</div>
```

`resources/views/livewire/inbox/conversation-window.blade.php`:

```blade
<div class="flex-1 flex flex-col">
    @if ($conversa)
        <div class="border-b border-slate-200 px-4 py-3 font-semibold text-slate-700">
            {{ $conversa->contact->nomeExibicao() }}
            <span class="ml-2 text-xs font-normal text-slate-500">{{ $conversa->contact->telefone_e164 }}</span>
        </div>

        <div class="flex-1 overflow-y-auto p-4 space-y-2 bg-slate-50">
            @if ($mensagens->count() >= $limite)
                <button wire:click="carregarMais" class="mx-auto block text-xs text-slate-500 underline">
                    carregar mensagens anteriores
                </button>
            @endif

            @foreach ($mensagens as $m)
                <div wire:key="msg-{{ $m->id }}" class="flex {{ $m->entrada() ? 'justify-start' : 'justify-end' }}">
                    <div class="max-w-lg rounded-lg px-3 py-2 text-sm
                                {{ $m->entrada() ? 'bg-white border border-slate-200' : 'bg-emerald-600 text-white' }}">
                        <div class="whitespace-pre-wrap">{{ $m->corpo }}</div>
                        <div class="mt-1 text-[10px] opacity-70">
                            {{ $m->created_at->format('H:i') }}
                            @unless ($m->entrada()) · {{ $m->status }} @endunless
                        </div>
                        @if ($m->erro)
                            <div class="mt-1 text-[10px] text-red-200">{{ $m->erro }}</div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="flex-1 grid place-items-center text-slate-400">
            Selecione uma conversa
        </div>
    @endif
</div>
```

`resources/views/livewire/inbox/message-composer.blade.php`:

```blade
<div class="border-t border-slate-200 p-3">
    @if ($conversationId)
        <form wire:submit="enviar" class="flex gap-2">
            <input type="text" wire:model="corpo" autocomplete="off"
                   placeholder="Escreva uma mensagem"
                   class="flex-1 rounded border border-slate-300 px-3 py-2 text-sm">
            <button type="submit"
                    class="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white">
                Enviar
            </button>
        </form>
        @error('corpo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    @endif
</div>
```

`resources/views/inbox.blade.php`:

```blade
<x-layouts.inbox>
    <div class="flex h-screen bg-white">
        <livewire:inbox.conversation-list />
        <div class="flex flex-1 flex-col">
            <livewire:inbox.conversation-window />
            <livewire:inbox.message-composer />
        </div>
    </div>
</x-layouts.inbox>
```

`resources/views/components/layouts/inbox.blade.php`:

```blade
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>OnChat</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased">
    {{ $slot }}
</body>
</html>
```

- [ ] **Passo 8: Echo no front**

```bash
su - onchat -c "cd /opt/onchat/app && npm install --silent laravel-echo pusher-js"
```

Em `resources/js/app.js`, acrescentar:

```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
```

- [ ] **Passo 9: rota**

Em `routes/web.php`:

```php
Route::view('/inbox', 'inbox')->middleware('auth')->name('inbox');
```

- [ ] **Passo 10: build, testar e commitar**

```bash
su - onchat -c "cd /opt/onchat/app && npm run build && php artisan test"
su - onchat -c "cd /opt/onchat/app && git add -A && git commit -m 'Adiciona a tela do inbox em tempo real'"
```

---

## Tarefa 7: Conectar o número pelo QR Code

**Arquivos:**
- Criar: `app/Filament/Resources/Channels/ChannelResource.php` e páginas, `app/Livewire/ChannelQrCode.php`, `resources/views/livewire/channel-qr-code.blade.php`
- Teste: `tests/Feature/ChannelProvisionamentoTest.php`

- [ ] **Passo 1: teste que falha**

`tests/Feature/ChannelProvisionamentoTest.php`:

```php
<?php

use App\Models\{Channel, Tenant};
use App\Services\EvolutionService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;

it('provisiona a instancia na Evolution com a url secreta de webhook', function () {
    Http::fake([
        '*/instance/create'    => Http::response(['instance' => ['instanceName' => 'x']], 201),
        '*/instance/connect/*' => Http::response(['base64' => 'data:image/png;base64,AAA'], 200),
    ]);

    $t = Tenant::create(['nome' => 'T', 'slug' => 't']);
    TenantContext::set($t->id);

    $canal = Channel::create(['nome' => 'Principal']);
    $canal->refresh();

    app(EvolutionService::class)->createInstance($canal->instance_name, $canal->webhookUrl());

    expect($canal->instance_name)->toBe("t{$t->id}-c{$canal->id}")
        ->and(strlen($canal->webhook_secret))->toBe(48);

    Http::assertSent(fn ($r) => str_contains($r['webhook']['url'], $canal->webhook_secret));
});
```

- [ ] **Passo 2: rodar**

Run: `su - onchat -c "cd /opt/onchat/app && php artisan test --filter=ChannelProvisionamentoTest"`
Esperado: PASS (o model e o serviço já existem das tarefas 2). Se falhar, corrigir antes de seguir.

- [ ] **Passo 3: gerar o resource do Filament**

```bash
su - onchat -c "cd /opt/onchat/app && php artisan make:filament-resource Channel --generate --no-interaction"
```

- [ ] **Passo 4: provisionar ao criar**

No `CreateChannel` gerado (`app/Filament/Resources/Channels/Pages/CreateChannel.php`), acrescentar:

```php
use App\Services\EvolutionService;

protected function afterCreate(): void
{
    $canal = $this->record->refresh();

    try {
        app(EvolutionService::class)->createInstance($canal->instance_name, $canal->webhookUrl());
    } catch (\Throwable $e) {
        $canal->update(['ultimo_erro' => mb_substr($e->getMessage(), 0, 500)]);
    }
}
```

- [ ] **Passo 5: componente do QR**

`app/Livewire/ChannelQrCode.php`:

```php
<?php

namespace App\Livewire;

use App\Models\Channel;
use App\Services\EvolutionService;
use Livewire\Component;

class ChannelQrCode extends Component
{
    public Channel $channel;
    public ?string $qrBase64 = null;
    public string $estado = 'desconhecido';

    public function mount(Channel $channel): void
    {
        $this->channel = $channel;
        $this->atualizar();
    }

    // Chamado por wire:poll enquanto o numero nao conecta.
    public function atualizar(): void
    {
        $evolution = app(EvolutionService::class);

        try {
            $estado = $evolution->connectionState($this->channel->instance_name);
            $this->estado = data_get($estado, 'instance.state', 'desconhecido');

            if ($this->estado === 'open') {
                $this->qrBase64 = null;
                $this->channel->forceFill(['status' => 'open', 'conectado_em' => now()])->saveQuietly();

                return;
            }

            $conexao = $evolution->connect($this->channel->instance_name);
            $this->qrBase64 = data_get($conexao, 'base64');
        } catch (\Throwable $e) {
            $this->estado = 'erro';
            $this->channel->update(['ultimo_erro' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }

    public function render()
    {
        return view('livewire.channel-qr-code');
    }
}
```

`resources/views/livewire/channel-qr-code.blade.php`:

```blade
<div @if ($estado !== 'open') wire:poll.5s="atualizar" @endif class="space-y-3 text-center">
    @if ($estado === 'open')
        <p class="font-medium text-emerald-600">Número conectado.</p>
    @elseif ($qrBase64)
        <p class="text-sm text-slate-600">Abra o WhatsApp → Aparelhos conectados → Conectar aparelho</p>
        <img src="{{ $qrBase64 }}" alt="QR Code" class="mx-auto w-64 h-64">
        <p class="text-xs text-slate-400">Estado: {{ $estado }}</p>
    @else
        <p class="text-sm text-slate-500">Gerando QR Code…</p>
    @endif
</div>
```

- [ ] **Passo 6: expor o QR no Filament**

Em `app/Filament/Resources/Channels/Pages/EditChannel.php`, adicionar um header action que abre um modal com o componente:

```php
use Filament\Actions\Action;
use Filament\Support\Enums\Width;

protected function getHeaderActions(): array
{
    return [
        Action::make('conectar')
            ->label('Conectar número')
            ->icon('heroicon-o-qr-code')
            ->modalWidth(Width::Medium)
            ->modalSubmitAction(false)
            ->modalContent(fn () => view('filament.channel-qr-modal', ['channel' => $this->record])),
    ];
}
```

`resources/views/filament/channel-qr-modal.blade.php`:

```blade
<div class="py-4">
    @livewire('channel-qr-code', ['channel' => $channel])
</div>
```

- [ ] **Passo 7: testar tudo e commitar**

```bash
su - onchat -c "cd /opt/onchat/app && npm run build && php artisan test"
su - onchat -c "cd /opt/onchat/app && git add -A && git commit -m 'Adiciona provisionamento de canal e conexao por QR Code'"
sudo systemctl restart onchat-horizon php8.3-fpm
```

---

## Verificação final (manual, com celular na mão)

1. Entrar em `https://chat.onsight.com.br/admin`, criar um canal, clicar em **Conectar número** e ler o QR Code com um chip de teste.
2. O estado vira `open` sozinho, sem recarregar.
3. De outro celular, mandar mensagem para o número conectado.
4. Abrir `https://chat.onsight.com.br/inbox`: a conversa aparece na lista **sem recarregar a página**.
5. Abrir a conversa e responder. A mensagem sai como `queued`, vira `sent`, depois `delivered` e `read` conforme o outro aparelho recebe e lê.
6. Conferir o Horizon em `/horizon`: nenhum job em `failed`.

Isso é o critério de pronto do spec. Se os seis passos funcionam, a fase 2 está entregue.
