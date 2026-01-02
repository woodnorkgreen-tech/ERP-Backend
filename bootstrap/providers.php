<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    App\Modules\ArchivalTask\Providers\ArchivalTaskServiceProvider::class,
    App\Modules\UniversalTask\Providers\UniversalTaskServiceProvider::class,
    App\Modules\MaterialsLibrary\Providers\MaterialsLibraryServiceProvider::class,
];