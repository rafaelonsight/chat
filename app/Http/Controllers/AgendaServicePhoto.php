<?php
namespace App\Http\Controllers;
use App\Models\AgendaService;
use Illuminate\Support\Facades\Storage;
class AgendaServicePhoto {
 public function __invoke(int $service){app(\App\Services\AgendaCalendar::class)->actor(auth()->user());$s=AgendaService::findOrFail($service);abort_unless($s->photo_path && str_starts_with($s->photo_path,'agenda-services/'.auth()->user()->tenant_id.'/') && Storage::disk('local')->exists($s->photo_path),404);return Storage::disk('local')->response($s->photo_path,null,['Cache-Control'=>'private, no-cache','X-Content-Type-Options'=>'nosniff']);}
}
