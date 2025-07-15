<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\VaultService;
use App\Services\CacheService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use PDO;

/**
 * Health Check Controller
 * Provides application health status for monitoring
 */
class HealthController
{
    private PDO $db;
    private CacheService $cacheService;
    private VaultService $vaultService;
    private LoggerInterface $logger;

    public function __construct(
        PDO $db,
        CacheService $cacheService,
        VaultService $vaultService,
        LoggerInterface $logger
    ) {
        $this->db = $db;
        $this->cacheService = $cacheService;
        $this->vaultService = $vaultService;
        $this->logger = $logger;
    }

    /**
     * Basic health check
     */
    public function check(Request $request, Response $response): Response
    {
        $health = [
            'status' => 'healthy',
            'timestamp' => date('c'),
            'version' => getenv('APP_VERSION') ?: '1.0.0',
            'environment' => getenv('APP_ENV') ?: 'production'
        ];

        return $this->jsonResponse($response, $health);
    }

    /**
     * Detailed health check with dependencies
     */
    public function detailed(Request $request, Response $response): Response
    {
        $startTime = microtime(true);
        $checks = [];
        $overallStatus = 'healthy';

        // Database check
        $checks['database'] = $this->checkDatabase();
        
        // Cache check
        $checks['cache'] = $this->checkCache();
        
        // Vault check
        $checks['vault'] = $this->checkVault();
        
        // Disk space check
        $checks['disk'] = $this->checkDiskSpace();
        
        // Memory check
        $checks['memory'] = $this->checkMemory();

        // Determine overall status
        foreach ($checks as $check) {
            if ($check['status'] !== 'healthy') {
                $overallStatus = 'unhealthy';
                break;
            }
        }

        $responseTime = round((microtime(true) - $startTime) * 1000, 2);

        $health = [
            'status' => $overallStatus,
            'timestamp' => date('c'),
            'version' => getenv('APP_VERSION') ?: '1.0.0',
            'environment' => getenv('APP_ENV') ?: 'production',
            'response_time_ms' => $responseTime,
            'checks' => $checks,
            'system' => [
                'php_version' => PHP_VERSION,
                'memory_usage' => $this->formatBytes(memory_get_usage(true)),
                'memory_peak' => $this->formatBytes(memory_get_peak_usage(true)),
                'uptime' => $this->getUptime()
            ]
        ];

        $statusCode = $overallStatus === 'healthy' ? 200 : 503;
        return $this->jsonResponse($response, $health, $statusCode);
    }

    /**
     * Check database connectivity
     */
    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            $stmt = $this->db->query('SELECT 1');
            $responseTime = round((microtime(true) - $start) * 1000, 2);
            
            if ($stmt) {
                return [
                    'status' => 'healthy',
                    'response_time_ms' => $responseTime,
                    'message' => 'Database connection successful'
                ];
            } else {
                return [
                    'status' => 'unhealthy',
                    'response_time_ms' => $responseTime,
                    'message' => 'Database query failed'
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('Database health check failed', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'status' => 'unhealthy',
                'response_time_ms' => 0,
                'message' => 'Database connection failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check cache connectivity
     */
    private function checkCache(): array
    {
        try {
            $start = microtime(true);
            $testKey = 'health_check_' . time();
            $testValue = 'test_value';
            
            // Test write
            $writeSuccess = $this->cacheService->set($testKey, $testValue, 60);
            
            // Test read
            $readValue = $this->cacheService->get($testKey);
            
            // Test delete
            $deleteSuccess = $this->cacheService->delete($testKey);
            
            $responseTime = round((microtime(true) - $start) * 1000, 2);
            
            if ($writeSuccess && $readValue === $testValue && $deleteSuccess) {
                return [
                    'status' => 'healthy',
                    'response_time_ms' => $responseTime,
                    'message' => 'Cache operations successful'
                ];
            } else {
                return [
                    'status' => 'unhealthy',
                    'response_time_ms' => $responseTime,
                    'message' => 'Cache operations failed'
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('Cache health check failed', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'status' => 'unhealthy',
                'response_time_ms' => 0,
                'message' => 'Cache connection failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check Vault connectivity
     */
    private function checkVault(): array
    {
        try {
            $start = microtime(true);
            $isHealthy = $this->vaultService->healthCheck();
            $responseTime = round((microtime(true) - $start) * 1000, 2);
            
            if ($isHealthy) {
                return [
                    'status' => 'healthy',
                    'response_time_ms' => $responseTime,
                    'message' => 'Vault connection successful'
                ];
            } else {
                return [
                    'status' => 'unhealthy',
                    'response_time_ms' => $responseTime,
                    'message' => 'Vault health check failed'
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('Vault health check failed', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'status' => 'unhealthy',
                'response_time_ms' => 0,
                'message' => 'Vault connection failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check disk space
     */
    private function checkDiskSpace(): array
    {
        try {
            $path = '/var/www/html';
            $freeBytes = disk_free_space($path);
            $totalBytes = disk_total_space($path);
            
            if ($freeBytes === false || $totalBytes === false) {
                return [
                    'status' => 'unknown',
                    'message' => 'Unable to check disk space'
                ];
            }
            
            $usedBytes = $totalBytes - $freeBytes;
            $usagePercent = round(($usedBytes / $totalBytes) * 100, 2);
            
            $status = $usagePercent > 90 ? 'unhealthy' : 'healthy';
            $message = $usagePercent > 90 ? 'Disk space critically low' : 'Disk space sufficient';
            
            return [
                'status' => $status,
                'message' => $message,
                'details' => [
                    'total' => $this->formatBytes($totalBytes),
                    'used' => $this->formatBytes($usedBytes),
                    'free' => $this->formatBytes($freeBytes),
                    'usage_percent' => $usagePercent
                ]
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unknown',
                'message' => 'Error checking disk space: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check memory usage
     */
    private function checkMemory(): array
    {
        try {
            $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
            $memoryUsage = memory_get_usage(true);
            $memoryPeak = memory_get_peak_usage(true);
            
            $usagePercent = $memoryLimit > 0 ? round(($memoryUsage / $memoryLimit) * 100, 2) : 0;
            
            $status = $usagePercent > 90 ? 'unhealthy' : 'healthy';
            $message = $usagePercent > 90 ? 'Memory usage critically high' : 'Memory usage normal';
            
            return [
                'status' => $status,
                'message' => $message,
                'details' => [
                    'limit' => $this->formatBytes($memoryLimit),
                    'current' => $this->formatBytes($memoryUsage),
                    'peak' => $this->formatBytes($memoryPeak),
                    'usage_percent' => $usagePercent
                ]
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unknown',
                'message' => 'Error checking memory: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return -1; // Unlimited
        }
        
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
                // no break
            case 'm':
                $value *= 1024;
                // no break
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }

    /**
     * Get system uptime
     */
    private function getUptime(): string
    {
        if (file_exists('/proc/uptime')) {
            $uptime = file_get_contents('/proc/uptime');
            $seconds = (int) explode(' ', $uptime)[0];
            
            $days = floor($seconds / 86400);
            $hours = floor(($seconds % 86400) / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            
            return "{$days}d {$hours}h {$minutes}m";
        }
        
        return 'Unknown';
    }

    /**
     * Create JSON response
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
