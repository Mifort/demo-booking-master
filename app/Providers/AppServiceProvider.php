<?php

namespace App\Providers;

use App\Models\Booking;
use App\Policies\BookingPolicy;
use Illuminate\Support\Facades\Gate;
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
     * Связывает модель брони с правилами авторизации действий над ней.
     */
    public function boot(): void
    {
        Gate::policy(Booking::class, BookingPolicy::class);
    }
}
