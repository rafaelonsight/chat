<?php

namespace App\Services\Demandas;

use App\Models\ClientAdmin;
use App\Models\ClientPortal;
use App\Models\PortalOtp;
use App\Models\PortalSession;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PortalOtpService
{
    public function issue(ClientPortal $portal, ClientAdmin $admin): void
    {
        PortalOtp::where('client_portal_id', $portal->id)->whereNull('used_at')->update(['used_at' => now()]);
        $code = (string) random_int(100000, 999999);
        PortalOtp::create(['tenant_id' => $portal->tenant_id, 'client_portal_id' => $portal->id, 'client_admin_id' => $admin->id, 'code_hash' => hash('sha256', $code), 'expires_at' => now()->addMinutes(10)]);
        Mail::raw("Seu código temporário do portal Virtus Chat é {$code}. Ele vale por 10 minutos e pode ser usado uma única vez.", fn ($mail) => $mail->to($admin->email, $admin->name)->subject('Código de acesso — Virtus Chat'));
    }

    public function verify(ClientPortal $portal, string $email, string $code, ?string $ip, ?string $userAgent): ?string
    {
        $admin = ClientAdmin::where('client_account_id', $portal->client_account_id)->whereRaw('lower(email) = ?', [mb_strtolower($email)])->where('active', true)->first();
        if (! $admin) return null;
        $otp = PortalOtp::where('client_portal_id', $portal->id)->where('client_admin_id', $admin->id)->whereNull('used_at')->latest()->first();
        if (! $otp || $otp->expires_at->isPast() || $otp->attempts >= 5) return null;
        $otp->increment('attempts');
        if (! hash_equals($otp->code_hash, hash('sha256', $code))) return null;
        $otp->update(['used_at' => now()]);
        $token = Str::random(64);
        PortalSession::create(['tenant_id' => $portal->tenant_id, 'client_portal_id' => $portal->id, 'client_admin_id' => $admin->id, 'token_hash' => hash('sha256', $token), 'ip' => $ip, 'user_agent' => $userAgent, 'expires_at' => now()->addHours(8)]);
        return $token;
    }
}
