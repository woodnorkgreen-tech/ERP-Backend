<?php

namespace App\Modules\MaterialsLibrary\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Services\MaterialExportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    public function downloadTemplate($workstationId)
    {
        return $this->exportService->downloadTemplate($workstationId);
    }
}
