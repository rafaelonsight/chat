<?php

namespace App\Filament\Pages;

use App\Models\{BoardField, BoardItem, Contact, Funnel, FunnelStage, Tag, User};
use App\Services\AssignBoardItem;
use App\Support\BoardAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class Paineis extends Page
{
    use \App\Filament\TraduzirNavegacao;

    use WithFileUploads, WithPagination;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-view-columns';
    protected static string|\UnitEnum|null $navigationGroup = 'CRM';
    protected static ?string $navigationLabel = 'Painéis';
    protected static ?string $title = 'Painéis e tarefas';
    protected static ?string $slug = 'paineis';
    protected string $view = 'filament.pages.paineis';

    public ?int $funilId = null;
    #[Locked] public ?int $itemId = null;
    #[Locked] public int $version = 1;
    public string $visualizacao = 'lista';
    public string $busca = '';
    public string $filtroPrioridade = '';
    public bool $soMinhas = false;
    public bool $arquivados = false;
    public bool $editandoItem = false;
    public bool $configurando = false;
    public string $novoPainel = '';
    public array $form = [];
    public array $colunas = [];
    public array $campos = [];
    public array $membros = [];
    public bool $restrito = false;
    public string $nomePainel = '';
    public string $novoChecklist = '';
    public string $novaSubtarefa = '';
    public ?int $dependencia = null;
    public $arquivo;
    public string $novaEtiqueta = '';
    public bool $confirmarConclusao = false;
    public string $espaco = '';
    public ?int $etapaRemover = null;
    public ?int $destinoEtapa = null;
    public bool $mostrarModelos = false;
    public string $filtroEtiqueta = '';
    public string $filtroResponsavel = '';
    public string $filtroStatus = '';
    public string $filtroContato = '';
    public string $filtroPrazo = '';
    public string $buscaContato = '';
    public bool $paineisArquivados = false;

    private function tenant(): int { return (int) auth()->user()->tenant_id; }

    private function boards()
    {
        $actor = auth()->user();
        $query = Funnel::withoutGlobalScopes()->where('tenant_id', $this->tenant())
            ->when($this->paineisArquivados,fn($q)=>$q->whereNotNull('arquivado_em'),fn($q)=>$q->whereNull('arquivado_em'));
        if (! $actor->veTudo()) {
            $query->where(function ($q) use ($actor) {
                $q->where('restrito', false)->orWhereExists(function ($m) use ($actor) {
                    $m->selectRaw('1')->from('board_members')->whereColumn('funnel_id', 'funnels.id')
                        ->where('board_members.tenant_id', $this->tenant())->where('user_id', $actor->id)
                        ->whereIn('papel', ['gestor', 'editor', 'leitor']);
                });
            });
        }
        return $query;
    }

    private function board(string $ability = 'view'): Funnel
    {
        $board = $this->boards()->findOrFail($this->funilId);
        abort_if($ability==='edit' && $board->arquivado_em,403);
        $access = app(BoardAccess::class);
        abort_unless(match ($ability) {
            'edit' => $access->canEdit(auth()->user(), $board),
            'manage' => $access->canManage(auth()->user(), $board),
            default => $access->canView(auth()->user(), $board),
        }, 403);
        return $board;
    }

    private function item(int $id, string $ability = 'view'): BoardItem
    {
        $this->board($ability);
        return BoardItem::withoutGlobalScopes()->where('tenant_id', $this->tenant())
            ->where('funnel_id', $this->funilId)->findOrFail($id);
    }

    public function mount(): void
    {
        $requested = (int) request()->query('painel');
        $this->funilId = $requested ?: $this->boards()->orderBy('ordem')->value('id');
        if ($this->funilId) $this->board();
        if ($id = (int) request()->query('item')) $this->editarItem($id);
    }

    public function updated($property): void
    {
        if($property==='paineisArquivados'){$this->funilId=$this->boards()->orderBy('ordem')->value('id');$this->editandoItem=$this->configurando=false;$this->itemId=null;}
        if (in_array($property, ['busca', 'soMinhas', 'arquivados', 'filtroPrioridade','mostrarModelos','filtroEtiqueta','filtroResponsavel','filtroStatus','filtroContato','filtroPrazo','paineisArquivados'])) $this->resetPage();
    }

    public function abrirFunil(int $id): void
    {
        $this->funilId = $this->boards()->findOrFail($id)->id;
        $this->board();
        $this->editandoItem = $this->configurando = false;
        $this->itemId = null;
        $this->filtroStatus = '';
        $this->resetPage();
    }

    public function criarFunil(): void
    {
        abort_unless(auth()->user()->veTudo(), 403);
        $this->novoPainel = trim($this->novoPainel);
        $this->validate(['novoPainel' => 'required|string|max:60']);
        $this->funilId = DB::transaction(function () {
            $board=Funnel::create(['tenant_id'=>$this->tenant(),'nome'=>$this->novoPainel,'ordem'=>(int)Funnel::max('ordem')+1]);
            foreach ([['A fazer','cinza',false],['Em andamento','ambar',false],['Concluído','verde',true]] as $i=>$row) {
                FunnelStage::create(['tenant_id'=>$this->tenant(),'funnel_id'=>$board->id,'nome'=>$row[0],'cor'=>$row[1],'encerra'=>$row[2],'ordem'=>$i]);
            }
            return $board->id;
        });
        $this->novoPainel = '';
        $this->ok('Painel criado');
    }

    public function arquivarPainel():void
    {
        DB::transaction(function(){
            $board=Funnel::where('tenant_id',$this->tenant())->lockForUpdate()->findOrFail($this->funilId);
            abort_unless(app(BoardAccess::class)->canManage(auth()->user(),$board),403);
            $board->forceFill(['arquivado_em'=>$board->arquivado_em?null:now()])->save();
        });
        $this->funilId=$this->boards()->orderBy('ordem')->value('id');$this->itemId=null;$this->editandoItem=$this->configurando=false;
        $this->ok('Painel atualizado; tarefas, conversas e histórico preservados');
    }

    public function abrirNovoItem(?int $etapa = null): void
    {
        $this->board('edit');
        $etapa = $etapa ?: FunnelStage::where('funnel_id', $this->funilId)->orderBy('ordem')->value('id');
        FunnelStage::where('funnel_id', $this->funilId)->findOrFail($etapa);
        $this->resetValidation();
        $this->itemId = null;
        $this->version = 1;
        $this->form = ['titulo' => '', 'descricao' => '', 'funnel_stage_id' => $etapa, 'prioridade' => 'normal',
            'prazo_em' => null, 'inicio_em'=>null,'recorrencia'=>null,'contact_id' => null, 'responsaveis' => [auth()->id()], 'tags' => [], 'dados' => []];
        $this->editandoItem = true;
    }

    public function editarItem(int $id): void
    {
        $item = $this->item($id);
        $this->resetValidation();
        $this->itemId = $item->id;
        $this->version = $item->version;
        $this->confirmarConclusao = false;
        $this->form = ['titulo' => $item->titulo, 'descricao' => $item->descricao ?? '',
            'funnel_stage_id' => $item->funnel_stage_id, 'prioridade' => $item->prioridade,
            'prazo_em' => $item->prazo_em?->format('Y-m-d\TH:i'), 'contact_id' => $item->contact_id,
            'inicio_em'=>$item->inicio_em?->format('Y-m-d\TH:i'),'recorrencia'=>$item->recorrencia,
            'responsaveis' => $item->responsaveis()->pluck('users.id')->all(),
            'tags' => $item->tags()->pluck('tags.id')->all(), 'dados' => $item->dados ?? []];
        if (! $this->form['responsaveis'] && $item->responsavel_id) $this->form['responsaveis'] = [$item->responsavel_id];
        $this->editandoItem = true;
    }

    public function salvarItem(): void
    {
        $this->board('edit');
        $this->form['titulo'] = trim($this->form['titulo'] ?? '');
        $rules = ['form.titulo' => 'required|string|max:160', 'form.descricao' => 'nullable|string|max:10000',
            'form.funnel_stage_id' => ['required', Rule::exists('funnel_stages', 'id')->where('funnel_id', $this->funilId)->where('tenant_id', $this->tenant())],
            'form.prioridade' => 'required|in:baixa,normal,alta,urgente', 'form.prazo_em' => 'nullable|date',
            'form.inicio_em'=>'nullable|date','form.recorrencia'=>'nullable|in:diaria,semanal,mensal',
            'form.contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('tenant_id', $this->tenant())->where('tipo', Contact::PESSOA)],
            'form.responsaveis' => 'array|max:50', 'form.responsaveis.*' => 'integer',
            'form.tags' => 'array|max:50', 'form.tags.*' => ['integer', Rule::exists('tags', 'id')->where('tenant_id', $this->tenant())],
            'form.dados' => 'array'];
        $fields = BoardField::where('funnel_id', $this->funilId)->whereNull('arquivado_em')->get();
        foreach ($fields as $field) {
            $rules['form.dados.'.$field->id] = [$field->obrigatorio ? 'required' : 'nullable', match ($field->tipo) {
                'numero', 'dinheiro' => 'numeric', 'data' => 'date', 'checkbox' => 'boolean',
                'lista' => Rule::in($field->opcoes ?? []), default => 'string',
            }];
        }
        $this->validate($rules);
        DB::transaction(function () use ($fields) {
            $lockedBoard=Funnel::where('tenant_id',$this->tenant())->lockForUpdate()->findOrFail($this->funilId);
            abort_unless(app(BoardAccess::class)->canEdit(auth()->user(),$lockedBoard),403);
            $item = $this->itemId ? $this->lockedItem() : new BoardItem;
            if ($item->exists) $this->verificarConclusao($item, (int)$this->form['funnel_stage_id'], $this->confirmarConclusao);
            $values = array_intersect_key($this->form['dados'], array_flip($fields->pluck('id')->all()));
            $wasClosed=$item->exists && (bool)$item->stage?->encerra;
            $item->fill(collect($this->form)->only(['titulo','descricao','funnel_stage_id','prioridade','prazo_em','contact_id','inicio_em','recorrencia'])->all());
            $item->inicio_em=$this->form['inicio_em']?:null;
            $item->recorrencia=$this->form['recorrencia']?:null;
            if($item->inicio_em && $item->prazo_em && $item->inicio_em->gt($item->prazo_em))throw \Illuminate\Validation\ValidationException::withMessages(['form.prazo_em'=>'O prazo deve ser posterior ao início.']);
            $item->contact_id = $item->contact_id ?: null;
            $item->prazo_em = $this->form['prazo_em'] ?: null;
            $item->dados = array_replace($item->dados??[], $values);
            if (! $item->exists) $item->fill(['tenant_id' => $this->tenant(), 'funnel_id' => $this->funilId, 'created_by' => auth()->id()]);
            if ($item->isDirty('funnel_stage_id')) $item->etapa_em = now();
            $item->version = ($item->exists ? $item->version : 0) + 1;
            $item->save();
            app(AssignBoardItem::class)->execute(auth()->user(), $item->id, array_map('intval', $this->form['responsaveis']));
            $validTags = Tag::deItem()->whereIn('id', $this->form['tags'])->pluck('id');
            app(\App\Services\Etiquetador::class)->sincronizar($item,$validTags->all(),\App\Services\Etiquetador::MANUAL,auth()->id());
            DB::table('board_watchers')->insertOrIgnore(['board_item_id' => $item->id, 'user_id' => auth()->id()]);
            $this->itemId = $item->id;
            if(!$wasClosed && FunnelStage::whereKey($item->funnel_stage_id)->value('encerra'))$this->gerarRecorrencia($item);
        });
        $this->editarItem($this->itemId);
        $this->ok('Tarefa salva');
    }

    private function lockedItem(): BoardItem
    {
        $board=Funnel::where('tenant_id',$this->tenant())->lockForUpdate()->findOrFail($this->funilId);
        abort_if($board->arquivado_em,403);
        abort_unless(app(BoardAccess::class)->canEdit(auth()->user(),$board),403);
        $item = BoardItem::where('tenant_id', $this->tenant())->where('funnel_id', $this->funilId)->lockForUpdate()->findOrFail($this->itemId);
        if ($item->version !== $this->version) {
            throw \Illuminate\Validation\ValidationException::withMessages(['conflito' => 'Outra pessoa alterou esta tarefa. Copie seu rascunho e reabra antes de salvar.']);
        }
        return $item;
    }

    private function verificarConclusao(BoardItem $item, int $stage, bool $confirm=false): void
    {
        app(\App\Support\TaskWorkflow::class)->assertCompletion($item,$stage,$confirm);
    }

    public function moverItem(int $id, int $stage, int $version): void
    {
        $this->item($id, 'edit');
        FunnelStage::where('funnel_id', $this->funilId)->findOrFail($stage);
        app(\App\Support\TaskWorkflow::class)->transition(auth()->user(),$id,$stage,$version);
    }

    public function arquivarItem(): void
    {
        $this->board('edit');
        DB::transaction(fn () => $this->lockedItem()->update(['arquivado_em' => now(), 'version' => $this->version + 1]));
        $this->editandoItem = false;
        $this->ok('Tarefa arquivada — disponível no filtro Arquivadas');
    }

    public function restaurarItem(): void
    {
        $this->board('edit');
        DB::transaction(fn () => $this->lockedItem()->update(['arquivado_em' => null, 'version' => $this->version + 1]));
        $this->editarItem($this->itemId);
        $this->ok('Tarefa restaurada');
    }

    public function adicionarChecklist(): void
    {
        $this->board('edit');
        $this->novoChecklist = trim($this->novoChecklist);
        $this->validate(['novoChecklist' => 'required|string|max:200']);
        $newVersion=DB::transaction(function () {
            $item = $this->lockedItem();
            $list = $item->checklist ?? [];
            abort_if(count($list) >= 100, 422);
            $list[] = ['id' => (string) \Illuminate\Support\Str::uuid(), 'texto' => $this->novoChecklist, 'feito' => false];
            $item->update(['checklist' => $list, 'version' => $item->version + 1]);
            return $item->version;
        });
        $this->novoChecklist = '';
        $this->version = $newVersion;
    }

    public function marcarChecklist(string $id): void
    {
        $this->board('edit');
        $newVersion=DB::transaction(function () use ($id) {
            $item = $this->lockedItem();
            $list = collect($item->checklist ?? [])->map(function ($row) use ($id) { if ($row['id'] === $id) $row['feito'] = ! $row['feito']; return $row; })->all();
            $item->update(['checklist' => $list, 'version' => $item->version + 1]);
            return $item->version;
        });
        $this->version = $newVersion;
    }

    public function criarSubtarefa(): void
    {
        $parent = $this->item($this->itemId, 'edit');
        abort_if($parent->parent_id, 422, 'Um nível de subtarefas é permitido.');
        $this->novaSubtarefa = trim($this->novaSubtarefa);
        $this->validate(['novaSubtarefa' => 'required|string|max:160']);
        DB::transaction(fn () => BoardItem::create(['tenant_id' => $this->tenant(), 'funnel_id' => $this->funilId,
            'funnel_stage_id' => FunnelStage::where('funnel_id', $this->funilId)->orderBy('ordem')->value('id'),
            'titulo' => $this->novaSubtarefa, 'parent_id' => $parent->id, 'created_by' => auth()->id(), 'version' => 1]));
        $this->novaSubtarefa = '';
    }

    public function alterarChecklist(string $id,?string $texto=null):void
    {
        $this->board('edit');
        if($texto!==null && (trim($texto)===''||mb_strlen($texto)>200))throw \Illuminate\Validation\ValidationException::withMessages(['checklist'=>'Informe um texto de até 200 caracteres.']);
        $this->version=DB::transaction(function()use($id,$texto){
            $item=$this->lockedItem();$rows=collect($item->checklist??[]);
            abort_unless($rows->contains('id',$id),404);
            $rows=$texto===null?$rows->reject(fn($r)=>$r['id']===$id):$rows->map(function($r)use($id,$texto){if($r['id']===$id)$r['texto']=trim($texto);return $r;});
            $item->update(['checklist'=>$rows->values()->all(),'version'=>$item->version+1]);
            $item->updates()->create(['tenant_id'=>$this->tenant(),'user_id'=>auth()->id(),'tipo'=>'checklist','corpo'=>$texto===null?'Item do checklist removido.':'Texto do checklist atualizado.']);
            return $item->version;
        });
    }

    public function adicionarDependencia(): void
    {
        $this->item($this->itemId, 'edit');
        $this->item((int) $this->dependencia);
        DB::transaction(function () {
            // A board-wide lock serializes edge insertion and cycle checks.
            Funnel::whereKey($this->funilId)->lockForUpdate()->firstOrFail();
            $edges = DB::table('board_dependencies')->whereIn('board_item_id', BoardItem::where('funnel_id', $this->funilId)->select('id'))->get()->groupBy('board_item_id');
            $queue = [(int) $this->dependencia]; $seen = [];
            while ($queue) {
                $id = array_pop($queue);
                if ($id === $this->itemId) throw \Illuminate\Validation\ValidationException::withMessages(['dependencia' => 'Esta dependência criaria um ciclo.']);
                if (isset($seen[$id])) continue;
                $seen[$id] = true;
                foreach ($edges->get($id, collect()) as $edge) $queue[] = (int) $edge->depends_on_id;
            }
            DB::table('board_dependencies')->insertOrIgnore(['board_item_id' => $this->itemId, 'depends_on_id' => $this->dependencia]);
        });
        $this->dependencia = null;
    }

    public function removerDependencia(int $id): void
    {
        $this->item($this->itemId, 'edit');
        DB::table('board_dependencies')->where('board_item_id', $this->itemId)->where('depends_on_id', $id)->delete();
    }

    public function acompanhar(): void
    {
        $this->item($this->itemId);
        $q = DB::table('board_watchers')->where('board_item_id', $this->itemId)->where('user_id', auth()->id());
        if ($q->exists()) $q->delete(); else DB::table('board_watchers')->insertOrIgnore(['board_item_id' => $this->itemId, 'user_id' => auth()->id()]);
    }

    public function anexar(): void
    {
        $this->item($this->itemId, 'edit');
        $this->validate(['arquivo' => 'required|file|max:25600|mimes:pdf,txt,csv,png,jpg,jpeg,webp,doc,docx,xls,xlsx,zip,mp3,wav,ogg,webm,m4a,mp4']);
        app(\App\Support\StoreTaskMedia::class)->store($this->tenant(),$this->arquivo,'board-media',function($path){
            $board=Funnel::where('tenant_id',$this->tenant())->lockForUpdate()->findOrFail($this->funilId);
            $this->item($this->itemId,'edit');
            abort_if(DB::table('board_attachments')->where('board_item_id',$this->itemId)->count()>=100,422);
            DB::table('board_attachments')->insert(['tenant_id' => $this->tenant(), 'board_item_id' => $this->itemId,
                'user_id' => auth()->id(), 'path' => $path, 'name' => mb_substr(basename($this->arquivo->getClientOriginalName()), 0, 200),
                'mime' => $this->arquivo->getMimeType(), 'size' => $this->arquivo->getSize(), 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->arquivo = null;
        $this->ok('Arquivo anexado');
    }

    public function baixar(int $id)
    {
        $file = DB::table('board_attachments')->where('tenant_id', $this->tenant())->find($id);
        abort_unless($file, 404);
        $this->item($file->board_item_id);
        return Storage::disk('local')->download($file->path, $file->name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function configurar(): void
    {
        $board = $this->board('manage');
        $this->nomePainel = $board->nome;
        $this->espaco = $board->espaco ?? '';
        $this->restrito = (bool) $board->restrito;
        $this->membros = DB::table('board_members')->where('funnel_id', $board->id)->pluck('papel', 'user_id')->all();
        $this->colunas = FunnelStage::where('funnel_id', $board->id)->orderBy('ordem')->get(['id','nome','cor','encerra'])->toArray();
        $this->campos = BoardField::where('funnel_id', $board->id)->whereNull('arquivado_em')->orderBy('ordem')->get()->map(fn ($f) => ['id' => $f->id, 'nome' => $f->nome, 'tipo' => $f->tipo, 'opcoes' => implode(',', $f->opcoes ?? []), 'obrigatorio' => $f->obrigatorio])->all();
        $this->configurando = true;
    }

    public function adicionarColuna(): void { $this->board('manage'); $this->colunas[] = ['id'=>null,'nome'=>'','cor'=>'cinza','encerra'=>false]; }
    public function adicionarCampo(): void { $this->board('manage'); $this->campos[] = ['id'=>null,'nome'=>'','tipo'=>'texto','opcoes'=>'','obrigatorio'=>false]; }

    public function salvarConfiguracao(): void
    {
        $this->board('manage');
        $this->validate(['nomePainel'=>'required|string|max:60','espaco'=>'nullable|string|max:60','restrito'=>'boolean','membros'=>'array','membros.*'=>'nullable|in:gestor,editor,leitor',
            'colunas'=>'array|min:1|max:30','colunas.*.nome'=>'required|string|max:40','colunas.*.cor'=>['required',Rule::in(array_keys(Tag::PALETA))],
            'colunas.*.encerra'=>'boolean','campos'=>'array|max:30','campos.*.nome'=>'required|string|max:60',
            'campos.*.tipo'=>Rule::in(array_keys(BoardField::TIPOS)),'campos.*.opcoes'=>'nullable|string|max:1000','campos.*.obrigatorio'=>'boolean']);
        if (!collect($this->colunas)->contains(fn($row)=>!$row['encerra'])) throw \Illuminate\Validation\ValidationException::withMessages(['colunas'=>'Mantenha pelo menos uma coluna em aberto para novas tarefas e recorrências.']);
        DB::transaction(function () {
            $board = Funnel::where('tenant_id', $this->tenant())->lockForUpdate()->findOrFail($this->funilId);
            abort_unless(app(BoardAccess::class)->canManage(auth()->user(),$board),403);
            foreach (array_filter($this->membros) as $id => $role) User::where('tenant_id', $this->tenant())->findOrFail($id);
            $board->forceFill(['nome'=>$this->nomePainel,'restrito'=>$this->restrito,'espaco'=>trim($this->espaco)?:null])->save();
            DB::table('board_members')->where('funnel_id', $board->id)->delete();
            foreach (array_filter($this->membros) as $id => $role) DB::table('board_members')->insert(['tenant_id'=>$this->tenant(),'funnel_id'=>$board->id,'user_id'=>$id,'papel'=>$role,'created_at'=>now(),'updated_at'=>now()]);
            foreach ($this->colunas as $i=>$row) {
                $stage = !empty($row['id']) ? FunnelStage::where('funnel_id',$board->id)->findOrFail($row['id']) : new FunnelStage;
                $stage->fill(['tenant_id'=>$this->tenant(),'funnel_id'=>$board->id,'nome'=>$row['nome'],'cor'=>$row['cor'],'encerra'=>$row['encerra'],'ordem'=>$i])->save();
            }
            foreach ($this->campos as $i=>$row) {
                $field = !empty($row['id']) ? BoardField::where('funnel_id',$board->id)->findOrFail($row['id']) : new BoardField;
                if ($field->exists && $field->tipo !== $row['tipo']) throw \Illuminate\Validation\ValidationException::withMessages(['campos'=>'O tipo de um campo existente não pode ser alterado sem migração.']);
                $field->fill(['tenant_id'=>$this->tenant(),'funnel_id'=>$board->id,'nome'=>$row['nome'],'tipo'=>$row['tipo'],'opcoes'=>array_values(array_filter(array_map('trim',explode(',',$row['opcoes']??'')))),'obrigatorio'=>$row['obrigatorio'],'ordem'=>$i])->save();
            }
        });
        $this->configurando = false;
        $this->ok('Configurações salvas');
    }

    public string $corNovaEtiquetaPainel='amarelo';
    public function criarEtiqueta(): void
    {
        abort_unless(auth()->user()?->admin && !auth()->user()?->ehAuditor(),403);
        $this->board('edit');
        $this->validate(['novaEtiqueta'=>'required|string|max:40','corNovaEtiquetaPainel'=>['required',Rule::in(array_keys(Tag::PALETA))]]);
        $tag = Tag::firstOrCreate(['tenant_id'=>$this->tenant(),'nome'=>trim($this->novaEtiqueta),'contexto'=>'item'], ['cor'=>$this->corNovaEtiquetaPainel]);
        $this->form['tags'][] = $tag->id;
        $this->novaEtiqueta = '';
    }

    private function ok(string $title): void { Notification::make()->title($title)->success()->send(); }

    public function favoritar(): void
    {
        $board=$this->board();$q=DB::table('board_favorites')->where('funnel_id',$board->id)->where('user_id',auth()->id());
        if($q->exists())$q->delete();else DB::table('board_favorites')->insertOrIgnore(['funnel_id'=>$board->id,'user_id'=>auth()->id()]);
    }

    public function removerColuna(): void
    {
        $this->board('manage');
        $from=FunnelStage::where('funnel_id',$this->funilId)->findOrFail($this->etapaRemover);
        $to=FunnelStage::where('funnel_id',$this->funilId)->findOrFail($this->destinoEtapa);
        abort_if($from->id===$to->id,422,'Escolha uma coluna de destino diferente.');
        if($to->encerra)throw \Illuminate\Validation\ValidationException::withMessages(['destinoEtapa'=>'Transfira para uma coluna em aberto. A conclusão deve ser feita individualmente, respeitando pendências e recorrência.']);
        DB::transaction(function()use($from,$to){
            $board=Funnel::where('tenant_id',$this->tenant())->lockForUpdate()->findOrFail($this->funilId);
            abort_unless(app(BoardAccess::class)->canManage(auth()->user(),$board),403);
            $from=FunnelStage::where('funnel_id',$board->id)->findOrFail($from->id);
            $to=FunnelStage::where('funnel_id',$board->id)->findOrFail($to->id);
            if($to->encerra)throw \Illuminate\Validation\ValidationException::withMessages(['destinoEtapa'=>'Escolha uma coluna em aberto.']);
            foreach(BoardItem::where('funnel_stage_id',$from->id)->where('funnel_id',$this->funilId)->lockForUpdate()->get() as $item){
                $item->update(['funnel_stage_id'=>$to->id,'version'=>$item->version+1,'etapa_em'=>now()]);
            }
            \App\Models\Conversation::where('funnel_stage_id',$from->id)->update(['funnel_stage_id'=>$to->id]);
            $from->delete();
        });$this->configurar();
    }

    public function arquivarCampo(int $id): void
    {
        $this->board('manage');BoardField::where('funnel_id',$this->funilId)->findOrFail($id)->forceFill(['arquivado_em'=>now()])->save();$this->configurar();
    }

    public function duplicar(bool $modelo=false): void
    {
        $original=$this->item($this->itemId,'edit');
        $new=DB::transaction(fn()=> $this->copiar($original,$modelo));
        $this->mostrarModelos=$modelo;$this->editarItem($new->id);$this->ok($modelo?'Modelo salvo':'Tarefa criada a partir da cópia');
    }

    private function copiar(BoardItem $original,bool $modelo=false):BoardItem
    {
        $stage=FunnelStage::where('funnel_id',$original->funnel_id)->where('encerra',false)->orderBy('ordem')->firstOrFail();
        $new=BoardItem::create(['tenant_id'=>$original->tenant_id,'funnel_id'=>$original->funnel_id,'funnel_stage_id'=>$stage->id,
            'titulo'=>$original->titulo,'descricao'=>$original->descricao,'prioridade'=>$original->prioridade,'contact_id'=>$original->contact_id,
            'dados'=>$original->dados,'checklist'=>collect($original->checklist??[])->map(fn($c)=>['id'=>(string)\Illuminate\Support\Str::uuid(),'texto'=>$c['texto'],'feito'=>false])->all(),
            'created_by'=>auth()->id(),'modelo'=>$modelo,'version'=>1]);
        $new->tags()->sync($original->tags()->pluck('tags.id')->mapWithKeys(fn($id)=>[$id=>['origem'=>'manual','aplicado_por'=>auth()->id(),'created_at'=>now()]])->all());
        return $new;
    }

    private function gerarRecorrencia(BoardItem $original):void
    {
        app(\App\Support\TaskWorkflow::class)->recur($original,auth()->user());
    }

    public function getViewData(): array
    {
        $board = $this->funilId ? $this->board() : null;
        $items = BoardItem::where('tenant_id',$this->tenant())->where('funnel_id',$board?->id)
            ->where('modelo',$this->mostrarModelos)
            ->with(['responsaveis','tags','stage'])->when($this->arquivados,fn($q)=>$q->whereNotNull('arquivado_em'),fn($q)=>$q->whereNull('arquivado_em'))
            ->when($this->busca,fn($q)=>$q->where('titulo','ilike','%'.$this->busca.'%'))
            ->when($this->filtroPrioridade,fn($q)=>$q->where('prioridade',$this->filtroPrioridade))
            ->when($this->filtroContato,fn($q)=>$q->where('contact_id',$this->filtroContato))
            ->when($this->filtroPrazo==='atrasadas',fn($q)=>$q->where('prazo_em','<',now())->whereHas('stage',fn($s)=>$s->where('encerra',false)))
            ->when($this->filtroPrazo==='sem-prazo',fn($q)=>$q->whereNull('prazo_em'))
            ->when($this->filtroPrazo==='proximas',fn($q)=>$q->whereBetween('prazo_em',[now(),now()->addDays(7)]))
            ->when($this->filtroStatus,fn($q)=>$q->where('funnel_stage_id',$this->filtroStatus))
            ->when($this->filtroEtiqueta,fn($q)=>$q->whereHas('tags',fn($t)=>$t->where('tags.id',$this->filtroEtiqueta)))
            ->when($this->filtroResponsavel,fn($q)=>$q->whereHas('responsaveis',fn($t)=>$t->where('users.id',$this->filtroResponsavel)))
            ->when($this->soMinhas,fn($q)=>$q->where(fn($q)=>$q->where('responsavel_id',auth()->id())->orWhereHas('responsaveis',fn($u)=>$u->where('users.id',auth()->id()))));
        $selected = $this->editandoItem && $this->itemId ? $this->item($this->itemId) : null;
        return ['painel'=>$board, 'paineis'=>$this->boards()->orderBy('espaco')->orderBy('ordem')->get(),
            'atendimentos'=>$board ? \App\Models\Conversation::whereIn('funnel_stage_id',FunnelStage::where('funnel_id',$board->id)->select('id'))->with(['contact','atendente'])->latest('etapa_em')->limit(100)->get() : collect(),
            'foraDoFunil'=>\App\Models\Conversation::whereNull('funnel_stage_id')->where('status','!=',\App\Models\Conversation::ARQUIVADA)->with(['contact','atendente'])->latest('ultima_msg_em')->limit(20)->get(),
            'favorito'=>$board && DB::table('board_favorites')->where('funnel_id',$board->id)->where('user_id',auth()->id())->exists(),
            'indicadores'=>['total'=>(clone $items)->count(),'atrasadas'=>(clone $items)->where('prazo_em','<',now())->whereHas('stage',fn($q)=>$q->where('encerra',false))->count(),'concluidas'=>(clone $items)->whereHas('stage',fn($q)=>$q->where('encerra',true))->count()],
            'podeEditar'=>$board && !$board->arquivado_em && app(BoardAccess::class)->canEdit(auth()->user(),$board),
            'podeGerir'=>$board && app(BoardAccess::class)->canManage(auth()->user(),$board),
            'etapas'=>$board ? FunnelStage::where('funnel_id',$board->id)->orderBy('ordem')->get() : collect(),
            'tarefas'=>$items->orderBy('prazo_em')->orderByDesc('id')->paginate(50),
            'camposDoPainel'=>BoardField::where('funnel_id',$board?->id)->whereNull('arquivado_em')->orderBy('ordem')->get(),
            'colegas'=>User::where('tenant_id',$this->tenant())->orderBy('name')->get()->filter(fn($u)=>$board && app(BoardAccess::class)->canEdit($u,$board)),
            'todosColegas'=>User::where('tenant_id',$this->tenant())->orderBy('name')->get(),
            'contatos'=>Contact::ativos()->where('tipo',Contact::PESSOA)->when($this->buscaContato,fn($q)=>$q->where(fn($s)=>$s->where('nome','ilike','%'.$this->buscaContato.'%')->orWhere('telefone_e164','like','%'.$this->buscaContato.'%')->orWhereKey($this->form['contact_id']??null)))->orderBy('nome')->limit(500)->get(),
            'etiquetas'=>Tag::deItem()->orderBy('nome')->get(),'selecionada'=>$selected,
            'filhas'=>$selected ? BoardItem::where('parent_id',$selected->id)->where('funnel_id',$board->id)->get() : collect(),
            'dependencias'=>$selected ? BoardItem::where('funnel_id',$board->id)->whereIn('id',DB::table('board_dependencies')->where('board_item_id',$selected->id)->select('depends_on_id'))->get() : collect(),
            'opcoesDependencia'=>$selected ? BoardItem::where('funnel_id',$board->id)->where('id','!=',$selected->id)->limit(200)->get(['id','titulo']) : collect(),
            'anexos'=>$selected ? DB::table('board_attachments')->where('board_item_id',$selected->id)->where('tenant_id',$this->tenant())->get() : collect(),
            'seguindo'=>$selected && DB::table('board_watchers')->where('board_item_id',$selected->id)->where('user_id',auth()->id())->exists()];
    }

    public function mover(int $conversationId,?int $etapaId):void
    {
        $this->board('edit');
        $conversation=\App\Models\Conversation::where('tenant_id',$this->tenant())->findOrFail($conversationId);
        if($conversation->funnel_stage_id){
            $source=FunnelStage::findOrFail($conversation->funnel_stage_id)->funnel;
            abort_unless($source && app(BoardAccess::class)->canEdit(auth()->user(),$source),403);
        }
        $stage=$etapaId?FunnelStage::where('funnel_id',$this->funilId)->findOrFail($etapaId):null;
        $conversation->moverPara($stage);
    }
}
