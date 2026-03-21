<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Notification;

$user = User::first();
if ($user) {
    echo "Fetching notifications for user: " . $user->name . " (ID: " . $user->id . ")\n";
    try {
        $notifications = $user->appNotifications()->orderBy('created_at', 'desc')->get();
        echo "Found " . $notifications->count() . " notifications.\n";
        
        // Try creating one
        echo "Creating a test notification...\n";
        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'test_notification',
            'title' => 'Test Notification',
            'message' => 'This is a test notification from the fix verification script.',
            'data' => ['foo' => 'bar'],
        ]);
        echo "Created notification with ID: " . $notification->id . "\n";
        
        $notificationsAfter = $user->appNotifications()->get();
        echo "Total notifications now: " . $notificationsAfter->count() . "\n";
        
    } catch (\Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
} else {
    echo "No users found in database.\n";
}
