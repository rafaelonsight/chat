<?php

use App\Livewire\Inbox\MessageComposer;
use App\Models\{Channel, Contact, Conversation, ConversationEvent, Team, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

/*
 * CHAMAR UM COLEGA DENTRO DA NOTA.
 *
 * A nota interna ja existia e funcionava — o que faltava era ela CHAMAR alguem. Sem aviso, nota
 * e diario: a pessoa escreve e reza para alguem abrir aquela conversa e ler.
 *
 * O CRUZAMENTO COM QUEM PODE VER A CONVERSA e a parte que nao pode falhar: o aviso leva o TEXTO
 * da nota e o nome do cliente. Sem o cruzamento, bastaria escrever "@fulano" para entregar a
 * fulano o comentario de uma conversa que ele nao tem permissao de abrir — vazamento pelo
 * avesso, saindo justamente pela porta que foi feita para colaborar.
 */

function cenarioMencao(string $slug): array
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($t->id);

    $ana = User::create([
        'tenant_id' => $t->id, 'name' => 'Ana Souza', 'email' => "ana@{$slug}.test",
        'password' => 'segredo123',
    ]);

    $celso = User::create([
        'tenant_id' => $t->id, 'name' => 'Celso Lopes', 'email' => "celso@{$slug}.test",
        'password' => 'segredo123',
    ]);

    $canal = Channel::create(['nome' => 'Vendas']);

    $contato = Contact::create([
        'jid' => "5511{$t->id}999@s.whatsapp.net", 'tipo' => Contact::PESSOA, 'nome' => 'Cliente Zé',
    ]);

    $conversa = Conversation::create([
        'channel_id' => $canal->refresh()->id, 'contact_id' => $contato->id, 'ultima_msg_em' => now(),
    ]);

    return [$t, $ana, $celso, $canal, $conversa->refresh()];
}

function escreverNota(User $quem, Conversation $conversa, string $texto, array $escolhidos = []): void
{
    Livewire::actingAs($quem)
        ->test(MessageComposer::class, ['conversationId' => $conversa->id])
        ->set('nota', true)
        ->set('mencionados', $escolhidos)
        ->set('corpo', $texto)
        ->call('enviar');
}

afterEach(fn () => TenantContext::forget());

// ------------------------------------------------------------------ o basico

it('a nota continua sendo nota, e o aviso e um extra', function () {
    // A nota tinha de continuar funcionando exatamente como antes: ela e o registro, o aviso e
    // so o empurrao. Se o aviso falhar um dia, a nota nao pode ir junto.
    [$t, $ana, $celso, $canal, $conversa] = cenarioMencao('mn1');

    escreverNota($ana, $conversa, '@celso confere o boleto desse cliente');

    $nota = ConversationEvent::where('tipo', ConversationEvent::NOTA)->first();

    expect($nota->descricao)->toBe('@celso confere o boleto desse cliente')
        ->and($nota->user_id)->toBe($ana->id);
});

it('o nome digitado chama a pessoa', function () {
    [$t, $ana, $celso, $canal, $conversa] = cenarioMencao('mn2');

    escreverNota($ana, $conversa, '@celso confere o boleto desse cliente');

    expect($celso->notifications()->count())->toBe(1);

    $aviso = $celso->notifications()->first()->data;

    expect($aviso['title'])->toContain('Ana')
        ->and($aviso['body'])->toContain('boleto');
});

it('o nome escolhido na lista tambem chama, mesmo sem arroba no texto', function () {
    // Quem clica na lista suspensa espera ter chamado. O texto e a escolha sao dois caminhos
    // para a mesma intencao.
    [$t, $ana, $celso, $canal, $conversa] = cenarioMencao('mn3');

    escreverNota($ana, $conversa, 'preciso de uma ajuda aqui', [$celso->id]);

    expect($celso->notifications()->count())->toBe(1);
});

it('o aviso leva o link da conversa certa', function () {
    // O caminho tem de terminar onde promete: sem o ?conversa, o clique jogaria a pessoa na
    // inbox para procurar na lista qual era — com o aviso ja marcado como lido.
    [$t, $ana, $celso, $canal, $conversa] = cenarioMencao('mn4');

    escreverNota($ana, $conversa, '@celso olha isso');

    $aviso = $celso->notifications()->first()->data;

    expect(json_encode($aviso))->toContain('conversa='.$conversa->id);
});

it('nao avisa quem escreveu', function () {
    // A pessoa acabou de escrever: avisa-la seria o sistema contando a ela o que ela fez.
    [$t, $ana, $celso, $canal, $conversa] = cenarioMencao('mn5');

    escreverNota($ana, $conversa, '@ana anotando para mim mesma', [$ana->id]);

    expect($ana->notifications()->count())->toBe(0);
});

// ------------------------------------------------ o cruzamento com o acesso

it('NAO avisa quem nao pode abrir a conversa, nem se o nome for escrito', function () {
    /*
     * O TESTE QUE NAO PODE QUEBRAR.
     *
     * O aviso carrega o texto da nota e o nome do cliente. Se a mencao passasse por cima do
     * acesso, a porta feita para colaborar viraria a saida do vazamento — e sem deixar rastro,
     * porque ninguem revisa avisos.
     */
    [$t, $ana, $celso, $canal, $conversa] = cenarioMencao('mn6');

    $outroCanal = Channel::create(['nome' => 'Suporte']);
    $celso->canais()->attach($outroCanal->refresh()->id);

    escreverNota($ana, $conversa, '@celso confere isso', [$celso->id]);

    expect($celso->notifications()->count())->toBe(0);

    // E a nota foi salva de qualquer jeito: o registro nao depende de haver alguem para chamar.
    expect(ConversationEvent::where('tipo', ConversationEvent::NOTA)->count())->toBe(1);
});

