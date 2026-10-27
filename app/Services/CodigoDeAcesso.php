<?php

namespace App\Services;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CodigoDeAcesso
{
    public function solicitar(string $email, string $painel, string $ip, string $sessao): ?string
    {
        $email = mb_strtolower(trim($email));
        $chave = 'codigo-envio:'.hash('sha256', $email);
        if (RateLimiter::tooManyAttempts($chave, 1)) {
            throw ValidationException::withMessages(['email' => 'Aguarde um minuto antes de pedir outro código.']);
        }
        RateLimiter::hit($chave, 60);
        $usuario = User::withoutGlobalScopes()->whereRaw('lower(email) = ?', [$email])->first();
        if (! $usuario || ! $usuario->canAccessPanel(Filament::getPanel($painel))) {
            return null;
        }
        $id = (string) Str::uuid();
        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        DB::table('codigos_de_acesso')->insert([
            'id' => $id, 'user_id' => $usuario->id, 'painel' => $painel,
            'sessao_hash' => hash('sha256', $sessao),
            'codigo_hash' => hash_hmac('sha256', $codigo, config('app.key')),
            'tentativas' => 0, 'expira_em' => now()->addMinutes(10), 'created_at' => now(),
        ]);
        try {
            // Não usa a fila de homologação nem o mailer log: o código precisa chegar por SMTP.
            $idioma=\App\Support\Idiomas::valido(\App\Models\Tenant::find($usuario->tenant_id)?->idioma_comunicacoes);
            \App\Support\Idiomas::noIdioma($idioma, function () use ($usuario, $codigo) {
            Mail::mailer(config('acesso.mailer'))->send(
                ['html' => 'emails.token-acesso', 'text' => 'emails.token-acesso-texto'],
                ['codigo' => $codigo],
                fn ($mail) => $mail->to($usuario->email)->subject(__('ui_16360c0b09f2')),
            );
            });
        } catch (\Throwable $erro) {
            DB::table('codigos_de_acesso')->where('id', $id)->delete();
            // Não registrar corpo, código ou credenciais do transporte em logs.
            logger()->warning('Falha ao entregar código de acesso', ['tipo' => get_class($erro)]);
            throw ValidationException::withMessages(['email' => 'Não foi possível enviar o código. Tente novamente em um minuto.']);
        }
        return $id;
    }

    public function consumir(?string $id, string $codigo, string $sessao): ?User
    {
        if (! $id) return null;
        $usuarioId = DB::transaction(function () use ($id, $codigo, $sessao) {
            $registro = DB::table('codigos_de_acesso')->where('id', $id)->lockForUpdate()->first();
            if (! $registro || $registro->usado_em || $registro->tentativas >= 5
                || \Carbon\Carbon::parse($registro->expira_em)->isPast()
                || ! hash_equals($registro->sessao_hash, hash('sha256', $sessao))) return null;
            DB::table('codigos_de_acesso')->where('id', $id)->increment('tentativas');
            if (! hash_equals($registro->codigo_hash, hash_hmac('sha256', $codigo, config('app.key')))) return null;
            $usuario = User::withoutGlobalScopes()->find($registro->user_id);
            if (! $usuario || ! $usuario->canAccessPanel(Filament::getPanel($registro->painel))) return null;
            DB::table('codigos_de_acesso')->where('id', $id)->update(['usado_em' => now()]);
            return $usuario->id;
        });
        return $usuarioId ? User::withoutGlobalScopes()->find($usuarioId) : null;
    }
}
