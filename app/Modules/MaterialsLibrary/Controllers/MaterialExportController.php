<?php

namespace App\Modules\MaterialsLibrary\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Services\MaterialExportService;

class MaterialExportController extends Controller
{
    protected $exportService;

    public function __construct(MaterialExportService $exportService)
    {
        $this->exportService = $exportService;
    }

    /**
     * Download the template for a workstation.
     */
    public function downloadAll()
    {
        return $this->exportService->downloadAll();
    }

    public function blankTemplate()
    {
        return $this->exportService->downloadTemplate();
    }

    public function downloadTemplate($workstationId)
    {
        return $this->exportService->downloadTemplate($workstationId);
    }
}
