<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;

class DatabaseServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Log slow queries for performance monitoring
        DB::listen(function (QueryExecuted $query) {
            if ($query->time > 1000) {
                Log::warning('Slow query detected', [
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                    'time' => $query->time . 'ms'
                ]);
            }
        });

        // Ensure PDO settings are correctly applied after connection
        // This is a safety net in case connections are created dynamically
        DB::connection()->getPdo();
        
        // Verify the critical PDO settings for Supabase PgBouncer compatibility
        $this->verifyPdoSettings();
    }

    /**
     * Verify that PDO is configured correctly for Supabase PgBouncer
     */
    protected function verifyPdoSettings(): void
    {
        try {
            $pdo = DB::connection()->getPdo();
            
            // Check if emulated prepares is enabled
            $emulatedPrepares = $pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES);
            
            if (!$emulatedPrepares) {
                Log::warning('PDO::ATTR_EMULATE_PREPARES is disabled. This may cause prepared statement errors with PgBouncer.');
                
                // Attempt to fix it
                $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
                Log::info('PDO::ATTR_EMULATE_PREPARES has been enabled at runtime.');
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to verify PDO settings', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
