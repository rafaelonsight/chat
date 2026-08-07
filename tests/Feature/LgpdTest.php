<?php

use App\Models\{Channel, Contact, ContactField, ContactFieldValue, Conversation, Message, Tag, Tenant, User};
use App\Services\DadosDoContato;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Storage;

/*
 * LGPD: acesso (Art. 18, II) e eliminacao (Art. 18, VI).
 *
 * O cliente escreve "me manda tudo que voces tem sobre mim" ou "apaga meus dados". Ate hoje a
 * resposta seria abrir o banco na mao — que na pratica quer dizer nao responder.
 *
 * A ESCOLHA QUE ESTE ARQUIVO DEFENDE: apagar ANONIMIZA, nao deleta a linha.
 *
 * Deletar destruiria o registro do NEGOCIO junto com o dado pessoal — quantos atendimentos
 * houve, quando, quanto tempo levaram. Isso nao e dado do titular, e a empresa tem obrigacao
 * fiscal e contabil de guardar. E sumir com conversas faria os relatorios do mes passado
 * mudarem sozinhos, que e a definicao de numero em que ninguem confia.
 *
 * Some tudo que IDENTIFICA e tudo que o titular escreveu ou recebeu. Fica a carcaca.
 */

beforeEach(function () {
    Storage::fake('local');

    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'lgpd']);
    TenantContext::set($this->conta->id);

    $this->admin = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Admin',
        'email' => 'admin@lgpd.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Canal',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'lgp',
    ]);

    $this->contato = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Joana Silva',
        'telefone_e164' => '+5541999990000', 'jid' => '5541999990000@s.whatsapp.net',
        'email' => 'joana@exemplo.com', 'cidade' => 'Curitiba', 'uf' => 'PR',
    ]);

    $campo = ContactField::create([
        'tenant_id' => $this->conta->id, 'nome' => 'CPF', 'chave' => 'cpf', 'tipo' => 'texto',
    ]);
    ContactFieldValue::create([
        'tenant_id' => $this->conta->id, 'contact_id' => $this->contato->id,
        'contact_field_id' => $campo->id, 'valor' => '123.456.789-00',
    ]);

    $etiqueta = Tag::create(['tenant_id' => $this->conta->id, 'nome' => 'VIP', 'cor' => '#ff0000']);
    $this->contato->tags()->attach($etiqueta->id);

    $this->conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $this->contato->id, 'status' => Conversation::ARQUIVADA,
        'satisfacao' => 5,
    ]);

    Storage::disk('local')->put('midia/comprovante.pdf', 'conteudo do arquivo');

    Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $this->conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'in', 'tipo' => 'text',
        'corpo' => 'meu CPF é 123.456.789-00', 'status' => Message::STATUS_DELIVERED,
    ]);

    Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $this->conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'in', 'tipo' => 'document',
        'media_path' => 'midia/comprovante.pdf', 'media_nome' => 'comprovante.pdf',
        'legenda' => 'segue em anexo', 'status' => Message::STATUS_DELIVERED,
    ]);

    $this->actingAs($this->admin);
    $this->servico = app(DadosDoContato::class);
});

// --------------------------------------------------------------- exportar

it('exporta cadastro, campos, etiquetas e o conteudo das conversas', function () {
    $d = $this->servico->exportar($this->contato);

    expect($d['cadastro']['nome'])->toBe('Joana Silva')
        ->and($d['cadastro']['email'])->toBe('joana@exemplo.com')
        ->and($d['cadastro']['endereco']['cidade'])->toBe('Curitiba')
        ->and($d['campos_personalizados']['CPF'])->toBe('123.456.789-00')
        ->and($d['etiquetas'])->toContain('VIP')
        ->and($d['conversas'])->toHaveCount(1)
        ->and($d['conversas'][0]['mensagens'])->toHaveCount(2)
        ->and($d['conversas'][0]['mensagens'][0]['texto'])->toBe('meu CPF é 123.456.789-00')
        ->and($d['conversas'][0]['mensagens'][1]['arquivo'])->toBe('comprovante.pdf');
});

it('o arquivo diz o que ele e, para quem receber entender', function () {
    $d = $this->servico->exportar($this->contato);

    expect($d['_leia_me'])->toContain('LGPD')
        ->and($this->servico->nomeDoArquivo($this->contato))->toContain('joana-silva');
});

