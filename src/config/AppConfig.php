<?php

declare(strict_types=1);

namespace App\Config;

use App\Services\VaultService;
use App\Services\AwsService;

/**
 * Application Configuration Manager
 * Handles configuration from multiple sources: Vault, Environment, AWS Parameter Store
 */
class AppConfig
{
    private static ?self $instance = null;
    private array $config = [];
    private VaultService $vaultService;
    private AwsService $awsService;

    private function __construct()
    {
        $this->vaultService = new VaultService();
        $this->awsService = new AwsService();
        $this->loadConfiguration();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function loadConfiguration(): void
    {
        // Load environment-specific configuration
        $this->config = [
            'app' => [
                'name' => getenv('APP_NAME') ?: 'NextGen PHP App',
                'version' => getenv('APP_VERSION') ?: '1.0.0',
                'environment' => getenv('APP_ENV') ?: 'production',
                'debug' => filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),
                'timezone' => getenv('APP_TIMEZONE') ?: 'UTC',
                'url' => getenv('APP_URL') ?: 'https://app.example.com',
            ],
            'database' => $this->getDatabaseConfig(),
            'redis' => $this->getRedisConfig(),
            'aws' => $this->getAwsConfig(),
            'vault' => $this->getVaultConfig(),
            'security' => $this->getSecurityConfig(),
            'logging' => $this->getLoggingConfig(),
            'cache' => $this->getCacheConfig(),
            'cdn' => $this->getCdnConfig(),
            'api' => $this->getApiConfig(),
        ];
    }

