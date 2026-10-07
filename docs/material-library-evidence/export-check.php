<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('local') || Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'db') throw new RuntimeException('Local read-only export only.');
$book=app(App\Modules\MaterialsLibrary\Services\MaterialExportService::class)->workbook();
$sheet=$book->getSheetByName('Materials');
$count=App\Modules\MaterialsLibrary\Models\LibraryMaterial::count();
if ($sheet->getHighestDataRow()-1!==$count) throw new RuntimeException('Export row count does not match the library.');
$path=__DIR__.'/material_library.xlsx';
(new PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
echo 'Exported '.$count.' non-deleted materials; '.$sheet->getHighestDataColumn().' columns; '.filesize($path)." bytes. No material records changed.\n";
$book->disconnectWorksheets();
