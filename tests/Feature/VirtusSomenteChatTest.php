<?php

use App\Filament\Pages\Agenda;
use App\Filament\Pages\CadastroErp;
use App\Filament\Pages\CentralDemandas;
use App\Filament\Pages\Paineis;
use App\Filament\Resources\ClientAccounts\ClientAccountResource;
use App\Filament\Resources\ClientOnboardingRequests\ClientOnboardingRequestResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Offerings\OfferingResource;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Filament\Resources\Tickets\TicketResource;

it('remove os módulos de ERP da navegação e do acesso', function () {
    expect(CadastroErp::shouldRegisterNavigation())->toBeFalse()
        ->and(CadastroErp::canAccess())->toBeFalse()
        ->and(CentralDemandas::shouldRegisterNavigation())->toBeFalse()
        ->and(CentralDemandas::canAccess())->toBeFalse()
        ->and(TicketResource::shouldRegisterNavigation())->toBeFalse()
        ->and(TicketResource::canViewAny())->toBeFalse()
        ->and(ClientAccountResource::shouldRegisterNavigation())->toBeFalse()
        ->and(ClientAccountResource::canViewAny())->toBeFalse()
        ->and(ClientOnboardingRequestResource::shouldRegisterNavigation())->toBeFalse()
        ->and(ClientOnboardingRequestResource::canViewAny())->toBeFalse()
        ->and(OfferingResource::shouldRegisterNavigation())->toBeFalse()
        ->and(OfferingResource::canViewAny())->toBeFalse()
        ->and(ProposalResource::shouldRegisterNavigation())->toBeFalse()
        ->and(ProposalResource::canViewAny())->toBeFalse();
});

it('mantém contatos do chat, agenda e painéis', function () {
    expect(ContactResource::getNavigationGroup())->toBe('CRM')
        ->and(ContactResource::getNavigationLabel())->toBe('Contatos')
        ->and(Agenda::shouldRegisterNavigation())->toBeTrue()
        ->and(Paineis::shouldRegisterNavigation())->toBeTrue();
});

it('remove os links públicos do ERP', function () {
    $this->get('/proposta/token-antigo')->assertNotFound();
    $this->get('/cadastro-cliente/token-antigo')->assertNotFound();
    $this->get('/portal/token-antigo')->assertNotFound();
});
