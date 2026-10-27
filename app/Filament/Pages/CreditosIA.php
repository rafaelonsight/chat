<?php
namespace App\Filament\Pages;
use App\Models\Tenant;use Illuminate\Support\Facades\DB;use Filament\Pages\Page;use BackedEnum;use UnitEnum;
class CreditosIA extends Page {
    use \App\Filament\Concerns\PertenceAoModulo;

    protected static string $modulo = 'ia';

 protected static string|BackedEnum|null $navigationIcon='heroicon-o-banknotes';protected static string|UnitEnum|null $navigationGroup='Configurações';protected static ?string $navigationLabel='Créditos de IA';protected static ?string $title='Créditos de IA';protected static ?string $slug='creditos-ia';protected string $view='filament.pages.creditos-ia';
 public string $conta='';public int $franquia=1000000;public int $adicionar=0;public int $tarifaAudio=1000;public string $motivo='';
 public static function acessoSemModulo(): bool{return (bool)auth()->user()?->super_admin;}
 public function mount():void{abort_unless(static::canAccess(),403);$this->tarifaAudio=(int)\App\Models\SystemSetting::ler('ia_creditos_minuto_audio','1000');}
 public function carregar():void{abort_unless(static::canAccess(),403);$t=Tenant::withoutGlobalScopes()->findOrFail((int)$this->conta);$this->franquia=(int)$t->franquia_ia;$this->adicionar=0;$this->motivo='';}
 public function salvar():void {abort_unless(static::canAccess(),403);$this->validate(['conta'=>'required|integer|exists:tenants,id','tarifaAudio'=>'required|integer|min:1|max:1000000','franquia'=>'required|integer|min:0|max:1000000000','adicionar'=>'required|integer|min:0|max:1000000000','motivo'=>'required|string|max:250']);DB::transaction(function(){DB::select('SELECT pg_advisory_xact_lock(7391, ?)',[(int)$this->conta]);$t=Tenant::withoutGlobalScopes()->findOrFail((int)$this->conta);$t->forceFill(['franquia_ia'=>$this->franquia,'extras_ia'=>(int)$t->extras_ia+$this->adicionar])->save();DB::table('creditos_ia')->insert(['tenant_id'=>$t->id,'user_id'=>auth()->id(),'tokens'=>$this->adicionar,'motivo'=>$this->motivo.' | franquia: '.$this->franquia,'created_at'=>now()]);});\App\Models\SystemSetting::gravar('ia_creditos_minuto_audio',(string)$this->tarifaAudio);$this->adicionar=0;$this->motivo='';\Filament\Notifications\Notification::make()->success()->title('Saldo atualizado')->send();}
 protected function getViewData():array{abort_unless(static::canAccess(),403);return ['contas'=>Tenant::withoutGlobalScopes()->orderBy('nome')->get(),'saldo'=>$this->conta?\App\Services\SaldoIA::estado(Tenant::withoutGlobalScopes()->findOrFail((int)$this->conta)):null];}
}