it('gera JSON valido e legivel, com acento de verdade', function () {
    $json = json_encode($this->servico->exportar($this->contato),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    expect($json)->toContain('Joana Silva')
        // Sem é no meio do texto: o titular vai LER este arquivo.
        ->and($json)->toContain('é')
        ->and(json_decode($json, true))->toBeArray();
});

// -------------------------------------------------------------- anonimizar

it('tira tudo que identifica', function () {
    $this->servico->anonimizar($this->contato);

    $c = $this->contato->fresh();

    expect($c->nome)->toBe('Contato removido')
        ->and($c->email)->toBeNull()
        // jid nao aceita nulo no banco: vira marcador, e o que importa e nao ter mais o numero
        ->and($c->jid)->not->toContain('41999990000')
        ->and($c->cidade)->toBeNull()
        ->and($c->telefone_e164)->not->toContain('41999990000')
        ->and($c->anonimizado())->toBeTrue()
        ->and(ContactFieldValue::where('contact_id', $c->id)->count())->toBe(0)
        ->and($c->tags()->count())->toBe(0);
});

it('tira o texto de todas as mensagens e apaga os arquivos do disco', function () {
    expect(Storage::disk('local')->exists('midia/comprovante.pdf'))->toBeTrue();

    $r = $this->servico->anonimizar($this->contato);

    expect($r['mensagens'])->toBe(2)
        ->and($r['arquivos'])->toBe(1)
        ->and(Storage::disk('local')->exists('midia/comprovante.pdf'))->toBeFalse();

    foreach (Message::where('conversation_id', $this->conversa->id)->get() as $m) {
        expect($m->corpo)->toBeNull()
            ->and($m->legenda)->toBeNull()
            ->and($m->media_path)->toBeNull()
            ->and($m->media_nome)->toBeNull();
    }
});

it('a CARCACA fica: quantas conversas, quantas mensagens, quando', function () {
    // O relatorio do mes passado nao pode mudar porque alguem exerceu um direito. Isso nao e
    // resistencia ao pedido: e a diferenca entre dado do titular e registro da empresa.
    $antes = Message::where('conversation_id', $this->conversa->id)->count();
    $quando = $this->conversa->created_at;

    $this->servico->anonimizar($this->contato);

    expect(Message::where('conversation_id', $this->conversa->id)->count())->toBe($antes)
        ->and(Conversation::find($this->conversa->id))->not->toBeNull()
        ->and(Conversation::find($this->conversa->id)->created_at->timestamp)->toBe($quando->timestamp)
        // a nota de satisfacao tambem fica: e numero da operacao, nao dado pessoal
        ->and(Conversation::find($this->conversa->id)->satisfacao)->toBe(5);
});

it('dois contatos anonimizados nao colidem no telefone', function () {
    // A coluna tem indice unico. Se os dois virassem o mesmo valor, o segundo pedido de
    // exclusao falharia — e falharia justamente numa obrigacao legal.
    $outro = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Outro',
        'telefone_e164' => '+5541988880000', 'jid' => '5541988880000@s.whatsapp.net',
    ]);

    $this->servico->anonimizar($this->contato);
    $this->servico->anonimizar($outro);

    expect($this->contato->fresh()->telefone_e164)
        ->not->toBe($outro->fresh()->telefone_e164);
});

it('deixa o contato bloqueado, com o motivo escrito', function () {
    // Sem isso, uma mensagem nova do mesmo numero recriaria o cadastro e o pedido seria
    // desfeito sozinho na semana seguinte.
    $this->servico->anonimizar($this->contato);

    expect($this->contato->fresh()->bloqueado())->toBeTrue()
        ->and($this->contato->fresh()->bloqueio_motivo)->toContain('LGPD');
});

it('nao encosta nos dados de outro contato', function () {
    $outro = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Nao Mexer',
        'telefone_e164' => '+5541977770000', 'jid' => '5541977770000@s.whatsapp.net',
    ]);
    $conversaDoOutro = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $outro->id, 'status' => Conversation::ARQUIVADA,
    ]);
    $msg = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $conversaDoOutro->id,
        'channel_id' => $this->canal->id, 'direcao' => 'in', 'tipo' => 'text',
        'corpo' => 'isto tem de continuar aqui', 'status' => Message::STATUS_DELIVERED,
    ]);

    $this->servico->anonimizar($this->contato);

    expect($outro->fresh()->nome)->toBe('Nao Mexer')
        ->and($msg->fresh()->corpo)->toBe('isto tem de continuar aqui');
});
