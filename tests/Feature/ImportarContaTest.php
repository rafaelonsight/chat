<?php

use App\Services\ImportadorDeConta;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\{Crypt, DB, Storage};

/*
 * IMPORTACAO DE CONTA. O que se perde se quebrar: a conta da LA Group chega com mensagem
 * pendurada em conversa de outra empresa, ou com o token da Meta que nao abre.
 *
 * Origem e destino aqui sao o mesmo banco (o da suite): a conta e copiada para dentro dele
 * mesmo, o que obriga a cada id ser novo. O ajuste so desvia o e-mail unico.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->bytes = random_bytes(32);
    $this->chave = 'base64:'.base64_encode($this->bytes);
    $velha = new Encrypter($this->bytes, config('app.cipher'));

    $a = DB::table('tenants')->insertGetId(['nome' => 'LA Group', 'slug' => 'la-group', 'created_at' => now()]);
    $this->origem = $a;
    $u = DB::table('users')->insertGetId(['tenant_id' => $a, 'name' => 'Tech', 'email' => 'tech@la.test', 'password' => 'x', 'admin' => true]);
    $time = DB::table('teams')->insertGetId(['tenant_id' => $a, 'nome' => 'Suporte']);
    DB::table('team_user')->insert(['team_id' => $time, 'user_id' => $u]);
    $canal = DB::table('channels')->insertGetId(['tenant_id' => $a, 'nome' => 'LA', 'tipo' => 'meta_cloud', 'status' => 'open',
        'webhook_secret' => 's', 'meta_token' => $velha->encryptString('token-da-meta')]);
    $contato = DB::table('contacts')->insertGetId(['tenant_id' => $a, 'nome' => 'Cliente', 'jid' => '5541@s.whatsapp.net']);
    $tag = DB::table('tags')->insertGetId(['tenant_id' => $a, 'nome' => 'VIP', 'contexto' => 'contato']);
    DB::table('contact_tag')->insert(['contact_id' => $contato, 'tag_id' => $tag]);
    $conversa = DB::table('conversations')->insertGetId(['tenant_id' => $a, 'channel_id' => $canal, 'contact_id' => $contato, 'status' => 'arquivada']);
    $msg = DB::table('messages')->insertGetId(['tenant_id' => $a, 'conversation_id' => $conversa, 'channel_id' => $canal, 'user_id' => $u,
        'direcao' => 'out', 'tipo' => 'image', 'media_path' => "media/{$a}/{$conversa}/foto.png", 'mencionados' => json_encode([$u])]);
    DB::table('chatbots')->insert(['tenant_id' => $a, 'nome' => 'Bot', 'mensagem_boas_vindas' => 'Oi', 'mensagem_nao_entendi' => '?',
        'channel_ids' => json_encode([$canal])]);
    DB::table('campaigns')->insert(['tenant_id' => $a, 'channel_id' => $canal, 'nome' => 'Aviso',
        'configuracao' => json_encode(['team_id' => $time, 'contact_ids' => [$contato, 999999], 'timezone' => 'America/Sao_Paulo'])]);
    DB::table('encerramentos_atendimento')->insert(['tenant_id' => $a, 'conversation_id' => $conversa, 'versao' => 1, 'protocolo' => 'LA-1',
        'assunto' => 'Oi', 'resumo' => 'r', 'dados' => '{}', 'canal_id' => $canal, 'responsavel_id' => $u, 'ultima_mensagem_id' => $msg]);
    DB::table('consumos_ia')->insert(['tenant_id' => $a, 'recurso' => 'resumo', 'provedor' => 'x', 'modelo' => 'y',
        'created_at' => now(), 'message_id' => $msg, 'user_id' => $u]);

    // Outra conta no mesmo banco: nada dela pode vir junto.
    $b = DB::table('tenants')->insertGetId(['nome' => 'Outra', 'slug' => 'outra']);
    DB::table('contacts')->insert(['tenant_id' => $b, 'nome' => 'Nao vem', 'jid' => '5599@s.whatsapp.net']);

    $this->midia = sys_get_temp_dir().'/importar-'.uniqid();
    @mkdir("{$this->midia}/private/media/{$a}/{$conversa}", 0777, true);
    file_put_contents("{$this->midia}/private/media/{$a}/{$conversa}/foto.png", 'png');
});

afterEach(fn () => @exec('rm -rf '.escapeshellarg($this->midia)));

function importador(): ImportadorDeConta
{
    return (new ImportadorDeConta(DB::connection()))
        ->ajustarLinha(fn ($tabela, $linha) => $tabela === 'users' ? ['email' => 'copia-'.$linha['email']] + $linha : $linha);
}

it('copia a conta com ids novos e tudo apontando para dentro dela', function () {
    $r = importador()->chaveDaOrigem($this->chave)->midiaDaOrigem($this->midia)->importar($this->origem, true);
    $n = $r['conta'];

    expect($n)->not->toBe($this->origem)
        ->and(DB::table('tenants')->where('id', $n)->value('slug'))->toBe('la-group-importada')
        ->and(DB::table('contacts')->where('tenant_id', $n)->pluck('nome')->all())->toBe(['Cliente'])
        ->and($r['tabelas'])->toMatchArray(['users' => 1, 'team_user' => 1, 'contact_tag' => 1, 'messages' => 1, 'encerramentos_atendimento' => 1]);

    $conversa = DB::table('conversations')->where('tenant_id', $n)->first();
    $contato = DB::table('contacts')->where('tenant_id', $n)->value('id');
    $canal = DB::table('channels')->where('tenant_id', $n)->value('id');
    $user = DB::table('users')->where('tenant_id', $n)->value('id');
    $msg = DB::table('messages')->where('tenant_id', $n)->first();

    expect($conversa->contact_id)->toBe($contato)
        ->and($conversa->channel_id)->toBe($canal)
        ->and($msg->conversation_id)->toBe($conversa->id)
        ->and(DB::table('contact_tag')->where('contact_id', $contato)->value('tag_id'))->toBe(DB::table('tags')->where('tenant_id', $n)->value('id'))
        ->and(DB::table('team_user')->where('user_id', $user)->value('team_id'))->toBe(DB::table('teams')->where('tenant_id', $n)->value('id'));

    // Colunas sem chave estrangeira no banco.
    $enc = DB::table('encerramentos_atendimento')->where('tenant_id', $n)->first();
    expect([$enc->canal_id, $enc->responsavel_id, $enc->ultima_mensagem_id])->toBe([$canal, $user, $msg->id])
        ->and(DB::table('consumos_ia')->where('tenant_id', $n)->value('message_id'))->toBe($msg->id);
});

it('ids dentro de JSON, token criptografado e arquivo acompanham a conta', function () {
    $n = importador()->chaveDaOrigem($this->chave)->midiaDaOrigem($this->midia)->importar($this->origem, true)['conta'];

    $canal = DB::table('channels')->where('tenant_id', $n)->first();
    $user = DB::table('users')->where('tenant_id', $n)->value('id');
    $msg = DB::table('messages')->where('tenant_id', $n)->first();
    $config = json_decode(DB::table('campaigns')->where('tenant_id', $n)->value('configuracao'), true);

    expect(json_decode(DB::table('chatbots')->where('tenant_id', $n)->value('channel_ids')))->toBe([$canal->id])
        ->and(json_decode($msg->mencionados))->toBe([$user])
        ->and($config['team_id'])->toBe(DB::table('teams')->where('tenant_id', $n)->value('id'))
        ->and($config['contact_ids'])->toBe([DB::table('contacts')->where('tenant_id', $n)->value('id')])
        ->and($config['timezone'])->toBe('America/Sao_Paulo')
        ->and(Crypt::decryptString($canal->meta_token))->toBe('token-da-meta')
        ->and($msg->media_path)->toBe("media/{$n}/{$msg->conversation_id}/foto.png");

    Storage::disk('local')->assertExists($msg->media_path);
});

it('sem gravar, simula e nada fica', function () {
    $antes = DB::table('tenants')->count();

    $r = importador()->chaveDaOrigem($this->chave)->importar($this->origem, false);

    expect($r['tabelas']['messages'])->toBe(1)
        ->and(DB::table('tenants')->count())->toBe($antes);
});

it('conflito para tudo e nao deixa meia conta', function () {
    $antes = DB::table('contacts')->count();

    expect(fn () => (new ImportadorDeConta(DB::connection()))->chaveDaOrigem($this->chave)->importar($this->origem, true))
        ->toThrow(RuntimeException::class, 'users');

    expect(DB::table('contacts')->count())->toBe($antes);
});

it('chave errada da origem para a importacao em vez de gravar token que nao abre', function () {
    expect(fn () => importador()->chaveDaOrigem('base64:'.base64_encode(random_bytes(32)))->importar($this->origem, true))
        ->toThrow(RuntimeException::class, 'channels.meta_token');
});

it('o comando pede a origem e recusa conta que nao existe', function () {
    putenv('IMPORTAR_ORIGEM_URL');
    $this->artisan('virtus:importar-conta', ['conta' => 1])->expectsOutputToContain('Informe a origem')->assertFailed();

    expect(fn () => $this->artisan('virtus:importar-conta', ['conta' => 999999, '--origem' => config('database.default')])->run())
        ->toThrow(RuntimeException::class, 'nao existe na origem');
});
