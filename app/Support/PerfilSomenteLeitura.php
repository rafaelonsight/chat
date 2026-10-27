<?php
namespace App\Support;
use Livewire\ComponentHook;
class PerfilSomenteLeitura extends ComponentHook {
 public function call($method,$params,$returnEarly): void {
  if(config('agenda.edition') && str_starts_with(get_class($this->component),'App\\Livewire\\Inbox\\')) {
   abort_if(auth()->user()?->isAgendaProfessional(),403);
   abort_if(in_array($method,['traduzirMensagem','sugerirEscrita','sugerirResposta','gerarResumoIA','reenviarResumo','chamarPorVideo'],true),403,'Recurso ainda não habilitado nesta instalação.');
  }
  if (! auth()->user()?->ehAuditor()) return;
  if ($this->component instanceof \App\Filament\Pages\AgendaFinanceReports && $method==='export') return;
  if ($this->component instanceof \App\Filament\Pages\AgendaFinanceiro && $method==='open' && ($params[1]??'')==='history') return;
  if ($this->component instanceof \App\Filament\Pages\Auth\Perfil) return;
  if ($this->component instanceof \App\Filament\Pages\QualidadeAtendimento && in_array($method,['aplicar','revisar','cancelar'],true)) return;
  if ($this->component instanceof \App\Filament\Pages\RelatorioAtendentes && in_array($method,['aplicar','selecionarAtendente'],true)) return;
  if ($this->component instanceof \App\Filament\Pages\RelatorioEtiquetas && in_array($method,['aplicar','selecionarEtiqueta'],true)) return;
  $leituras=['periodo','$refresh','$set','$toggle','abrir','carregarMais','talvezAtualizar','limparBusca','limparFiltros','verDetalhes','fechar','cancelarResposta','cancelarAnotacao','carregarParticipantesGrupo','buscar','atualizar','selecionar','selecionarConversa','trocarBalde','filtrarEtiqueta','alternarBusca','alternarFiltros','alternarNaoLidas','gotoPage','nextPage','previousPage','setPage','sortTable','resetTableFilters','removeTableFilter','removeTableFilters','search','loadTable','resetTablePage','toggleTableColumn','reorderTableColumns','getTableRecords','ver'];
  if ($method === '__dispatch') { $listeners=$this->component->getListeners(); $method=$listeners[$params[0]??'']??''; }
  abort_unless(in_array($method,$leituras,true),403,'O perfil Auditor permite somente consulta.');
 }
}
