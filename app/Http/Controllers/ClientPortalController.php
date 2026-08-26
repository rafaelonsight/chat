<?php

namespace App\Http\Controllers;

use App\Models\ClientAccount;
use App\Models\ClientAdmin;
use App\Models\ClientPortal;
use App\Models\ClientRequester;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Demandas\DemandMeetingScheduler;
use App\Services\Demandas\PortalOtpService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ClientPortalController extends Controller
{
    public function show(Request $request, string $token)
    {
        [$portal, $client] = $this->context($token);
        $clientIds = $this->clientIds($client);
        $tickets = Ticket::whereIn('client_account_id', $clientIds)->visible()->with(['client', 'requester', 'assignees', 'tags'])->latest()->paginate(20);
        $portal->update(['last_accessed_at' => now()]);

        return view('portal.dashboard', compact('portal', 'client', 'tickets', 'token'));
    }

    public function storeTicket(Request $request, string $token)
    {
        [$portal, $owner] = $this->context($token);
        $allowed = $this->clientIds($owner);
        $data = $request->validate([
            'client_account_id' => ['nullable', 'integer', 'in:'.implode(',', $allowed)],
            'requester_name' => 'required|string|max:160', 'requester_email' => 'nullable|email|max:160',
            'requester_phone' => 'nullable|string|max:30', 'title' => 'required|string|max:180',
            'description' => 'required|string|max:10000', 'category' => 'required|in:'.implode(',', array_keys(Ticket::CATEGORIES)),
            'priority' => 'required|in:'.implode(',', array_keys(Ticket::PRIORITIES)),
        ]);
        $clientId = (int) ($data['client_account_id'] ?? $owner->id);
        $requester = ClientRequester::updateOrCreate(
            ['client_account_id' => $clientId, 'email' => $data['requester_email'] ?: null, 'name' => $data['requester_name']],
            ['tenant_id' => $portal->tenant_id, 'phone' => ($data['requester_phone'] ?? null) ?: null, 'active' => true]
        );
        $ticket = Ticket::create([
            'tenant_id' => $portal->tenant_id, 'client_account_id' => $clientId, 'requester_id' => $requester->id,
            'title' => $data['title'], 'description' => $data['description'], 'category' => $data['category'],
            'priority' => $data['priority'], 'origin' => 'portal',
        ]);

        return redirect()->route('portal.ticket', [$token, $ticket->protocol])->with('success', "Demanda {$ticket->protocol} recebida.");
    }

    public function ticket(string $token, string $protocol)
    {
        [$portal, $client] = $this->context($token);
        $ticket = Ticket::where('protocol', $protocol)->whereIn('client_account_id', $this->clientIds($client))
            ->with(['client', 'requester', 'project', 'tags', 'meetings.meeting'])->firstOrFail();
        $visibilities = $client->type === ClientAccount::PARTNER ? ['public', 'partner'] : ['public'];
        $messages = TicketMessage::where('ticket_id', $ticket->id)->whereIn('visibility', $visibilities)->with('author')->oldest()->get();

        return view('portal.ticket', compact('portal', 'client', 'ticket', 'messages', 'token'));
    }

    public function message(Request $request, string $token, string $protocol)
    {
        [$portal, $client] = $this->context($token);
        $ticket = Ticket::where('protocol', $protocol)->whereIn('client_account_id', $this->clientIds($client))->firstOrFail();
        $data = $request->validate(['body' => 'required|string|max:10000', 'requester_id' => 'nullable|integer']);
        TicketMessage::create(['tenant_id' => $portal->tenant_id, 'ticket_id' => $ticket->id, 'requester_id' => $data['requester_id'] ?? $ticket->requester_id, 'visibility' => 'public', 'body' => $data['body']]);

        return back()->with('success', 'Resposta adicionada ao ticket.');
    }

    public function meeting(Request $request, string $token, string $protocol, DemandMeetingScheduler $scheduler)
    {
        [$portal, $client] = $this->context($token);
        $ticket = Ticket::where('protocol', $protocol)->whereIn('client_account_id', $this->clientIds($client))->firstOrFail();
        $data = $request->validate(['starts_at' => 'required|date|after:now', 'duration' => 'required|integer|in:30,45,60']);
        try {
            $scheduler->schedule($ticket, $ticket->category, Carbon::parse($data['starts_at']), (int) $data['duration'], $ticket->requester_id);
        } catch (\DomainException $e) {
            return back()->withErrors(['starts_at' => $e->getMessage()]);
        }

        return back()->with('success', 'Reunião confirmada na agenda da equipe.');
    }

    public function requestOtp(Request $request, string $token, PortalOtpService $service)
    {
        [$portal] = $this->context($token);
        $data = $request->validate(['email' => 'required|email']);
        $admin = ClientAdmin::where('client_account_id', $portal->client_account_id)->whereRaw('lower(email) = ?', [mb_strtolower($data['email'])])->where('active', true)->first();
        if ($admin) $service->issue($portal, $admin);
        return back()->with('success', 'Se o e-mail estiver autorizado, o código será enviado.');
    }

    public function verifyOtp(Request $request, string $token, PortalOtpService $service)
    {
        [$portal] = $this->context($token);
        $data = $request->validate(['email' => 'required|email', 'code' => 'required|digits:6']);
        $session = $service->verify($portal, $data['email'], $data['code'], $request->ip(), $request->userAgent());
        if (! $session) return back()->withErrors(['code' => 'Código inválido ou expirado.']);
        return back()->withCookie(cookie('virtus_portal_session', $session, 480, '/', null, true, true, false, 'Strict'))->with('success', 'Acesso administrativo liberado por 8 horas.');
    }

    private function context(string $token): array
    {
        $portal = ClientPortal::findByToken($token);
        abort_unless($portal, 404);
        TenantContext::set($portal->tenant_id);
        return [$portal, ClientAccount::findOrFail($portal->client_account_id)];
    }

    private function clientIds(ClientAccount $client): array
    {
        return $client->type === ClientAccount::PARTNER ? array_merge([$client->id], $client->children()->pluck('id')->all()) : [$client->id];
    }
}
