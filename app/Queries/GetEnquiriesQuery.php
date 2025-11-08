<?php

namespace App\Queries;

class GetEnquiriesQuery
{
    public ?int $userId;
    public ?string $search;
    public ?string $status;
    public ?int $clientId;
    public ?int $departmentId;

    public function __construct(?int $userId = null, ?string $search = null, ?string $status = null, ?int $clientId = null, ?int $departmentId = null)
    {
        $this->userId = $userId;
        $this->search = $search;
        $this->status = $status;
        $this->clientId = $clientId;
        $this->departmentId = $departmentId;
    }
}
