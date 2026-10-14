<?php

namespace App\Providers;

use App\Models\AttendanceEvent;
use App\Policies\AttendanceEventPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(AttendanceEvent::class, AttendanceEventPolicy::class);
    }
}
