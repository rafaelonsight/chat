<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ConviteDeAcesso;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * Nasce um cliente: a conta, o primeiro usuario dela e o convite para ele entrar.
 *
 * Fica em servico e nao na tela por dois motivos: o comando de console usa o mesmo caminho, e
 * o essencial fica testavel sem passar por Livewire.
 */
class CriarCliente
{
    /**
     * @param  array{nome:string, documento?:?string, email?:?string, telefone?:?string,
     *               fuso_horario?:?string, responsavel_nome:string, responsavel_email:string,
     *               receita?:array<string, ?string>}  $dados
     * @return array{cliente:Tenant, responsavel:User, link:string,
     *               email_enviado:bool, falha_de_email:?string}
     */
    public function criar(array $dados): array
    {
        /** @var array{0:Tenant, 1:User} $criados */
        $criados = DB::transaction(function () use ($dados) {
            $cliente = Tenant::create(array_merge($dados['receita'] ?? [], [
                'nome'         => trim($dados['nome']),
                'slug'         => $this->slugLivre($dados['nome']),
                'documento'    => ConsultaCnpj::digitos($dados['documento'] ?? null) ?: null,
                'email'        => $this->limpar($dados['email'] ?? null),
                'telefone'     => $this->limpar($dados['telefone'] ?? null),
                'fuso_horario' => $dados['fuso_horario'] ?: 'America/Sao_Paulo',
            ]));

            // tenant_id EXPLICITO. O hook do BelongsToTenant preenche com o tenant de quem
            // esta logado quando o campo vem vazio — e quem esta logado aqui e o operador.
            // Sem esta linha o usuario do cliente novo nasceria dentro da conta da casa,
            // enxergando as conversas dela.
            $responsavel = User::create([
                'tenant_id' => $cliente->id,
                'name'      => trim($dados['responsavel_nome']),
                'email'     => mb_strtolower(trim($dados['responsavel_email'])),
                // Senha aleatoria e descartada: ninguem, nem eu, sabe qual e. Quem define a
                // senha do cliente e o proprio cliente, pelo link do convite.
                'password'  => Str::password(40),
                'admin'     => true,
            ]);

            return [$cliente, $responsavel];
        });

        [$cliente, $responsavel] = $criados;

        // Fora da transacao de proposito: SMTP lento seguraria a transacao aberta, e falha de
        // e-mail nao pode desfazer um cliente que ja existe.
        $convite = $this->convidar($responsavel);

        return [
            'cliente'        => $cliente,
            'responsavel'    => $responsavel,
            'link'           => $convite['link'],
            'email_enviado'  => $convite['enviado'],
            'falha_de_email' => $convite['falha'],
        ];
    }

    /**
     * Gera o link de definir senha e tenta mandar por e-mail.
     *
     * Devolve o link SEMPRE, tenha o e-mail saido ou nao. E o que garante que uma configuracao
     * de e-mail quebrada atrase a entrega do acesso em vez de impedi-la: o operador copia o
     * link da tela e manda pelo caminho que tiver.
     *
     * @return array{link:string, enviado:bool, falha:?string}
     */
    public function convidar(User $usuario): array
    {
        $token = Password::broker()->createToken($usuario);
        $link = Filament::getPanel('admin')->getResetPasswordUrl($token, $usuario);

        try {
            $usuario->notify(new ConviteDeAcesso($link));

            return ['link' => $link, 'enviado' => true, 'falha' => null];
        } catch (Throwable $e) {
            // Engolir a excecao aqui e deliberado, e diferente de engolir em silencio: a falha
            // volta no retorno e a tela mostra ao operador, com o link ao lado.
            report($e);

            return ['link' => $link, 'enviado' => false, 'falha' => $e->getMessage()];
        }
    }

    /**
     * Slug e chave de URL e tem de ser unico. Dois clientes com o mesmo nome nao e caso raro
     * ("Ótica Central"), e o erro de chave duplicada apareceria como tela branca.
     */
    private function slugLivre(string $nome): string
    {
        $base = Str::slug($nome) ?: 'cliente';
        $slug = $base;
        $n = 1;

        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }

    private function limpar(?string $valor): ?string
    {
        return trim((string) $valor) ?: null;
    }
}
