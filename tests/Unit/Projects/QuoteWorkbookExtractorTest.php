<?php

namespace Tests\Unit\Projects;

use App\Modules\Projects\Services\QuoteWorkbookExtractor;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class QuoteWorkbookExtractorTest extends TestCase
{
    public function test_it_extracts_each_commercial_row_as_an_element_with_an_empty_bom(): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Main Quote');
        $sheet->fromArray([
            ['Element', 'Description', 'Qty', 'Unit', 'Rate', 'Amount'],
            ['Reception', '18mm MDF board', 12, 'Sheets', 3500, 42000],
            ['Reception', 'White emulsion paint', 4, 'Cans', 2800, 11200],
            ['', 'Subtotal', '', '', '', 53200],
            ['Stage', 'Black carpet', 40, 'sqm', 900, 36000],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'quote-extraction-') . '.xlsx';
        (new Xlsx($book))->save($path);

        try {
            $result = app(QuoteWorkbookExtractor::class)->extract($path);

            $this->assertSame(3, $result['summary']['elementCount']);
            $this->assertSame(0, $result['summary']['materialCount']);
            $this->assertSame('18mm MDF board', $result['elements'][0]['name']);
            $this->assertSame('Reception', $result['elements'][0]['section']);
            $this->assertSame(12.0, $result['elements'][0]['quotedQuantity']);
            $this->assertSame('Sheets', $result['elements'][0]['quotedUnit']);
            $this->assertSame(3500.0, $result['elements'][0]['quotedUnitPrice']);
            $this->assertSame([], $result['elements'][0]['materials']);
        } finally {
            if (is_file($path)) unlink($path);
        }
    }

    public function test_it_returns_a_warning_instead_of_inventing_lines_without_recognisable_headers(): void
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([['Client proposal'], ['Terms and conditions']]);
        $path = tempnam(sys_get_temp_dir(), 'quote-extraction-') . '.xlsx';
        (new Xlsx($book))->save($path);

        try {
            $result = app(QuoteWorkbookExtractor::class)->extract($path);
            $this->assertSame([], $result['elements']);
            $this->assertNotEmpty($result['warnings']);
        } finally {
            if (is_file($path)) unlink($path);
        }
    }

    public function test_review_normalization_preserves_element_provenance_and_never_accepts_a_bom(): void
    {
        $result = app(QuoteWorkbookExtractor::class)->normalizeReview([
            'elements' => [[
                'id' => 'element-1',
                'sourceKey' => 'sheet:0:element:stage',
                'name' => 'Stage',
                'category' => 'production',
                'quotedQuantity' => 2,
                'quotedUnit' => 'Sets',
                'quotedUnitPrice' => 900,
                'materials' => [[
                    'id' => 'line-1',
                    'sourceKey' => 'sheet:0:row:8',
                    'description' => 'Black carpet',
                    'quantity' => 40,
                    'unitOfMeasurement' => 'sqm',
                    'quotedUnitPrice' => 900,
                    'libraryMaterialId' => 99,
                    'unitCost' => 1,
                    'isVisible' => false,
                ]],
            ]],
        ]);

        $element = $result['elements'][0];
        $this->assertSame('reviewed', $result['reviewStatus']);
        $this->assertSame('sheet:0:element:stage', $element['sourceKey']);
        $this->assertSame(2.0, $element['quotedQuantity']);
        $this->assertSame('Sets', $element['quotedUnit']);
        $this->assertSame(900.0, $element['quotedUnitPrice']);
        $this->assertSame([], $element['materials']);
        $this->assertSame(0, $result['summary']['materialCount']);
    }
}
