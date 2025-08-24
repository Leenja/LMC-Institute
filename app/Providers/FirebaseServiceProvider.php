<?php

/*namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Factory;

class FirebaseServiceProvider extends ServiceProvider
{
  
    public function register()
    {
        $this->app->singleton('firebase', function ($app) {
            $serviceAccount = base_path('storage/app/firebase/lmc-institute-647ba-firebase-adminsdk-fbsvc-d89f68629b.json');

            return (new Factory)
                ->withServiceAccount($serviceAccount);
        });
    }

    public function boot()
    {
        //
    }
}*/


namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Contract\Messaging;

class FirebaseServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(Messaging::class, function () {
            $factory = (new Factory)
                ->withServiceAccount(config('services.firebase.credentials'));

            if ($url = config('services.firebase.database_url')) {
                $factory = $factory->withDatabaseUri($url);
            }

            return $factory->createMessaging();
        });
    }

    public function boot() {}
}
