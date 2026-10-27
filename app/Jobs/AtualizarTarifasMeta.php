<?php
namespace App\Jobs;
use App\Services\TarifasMeta;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
class AtualizarTarifasMeta implements ShouldQueue {
 use Queueable;
 public int $timeout=100;
 public function __construct(public string $mercado,public string $moeda){}
 public function middleware(): array {return [(new WithoutOverlapping('meta-tarifas-'.$this->mercado.'-'.$this->moeda))->expireAfter(120)];}
 public function handle(TarifasMeta $servico): void {$servico->atualizar($this->mercado,$this->moeda);}
}
