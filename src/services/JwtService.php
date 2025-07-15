<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Log\LoggerInterface;

/**
 * JWT Service
 * Handles JWT token generation and validation
 */
class JwtService
{
    private string $secret;
    private string $algorithm;
    private VaultService $vaultService;
    private LoggerInterface $logger;

    public function __construct(VaultService $vaultService = null, LoggerInterface $logger = null)
    {
        $config = AppConfig::getInstance();
        $this->vaultService = $vaultService ?? new VaultService();
        $this->logger = $logger ?? new \Monolog\Logger('jwt');
        
        try {
            // Try to get JWT secret from Vault
            $securitySecrets = $this->vaultService->getSecret('security/keys');
            $this->secret = $securitySecrets['jwt_secret'] ?? '';
        } catch (\Exception $e) {
            // Fallback to environment variable
            $this->secret = '';
            $this->logger->warning('Failed to get JWT secret from Vault, falling back to env', [
                'error' => $e->getMessage()
            ]);
        }
        
        // If still empty, use environment variable
        if (empty($this->secret)) {
            $this->secret = $config->get('security.jwt_secret', getenv('JWT_SECRET') ?: '');
            
            if (empty($this->secret)) {
                throw new \RuntimeException('JWT secret is not configured');
            }
        }
        
        $this->algorithm = 'HS256';
    }

    /**
     * Encode data into JWT token
     */
    public function encode(array $payload): string
    {
        try {
            return JWT::encode($payload, $this->secret, $this->algorithm);
        } catch (\Exception $e) {
            $this->logger->error('JWT encoding failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Decode JWT token
     */
    public function decode(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));
            return (array) $decoded;
        } catch (\Exception $e) {
            $this->logger->warning('JWT decoding failed', [
                'error' => $e->getMessage(),
                'token' => substr($token, 0, 10) . '...'
            ]);
            return null;
        }
    }

    /**
     * Validate JWT token
     */
    public function validate(string $token): bool
    {
        return $this->decode($token) !== null;
    }

    /**
     * Generate a new JWT token for a user
     */
    public function generateUserToken(int $userId, string $email, string $role = 'user'): string
    {
        $payload = [
            'user_id' => $userId,
            'email' => $email,
            'role' => $role,
            'iat' => time(),
            'exp' => time() + (24 * 60 * 60) // 24 hours
        ];

        return $this->encode($payload);
    }
}
