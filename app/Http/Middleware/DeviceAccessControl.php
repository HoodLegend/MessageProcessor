<?php

namespace App\Http\Middleware;


use App\Services\DeviceAccessService;
use Cache;
use Closure;
use Http;
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

        //  Cache::forget($cacheKey);
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
            $url = "http://127.0.0.1:8081/api/v1/check-ip?ip=" . urlencode($clientIP);
            $response = Http::timeout(2)->get($url);

            if ($response->failed()) {
                Log::error("Failed to contact IP service", ['ip' => $clientIP]);
                return false;
            }

            return $response->json('allowed', false);
        } catch (\Exception $e) {
            Log::error("Error contacting IP service", ['error' => $e->getMessage()]);
            return false;
        }
    }

}
