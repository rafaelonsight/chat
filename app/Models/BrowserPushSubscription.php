<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BrowserPushSubscription extends Model {
    protected $guarded = ['id'];
    protected $hidden = ['endpoint', 'public_key', 'auth_token', 'session_hash'];
    protected function casts(): array { return ['expires_at' => 'datetime']; }
    public function user() { return $this->belongsTo(User::class); }
}
