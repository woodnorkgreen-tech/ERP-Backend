<?php

namespace App\Modules\Assets\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Services\AssetExportService;

class AssetExportController extends Controller
{
    protected $exportService;

    public function __construct(AssetExportService $exportService)
    {
        $this->exportService = $exportService;
    }

    /**
     * Download the bulk-import template.
     */
    public function downloadTemplate()
    {
        return $this->exportService->downloadTemplate();
    }
}
