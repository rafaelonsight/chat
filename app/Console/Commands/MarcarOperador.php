<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Concede ou tira a marca de operador do produto.
 *
 * Existe como comando e nao como campo de formulario de proposito: 'operador' e a chave da
 * casa. Se ela puder ser marcada por uma tela, um dia uma tela vai marca-la sem querer. Aqui
 * exige acesso ao servidor, que e exatamente o nivel de confianca que o poder representa.
 */
class MarcarOperador extends Command
{
    protected $signature = 'onchat:operador
                            {email : E-mail do usuario}
                            {--remover : Tira a marca em vez de conceder}';

    protected $description = 'Concede (ou remove) acesso de operador do produto a um usuario';

    public function handle(): int
    {
        // withoutGlobalScope porque em console nao ha usuario logado hoje, mas depender disso
        // seria depender de um acaso: se um dia isto rodar dentro de um contexto de tenant, a
        // busca silenciosamente nao acharia ninguem.
        $usuario = User::withoutGlobalScope('tenant')
            ->where('email', mb_strtolower(trim($this->argument('email'))))
            ->first();

        if (! $usuario) {
            $this->error('Nao existe usuario com este e-mail.');

            return self::FAILURE;
        }

        $conceder = ! $this->option('remover');

        // forceFill porque 'operador' esta fora do fillable — e essa e a protecao.
        $usuario->forceFill(['operador' => $conceder])->save();

        $this->info(($conceder ? 'Concedido' : 'Removido').': '.$usuario->email
            .' ('.$usuario->name.')');

        return self::SUCCESS;
    }
}
