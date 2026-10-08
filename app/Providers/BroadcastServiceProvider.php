<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Registers the /broadcasting/auth endpoint used by Laravel Echo to
     * authorize private channels (routes/channels.php). This project has
     * no single "web"/"sanctum" guard - customers, providers and admins
     * each authenticate on their own guard (user_api/provider_api/
     * admin_api) - so all three are tried in order; whichever one
     * actually authenticates the bearer token is what each channel's
     * authorization callback receives as $user.
     */
    public function boot(): void
    {
        Broadcast::routes(['middleware' => ['auth:user_api,provider_api,admin_api']]);

        require base_path('routes/channels.php');
    }
}
