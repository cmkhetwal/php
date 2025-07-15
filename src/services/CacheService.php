<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;
use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;

/**
 * Cache Service
 * Redis-based caching service with fallback to file cache
 */
class CacheService
{
    private ?RedisClient $redis = null;
    private bool $useRedis = true;
    private string $prefix;
    private LoggerInterface $logger;
    private string $fileCacheDir;

    public function __construct(LoggerInterface $logger = null)
    {
        $config = AppConfig::getInstance();
        $this->logger = $logger ?? new \Monolog\Logger('cache');
        $this->prefix = $config->get('cache.prefix', 'app:');
        $this->fileCacheDir = sys_get_temp_dir() . '/nextgen_app_cache';
        
        $this->initializeRedis($config);
        
        // Create file cache directory if Redis is not available
        if (!$this->useRedis && !is_dir($this->fileCacheDir)) {
            mkdir($this->fileCacheDir, 0755, true);
        }
    }

    /**
     * Initialize Redis connection
     */
    private function initializeRedis(AppConfig $config): void
    {
        try {
            $redisConfig = $config->get('redis');
            
            $this->redis = new RedisClient([
                'scheme' => 'tcp',
                'host' => $redisConfig['host'],
                'port' => $redisConfig['port'],
                'password' => $redisConfig['password'],
                'database' => $redisConfig['database'],
            ]);

            // Test connection
            $this->redis->ping();
            
            $this->logger->info('Redis cache initialized successfully');
            
        } catch (\Exception $e) {
            $this->useRedis = false;
            $this->logger->warning('Redis not available, falling back to file cache', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get value from cache
     */
    public function get(string $key, $default = null)
    {
        $key = $this->prefix . $key;
        
        try {
            if ($this->useRedis) {
                $value = $this->redis->get($key);
                
                if ($value === null) {
                    return $default;
                }
                
                return $this->unserialize($value);
            } else {
                return $this->getFromFile($key, $default);
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Cache get failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return $default;
        }
    }

    /**
     * Set value in cache
     */
    public function set(string $key, $value, int $ttl = 3600): bool
    {
        $key = $this->prefix . $key;
        
        try {
            if ($this->useRedis) {
                $serialized = $this->serialize($value);
                
                if ($ttl > 0) {
                    return $this->redis->setex($key, $ttl, $serialized) === 'OK';
                } else {
                    return $this->redis->set($key, $serialized) === 'OK';
                }
            } else {
                return $this->setToFile($key, $value, $ttl);
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Cache set failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Delete value from cache
     */
    public function delete(string $key): bool
    {
        $key = $this->prefix . $key;
        
        try {
            if ($this->useRedis) {
                return $this->redis->del($key) > 0;
            } else {
                return $this->deleteFromFile($key);
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Cache delete failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check if key exists in cache
     */
    public function exists(string $key): bool
    {
        $key = $this->prefix . $key;
        
        try {
            if ($this->useRedis) {
                return $this->redis->exists($key) > 0;
            } else {
                return $this->existsInFile($key);
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Cache exists check failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Increment a numeric value in cache
     */
    public function increment(string $key, int $value = 1): int
    {
        $key = $this->prefix . $key;
        
        try {
            if ($this->useRedis) {
                return $this->redis->incrby($key, $value);
            } else {
                $current = $this->getFromFile($key, 0);
                $new = (int)$current + $value;
                $this->setToFile($key, $new, 3600);
                return $new;
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Cache increment failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Clear all cache
     */
    public function clear(): bool
    {
        try {
            if ($this->useRedis) {
                return $this->redis->flushdb() === 'OK';
            } else {
                return $this->clearFileCache();
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Cache clear failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get multiple values from cache
     */
    public function getMultiple(array $keys, $default = null): array
    {
        $result = [];
        
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        
        return $result;
    }

    /**
     * Set multiple values in cache
     */
    public function setMultiple(array $values, int $ttl = 3600): bool
    {
        $success = true;
        
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }
        
        return $success;
    }

    /**
     * File cache methods (fallback when Redis is not available)
     */
    private function getFromFile(string $key, $default = null)
    {
        $filename = $this->getFilename($key);
        
        if (!file_exists($filename)) {
            return $default;
        }
        
        $data = file_get_contents($filename);
        $cached = unserialize($data);
        
        if ($cached['expires'] > 0 && $cached['expires'] < time()) {
            unlink($filename);
            return $default;
        }
        
        return $cached['value'];
    }

    private function setToFile(string $key, $value, int $ttl): bool
    {
        $filename = $this->getFilename($key);
        $expires = $ttl > 0 ? time() + $ttl : 0;
        
        $data = serialize([
            'value' => $value,
            'expires' => $expires
        ]);
        
        return file_put_contents($filename, $data, LOCK_EX) !== false;
    }

    private function deleteFromFile(string $key): bool
    {
        $filename = $this->getFilename($key);
        
        if (file_exists($filename)) {
            return unlink($filename);
        }
        
        return true;
    }

    private function existsInFile(string $key): bool
    {
        $filename = $this->getFilename($key);
        
        if (!file_exists($filename)) {
            return false;
        }
        
        $data = file_get_contents($filename);
        $cached = unserialize($data);
        
        if ($cached['expires'] > 0 && $cached['expires'] < time()) {
            unlink($filename);
            return false;
        }
        
        return true;
    }

    private function clearFileCache(): bool
    {
        if (!is_dir($this->fileCacheDir)) {
            return true;
        }
        
        $files = glob($this->fileCacheDir . '/*');
        
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        
        return true;
    }

    private function getFilename(string $key): string
    {
        return $this->fileCacheDir . '/' . md5($key) . '.cache';
    }

    private function serialize($value): string
    {
        return serialize($value);
    }

    private function unserialize(string $value)
    {
        return unserialize($value);
    }
}
