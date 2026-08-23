<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use App\Services\Canais\MetaInstagramSignup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Callback do login direto no Instagram.
 *
 * Ao contrario do Cadastro Incorporado do WhatsApp e do login do Messenger, este
 * fluxo NAO precisa de popup nem de postMessage: e um redirect comum, na MESMA aba,
 * porque logar no Instagram nao envolve escolher nada depois (a conta e a que a
 * pessoa usou para logar) — entao este metodo ja persiste no banco e redireciona,
 * tudo de uma vez.
 *
 * O 'state' e o que impede um "code" forjado (de uma conta do Instagram que nao e a
 * pessoa que clicou aqui) de ser aceito: ele tem que casar com o que
 * ConectarMetaSocial guardou no CACHE (nao sessao — sessao e compartilhada entre abas
 * do mesmo navegador, e o mount() da tela roda a cada carregamento; duas abas abertas
 * ao mesmo tempo sobrescreveriam uma a state da outra). Cache::pull consome o state na
 * mesma operacao que le, entao um state so vale uma vez.
 */
class MetaInstagramSignupController extends Controller
{
    public function retorno(Request $request, MetaInstagramSignup $signup): RedirectResponse
    {
        abort_unless((bool) auth()->user()?->admin, 403);

        $estadoRecebido = (string) $request->query('state', '');
        $pendente = $estadoRecebido !== '' ? Cache::pull("instagram_oauth:{$estadoRecebido}") : null;

        if ($pendente === null) {
            return $this->comErro(null, 'A conexão expirou ou já foi usada. Clique em "Continuar com o Instagram" de novo.');
        }

        if ($pendente['tenant_id'] !== auth()->user()->tenant_id) {
            return $this->comErro(null, 'Esta conexão pertence a outra conta.');
        }

        $canalId = $pendente['canal_id'];

        $erroDoInstagram = $request->query('error_description') ?? $request->query('error');

        if ($erroDoInstagram) {
            return $this->comErro($canalId, "O Instagram recusou: {$erroDoInstagram}");
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            Log::warning('instagram-signup: volta sem code', ['query' => $request->query()]);

            return $this->comErro($canalId, 'O Instagram não devolveu o código de autorização.');
        }

        $r = $signup->conectar($code, $this->redirectUri());

        if (! $r['ok']) {
            return $this->comErro($canalId, $r['erro']);
        }

        $canalExistente = $canalId !== null
            ? Channel::query()->where('tipo', Channel::INSTAGRAM)->find($canalId)
            : null;

        $trocouDeConta = $canalExistente !== null
            && $canalExistente->meta_ig_account_id !== null
            && $canalExistente->meta_ig_account_id !== $r['ig_account_id'];

        if ($trocouDeConta) {
            // Nao bloqueia — a pessoa ja logou, forcar login de novo so para confirmar
            // seria fricção sem muito ganho. Mas isto NAO pode ficar calado: e
            // exatamente o tipo de troca que devolve o canal errado a quem nao notou.
            Log::warning('reconexao do instagram trocou de conta', [
                'canal'          => $canalExistente->id,
                'conta_antiga'   => $canalExistente->meta_ig_account_id,
                'conta_nova'     => $r['ig_account_id'],
                'usuario_antigo' => $canalExistente->meta_ig_username,
                'usuario_novo'   => $r['username'],
            ]);
        }

        $canal = $this->criarOuAtualizarCanal($r, $canalExistente);

        $redirect = redirect(ChannelResource::getUrl('edit', ['record' => $canal]));

        if ($trocouDeConta) {
            // Mesma chave que o lado do Messenger usa (MetaSocialSignupController),
            // lida uma unica vez em EditChannel::mount().
            $redirect->with('aviso_troca_de_conta_social', "Este canal estava conectado como @{$canalExistente->meta_ig_username} e agora passou a ser @{$r['username']}. Se não era essa a intenção, reconecte com a conta certa.");
        }

        return $redirect;
    }

    private function redirectUri(): string
    {
        return route('canais.instagram.callback');
    }

    /**
     * @param  array{token: string, ig_account_id: string, username: ?string}  $r
     */
    private function criarOuAtualizarCanal(array $r, ?Channel $canalExistente): Channel
    {
        $dados = [
            'tipo'               => Channel::INSTAGRAM,
            'meta_ig_account_id' => $r['ig_account_id'],
            'meta_ig_username'   => $r['username'],
            'meta_token'         => $r['token'],
            'status'             => 'open',
            'conectado_em'       => now(),
            'ultimo_erro'        => null,
        ];

        if ($canalExistente !== null) {
            // Nome e do canal que ja existia — reconectar nao pode pisar num nome
            // que a pessoa ja personalizou.
            $canalExistente->update($dados);

            return $canalExistente;
        }

        return Channel::create([
            ...$dados,
            'tenant_id' => auth()->user()->tenant_id,
            'nome'      => $r['username'] ? '@'.$r['username'] : 'Instagram',
        ]);
    }

    private function comErro(?int $canalId, string $mensagem): RedirectResponse
    {
        $destino = $canalId !== null
            ? ChannelResource::getUrl('conectar-meta-social').'?canal='.$canalId
            : ChannelResource::getUrl('conectar-meta-social');

        return redirect($destino)->with('erro_instagram', $mensagem);
    }
}
