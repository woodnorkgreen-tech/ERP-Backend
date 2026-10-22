<?php

namespace App\Modules\HR\Support\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for every HR PDF document.
 *
 * Subclasses declare only WHAT the document is (its view, data and filename);
 * this base owns HOW it is produced (dompdf wiring, paper size, filename
 * normalisation, and the download / stream / store transports). All HR PDFs
 * therefore share one rendering path and one branded layout
 * (resources/views/pdf/layouts/document.blade.php).
 */
abstract class HrPdfDocument
{
    /** Blade view that @extends('pdf.layouts.document'). */
    abstract protected function view(): string;

    /** Data bound into the view. Keep computation in a service, not here. */
    abstract protected function data(): array;

    /** Download filename (the .pdf extension is added automatically). */
    abstract protected function filename(): string;

    /** [size, orientation] — override per document (e.g. ['a4', 'landscape']). */
    protected array $paper = ['a4', 'portrait'];

    protected function build(): DomPdf
    {
        $pdf = Pdf::loadView($this->view(), $this->data())
            ->setPaper($this->paper[0], $this->paper[1] ?? 'portrait');

        // "Page X of Y" is stamped after layout: dompdf cannot resolve
        // counter(pages) in CSS (it printed "of 0"), but its canvas knows the
        // final count once every page exists.
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('Helvetica', 'bold');
        $canvas->page_text($canvas->get_width() - 92, $canvas->get_height() - 30, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7, [0.07, 0.09, 0.15]);

        return $pdf;
    }

    public function download(): Response
    {
        return $this->build()->download($this->normalizedFilename());
    }

    public function stream(): Response
    {
        return $this->build()->stream($this->normalizedFilename());
    }

    /**
     * Persist the PDF to a disk and return the stored path. Use this for bulk or
     * queued generation so heavy reports are not rendered inline on the request.
     */
    public function store(string $disk, string $directory = 'hr/documents'): string
    {
        $path = trim($directory, '/') . '/' . $this->normalizedFilename();
        Storage::disk($disk)->put($path, $this->build()->output());

        return $path;
    }

    protected function normalizedFilename(): string
    {
        $name = $this->filename();

        return str_ends_with(strtolower($name), '.pdf') ? $name : $name . '.pdf';
    }
}
