<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    App\Modules\ArchivalTask\Providers\ArchivalTaskServiceProvider::class,
    App\Modules\UniversalTask\Providers\UniversalTaskServiceProvider::class,
    App\Modules\MaterialsLibrary\Providers\MaterialsLibraryServiceProvider::class,
    App\Modules\Assets\Providers\AssetsServiceProvider::class,
    App\Modules\ProcurementStores\Providers\ProcurementStoresServiceProvider::class,
    App\Modules\Production\Providers\ProductionServiceProvider::class,
    App\Modules\Logistics\Providers\LogisticsServiceProvider::class,
    App\Modules\Finance\Providers\FinanceServiceProvider::class,
    App\Modules\HR\Providers\HRServiceProvider::class,
    App\Modules\Notifications\Providers\NotificationsServiceProvider::class,
    App\Modules\Design\Providers\DesignServiceProvider::class,
];
