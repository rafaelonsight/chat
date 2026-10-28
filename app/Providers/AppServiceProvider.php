<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Support\Modulos::class);
        $this->app->singleton(\App\Services\TranscriptionService::class, fn () => new \App\Services\TranscriptionService(
            (bool) config('services.transcricao.ativa'),
            (string) config('services.transcricao.url'),
            (int) config('services.transcricao.max_segundos'),
            (string) config('services.transcricao.vocabulario'),
        ));
        $this->app->singleton(\App\Services\Canais\MetaCloudEnviador::class, fn () => new \App\Services\Canais\MetaCloudEnviador(
            (string) config('services.meta.token'),
            (string) config('services.meta.versao'),
            (int) config('services.meta.timeout'),
        ));
        $this->app->singleton(\App\Services\Canais\MetaEmbeddedSignup::class, fn () => new \App\Services\Canais\MetaEmbeddedSignup(
            (string) config('services.meta.app_id'),
            (string) config('services.meta.app_secret'),
            (string) config('services.meta.versao'),
            (int) config('services.meta.timeout'),
        ));
        $this->app->singleton(\App\Services\Canais\MetaSocialSignup::class, fn () => new \App\Services\Canais\MetaSocialSignup(
            (string) config('services.meta.app_id'),
            (string) config('services.meta.app_secret'),
            (string) config('services.meta.versao'),
            (int) config('services.meta.timeout'),
        ));
        $this->app->singleton(\App\Services\Canais\MetaMessengerEnviador::class, fn () => new \App\Services\Canais\MetaMessengerEnviador(
            (string) config('services.meta.versao'),
            (int) config('services.meta.timeout'),
        ));
        $this->app->singleton(\App\Services\Canais\MetaInstagramEnviador::class, fn () => new \App\Services\Canais\MetaInstagramEnviador(
            (string) config('services.meta.versao'),
            (int) config('services.meta.timeout'),
        ));
        $this->app->singleton(\App\Services\Canais\MetaInstagramSignup::class, fn () => new \App\Services\Canais\MetaInstagramSignup(
            (string) config('services.meta.instagram_app_id'),
            (string) config('services.meta.instagram_app_secret'),
            (string) config('services.meta.versao'),
            (int) config('services.meta.timeout'),
        ));

        $this->app->singleton(\App\Services\EvolutionService::class, fn () => new \App\Services\EvolutionService(
            (string) config('services.evolution.url'),
            (string) config('services.evolution.key'),
        ));
        $this->app->singleton(\App\Services\ConsultaCnpj::class, fn () => new \App\Services\ConsultaCnpj(
            (string) config('services.cnpj.url'),
            (int) config('services.cnpj.timeout'),
            (int) config('services.cnpj.cache_horas'),
        ));
        $this->app->singleton(\App\Services\ConsultaCep::class, fn () => new \App\Services\ConsultaCep(
            (string) config('services.cep.url'),
            (int) config('services.cep.timeout'),
            (int) config('services.cep.cache_horas'),
        ));
        if (config('agenda.edition')) $this->app->bind(\Filament\Auth\Notifications\ResetPassword::class, \App\Notifications\AgendaResetPassword::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if(config('agenda.edition')) {
            config(['queue.connections.redis.queue'=>'agenda-inbox']);
            $this->app->singleton(\App\Services\EvolutionService::class,fn()=>new \App\Services\EvolutionService((string)config('agenda.evolution_url'),(string)config('agenda.evolution_key')));
        }
        if (config('agenda.edition')) \Livewire\Livewire::addPersistentMiddleware([\App\Http\Middleware\RequireAgendaTenant::class,\App\Http\Middleware\RequireAgendaAccess::class]);
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class,function($event){
            if(config('agenda.edition') && request()->hasSession())request()->session()->put('agenda.access_version',(int)$event->user->agenda_access_version);
        });
        \Illuminate\Validation\Rules\Password::defaults(fn () => \Illuminate\Validation\Rules\Password::min(8));
        // Homologacao roda com copia dos dados da producao: com MAIL_SEMPRE_PARA, todo e-mail
        // (codigo de acesso, resumo para o cliente, alerta) vai para essa caixa e para mais ninguem.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class, function ($event) {
            if ($para = config('mail.sempre_para')) {
                $event->message->to($para);
                $event->message->getHeaders()->remove('Cc');
                $event->message->getHeaders()->remove('Bcc');
            }
        });
        if (config('agenda.edition') && !config('agenda.integrations_enabled') && !$this->app->runningUnitTests()) {
            \Illuminate\Support\Facades\Http::globalRequestMiddleware(function ($request) {
                $uri=$request->getUri();
                if(config('agenda.evolution_enabled') && rtrim((string)config('agenda.evolution_url'),'/')==='http://127.0.0.1:8085' && $uri->getScheme()==='http' && $uri->getHost()==='127.0.0.1' && $uri->getPort()===8085)return $request;
                if(config('agenda.channels_enabled') && $uri->getScheme()==='https' && in_array($uri->getHost(),['graph.facebook.com','graph.instagram.com'],true) && (!$uri->getPort() || $uri->getPort()===443))return $request;
                throw new \RuntimeException('Integrações externas desativadas nesta instalação.');
            });
        }
        \Livewire\Livewire::componentHook(\App\Support\PerfilSomenteLeitura::class);
        \Livewire\Livewire::componentHook(\App\Support\MetodoDoModulo::class);
        \Illuminate\Support\Facades\Gate::before(function($usuario,$ability){
            if ($usuario->ehAuditor() && in_array($ability,['create','update','delete','deleteAny','forceDelete','forceDeleteAny','restore','restoreAny','replicate','reorder'],true)) return false;
            return null;
        });
        \Illuminate\Support\Facades\Event::listen(\App\Events\MessageStored::class, \App\Push\SendBrowserPush::class);
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Logout::class, \App\Push\RemoveBrowserPushOnLogout::class);

        // Registro explicito em vez de descoberta automatica: a descoberta depende de o
        // cache de eventos estar em dia, e um listener que some em producao some em silencio.
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Mail\Events\MessageSent::class,
            \App\Listeners\RegistrarEmailEnviado::class,
        );

        //
    }
}
