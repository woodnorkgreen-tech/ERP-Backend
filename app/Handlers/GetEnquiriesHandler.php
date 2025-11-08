<?php

namespace App\Handlers;

use App\Queries\GetEnquiriesQuery;
use App\Models\User;
use App\Repositories\EnquiryRepository;

class GetEnquiriesHandler
{
    protected $enquiryRepository;

    public function __construct(EnquiryRepository $enquiryRepository)
    {
        $this->enquiryRepository = $enquiryRepository;
    }

    public function handle(GetEnquiriesQuery $query)
    {
        try {
            \Log::info('GetEnquiriesHandler handle called', [
                'userId' => $query->userId,
                'search' => $query->search,
                'status' => $query->status,
                'clientId' => $query->clientId,
                'departmentId' => $query->departmentId
            ]);

            $user = $query->userId ? User::find($query->userId) : null;

            \Log::info('User found', ['user' => $user ? $user->id : null]);

            $enquiries = $this->enquiryRepository->getAll();

            \Log::info('Initial enquiries count', ['count' => $enquiries->count()]);

            if ($user) {
                $enquiries = $this->enquiryRepository->getByUser($user);
                \Log::info('Enquiries after getByUser', ['count' => $enquiries->count()]);
            }

            // Apply filters
            if ($query->search) {
                \Log::info('Applying search filter', ['search' => $query->search]);
                $enquiries = $this->enquiryRepository->search($query->search, $user);
                \Log::info('Enquiries after search', ['count' => $enquiries->count()]);
            }

            if ($query->status) {
                \Log::info('Applying status filter', ['status' => $query->status]);
                $enquiries = $enquiries->where('status', $query->status);
            }

            if ($query->clientId) {
                \Log::info('Applying client filter', ['clientId' => $query->clientId]);
                $enquiries = $enquiries->where('client_id', $query->clientId);
            }

            if ($query->departmentId) {
                \Log::info('Applying department filter', ['departmentId' => $query->departmentId]);
                $enquiries = $enquiries->where('department_id', $query->departmentId);
            }

            \Log::info('Final enquiries count before return', ['count' => $enquiries->count()]);

            return $enquiries;
        } catch (\Exception $e) {
            \Log::error('Error in GetEnquiriesHandler handle', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
