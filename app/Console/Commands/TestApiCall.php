<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestApiCall extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-api-call {--id=22} {--enquiry_id=15}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the site survey update endpoint';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $id = $this->option('id');
        $enquiryId = $this->option('enquiry_id');

        $this->info("Testing site survey update for ID: {$id} with enquiry_id: {$enquiryId}");

        // Get a user for authentication
        $user = \App\Models\User::first();
        if (!$user) {
            $this->error('No users found in database');
            return;
        }

        // Create a personal access token for the user
        $token = $user->createToken('test-token')->plainTextToken;

        $this->info("Using user: {$user->email} with token: " . substr($token, 0, 20) . '...');

        // Make the API call
        $response = Http::withToken($token)
            ->put("http://localhost:8000/api/projects/site-surveys/{$id}", [
                'project_enquiry_id' => $enquiryId,
                'client_name' => 'Updated Client Name',
                'location' => 'Updated Location',
                'site_visit_date' => '2025-10-15',
                'project_description' => 'Updated project description'
            ]);

        $this->info("Response status: {$response->status()}");

        if ($response->successful()) {
            $data = $response->json();
            $this->info('Update successful!');
            $this->line('Response data:');
            $this->line(json_encode($data, JSON_PRETTY_PRINT));

            // Check if enquiry_task_id was auto-linked
            if (isset($data['enquiry_task_id'])) {
                $this->info("✓ enquiry_task_id auto-linked: {$data['enquiry_task_id']}");
            } else {
                $this->warn('✗ enquiry_task_id not found in response');
            }
        } else {
            $this->error('Update failed!');
            $this->error("Response: " . $response->body());
        }
    }
}
