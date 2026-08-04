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
        $this->app->singleton(\App\Services\TranscriptionService::class, fn () => new \App\Services\TranscriptionService(
            (bool) config('services.transcricao.ativa'),
            (string) config('services.transcricao.url'),
            (int) config('services.transcricao.max_segundos'),
            (string) config('services.transcricao.vocabulario'),
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
