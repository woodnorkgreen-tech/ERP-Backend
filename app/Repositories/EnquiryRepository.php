<?php

namespace App\Repositories;

use App\Models\ProjectEnquiry;
use Illuminate\Database\Eloquent\Collection;

class EnquiryRepository
{
    public function find(int $id): ?ProjectEnquiry
    {
        return ProjectEnquiry::find($id);
    }

    public function findWithRelations(int $id): ?ProjectEnquiry
    {
        return ProjectEnquiry::with('client', 'department', 'enquiryTasks', 'project')->find($id);
    }

    public function getAll(): Collection
    {
        return ProjectEnquiry::all();
    }

    public function getByUser($user): Collection
    {
        return ProjectEnquiry::accessibleByUser($user)->get();
    }

    public function create(array $data): ProjectEnquiry
    {
        return ProjectEnquiry::create($data);
    }

    public function update(ProjectEnquiry $enquiry, array $data): bool
    {
        return $enquiry->update($data);
    }

    public function delete(ProjectEnquiry $enquiry): bool
    {
        return $enquiry->delete();
    }

    public function getActive(): Collection
    {
        return ProjectEnquiry::active()->get();
    }

    public function getCompleted(): Collection
    {
        return ProjectEnquiry::completed()->get();
    }

    public function search(string $query, $user = null)
    {
        $q = ProjectEnquiry::with('client', 'department', 'enquiryTasks');

        if ($user && !$user->hasPermissionTo('enquiry.read')) {
            $q->accessibleByUser($user);
        }

        return $q->where(function ($q) use ($query) {
            $q->where('title', 'like', "%{$query}%")
              ->orWhereHas('client', function ($clientQuery) use ($query) {
                  $clientQuery->where('full_name', 'like', "%{$query}%");
              })
              ->orWhere('contact_person', 'like', "%{$query}%");
        })->get();
    }
}
