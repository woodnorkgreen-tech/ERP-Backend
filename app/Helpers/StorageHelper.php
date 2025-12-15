<?php

if (!function_exists('storage_url')) {
    /**
     * Generate the correct storage URL for all environments
     * 
     * Returns /storage/ which works with Laravel's storage symlink
     * (public/storage -> storage/app/public)
     * 
     * @param string $path The file path within storage/app/public
     * @return string The complete URL path
     */
    function storage_url(string $path): string
    {
        // Use /storage/ prefix which matches the actual symlink location
        // public/storage -> storage/app/public
        // Files are served directly by Apache via the symlink
        return '/storage/' . ltrim($path, '/');
    }
}
