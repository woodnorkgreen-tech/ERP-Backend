<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>@yield('title', 'Document')</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #111827;
            margin: 0;
            padding: 0;
            line-height: 1.5;
        }
        @page { margin: 0.6in 0.6in 0.9in 0.6in; }

        /* Brand palette (Woodnork Green) */
        .text-green-600 { color: #166534; }
        .text-green-700 { color: #15803d; }
        .text-red-600 { color: #dc2626; }
        .text-gray-500 { color: #6b7280; }
        .text-gray-600 { color: #4b5563; }
        .text-gray-700 { color: #374151; }
        .text-gray-900 { color: #111827; }

        .bg-green-600 { background-color: #166534; color: white; }
        .bg-gray-100 { background-color: #f3f4f6; }

        .font-bold { font-weight: bold; }
        .uppercase { text-transform: uppercase; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .mb-2 { margin-bottom: 6px; }
        .mb-4 { margin-bottom: 14px; }
        .mt-4 { margin-top: 14px; }

        .section-header {
            background-color: #166534;
            color: white;
            padding: 5px 9px;
            font-weight: bold;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
            width: 100%;
            display: block;
        }

        .meta-box {
            border: 1px solid #94a3b8;
            border-collapse: collapse;
        }
        .meta-box td {
            padding: 4px 10px;
            font-size: 8.5px;
            border: 1px solid #cbd5e1;
        }
        .meta-box .k { background-color: #f1f5f9; color: #374151; font-weight: bold; text-transform: uppercase; }
        .meta-box .v { color: #15803d; font-weight: bold; }

        /* Data tables (spreadsheet style) */
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: middle; }
        .data-table {
            width: 100%; border-collapse: collapse; font-size: 9px;
            margin-bottom: 14px; border: 1px solid #94a3b8;
        }
        .data-table th {
            background-color: #f1f5f9; color: #1e293b; font-weight: bold;
            text-align: left; padding: 5px 8px; border: 1px solid #cbd5e1;
            text-transform: uppercase; font-size: 8px; letter-spacing: 0.5px;
        }
        .data-table td { border: 1px solid #e2e8f0; padding: 5px 8px; }
        .data-table tr:nth-child(even) td { background-color: #f8fafc; }
        .total-row td {
            font-weight: bold !important; background-color: #f1f5f9 !important;
            border-top: 1.5px solid #94a3b8 !important;
            border-bottom: 3px double #94a3b8 !important; color: #111827 !important;
        }
        .num-val { font-family: 'Courier New', Courier, monospace; font-weight: bold; font-size: 9.5px; }

        /* Letter / prose blocks */
        .letter { font-size: 10.5px; line-height: 1.7; }
        .letter p { margin: 0 0 10px 0; }
        .letter .ref { color: #4b5563; font-size: 9px; margin-bottom: 10px; }
        .doc-title {
            font-size: 13px; font-weight: bold; text-transform: uppercase;
            letter-spacing: 1px; color: #166534; text-align: center;
            border-top: 2px solid #166534; border-bottom: 2px solid #166534;
            padding: 6px 0; margin: 4px 0 16px 0;
        }
        .signature { margin-top: 36px; }
        .signature .line { border-top: 1px solid #111827; width: 220px; padding-top: 4px; font-size: 9px; }

        .footer {
            position: fixed;
            bottom: -40px; left: 0; right: 0; height: 50px;
            text-align: center; color: #4b5563; font-size: 7.5px;
            border-top: 1px solid #cbd5e1; padding-top: 5px; line-height: 1.3;
        }
        .footer p { margin: 1px 0; }
        .footer .pageno:after { content: "Page " counter(page) " of " counter(pages); }
    </style>
    @yield('styles')
</head>
<body>

    <!-- Letterhead -->
    <table style="margin-bottom: 18px; width: 100%;">
        <tr>
            <td style="width: 55%; vertical-align: top;">
                <img src="{{ public_path('woodnork-green-logo.png') }}" style="width: 120px; height: auto; margin-bottom: 5px; display: block;" alt="Woodnork Green logo"/>
            </td>
            <td style="width: 45%; text-align: right; vertical-align: top;">
                <h2 class="text-green-600 uppercase" style="margin: 0 0 8px 0; font-size: 18px; letter-spacing: 1px;">@yield('title')</h2>
                @hasSection('subtitle')
                    <p class="text-gray-600" style="margin: 0 0 8px 0; font-size: 9px;">@yield('subtitle')</p>
                @endif
                <div style="display: inline-block;">
                    @hasSection('meta')
                        <table class="meta-box">
                            @yield('meta')
                        </table>
                    @else
                        <table class="meta-box">
                            <tr><td class="k">Date</td><td class="v">{{ now()->format('d/m/Y') }}</td></tr>
                            <tr><td class="k">System</td><td class="v text-red-600">HR-ERP</td></tr>
                        </table>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <div class="content">
        @yield('content')
    </div>

    <!-- Footer -->
    <div class="footer">
        <p class="font-bold text-gray-900">Woodnork Green Ltd <span class="text-gray-500">&bull;</span> <span class="pageno"></span></p>
        <p>Tel: +254 780 397 798 | Email: admin@woodnorkgreen.co.ke</p>
        <p>Karen Village, Ngong Road, Nairobi, Kenya | www.woodnorkgreen.co.ke</p>
    </div>

</body>
</html>
