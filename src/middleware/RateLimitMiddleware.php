<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Config\AppConfig;
use App\Services\CacheService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Rate Limiting Middleware
 * Prevents abuse by limiting request frequency
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private CacheService $cacheService;
    private bool $enabled;
    private int $requestsPerMinute;

    public function __construct(CacheService $cacheService)
    {
        $config = AppConfig::getInstance();
        $this->cacheService = $cacheService;
        $this->enabled = $config->get('security.rate_limiting.enabled', true);
        $this->requestsPerMinute = $config->get('security.rate_limiting.requests_per_minute', 60);
    }

    /**
     * Process the request
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
        // Skip rate limiting if disabled
        if (!$this->enabled) {
            return $handler->handle($request);
        }

        // Skip rate limiting for certain paths
        $path = $request->getUri()->getPath();
        if ($this->shouldSkipRateLimiting($path)) {
            return $handler->handle($request);
        }

        $clientIp = $this->getClientIp($request);
        $key = "rate_limit:{$clientIp}:" . floor(time() / 60); // Per minute
        
        $currentRequests = $this->cacheService->get($key, 0);
        $this->cacheService->set($key, $currentRequests + 1, 70); // 70 seconds (slightly more than a minute)
        
        if ($currentRequests >= $this->requestsPerMinute) {
            return $this->tooManyRequestsResponse();
        }
        
        $response = $handler->handle($request);
        
        // Add rate limit headers
        return $response
            ->withHeader('X-RateLimit-Limit', (string)$this->requestsPerMinute)
            ->withHeader('X-RateLimit-Remaining', (string)max(0, $this->requestsPerMinute - $currentRequests - 1))
            ->withHeader('X-RateLimit-Reset', (string)(floor(time() / 60) * 60 + 60)); // Next minute
    }

    /**
     * Check if rate limiting should be skipped for this path
     */
    private function shouldSkipRateLimiting(string $path): bool
    {
        $excludedPaths = [
            '/health',
            '/assets/',
            '/favicon.ico',
        ];
        
        foreach ($excludedPaths as $excludedPath) {
            if (strpos($path, $excludedPath) === 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get client IP address
     */
    private function getClientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();
        
        if (!empty($serverParams['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $serverParams['HTTP_X_FORWARDED_FOR'])[0];
        }
        
        if (!empty($serverParams['HTTP_X_REAL_IP'])) {
            return $serverParams['HTTP_X_REAL_IP'];
        }
        
        return $serverParams['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Create too many requests response
     */
    private function tooManyRequestsResponse(): Response
    {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode([
            'success' => false,
            'message' => 'Too many requests. Please try again later.'
        ]));
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Retry-After', '60')
            ->withStatus(429);
    }
}
