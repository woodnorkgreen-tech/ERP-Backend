<?php

namespace App\Commands;

class CreateEnquiryCommand
{
    public array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }
}
