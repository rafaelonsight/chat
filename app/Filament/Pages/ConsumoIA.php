<?php
namespace App\Filament\Pages;
use App\Models\{Tenant,User};use App\Support\TenantContext;use Illuminate\Support\Facades\DB;use Illuminate\Support\Carbon;use Filament\Pages\Page;use BackedEnum;use UnitEnum;
class ConsumoIA extends Page {
    use \App\Filament\Concerns\PertenceAoModulo;

    protected static string $modulo = 'ia';

 use \App\Filament\TraduzirNavegacao;
 protected static string|BackedEnum|null $navigationIcon='heroicon-o-cpu-chip';
 protected static string|UnitEnum|null $navigationGroup='Relatórios';
 protected static ?string $navigationLabel='Consumo de IA';protected static ?string $title='Consumo de IA';protected static ?string $slug='consumo-ia';protected string $view='filament.pages.consumo-ia';
 public const RECURSOS=['sugestao'=>'Sugestão de resposta','corrigir'=>'Correção de texto','clareza'=>'Clareza','cordial'=>'Tom cordial','encurtar'=>'Encurtar texto','traduzir_uk'=>'Tradução de rascunho','traducao'=>'Tradução de mensagem','traducao_audio'=>'Tradução de áudio','transcricao'=>'Transcrição de áudio','resumo'=>'Resumo de atendimento','teste_configuracao'=>'Teste de configuração','chatbot_ferramenta'=>'Seleção de ferramenta da IA','chatbot_agente'=>'Agente do chatbot','chatbot_supervisor'=>'Supervisor do chatbot'];
 public const PACOTES=[500000=>1990,1000000=>2990,3000000=>6990];
 public function solicitarCompra(int $tokens):void {abort_unless(static::canAccess() && auth()->user()?->admin,403);abort_unless(isset(self::PACOTES[$tokens]),422);$id=TenantContext::get();abort_unless($id,404);DB::transaction(function()use($id,$tokens){Tenant::whereKey($id)->lockForUpdate()->firstOrFail();if(!DB::table('compras_tokens_ia')->where('tenant_id',$id)->where('tokens',$tokens)->where('status','solicitada')->exists())DB::table('compras_tokens_ia')->insert(['tenant_id'=>$id,'user_id'=>auth()->id(),'tokens'=>$tokens,'valor_centavos'=>self::PACOTES[$tokens],'status'=>'solicitada','created_at'=>now(),'updated_at'=>now()]);});\Filament\Notifications\Notification::make()->success()->title(__('Solicitação de compra registrada'))->body(__('Aguarde as instruções de pagamento. Os tokens ainda não foram adicionados ao saldo.'))->send();}
 public string $inicio='';public string $fim='';public string $atendente='';public string $recurso='';
 public static function acessoSemModulo(): bool {return (bool)auth()->user()?->consultaRelatorios();}
 public function mount():void {$tz=Tenant::find(TenantContext::get())?->fuso_horario ?: 'America/Sao_Paulo';$this->inicio=now()->timezone($tz)->startOfMonth()->toDateString();$this->fim=now()->timezone($tz)->toDateString();}
 public function aplicar():void {$this->validar();}
 private function validar():void {$this->validate(['inicio'=>'required|date_format:Y-m-d','fim'=>'required|date_format:Y-m-d|after_or_equal:inicio','atendente'=>'nullable|integer','recurso'=>'nullable|max:80']);}
 private function base(){abort_unless(static::canAccess(),403);$this->validar();$id=TenantContext::get();abort_unless($id,404);$tz=Tenant::find($id)?->fuso_horario ?: 'America/Sao_Paulo';return DB::table('consumos_ia')->where('consumos_ia.tenant_id',$id)->whereBetween('consumos_ia.created_at',[Carbon::parse($this->inicio,$tz)->startOfDay()->timezone(config('app.timezone')),Carbon::parse($this->fim,$tz)->endOfDay()->timezone(config('app.timezone'))])->when($this->atendente,fn($q)=>$q->where('user_id',(int)$this->atendente))->when($this->recurso,fn($q)=>$q->where('recurso',$this->recurso));}
 protected function getViewData():array {
  $q=$this->base();$tz=Tenant::find(TenantContext::get())?->fuso_horario ?: 'America/Sao_Paulo';
  $mensal=DB::table('consumos_ia')->where('tenant_id',TenantContext::get())->whereBetween('created_at',[now()->timezone($tz)->startOfMonth()->timezone(config('app.timezone')),now()->timezone($tz)->endOfMonth()->timezone(config('app.timezone'))])->selectRaw('COALESCE(SUM(total),0) as usados,COUNT(*) FILTER (WHERE total IS NULL OR estimado) as desconhecidas')->first();
  $saldo=\App\Services\SaldoIA::estado(Tenant::findOrFail(TenantContext::get()));$franquia=$saldo['franquia'];
  return ['nomesRecursos'=>self::RECURSOS,'historicoCreditos'=>DB::table('creditos_ia')->where('tenant_id',TenantContext::get())->orderByDesc('id')->limit(30)->get(),'audio'=>DB::table('consumos_ia')->where('tenant_id',TenantContext::get())->whereBetween('created_at',[\Illuminate\Support\Carbon::parse($this->inicio,$tz)->startOfDay()->timezone(config('app.timezone')),\Illuminate\Support\Carbon::parse($this->fim,$tz)->endOfDay()->timezone(config('app.timezone'))])->sum('segundos_audio'),'pacotes'=>self::PACOTES,'compras'=>DB::table('compras_tokens_ia')->where('tenant_id',TenantContext::get())->orderByDesc('id')->limit(10)->get(),'franquia'=>$franquia,'usadosMes'=>(int)$mensal->usados,'saldoExtra'=>$saldo['extras'],'disponiveis'=>$saldo['disponiveis'],'percentual'=>min(100,round((int)$mensal->usados/max(1,$franquia)*100,2)),'desconhecidasMes'=>(int)$mensal->desconhecidas,'renovacao'=>now()->timezone($tz)->addMonthNoOverflow()->startOfMonth()->format('d/m/Y'),'totais'=>(clone $q)->selectRaw('COUNT(*) as chamadas,COALESCE(SUM(entrada),0) as entrada,COALESCE(SUM(saida),0) as saida,COALESCE(SUM(total),0) as total,COUNT(*) FILTER (WHERE total IS NULL OR estimado) as desconhecidas')->first(),
   'dias'=>(clone $q)->selectRaw('DATE(created_at AT TIME ZONE ? AT TIME ZONE ?) as dia,COUNT(*) as chamadas,SUM(total) as total',[config('app.timezone'),$tz])->groupByRaw('1')->orderBy('dia')->get(),
   'recursos'=>(clone $q)->selectRaw('recurso,modelo,COUNT(*) as chamadas,SUM(entrada) as entrada,SUM(saida) as saida,SUM(total) as total')->groupBy('recurso','modelo')->orderByDesc('total')->get(),
   'pessoas'=>(clone $q)->leftJoin('users as u',fn($j)=>$j->on('u.id','=','consumos_ia.user_id')->on('u.tenant_id','=','consumos_ia.tenant_id'))->selectRaw("COALESCE(u.name,'Automação / sem atendente') as nome,COUNT(*) as chamadas,SUM(total) as total")->groupBy('u.id','u.name')->orderByDesc('total')->get(),
   'usuarios'=>User::where('tenant_id',TenantContext::get())->orderBy('name')->get(),
   'opcoes'=>DB::table('consumos_ia')->where('tenant_id',TenantContext::get())->distinct()->pluck('recurso'),
   'primeiro'=>DB::table('consumos_ia')->where('tenant_id',TenantContext::get())->min('created_at')];
 }
 public function exportar(){ $linhas=$this->base()->orderBy('id')->get();return response()->streamDownload(function()use($linhas){$f=fopen('php://output','w');fwrite($f,"\xEF\xBB\xBF");fputcsv($f,['Data','Atendente ID','Recurso','Provedor','Modelo','Entrada','Saída','Total'],';');foreach($linhas as $l){$campos=[$l->created_at,$l->user_id,$l->recurso,$l->provedor,$l->modelo,$l->entrada,$l->saida,$l->total];fputcsv($f,array_map(fn($v)=>preg_match('/^[=+@\-\t\r\n]/',(string)$v)?"'".$v:$v,$campos),';');}fclose($f);},'consumo-ia.csv');}
}
