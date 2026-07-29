<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>@yield('title', 'Report')</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 9px;
            color: #111827;
            margin: 0;
            padding: 0;
            line-height: 1.4;
        }
        @page {
            margin: 0.5in;
        }
        
        /* Typography & Colors (Woodnork Green Theme) */
        .text-green-600 { color: #166534; }
        .text-green-700 { color: #15803d; }
        .text-red-600 { color: #dc2626; }
        .text-gray-600 { color: #4b5563; }
        .text-gray-700 { color: #374151; }
        .text-gray-900 { color: #111827; }
        
        .bg-green-600 { background-color: #166534; color: white; }
        .bg-green-700 { background-color: #15803d; color: white; }
        .bg-gray-200 { background-color: #e5e7eb; }
        .bg-gray-100 { background-color: #f3f4f6; }
        .bg-white { background-color: #ffffff; }
        
        .font-bold { font-weight: bold; }
        .uppercase { text-transform: uppercase; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        
        /* Layout Utilities */
        .mb-2 { margin-bottom: 5px; }
        .mb-4 { margin-bottom: 15px; }
        
        .section-header {
            background-color: #166534;
            color: white;
            padding: 4px 8px;
            font-weight: bold;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
            display: inline-block;
            width: 100%;
            border: 1px solid #166534;
        }

        .info-box {
            background-color: #f8fafc;
            padding: 8px;
            border: 1px solid #cbd5e1;
            font-size: 9px;
            border-radius: 2px;
            margin-bottom: 15px;
        }

        /* Structured Excel Table System */
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: middle; }
        
        .data-table { 
            width: 100%; 
            border-collapse: collapse; 
            font-size: 9px; 
            margin-bottom: 15px; 
            border: 1px solid #94a3b8; /* Outer spreadsheet grid border */
        }
        
        .data-table th {
            background-color: #f1f5f9; /* Classic light Excel header fill */
            color: #1e293b;
            font-weight: bold;
            text-align: left;
            padding: 5px 8px;
            border: 1px solid #cbd5e1; /* Cell border gridlines */
            text-transform: uppercase;
            font-size: 8px;
            letter-spacing: 0.5px;
        }
        
        .data-table td {
            border: 1px solid #e2e8f0; /* Cell border gridlines */
            padding: 5px 8px;
            vertical-align: middle;
        }
        
        /* Alternating row styling (Spreadsheet striping) */
        .data-table tr:nth-child(even) td {
            background-color: #f8fafc; 
        }
        
        /* Excel Standard Double-Underline Accounting Totals */
        .total-row td {
            font-weight: bold !important;
            background-color: #f1f5f9 !important;
            border-top: 1.5px solid #94a3b8 !important;
            border-bottom: 3px double #94a3b8 !important; /* standard accounting double border */
            color: #111827 !important;
        }

        .category-title {
            font-size: 10px;
            font-weight: bold;
            color: #166534;
            border-left: 3px solid #15803d;
            padding-left: 8px;
            margin: 15px 0 8px 0;
            background-color: #f0fdf4;
            padding-top: 4px;
            padding-bottom: 4px;
            border-top: 1px solid #e2e8f0;
            border-right: 1px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
        }

        .badge {
            padding: 2px 6px;
            border-radius: 2px;
            font-size: 8px;
            font-weight: bold;
            display: inline-block;
            text-transform: uppercase;
            border: 1px solid transparent;
        }
        .bg-green { background-color: #dcfce7; color: #166534; border-color: #bbf7d0; }
        .bg-red { background-color: #fee2e2; color: #991b1b; border-color: #fecaca; }
        .bg-blue { background-color: #dbeafe; color: #1e40af; border-color: #bfdbfe; }
        
        /* Number values alignment and font structure */
        .num-val {
            font-family: 'Courier New', Courier, monospace;
            font-weight: bold;
            font-size: 9.5px;
        }

        .footer {
            position: fixed;
            bottom: -20px;
            left: 0px;
            right: 0px;
            height: 35px;
            text-align: center;
            color: #4b5563;
            font-size: 7.5px;
            border-top: 1px solid #cbd5e1;
            padding-top: 5px;
            line-height: 1.2;
        }
        .footer p {
            margin: 1px 0;
        }
    </style>
</head>
<body>

    <!-- Header (Matching standard Woodnork Green structure) -->
    <table style="margin-bottom: 20px; width: 100%;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                <img src="{{ public_path('woodnork-green-logo.png') }}" style="width: 125px; height: auto; margin-bottom: 5px; display: block;" alt="Woodnork Green logo"/>
            </td>
            <td style="width: 50%; text-align: right; vertical-align: top;">
                <h2 class="text-green-600 mb-2 uppercase tracking-wide text-2xl" style="margin: 0 0 10px 0;">@yield('title')</h2>
                <div style="display: inline-block; border: 1px solid #94a3b8;">
                    <table style="border-collapse: collapse;">
                        <tr>
                            <td class="bg-white text-gray-700 font-bold border-r border-gray-300 text-center uppercase" style="padding: 4px 10px; font-size: 8px; border-right: 1px solid #cbd5e1; background-color: #f1f5f9;">Date</td>
                            <td class="bg-white text-green-700 font-bold text-center" style="padding: 4px 10px; width: 100px; font-size: 8px;">{{ now()->format('d/m/Y') }}</td>
                        </tr>
                        <tr>
                            <td class="bg-white text-gray-700 font-bold border-r border-gray-300 text-center uppercase" style="border-top: 1px solid #cbd5e1; padding: 4px 10px; font-size: 8px; border-right: 1px solid #cbd5e1; background-color: #f1f5f9;">SYSTEM</td>
                            <td class="bg-white text-red-600 font-bold text-center" style="border-top: 1px solid #cbd5e1; padding: 4px 10px; font-size: 8px;">HR-ERP</td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <div class="content">
        @yield('content')
    </div>

    <!-- Footer -->
    <div class="footer">
        <p class="font-bold text-gray-900">Woodnork Green Ltd</p>
        <p>Tel: +254 780 397 798 | Email: admin@woodnorkgreen.co.ke</p>
        <p>Physical Address: Karen Village, Ngong Road, Nairobi, Kenya | Website: www.woodnorkgreen.co.ke</p>
    </div>

</body>
</html>
