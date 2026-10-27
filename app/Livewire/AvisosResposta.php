<?php
namespace App\Livewire;
use Livewire\Component;use App\Services\AlertasResposta;
class AvisosResposta extends Component {
 public function conferir():void {$this->dispatch('virtus-atrasos',avisos:app(AlertasResposta::class)->pendentes());}
 public function render(){return view('livewire.avisos-resposta');}
}
