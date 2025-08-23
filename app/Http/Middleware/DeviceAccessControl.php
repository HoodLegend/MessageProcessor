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
        $apiKey = $this->getApiKey($request); // Optional: get from header or session

        $cacheKey = "device_access_" . md5($clientIP . ($apiKey ?? ''));
        $cacheTtl = config('device_access.cache_ttl', 300);

        // Cache for 5 minutes to avoid repeated JAR calls
        $isAllowed = Cache::remember($cacheKey, $cacheTtl, function () use ($clientIP, $apiKey) {
            return $this->checkDeviceAccess($clientIP, $apiKey);
        });

        if (!$isAllowed) {
            Log::warning("Device access denied", [
                'ip' => $clientIP,
                'has_api_key' => !empty($apiKey),
                'user_agent' => $request->userAgent()
            ]);

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
                // Handle comma-separated IPs (X-Forwarded-For)
                return trim(explode(',', $ip)[0]);
            }
        }

        return $request->ip();
    }

    /**
     * Get API key from request (optional - for future use)
     */
    // private function getApiKey(Request $request): ?string
    // {
    //     // Check header first
    //     $apiKey = $request->header('X-API-KEY');

    //     // Fallback to session or bearer token if needed
    //     if (empty($apiKey)) {
    //         $apiKey = $request->session()->get('api_key');
    //     }

    //     return $apiKey;
    // }
    private function getApiKey(Request $request): ?string
    {
        // Get the appropriate API key for current environment
        $apiKeyCallback = config('device_access.current_api_key');
        $apiKey = is_callable($apiKeyCallback) ? $apiKeyCallback() : $apiKeyCallback;
           Log::info("getApiKey Debug", [
        'environment' => app()->environment(),
        'callback_type' => is_callable($apiKeyCallback) ? 'callable' : 'not_callable',
        'api_key' => $apiKey,
        'api_key_type' => gettype($apiKey),
        'api_key_length' => strlen($apiKey ?? ''),
    ]);
        return $apiKey;
    }


    /**
     * Execute JAR file to check device access
     */
    private function checkDeviceAccess(string $clientIP, ?string $apiKey = null): bool
    {
        try {
            $baseUrl = config('device_access.service_url', 'http://127.0.0.1:8081');

            if ($apiKey) {
                // Use full access check if API key is present
                $response = Http::timeout(3)
                    ->post($baseUrl . '/api/v1/check-access', [
                        'ip' => $clientIP,
                        'apiKey' => $apiKey
                    ]);
            } else {
                // Use IP-only check (current behavior)
                $response = Http::timeout(3)
                    ->get($baseUrl . '/api/v1/check-ip', [
                        'ip' => $clientIP
                    ]);
            }

            if ($response->failed()) {
                Log::error("Access control service failed", [
                    'ip' => $clientIP,
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                return false;
            }

            $result = $response->json();
            return $result['allowed'] ?? false;

        } catch (\Exception $e) {
            Log::error("Error contacting access control service", [
                'ip' => $clientIP,
                'error' => $e->getMessage()
            ]);

            // Fail closed for security
            return false;
        }
    }

}
