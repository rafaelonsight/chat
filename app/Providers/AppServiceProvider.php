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
