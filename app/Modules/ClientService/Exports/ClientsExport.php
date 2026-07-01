<?php

namespace App\Modules\ClientService\Exports;

use App\Modules\ClientService\Models\Client;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ClientsExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    use Exportable;

    protected $leadSource;

    public function __construct($leadSource = null)
    {
        $this->leadSource = $leadSource;
    }

    public function query()
    {
        $query = Client::query();

        if (!empty($this->leadSource) && $this->leadSource !== 'all') {
            $query->where('lead_source', $this->leadSource);
        }

        return $query->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'ID',
            'Full Name',
            'Company Name',
            'Contact Person',
            'Email',
            'Phone',
            'Lead Source',
            'Customer Type',
            'Status',
            'Registration Date',
        ];
    }

    public function map($client): array
    {
        return [
            $client->id,
            $client->full_name,
            $client->company_name,
            $client->contact_person,
            $client->email,
            $client->phone,
            $client->lead_source,
            $client->customer_type,
            $client->status,
            $client->registration_date ? $client->registration_date->format('Y-m-d') : '',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 12]],
        ];
    }
}