it('nao oferece na lista quem nao pode abrir a conversa', function () {
    // Oferecer e depois nao avisar seria pior que nao oferecer: a pessoa clica, acha que chamou,
    // e vai embora esperando resposta.
    [$t, $ana, $celso, $canal, $conversa] = cenarioMencao('mn7');

    $outro = Channel::create(['nome' => 'Suporte']);
    $celso->canais()->attach($outro->refresh()->id);

    Livewire::actingAs($ana)
        ->test(MessageComposer::class, ['conversationId' => $conversa->id])
        ->set('nota', true)
        ->assertViewHas('mencionaveis', fn ($lista) => collect($lista)->pluck('id')->all() === [$ana->id]);
});

it('restrito a time nao entra na lista de uma conversa sem time', function () {
    // Mesma regra do escopo: quem tem time nao ve a fila sem time, entao nao pode ser chamado
    // nela — ele nem conseguiria abrir para responder.
    [$t, $ana, $celso, $canal, $conversa] = cenarioMencao('mn8');

    $time = Team::create(['nome' => 'Financeiro']);
    $celso->teams()->attach($time->id);

    expect($celso->podeVer($conversa))->toBeFalse()
        ->and(User::quePodemVer($conversa)->pluck('id')->all())->toBe([$ana->id]);
});

// ----------------------------------------------------------- a ambiguidade

it('dois nomes iguais na equipe nao chamam ninguem pelo texto', function () {
    /*
     * Adivinhar qual das duas Anas seria pior que nao avisar: a errada recebe assunto que nao e
     * dela, e a certa continua esperando. Quem quer chamar uma Ana especifica escolhe na lista,
     * que carrega o id.
     */
    [$t, $ana, $celso, $canal, $conversa] = cenarioMencao('mn9');

    $outraAna = User::create([
        'tenant_id' => $t->id, 'name' => 'Ana Ribeiro', 'email' => 'ana2@mn9.test',
        'password' => 'segredo123',
    ]);

    escreverNota($celso, $conversa, '@ana pode ver isso?');

    expect($ana->notifications()->count())->toBe(0)
        ->and($outraAna->notifications()->count())->toBe(0);
});

it('escolher na lista resolve a ambiguidade que o texto nao resolve', function () {
    [$t, $ana, $celso, $canal, $conversa] = cenarioMencao('mn10');

    $outraAna = User::create([
        'tenant_id' => $t->id, 'name' => 'Ana Ribeiro', 'email' => 'ana2@mn10.test',
        'password' => 'segredo123',
    ]);

    escreverNota($celso, $conversa, '@ana pode ver isso?', [$outraAna->id]);

    expect($outraAna->notifications()->count())->toBe(1)
        ->and($ana->notifications()->count())->toBe(0);
});

// --------------------------------------------- as duas versoes da mesma regra

it('podeVer concorda com o escopo de acesso, caso a caso', function () {
    /*
     * ANTI-DERIVA. O escopo Acesso sabe FILTRAR uma consulta; o podeVer responde ao contrario,
     * "esta pessoa ve esta conversa?". Sao duas escritas da mesma regra, e regra escrita duas
     * vezes se afasta em silencio — normalmente quando alguem muda uma delas com pressa.
     *
     * Aqui as duas sao comparadas em todas as combinacoes do cenario. Se um dia divergirem, a
     * mencao passa a oferecer gente que nao pode abrir a conversa (ou a esconder gente que pode).
     */
    [$t, $ana, $celso, $canal, $conversa] = cenarioMencao('mn11');

    $time = Team::create(['nome' => 'Financeiro']);
    $outroCanal = Channel::create(['nome' => 'Suporte'])->refresh();

    // Contatos diferentes de proposito: ha indice unico de conversa ABERTA por canal+contato,
    // e reusar o mesmo contato no mesmo canal e justamente o que ele existe para impedir.
    $outroContato = Contact::create([
        'jid' => '5511888@s.whatsapp.net', 'tipo' => Contact::PESSOA, 'nome' => 'Cliente Dois',
    ]);

    $comTime = Conversation::create([
        'channel_id' => $canal->id, 'contact_id' => $outroContato->id, 'ultima_msg_em' => now(),
    ]);
    $comTime->forceFill(['team_id' => $time->id])->save();

    $noOutroCanal = Conversation::create([
        'channel_id' => $outroCanal->id, 'contact_id' => $conversa->contact_id, 'ultima_msg_em' => now(),
    ]);

    $celso->canais()->attach($canal->id);
    $celso->teams()->attach($time->id);

    foreach ([$ana, $celso] as $pessoa) {
        $this->actingAs($pessoa->fresh());

        foreach ([$conversa, $comTime, $noOutroCanal] as $qual) {
            // A regra escrita a mao...
            $pelaRegra = auth()->user()->podeVer($qual->fresh());

            // ...e a mesma pergunta respondida pelo banco, com o escopo aplicado.
            $pelaConsulta = Conversation::whereKey($qual->id)->exists();

            expect($pelaRegra)->toBe(
                $pelaConsulta,
                "divergiu para {$pessoa->name} na conversa {$qual->id}",
            );
        }
    }
});
