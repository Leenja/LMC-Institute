<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Factory;

class FirebaseServiceProvider extends ServiceProvider
{
    /*
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('firebase', function ($app) {
            $serviceAccount = base_path('storage/app/firebase/lmc-institute-647ba-firebase-adminsdk-fbsvc-d89f68629b.json');

            return (new Factory)
                ->withServiceAccount($serviceAccount);
        });
    }

    /*
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}