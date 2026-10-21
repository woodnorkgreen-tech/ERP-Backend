<?php
$t = App\Modules\Logistics\Models\TripRequest::latest()->first();
var_dump(App\Modules\Logistics\Services\SmsService::send('0798302731', 'Woodnork: Trip '.$t->request_code.' to '.$t->destination.' is completed.'));
