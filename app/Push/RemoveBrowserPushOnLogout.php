<?php
namespace App\Push;
use App\Models\BrowserPushSubscription;
use Illuminate\Auth\Events\Logout;
class RemoveBrowserPushOnLogout {
    public function handle(Logout $event): void {
        if ($event->user && request()->hasSession()) {
            BrowserPushSubscription::where('user_id', $event->user->id)
                ->where('session_hash', hash('sha256', request()->session()->getId()))->delete();
        }
    }
}
