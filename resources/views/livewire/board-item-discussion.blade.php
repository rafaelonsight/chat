<div wire:poll.15s.visible>
<style>.comment:target{outline:2px solid #E8A924;scroll-margin-top:90px}</style>
<h3>Conversa e histórico</h3>
@if($mais)<button wire:click="anteriores">Carregar anteriores</button>@endif
@foreach($atualizacoes as $update)
<div id="comment-{{ $update->id }}" wire:key="comment-{{ $update->id }}" class="{{ $update->tipo==='mensagem'?'comment':'history' }}">
<span class="muted">{{ $update->user?->name ?? 'Sistema' }} · {{ $update->created_at->format('d/m H:i') }}{{ $update->edited_at?' · editado':'' }}</span>
@if($update->reply_to_id)<div class="muted">Em resposta ao comentário #{{ $update->reply_to_id }}</div>@endif
<div class="commenttext">{!! \App\Support\TaskText::render($update->corpo) !!}</div>
@if($podeComentar && $update->tipo==='mensagem' && !$update->deleted_at)
<div class="row"><button wire:click="responder({{ $update->id }})">Responder</button>@if($update->user_id===auth()->id())<button wire:click="editar({{ $update->id }})">Editar</button><button wire:click="remover({{ $update->id }})" wire:confirm="Remover o conteúdo deste comentário? O registro de remoção será mantido.">Remover</button>@endif</div>
<div class="row spaced">@foreach(['👍','✅','❤️','👀'] as $emoji)<button aria-label="Reagir {{ $emoji }} ao comentário {{ $update->id }}" wire:click="reagir({{ $update->id }},'{{ $emoji }}')">{{ $emoji }} {{ $reacoes->get($update->id,collect())->where('emoji',$emoji)->count() ?: '' }}</button>@endforeach</div>
@endif
</div>
@endforeach
@if($podeComentar)
<div class="section" x-data="{text:$wire.entangle('texto'),people:@js($pessoas),open:false,query:'',index:0,pos:0, get matches(){return this.people.filter(p=>p.name.toLocaleLowerCase().includes(this.query.toLocaleLowerCase())).slice(0,8)},check(){this.pos=$refs.editor.selectionStart;let m=this.text.slice(0,this.pos).match(/@([^@\n]*)$/);this.open=!!m;this.query=m?m[1]:'';this.index=0},choose(p){if(!p)return;this.text=this.text.slice(0,this.pos).replace(/@[^@\n]*$/,'@'+p.name+' ')+this.text.slice(this.pos);$wire.mencoes=[...new Set([...$wire.mencoes,p.id])];this.open=false;$refs.editor.focus()}}">
@if($replyTo || $editingId)<p class="muted">{{ $editingId?'Editando comentário #'.$editingId:'Respondendo ao comentário #'.$replyTo }} <button wire:click="cancelar">Cancelar</button></p>@endif
<div class="suggestions" x-show="open && matches.length" x-cloak role="listbox" aria-label="Colaboradores"><template x-for="(p,i) in matches" :key="p.id"><button type="button" role="option" :aria-selected="i===index" :class="i===index?'active':''" @click="choose(p)" x-text="'@'+p.name"></button></template></div>
<textarea rows="4" x-ref="editor" aria-label="Comentário da tarefa" x-model="text" @input="check()" @keydown.escape="open=false" @keydown.arrow-down="if(open && matches.length){$event.preventDefault();index=(index+1)%matches.length}" @keydown.arrow-up="if(open && matches.length){$event.preventDefault();index=(index+matches.length-1)%matches.length}" @keydown.enter="if(open && matches.length){$event.preventDefault();choose(matches[index])}" placeholder="Escreva uma atualização. Digite @ para mencionar…"></textarea>
<div class="row spaced"><button type="button" @click="$refs.editor.setRangeText('**'+($refs.editor.value.slice($refs.editor.selectionStart,$refs.editor.selectionEnd)||'texto')+'**',$refs.editor.selectionStart,$refs.editor.selectionEnd,'select');text=$refs.editor.value;$refs.editor.focus()">Negrito</button><button type="button" @click="$refs.editor.setRangeText('*'+($refs.editor.value.slice($refs.editor.selectionStart,$refs.editor.selectionEnd)||'texto')+'*',$refs.editor.selectionStart,$refs.editor.selectionEnd,'select');text=$refs.editor.value;$refs.editor.focus()">Itálico</button><span class="muted">Formatação Markdown e links; confira após enviar.</span></div>
@error('texto')<p class="error">{{ $message }}</p>@enderror
<div class="row between spaced"><span class="muted">Selecione a pessoa nas sugestões para notificá-la; apenas digitar @nome não envia uma menção.</span><button class="primary" wire:click="enviar" wire:loading.attr="disabled">{{ $editingId?'Salvar edição':'Enviar comentário' }}</button></div>
</div>
@else<p class="muted section">Você tem acesso de leitura a este painel.</p>@endif
</div>
