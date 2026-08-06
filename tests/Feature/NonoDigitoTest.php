<?php

use App\Models\{Channel, Contact, Tenant};
use App\Support\{Jid, PhoneNumber, TenantContext};
use Illuminate\Support\Facades\Http;

/*
 * O nono digito do celular brasileiro.
 *
 * O WhatsApp identifica contas antigas SEM o nono digito, mas o cliente escreve o numero COM
 * ele. Na pratica o mesmo cliente e 554184919939 para a Meta e 5541984919939 no cartao de
 * visita. Sem tratar: dois contatos da mesma pessoa, cada um com metade do historico, e a
 * busca nao acha nenhum dos dois.
 */

const NONO_SEGREDO = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
const NONO_PNID    = '1235849066282498';

function postDaMeta(string $de, string $wamid = 'wamid.NONO1')
{
    $payload = [
        'object' => 'whatsapp_business_account',
        'entry'  => [[
            'id'      => '362',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata'          => ['phone_number_id' => NONO_PNID],
                    'contacts'          => [['profile' => ['name' => 'Rafael'], 'wa_id' => $de]],
                    'messages'          => [[
                        'from' => $de, 'id' => $wamid,
                        'timestamp' => (string) now()->timestamp,
                        'type' => 'text', 'text' => ['body' => 'oi'],
                    ]],
                ],
            ]],
        ]],
    ];

    $corpo = json_encode($payload);

    return test()->call('POST', '/webhooks/meta/whatsapp', [], [], [], [
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $corpo, NONO_SEGREDO),
        'CONTENT_TYPE'             => 'application/json',
    ], $corpo);
}

beforeEach(function () {
    config([
        'services.meta.app_secret'   => NONO_SEGREDO,
        'services.meta.verify_token' => 'tk',
        'services.meta.token'        => 'EAA-env',
        'services.meta.versao'       => 'v23.0',
    ]);

    Http::fake(['*' => Http::response(['ok' => true])]);

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'nono']);
    TenantContext::set($this->tenant->id);

    $this->canal = Channel::create([
        'nome' => 'Oficial', 'tipo' => Channel::META_CLOUD,
        'meta_phone_number_id' => NONO_PNID, 'meta_waba_id' => '362',
    ])->refresh();
});

afterEach(fn () => TenantContext::forget());

// ================================================================ as duas grafias

it('celular com o nono tem a forma sem, e vice-versa', function () {
    expect(PhoneNumber::variantes('+5541984919939'))
        ->toBe(['+5541984919939', '+554184919939'])
        ->and(PhoneNumber::variantes('+554184919939'))
        ->toBe(['+554184919939', '+5541984919939']);
});

it('fixo NAO ganha nono digito', function () {
    // Fixo comeca em 2-5. Acrescentar o 9 criaria um numero que nao existe, e casaria
    // contatos diferentes como se fossem a mesma pessoa.
    expect(PhoneNumber::variantes('+554133334444'))->toBe(['+554133334444']);
});

it('numero estrangeiro nao ganha variacao', function () {
    // A regra e brasileira. Inventar variacao em numero de fora juntaria pessoas distintas.
    expect(PhoneNumber::variantes('+15556725603'))->toBe(['+15556725603']);
});

it('a forma discavel e a que tem o nono', function () {
    expect(PhoneNumber::discavel('+554184919939'))->toBe('+5541984919939')
        ->and(PhoneNumber::discavel('+5541984919939'))->toBe('+5541984919939');
});

// ============================================================ nao duplica contato

it('mensagem da Meta sem o nono acha o contato que ja existe com o nono', function () {
    // O caso real: contato cadastrado por planilha ou criado pela Evolution, e o cliente
    // escreve pelo numero oficial.
    $jaExiste = Contact::create(['nome' => 'Rafael', 'telefone_e164' => '+5541984919939']);

    postDaMeta('554184919939')->assertOk();

    expect(Contact::count())->toBe(1)
        ->and(Contact::first()->id)->toBe($jaExiste->id)
        // o nome que o atendente cadastrou nao e trocado pelo do perfil
        ->and(Contact::first()->nome)->toBe('Rafael');
});

it('planilha com o nono nao duplica quem chegou pela Meta sem ele', function () {
    postDaMeta('554184919939')->assertOk();

    expect(Contact::count())->toBe(1);

    // O que o importador faz ao resolver a linha.
    $achado = Contact::acharPorTelefone('+5541984919939');

    expect($achado)->not->toBeNull()
        ->and($achado->id)->toBe(Contact::first()->id);
});

it('acha pelas duas pontas: por jid e por telefone', function () {
    // As duas colunas podem ter nascido de provedores diferentes.
    $porJid = Contact::create([
        'nome' => 'Do jid', 'jid' => Jid::dePessoa('+554199990000'), 'telefone_e164' => null,
    ]);

    expect(Contact::acharPorTelefone('+5541999990000')?->id)->toBe($porJid->id);
});

it('duas mensagens no mesmo instante nao criam contato duplicado', function () {
    // createOrFirst: a corrida termina com quem venceu, nao com erro na fila.
    postDaMeta('554184919939', 'wamid.A')->assertOk();
    postDaMeta('554184919939', 'wamid.B')->assertOk();

    expect(Contact::count())->toBe(1);
});

it('numeros de pessoas diferentes continuam separados', function () {
    // A trava contra o excesso de zelo: variacao nao pode juntar quem nao e a mesma pessoa.
    Contact::create(['nome' => 'Um', 'telefone_e164' => '+5541984919939']);

    postDaMeta('554184919938')->assertOk(); // termina em 8, nao em 9

    expect(Contact::count())->toBe(2);
});

// ==================================================================== exibicao

it('o painel mostra o numero que da para discar', function () {
    postDaMeta('554184919939')->assertOk();

    $c = Contact::first();

    expect($c->telefone_e164)->toBe('+554184919939')      // guardado como a Meta conhece
        ->and($c->telefoneDiscavel())->toBe('+5541984919939'); // mostrado como se disca
});

// ======================================================================= busca

it('busca pelo numero local com o nono acha quem esta sem ele', function () {
    postDaMeta('554184919939')->assertOk();

    $formas = PhoneNumber::variantesDeBusca('984919939');

    expect($formas)->toContain('984919939')->toContain('84919939');

    $achou = Contact::query()
        ->where(function ($q) use ($formas) {
            foreach ($formas as $f) {
                $q->orWhere('telefone_e164', 'ilike', '%'.$f.'%');
            }
        })
        ->exists();

    expect($achou)->toBeTrue();
});

it('busca por trecho curto nao inventa grafia', function () {
    // Com poucos digitos nao ha posicao de nono digito para adivinhar.
    expect(PhoneNumber::variantesDeBusca('4198'))->toBe(['4198']);
});
