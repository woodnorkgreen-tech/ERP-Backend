<?php

namespace App\Repositories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;

class ProjectRepository
{
    public function find(int $id): ?Project
    {
        return Project::find($id);
    }

    public function findWithRelations(int $id): ?Project
    {
        return Project::with('enquiry', 'projectTasks')->find($id);
    }

    public function getAll(): Collection
    {
        return Project::all();
    }

    public function create(array $data): Project
    {
        return Project::create($data);
    }

    public function update(Project $project, array $data): bool
    {
        return $project->update($data);
    }

    public function delete(Project $project): bool
    {
        return $project->delete();
    }

    public function getByEnquiry(int $enquiryId): ?Project
    {
        return Project::where('enquiry_id', $enquiryId)->first();
    }

    public function getActive(): Collection
    {
        return Project::whereIn('status', ['planning', 'in_progress'])->get();
    }

    public function getCompleted(): Collection
    {
        return Project::where('status', 'completed')->get();
    }
}
