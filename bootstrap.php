<?php

/*
 * Loaded by Composer's autoloader, so this shutdown function is registered
 * before Laravel's fatal error handler and runs first. When the script dies on
 * memory_limit it grants 64 MB more: enough for Laravel to report the error and
 * for the agent to record and spool the crash. Laravel alone keeps only 32 KB
 * in reserve, so out-of-memory crashes otherwise vanish without a trace.
 */
register_shutdown_function(static function (): void {
    if (! defined('LARAMON_AGENT_RECORDING')) {
        return;
    }

    $error = error_get_last();
    if ($error === null || ! ($error['type'] & E_ERROR) || ! str_contains($error['message'], 'Allowed memory size of')) {
        return;
    }

    $limit = trim((string) ini_get('memory_limit'));
    $bytes = (int) $limit * match (strtolower(substr($limit, -1))) {
        'g' => 1024 ** 3,
        'm' => 1024 ** 2,
        'k' => 1024,
        default => 1,
    };
    if ($bytes > 0) {
        @ini_set('memory_limit', (string) ($bytes + 64 * 1024 * 1024));
    }
});
