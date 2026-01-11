<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quote {{ $quoteNumber }}</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap');
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Poppins', sans-serif;
            font-size: 12px; /* text-xs */
            line-height: 1.6;
            color: #111827;
            background: white;
            padding: 40px 60px 60px; /* sm:p-16 equivalent */
        }
        .quote-content {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        /* Header Section - mb-6 = 24px */
        .header {
            display: table;
            width: 100%;
            margin-bottom: 24px;
        }
        .header-left {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }
        .header-logo-container {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .header-logo-container img {
            height: 80px;
            width: auto;
            margin-bottom: 8px;
        }
        .header-logo-container h1 {
            font-size: 14px; /* text-sm */
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.1em; /* tracking-widest */
            color: #6D6E71; /* Logo gray */
            margin-top: 8px; /* mb-2 = 8px */
        }
        .header-right {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            text-align: right;
        }
        .quote-title {
            font-size: 24px; /* text-2xl */
            font-weight: bold;
            color: #00B2E3; /* Brand cyan blue */
            margin-bottom: 8px; /* mb-2 */
            text-transform: uppercase;
            letter-spacing: 0.05em; /* tracking-wide */
        }
        .info-box {
            display: inline-block;
            border: 1px solid #d1d5db; /* border-gray-300 */
        }
        .info-row {
            display: table;
            width: 100%;
            border-bottom: 1px solid #d1d5db;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-cell-label {
            display: table-cell;
            background: white;
            padding: 4px 16px; /* py-1 px-4 */
            font-size: 12px; /* text-xs */
            font-weight: bold;
            color: #374151; /* text-gray-700 */
            border-right: 1px solid #d1d5db;
            width: 96px; /* w-24 = 6rem = 96px */
            text-align: center;
        }
        .info-cell-value {
            display: table-cell;
            background: white;
            padding: 4px 16px; /* py-1 px-4 */
            font-size: 12px; /* text-xs */
            font-weight: bold;
            color: #ef4444; /* text-red-500 */
            width: 128px; /* w-32 = 8rem = 128px */
            text-align: center;
        }
        
        /* Customer Details Section - mb-6 = 24px */
        .customer-section {
            margin-bottom: 24px;
        }
        .section-header {
            background: #00B2E3; /* Brand cyan blue */
            color: white;
            padding: 4px 8px; /* px-2 py-1 */
            font-size: 12px; /* text-xs */
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.05em; /* tracking-wide */
            width: 50%; /* w-1/2 */
        }
        .customer-details {
            background: #e5e7eb; /* bg-gray-200 */
            padding: 12px; /* p-3 */
            font-size: 12px; /* text-xs */
            color: #111827;
        }
        .customer-details div {
            margin-bottom: 4px; /* mb-1 */
        }
        .customer-details div:last-child {
            margin-bottom: 0;
        }
        .font-bold {
            font-weight: bold;
        }
        .text-red {
            color: #dc2626; /* text-red-600 */
            font-weight: bold;
        }
        
        /* Table - mb-6 = 24px */
        .table-container {
            margin-bottom: 24px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px; /* text-xs */
            table-layout: fixed;
        }
        thead tr {
            background: #e0f7fa; /* Lighter cyan for background */
            border-bottom: 2px solid #00B2E3; /* Brand cyan border */
        }
        th {
            background: #00B2E3; /* Brand cyan blue */
            color: white;
            padding: 4px 8px; /* py-1 px-2 */
            font-weight: bold;
            text-align: center;
            border: 1px solid white; /* border border-white */
        }
        th.text-left {
            text-align: left;
        }
        th.w-12 {
            width: 25px; /* Very narrow for LINE # and QTY */
        }
        th.w-24 {
            width: 60px; /* Narrow for Unit Price and AMOUNT */
        }
        tbody tr {
            border-bottom: 1px solid #d1d5db; /* border-b border-gray-300 */
        }
        tbody tr:hover {
            background: #f9fafb; /* hover:bg-gray-50 */
        }
        tbody tr.detailed-header {
            background: #f3f4f6; /* bg-gray-100 */
        }
        td {
            padding: 4px 8px; /* py-1 px-2 */
            border-right: 1px solid #d1d5db; /* border-r border-gray-300 */
            word-wrap: break-word;
            overflow-wrap: break-word;
            vertical-align: top;
        }
        td:last-child {
            border-right: none;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .text-gray-600 {
            color: #4b5563;
        }
        .text-gray-800 {
            color: #1f2937;
        }
        .text-gray-900 {
            color: #111827;
        }
        
        /* Totals */
        .totals-separator {
            border-top: 2px solid #9ca3af; /* border-t-2 border-gray-400 */
        }
        .total-label {
            font-weight: bold;
            color: #111827;
        }
        .vat-label {
            font-weight: bold;
            color: #7c3aed; /* text-purple-700 */
        }
        .vat-value {
            font-weight: bold;
            color: #7c3aed;
        }
        
        /* Terms Section */
        .terms-container {
            display: table;
            width: 100%;
            break-inside: avoid;
        }
        .terms-left {
            display: table-cell;
            width: 66.666%; /* w-full md:w-2/3 */
            vertical-align: top;
            padding-right: 32px; /* gap-8 = 2rem */
        }
        .terms-right {
            display: table-cell;
            width: 33.333%; /* w-full md:w-1/3 */
            vertical-align: top;
        }
        .section-header-terms {
            background: #00B2E3; /* Brand cyan blue */
            color: white;
            padding: 4px 8px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 1px;
        }
        .terms-box {
            border: 1px solid #d1d5db; /* border border-gray-300 */
            padding: 12px; /* p-3 */
            background: #f9fafb; /* bg-gray-50 */
            font-size: 10px; /* text-[10px] */
            line-height: 1.4; /* leading-tight */
        }
        .terms-box h4 {
            font-weight: bold;
            color: #dc2626; /* text-red-600 */
            margin-bottom: 4px; /* mb-1 */
            margin-top: 8px; /* mb-2 above next section */
            font-size: 10px;
        }
        .terms-box h4:first-child {
            margin-top: 0;
        }
        .terms-box ul {
            list-style: none;
            padding-left: 0;
            margin: 0;
        }
        .terms-box li {
            margin-bottom: 2px; /* space-y-0.5 */
            color: #374151; /* text-gray-800 */
        }
        .terms-box .font-semibold {
            font-weight: 600;
        }
        
        /* Bank Details - shown only if VAT enabled */
        .bank-section {
            margin-bottom: 0;
        }
        .bank-box {
            border: 1px solid #d1d5db;
            padding: 12px;
            background: white;
            font-size: 10px;
        }
        .bank-grid {
            display: table;
            width: 100%;
            margin-bottom: 8px;
        }
        .bank-grid:last-child {
            margin-bottom: 0;
        }
        .bank-item {
            display: table-cell;
            width: 50%;
            padding-right: 8px;
        }
        .bank-item:last-child {
            padding-right: 0;
        }
        .bank-label {
            font-size: 9px;
            color: #6b7280;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 2px;
            letter-spacing: -0.025em; /* tracking-tighter */
        }
        .bank-value {
            font-size: 10px;
            font-weight: 900; /* font-black */
            color: #111827;
        }
        .bank-value-highlight {
            color: #00B2E3; /* Brand cyan/turquoise from logo */
        }
        
        /* Footer */
        .footer {
            text-align: center;
            font-size: 10px; /* text-[10px] */
            color: #4b5563; /* text-gray-600 */
            margin-top: 16px; /* mt-4 */
            padding-top: 8px; /* pt-2 */
            border-top: 1px solid #e5e7eb; /* border-t border-gray-200 */
        }
        .footer p {
            margin-bottom: 2px;
        }
        .footer .font-bold {
            font-weight: bold;
            color: #1f2937; /* text-gray-800 */
        }
    </style>
</head>
<body>
    <div class="quote-content">
        <!-- Header Section -->
        <div class="header">
            <div class="header-left">
                <div class="header-logo-container">
                    <img src="{{ public_path('wng-logo.png') }}" alt="Woodnork Green Logo" />
                    <h1>Woodnork Green</h1>
                </div>
            </div>
            <div class="header-right">
                <div class="quote-title">QUOTE</div>
                <div class="info-box">
                    <div class="info-row">
                        <div class="info-cell-label">DATE</div>
                        <div class="info-cell-value">{{ $date }}</div>
                    </div>
                    <div class="info-row">
                        <div class="info-cell-label">QUOTE #</div>
                        <div class="info-cell-value">{{ $quoteNumber }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Customer Details Section -->
        <div class="customer-section">
            <div class="section-header">CUSTOMER DETAILS</div>
            <div class="customer-details">
                <div class="font-bold">{{ $projectInfo['clientName'] ?? 'Client Name' }}</div>
                <div>{{ $projectInfo['location'] ?? 'Nairobi, Kenya' }}</div>
                <div><span class="font-bold">Attn:</span> {{ $projectInfo['attentionTo'] ?? 'Project Manager' }}</div>
                <div><span class="font-bold">Project/Event/Setup/Delivery Date:</span> {{ $projectInfo['setupDate'] ?? 'TBC' }}</div>
                <div><span class="text-red">Ref:</span> <span class="text-red">{{ $projectInfo['enquiryTitle'] ?? 'Project Reference' }}</span></div>
            </div>
        </div>

        <!-- Quote Table -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th class="w-12" style="width: 5%;">LINE #</th>
                        <th class="text-left" style="width: 50%;">DESCRIPTION</th>
                        <th class="w-12" style="width: 5%;">QTY</th>
                        <th class="w-24" style="width: 15%;">Unit Price</th>
                        <th class="w-24" style="width: 15%;">AMOUNT</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($elements as $index => $element)
                        <!-- Category Header -->
                        <tr class="detailed-header">
                            <td class="text-center font-bold">{{ $index + 1 }}</td>
                            <td colspan="4" class="font-bold text-gray-800">{{ $element['name'] ?? 'Category' }}</td>
                        </tr>

                        <!-- Individual Items -->
                        @foreach($element['items'] as $itemIndex => $item)
                        <tr>
                            <td class="text-center text-gray-600"></td>
                            <td class="text-gray-900" style="padding-left: 24px;">{{ $item['description'] ?? 'Deliverable' }}</td>
                            <td class="text-center">{{ $item['qty'] }}</td>
                            <td class="text-right">{{ number_format($item['unitPrice'] * $item['days'], 2) }}</td>
                            <td class="text-right">{{ number_format($item['total'], 2) }}</td>
                        </tr>
                        @endforeach
                    @endforeach

                    <!-- Totals -->
                    <tr class="totals-separator">
                        <td colspan="3"></td>
                        <td class="total-label">Sub Total</td>
                        <td class="text-right font-bold">{{ number_format($subTotal, 2) }}</td>
                    </tr>
                    
                    @if($vatEnabled)
                    <tr>
                        <td colspan="3"></td>
                        <td class="vat-label">VAT (16%)</td>
                        <td class="text-right vat-value">{{ number_format($vat, 2) }}</td>
                    </tr>
                    @endif

                    <tr>
                        <td colspan="3"></td>
                        <td class="total-label">{{ $vatEnabled ? 'Total (Inc. VAT)' : 'Total' }}</td>
                        <td class="text-right font-bold">{{ number_format($grandTotal, 2) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Terms and Conditions -->
        <div class="terms-container">
            <div class="terms-left">
                <div class="section-header-terms">TERMS AND CONDITIONS</div>
                <div class="terms-box">
                    <h4>PAYMENT TERMS</h4>
                    <ul>
                        <li><span class="font-semibold">Deposit Payment:</span> Within Agreed Timelines (Per Email)</li>
                        <li><span class="font-semibold">Balance Payment:</span> Upon complete delivery</li>
                        <li><span class="font-semibold">Late Payment Penalty:</span> 2% Monthly for Late Payments</li>
                        <li>Production begins after receipt of LPO and payment of 70% Deposit</li>
                        <li>The Total Quote amount is <span style="color: #dc2626; font-weight: 600;">{{ $vatEnabled ? 'inclusive of 16% VAT' : 'exclusive of 16% VAT' }}</span></li>
                    </ul>

                    <h4>CLIENT OBLIGATIONS</h4>
                    <ul>
                        <li><span class="font-semibold">Setup & Branding Time:</span> Client must provide ample time for setup</li>
                        <li><span class="font-semibold">Pre-Production Approvals:</span> Client must approve pre-production on time</li>
                    </ul>

                    <h4>APPROVAL & EXECUTION</h4>
                    <ul>
                        <li><span class="font-semibold">Approval Required Before Work:</span> Client must approve before work starts</li>
                        <li><span class="font-semibold">Quote Validity:</span> This quote is valid for 7 working days</li>
                        <li><span class="font-semibold">Change Requests Process:</span> Changes to initial quote will be billed separately</li>
                    </ul>
                </div>
            </div>
            
            @if($vatEnabled)
            <div class="terms-right">
                <div class="section-header-terms">BANK DETAILS</div>
                <div class="bank-section">
                    <div class="bank-box">
                        <div class="bank-grid">
                            <div class="bank-item" style="width: 100%; padding-right: 0;">
                                <div class="bank-label">Cheques Payable To</div>
                                <div class="bank-value">{{ $bankInfo['chequePayable'] }}</div>
                            </div>
                        </div>
                        <div class="bank-grid">
                            <div class="bank-item" style="width: 100%; padding-right: 0;">
                                <div class="bank-label">Account Name</div>
                                <div class="bank-value">{{ $bankInfo['accountName'] ?? $bankInfo['chequePayable'] }}</div>
                            </div>
                        </div>
                        <div class="bank-grid">
                            <div class="bank-item">
                                <div class="bank-label">Bank</div>
                                <div class="bank-value">{{ $bankInfo['bankName'] }} ({{ $bankInfo['bankCode'] ?? '07000' }})</div>
                            </div>
                            <div class="bank-item">
                                <div class="bank-label">Branch</div>
                                <div class="bank-value">{{ $bankInfo['branch'] }} ({{ $bankInfo['branchCode'] ?? '125' }})</div>
                            </div>
                        </div>
                        <div class="bank-grid">
                            <div class="bank-item">
                                <div class="bank-label">SWIFT Code</div>
                                <div class="bank-value">{{ $bankInfo['swiftCode'] ?? 'CBAFKENX' }}</div>
                            </div>
                            <div class="bank-item">
                                <div class="bank-label">Paybill</div>
                                <div class="bank-value">{{ $bankInfo['paybill'] }}</div>
                            </div>
                        </div>
                        <div class="bank-grid">
                            <div class="bank-item" style="width: 100%; padding-right: 0;">
                                <div class="bank-label">Account Number</div>
                                <div class="bank-value bank-value-highlight">{{ $bankInfo['accountNumber'] }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @endif
        </div>

        <!-- Footer -->
        <div class="footer">
            <p class="font-bold">Woodnork Green Ltd</p>
            <p>Tel: +254 780 397 798 | Email: admin@woodnorkgreen.co.ke</p>
            <p>Physical Address: Karen Village, Ngong Road, Nairobi, Kenya | Website: www.woodnorkgreen.co.ke</p>
        </div>
    </div>
</body>
</html>
