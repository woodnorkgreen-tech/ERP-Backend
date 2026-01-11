<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;

class FlashQuoteController extends Controller
{
    public function generatePdf(Request $request)
    {
        $validated = $request->validate([
            'quoteNumber' => 'required|string',
            'date' => 'required|string',
            'vatEnabled' => 'required|boolean',
            'projectInfo' => 'required|array',
            'projectInfo.enquiryTitle' => 'nullable|string',
            'projectInfo.clientName' => 'nullable|string',
            'projectInfo.location' => 'nullable|string',
            'projectInfo.attentionTo' => 'nullable|string',
            'projectInfo.eventVenue' => 'nullable|string',
            'projectInfo.setupDate' => 'nullable|string',
            'projectInfo.setDownDate' => 'nullable|string',
            'elements' => 'required|array',
            'elements.*.name' => 'nullable|string',
            'elements.*.items' => 'required|array',
            'elements.*.items.*.description' => 'nullable|string',
            'elements.*.items.*.qty' => 'required|numeric',
            'elements.*.items.*.days' => 'required|numeric',
            'elements.*.items.*.unitPrice' => 'required|numeric',
            'paymentTerms' => 'nullable|string',
            'clientObligations' => 'nullable|string',
            'legalCaveat' => 'nullable|string',
            'bankInfo' => 'required|array',
        ]);

        // Calculate totals
        $subTotal = 0;
        foreach ($validated['elements'] as &$element) {
            $elementTotal = 0;
            foreach ($element['items'] as &$item) {
                $itemTotal = $item['qty'] * $item['days'] * $item['unitPrice'];
                $item['total'] = $itemTotal;
                $elementTotal += $itemTotal;
            }
            $element['total'] = $elementTotal;
            $subTotal += $elementTotal;
        }

        $validated['subTotal'] = $subTotal;
        $validated['vat'] = $validated['vatEnabled'] ? $subTotal * 0.16 : 0;
        $validated['grandTotal'] = $subTotal + $validated['vat'];

        // Generate PDF
        $pdf = Pdf::loadView('pdf.flash-quote', $validated);
        $pdf->setPaper('A4', 'portrait');
        
        $filename = 'quote-' . $validated['quoteNumber'] . '.pdf';
        
        return $pdf->download($filename);
    }
}
