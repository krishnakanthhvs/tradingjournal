<?php
// Simple file-based cache (safe for shared hosting)

function cache_get(string $key, int $ttl = 60) {
    $file = __DIR__ . "/cache/{$key}.json";

    if (!file_exists($file)) {
        return null;
    }

    // expired?
    if ((time() - filemtime($file)) > $ttl) {
        return null;
    }

    $data = file_get_contents($file);
    return $data !== false ? json_decode($data, true) : null;
}

function cache_set(string $key, $data): void {
    $file = __DIR__ . "/cache/{$key}.json";
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
}

function cache_forget(string $key): void {
    $file = __DIR__ . "/cache/{$key}.json";
    if (file_exists($file)) {
        unlink($file);
    }
}