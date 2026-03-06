<?php

namespace App\Providers;

use App\Events\StockMovementCreated;
use App\Events\StockTransferCompleted;
use App\Listeners\RecordInventoryEvent;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Observers\SaleObserver;
use App\Observers\StockMovementObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        StockMovement::observe(StockMovementObserver::class);
        Sale::observe(SaleObserver::class);

        Event::listen(StockMovementCreated::class, RecordInventoryEvent::class);
        Event::listen(StockTransferCompleted::class, RecordInventoryEvent::class);
    }
}
