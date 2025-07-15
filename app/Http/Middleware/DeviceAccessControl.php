<?php

namespace App\Http\Middleware;


use App\Services\DeviceAccessService;
use Cache;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Log;
use Symfony\Component\HttpFoundation\Response;

class DeviceAccessControl
{
 /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $clientIP = $this->getClientIP($request);
        $cacheKey = "device_access_" . md5($clientIP);

         Cache::forget($cacheKey);
        // Check cache first to avoid repeated JAR execution
        $isAllowed = Cache::remember($cacheKey, 300, function () use ($clientIP) {
            return $this->checkDeviceAccess($clientIP);
        });






        if (!$isAllowed) {
            Log::warning("Device access denied", ['ip' => $clientIP]);

            return Inertia::render("ErrorPage", [
                'error' => 'Access Denied',
                'message' => 'Your device is not authorized to access this application'
            ]);
        }

        return $next($request);
    }



    /**
     * Get client IP address handling proxies
     */
    private function getClientIP(Request $request): string
    {
        $headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            $ip = $request->server($header);
            if (!empty($ip) && $ip !== 'unknown') {
                return explode(',', $ip)[0];
            }
        }

        return $request->ip();
    }

    /**
     * Execute JAR file to check device access
     */
    private function checkDeviceAccess(string $clientIP): bool
    {

        try {
            $jarPath = storage_path('app/DeviceAccessControl.jar');
            $configPath = storage_path('app/access-control.conf');

            if (!file_exists($jarPath)) {
                Log::error("JAR file not found: $jarPath");
                return false;
            }

            // Execute JAR with IP parameter
            $command = sprintf(
                'java -jar %s --check-ip %s --config %s 2>&1',
                escapeshellarg($jarPath),
                escapeshellarg($clientIP),
                escapeshellarg($configPath)
            );

            // $output = shell_exec($command);
            // $exitCode = $this->getLastExitCode();

            exec($command, $output, $exitCode);
            $output = implode("\n", $output);

            Log::info("JAR Output", ['output' => $output]);


            Log::info("Device access check", [
                'ip' => $clientIP,
                'command' => $command,
                'output' => $output,
                'exit_code' => $exitCode
            ]);

            // Exit code 0 = allowed, 1 = denied
            return $exitCode === 0;

        } catch (\Exception $e) {
            Log::error("Error checking device access", [
                'ip' => $clientIP,
                'error' => $e->getMessage()
            ]);

            return false; // Deny access on error
        }
    }

    /**
     * Get the exit code of the last executed command
     */
    private function getLastExitCode(): int
    {
        return (int) shell_exec('echo $?');
    }

}
