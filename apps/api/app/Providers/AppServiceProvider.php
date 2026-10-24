<?php

namespace App\Providers;

use App\Infrastructure\Provider\CollectionStore;
use App\Infrastructure\Provider\CollectorState;
use App\Infrastructure\Provider\MongoCollectionStore;
use App\Infrastructure\Provider\MongoCollectorState;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CollectionStore::class, MongoCollectionStore::class);
        $this->app->bind(CollectorState::class, MongoCollectorState::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
