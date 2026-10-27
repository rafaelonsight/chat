<?php
namespace App\Http\Controllers;
use App\Services\AgendaBusiness;
use Illuminate\Support\Facades\Storage;
class AgendaProductPhoto {
 public function __invoke(int $product){$b=app(AgendaBusiness::class);$u=$b->actor(auth()->user(),'agenda_products_manage');$p=$b->query('agenda_products')->where('id',$product)->first();abort_unless($p?->photo_path && str_starts_with($p->photo_path,'agenda-products/'.$u->tenant_id.'/') && Storage::disk('local')->exists($p->photo_path),404);return Storage::disk('local')->response($p->photo_path,null,['Cache-Control'=>'private, no-cache','X-Content-Type-Options'=>'nosniff']);}
}
