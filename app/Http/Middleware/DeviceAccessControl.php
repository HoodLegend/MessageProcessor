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
        $apiKey = $this->getDeviceApiKey($request);

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
                'user_agent' => $request->userAgent(),
                'detected_device' => $this->detectDeviceType($request)
            ]);

            return Inertia::render("ErrorPage", [
                'error' => 'Access Denied',
                'message' => 'Your device is not authorized to access this application'
            ]);
        }

        return $next($request);
    }

    /**
     * Get device-specific API key
    //  */
    // private function getDeviceApiKey(Request $request): ?string
    // {
    //     // 1. Check for explicit API key in headers (highest priority)
    //     $headerKey = $request->header('X-Device-API-Key') ?? $request->header('Authorization');
    //     if ($headerKey) {
    //         // Remove "Bearer " prefix if present
    //         return str_replace('Bearer ', '', $headerKey);
    //     }

    //     // 2. Detect device type and get appropriate key
    //     $deviceType = $this->detectDeviceType($request);
    //     $apiKeys = config('device_access.api_keys', []);

    //     // Try device-specific key first
    //     if (isset($apiKeys[$deviceType])) {
    //         return $apiKeys[$deviceType];
    //     }

    //     // Fallback to environment-based key
    //     // $environment = app()->environment();
    //     // return $apiKeys[$environment] ?? $apiKeys['production'] ?? null;
    //     return null;
    // }

    private function getDeviceApiKey(Request $request): ?string
{
    // Only accept API keys from headers
    $headerKey = $request->header('X-Device-API-Key') ?? $request->header('Authorization');

    if ($headerKey) {
        return str_replace('Bearer ', '', $headerKey);
    }

    // No fallback – if no header, return null
    return null;
}


    /**
     * Detect device type from user agent
     */
    private function detectDeviceType(Request $request): string
    {
        $userAgent = strtolower($request->userAgent() ?? '');

        // Mobile detection
        if (preg_match('/mobile|android|iphone|ipad|tablet/', $userAgent)) {
            return 'mobile';
        }

        // Desktop/Web detection
        if (preg_match('/chrome|firefox|safari|edge|opera/', $userAgent)) {
            return 'web';
        }

        // API client detection
        if (preg_match('/postman|insomnia|curl|guzzle/', $userAgent)) {
            return 'api';
        }

        // Default fallback
        return app()->environment();
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
                return trim(explode(',', $ip)[0]);
            }
        }

        return $request->ip();
    }

    /**
     * Execute JAR file to check device access
     */
    private function checkDeviceAccess(string $clientIP, ?string $apiKey = null): bool
    {
        try {
            $baseUrl = config('device_access.service_url', 'http://127.0.0.1:8081');

            if ($apiKey) {
                $response = Http::timeout(3)
                    ->post($baseUrl . '/api/v1/check-access', [
                        'ip' => $clientIP,
                        'apiKey' => $apiKey
                    ]);
            } else {
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
            return false;
        }

    }
}
