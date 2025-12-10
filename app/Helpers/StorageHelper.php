<?php

if (!function_exists('storage_url')) {
    /**
     * Generate the correct storage URL based on environment
     * 
     * @param string $path The file path within storage/app/public
     * @return string The complete URL path
     */
    function storage_url(string $path): string
    {
        // In production, prepend /system to the storage path
        // In development, use just /storage
        $prefix = config('app.env') === 'production' 
            ? '/system/storage/' 
            : '/storage/';
        
        return $prefix . ltrim($path, '/');
    }
}
