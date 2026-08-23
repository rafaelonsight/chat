<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use App\Services\Canais\MetaInstagramSignup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
 * ConectarMetaSocial guardou na sessao ANTES de mostrar o link — sem essa conferencia,
 * a volta valeria so por ter chegado num GET autenticado.
 */
class MetaInstagramSignupController extends Controller
{
    public function retorno(Request $request, MetaInstagramSignup $signup): RedirectResponse
    {
        abort_unless((bool) auth()->user()?->admin, 403);

        $estadoEsperado = (string) $request->session()->pull('instagram_oauth_state');
        $canalId = $request->session()->pull('instagram_oauth_canal');

        $estadoRecebido = (string) $request->query('state', '');

        if ($estadoEsperado === '' || ! hash_equals($estadoEsperado, $estadoRecebido)) {
            return $this->comErro(null, 'A conexão expirou ou foi aberta em outra aba. Clique em "Continuar com o Instagram" de novo.');
        }

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

        $canal = $this->criarOuAtualizarCanal($r, $canalExistente);

        return redirect(ChannelResource::getUrl('edit', ['record' => $canal]));
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