    private function getDatabaseConfig(): array
    {
        try {
            $dbSecrets = $this->vaultService->getSecret('database/mysql');
            return [
                'host' => $dbSecrets['host'] ?? getenv('DB_HOST') ?? 'localhost',
                'port' => (int)($dbSecrets['port'] ?? getenv('DB_PORT') ?? 3306),
                'database' => $dbSecrets['database'] ?? getenv('DB_NAME') ?? 'nextgen_app',
                'username' => $dbSecrets['username'] ?? getenv('DB_USER') ?? 'root',
                'password' => $dbSecrets['password'] ?? getenv('DB_PASS') ?? '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'options' => [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                    \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
                ]
            ];
        } catch (\Exception $e) {
            error_log("Failed to load database config from Vault: " . $e->getMessage());
            return $this->getFallbackDatabaseConfig();
        }
    }

    private function getRedisConfig(): array
    {
        try {
            $redisSecrets = $this->vaultService->getSecret('cache/redis');
            return [
                'host' => $redisSecrets['host'] ?? getenv('REDIS_HOST') ?? 'localhost',
                'port' => (int)($redisSecrets['port'] ?? getenv('REDIS_PORT') ?? 6379),
                'password' => $redisSecrets['password'] ?? getenv('REDIS_PASSWORD') ?? null,
                'database' => (int)(getenv('REDIS_DB') ?? 0),
                'prefix' => getenv('REDIS_PREFIX') ?? 'nextgen_app:',
            ];
        } catch (\Exception $e) {
            error_log("Failed to load Redis config from Vault: " . $e->getMessage());
            return $this->getFallbackRedisConfig();
        }
    }

    private function getAwsConfig(): array
    {
        try {
            $awsSecrets = $this->vaultService->getSecret('aws/credentials');
            return [
                'region' => getenv('AWS_REGION') ?? 'us-east-1',
                'access_key_id' => $awsSecrets['access_key_id'] ?? getenv('AWS_ACCESS_KEY_ID'),
                'secret_access_key' => $awsSecrets['secret_access_key'] ?? getenv('AWS_SECRET_ACCESS_KEY'),
                's3' => [
                    'bucket' => getenv('AWS_S3_BUCKET') ?? 'nextgen-app-assets',
                    'region' => getenv('AWS_S3_REGION') ?? getenv('AWS_REGION') ?? 'us-east-1',
                ],
                'cloudfront' => [
                    'distribution_id' => getenv('AWS_CLOUDFRONT_DISTRIBUTION_ID'),
                    'domain' => getenv('AWS_CLOUDFRONT_DOMAIN'),
                ],
                'sqs' => [
                    'queue_url' => getenv('AWS_SQS_QUEUE_URL'),
                ],
                'sns' => [
                    'topic_arn' => getenv('AWS_SNS_TOPIC_ARN'),
                ],
            ];
        } catch (\Exception $e) {
            error_log("Failed to load AWS config from Vault: " . $e->getMessage());
            return $this->getFallbackAwsConfig();
        }
    }

    private function getCdnConfig(): array
    {
        return [
            'enabled' => filter_var(getenv('CDN_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN),
            'base_url' => getenv('CDN_BASE_URL') ?: $this->config['aws']['cloudfront']['domain'] ?? '',
            'cache_control' => [
                'css' => 'public, max-age=31536000', // 1 year
                'js' => 'public, max-age=31536000',  // 1 year
                'images' => 'public, max-age=2592000', // 30 days
                'fonts' => 'public, max-age=31536000', // 1 year
            ],
        ];
    }

    private function getSecurityConfig(): array
    {
        try {
            $securitySecrets = $this->vaultService->getSecret('security/keys');
            return [
                'jwt_secret' => $securitySecrets['jwt_secret'] ?? getenv('JWT_SECRET'),
                'encryption_key' => $securitySecrets['encryption_key'] ?? getenv('ENCRYPTION_KEY'),
                'session_secret' => $securitySecrets['session_secret'] ?? getenv('SESSION_SECRET'),
                'cors' => [
                    'allowed_origins' => explode(',', getenv('CORS_ALLOWED_ORIGINS') ?: '*'),
                    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
                    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
                ],
                'rate_limiting' => [
                    'enabled' => filter_var(getenv('RATE_LIMITING_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN),
                    'requests_per_minute' => (int)(getenv('RATE_LIMIT_RPM') ?? 60),
                ],
            ];
        } catch (\Exception $e) {
            error_log("Failed to load security config from Vault: " . $e->getMessage());
            throw new \RuntimeException('Security configuration is required');
        }
    }

    private function getLoggingConfig(): array
    {
        return [
            'level' => getenv('LOG_LEVEL') ?: 'info',
            'channel' => getenv('LOG_CHANNEL') ?: 'app',
            'path' => getenv('LOG_PATH') ?: '/var/log/app.log',
            'max_files' => (int)(getenv('LOG_MAX_FILES') ?? 30),
        ];
    }

    private function getCacheConfig(): array
    {
        return [
            'default_ttl' => (int)(getenv('CACHE_TTL') ?? 3600),
            'prefix' => getenv('CACHE_PREFIX') ?? 'app:',
        ];
    }

    private function getApiConfig(): array
    {
        return [
            'version' => getenv('API_VERSION') ?: 'v1',
            'base_path' => getenv('API_BASE_PATH') ?: '/api',
            'documentation_enabled' => filter_var(getenv('API_DOCS_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN),
        ];
    }

    public function get(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    public function all(): array
    {
        return $this->config;
    }

    // Fallback configurations for when Vault is unavailable
    private function getFallbackDatabaseConfig(): array
    {
        return [
            'host' => getenv('DB_HOST') ?? 'localhost',
            'port' => (int)(getenv('DB_PORT') ?? 3306),
            'database' => getenv('DB_NAME') ?? 'nextgen_app',
            'username' => getenv('DB_USER') ?? 'root',
            'password' => getenv('DB_PASS') ?? '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ];
    }

    private function getFallbackRedisConfig(): array
    {
        return [
            'host' => getenv('REDIS_HOST') ?? 'localhost',
            'port' => (int)(getenv('REDIS_PORT') ?? 6379),
            'password' => getenv('REDIS_PASSWORD') ?? null,
            'database' => (int)(getenv('REDIS_DB') ?? 0),
            'prefix' => getenv('REDIS_PREFIX') ?? 'nextgen_app:',
        ];
    }

    private function getFallbackAwsConfig(): array
    {
        return [
            'region' => getenv('AWS_REGION') ?? 'us-east-1',
            'access_key_id' => getenv('AWS_ACCESS_KEY_ID'),
            'secret_access_key' => getenv('AWS_SECRET_ACCESS_KEY'),
        ];
    }

    private function getVaultConfig(): array
    {
        return [
            'url' => getenv('VAULT_URL') ?: 'http://vault.internal:8200',
            'token' => getenv('VAULT_TOKEN') ?: '',
            'role' => getenv('VAULT_ROLE') ?: 'nextgen-php-app',
            'auth_method' => getenv('VAULT_AUTH_METHOD') ?: 'aws',
        ];
    }
}
