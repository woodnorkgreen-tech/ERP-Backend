@extends('pdf.layouts.document')

@section('title', 'Certificate of Service')

@section('meta')
    <tr><td class="k">Ref</td><td class="v">COS/{{ str_pad($employee->id, 5, '0', STR_PAD_LEFT) }}</td></tr>
    <tr><td class="k">Issued</td><td class="v">{{ $issuedOn->format('d/m/Y') }}</td></tr>
@endsection

@section('content')
    <div class="doc-title">Certificate of Service</div>

    <div class="letter">
        <p>This is to certify that the person whose details appear below was employed by
           <span class="font-bold">Woodnork Green Ltd</span>.</p>

        <table class="data-table" style="margin-top: 6px;">
            <tr>
                <th style="width: 30%;">Employee Name</th>
                <td class="font-bold">{{ $fullName }}</td>
            </tr>
            @if($idNumber)
            <tr>
                <th>National ID</th>
                <td>{{ $idNumber }}</td>
            </tr>
            @endif
            <tr>
                <th>Position Held</th>
                <td>{{ $position ?? 'N/A' }}</td>
            </tr>
            @if($department)
            <tr>
                <th>Department</th>
                <td>{{ $department }}</td>
            </tr>
            @endif
            <tr>
                <th>Period of Service</th>
                <td>
                    {{ $startDate ? $startDate->format('d F Y') : 'N/A' }}
                    &mdash;
                    @if($stillServing)
                        <span class="font-bold text-green-700">Present (currently in service)</span>
                    @else
                        {{ $endDate->format('d F Y') }}
                    @endif
                </td>
            </tr>
            @if($period)
            <tr>
                <th>Duration</th>
                <td>{{ $period }}</td>
            </tr>
            @endif
        </table>

        <p class="mt-4">During the period of employment, the above-named served in the stated
           capacity, performing duties consistent with the position held.</p>

        <p>This certificate is issued in accordance with Section 51 of the Employment Act and
           does not, of itself, comment on the conduct or performance of the employee.</p>

        <div class="signature">
            <div class="line">Authorised Signatory</div>
            <p style="margin-top: 4px; font-size: 9px;" class="text-gray-600">
                Human Resources Department<br/>
                Woodnork Green Ltd
            </p>
        </div>
    </div>
@endsection
