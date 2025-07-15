<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * HashiCorp Vault Service
 * Enhanced service for secure secrets management with multiple auth methods
 */
class VaultService
{
    private string $vaultUrl;
    private ?string $vaultToken = null;
    private Client $httpClient;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(LoggerInterface $logger = null)
    {
        $this->config = [
            'url' => getenv('VAULT_URL') ?: 'http://vault.internal:8200',
            'token' => getenv('VAULT_TOKEN') ?: '',
            'role' => getenv('VAULT_ROLE') ?: 'nextgen-php-app',
            'auth_method' => getenv('VAULT_AUTH_METHOD') ?: 'aws',
            'timeout' => (int)(getenv('VAULT_TIMEOUT') ?: 30),
        ];

        $this->vaultUrl = $this->config['url'];
        $this->vaultToken = $this->config['token'];
        $this->logger = $logger ?? new \Monolog\Logger('vault');

        $this->httpClient = new Client([
            'timeout' => $this->config['timeout'],
            'verify' => filter_var(getenv('VAULT_SSL_VERIFY') ?: 'true', FILTER_VALIDATE_BOOLEAN),
        ]);

        if (empty($this->vaultToken)) {
            $this->authenticate();
        }
    }
    
    /**
     * Authenticate with Vault using configured method
     */
    private function authenticate(): void
    {
        try {
            switch ($this->config['auth_method']) {
                case 'aws':
                    $this->authenticateWithAWS();
                    break;
                case 'kubernetes':
                    $this->authenticateWithKubernetes();
                    break;
                case 'token':
                    if (empty($this->vaultToken)) {
                        throw new \Exception('Vault token not provided');
                    }
                    break;
                default:
                    throw new \Exception('Unsupported Vault auth method: ' . $this->config['auth_method']);
            }

            $this->logger->info('Successfully authenticated with Vault', [
                'auth_method' => $this->config['auth_method']
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Vault authentication failed', [
                'error' => $e->getMessage(),
                'auth_method' => $this->config['auth_method']
            ]);
            throw $e;
        }
    }

    /**
     * Authenticate with Vault using AWS EC2 instance metadata
     */
    private function authenticateWithAWS(): void
    {
        try {
            // Get EC2 instance identity document and signature
            $identityDoc = $this->getEC2IdentityDocument();
            $signature = $this->getEC2IdentitySignature();

            $authData = [
                'role' => $this->config['role'],
                'identity' => base64_encode($identityDoc),
                'signature' => $signature
            ];

            $response = $this->httpClient->post($this->vaultUrl . '/v1/auth/aws/login', [
                'json' => $authData,
                'headers' => ['Content-Type' => 'application/json']
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['auth']['client_token'])) {
                $this->vaultToken = $result['auth']['client_token'];
            } else {
                throw new \Exception('Invalid response from Vault AWS auth');
            }

        } catch (GuzzleException $e) {
            throw new \Exception('AWS authentication failed: ' . $e->getMessage());
        }
    }

    /**
     * Authenticate with Vault using Kubernetes service account
     */
    private function authenticateWithKubernetes(): void
    {
        try {
            $jwt = file_get_contents('/var/run/secrets/kubernetes.io/serviceaccount/token');

            if (!$jwt) {
                throw new \Exception('Could not read Kubernetes service account token');
            }

            $authData = [
                'role' => $this->config['role'],
                'jwt' => $jwt
            ];

            $response = $this->httpClient->post($this->vaultUrl . '/v1/auth/kubernetes/login', [
                'json' => $authData,
                'headers' => ['Content-Type' => 'application/json']
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['auth']['client_token'])) {
                $this->vaultToken = $result['auth']['client_token'];
            } else {
                throw new \Exception('Invalid response from Vault Kubernetes auth');
            }

        } catch (GuzzleException $e) {
            throw new \Exception('Kubernetes authentication failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Get secret from Vault
     */
    public function getSecret($path) {
        try {
            $headers = [
                'X-Vault-Token: ' . $this->vaultToken,
                'Content-Type: application/json'
            ];
            
            $response = $this->httpClient->get(
                $this->vaultUrl . '/v1/secret/data/' . $path,
                $headers
            );
            
            $result = json_decode($response, true);
            
            if (isset($result['data']['data'])) {
                return $result['data']['data'];
            } else {
                throw new Exception('Secret not found: ' . $path);
            }
            
        } catch (Exception $e) {
            error_log("Failed to fetch secret from Vault: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get EC2 instance identity document
     */
    private function getEC2IdentityDocument() {
        $url = 'http://169.254.169.254/latest/dynamic/instance-identity/document';
        return $this->httpClient->get($url, [], 2); // 2 second timeout
    }
    
    /**
     * Get EC2 instance identity signature
     */
    private function getEC2IdentitySignature() {
        $url = 'http://169.254.169.254/latest/dynamic/instance-identity/signature';
        return $this->httpClient->get($url, [], 2); // 2 second timeout
    }
}

/**
 * Simple HTTP Client for Vault communication
 */
class CurlHttpClient {
    public function get($url, $headers = [], $timeout = 30) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 400) {
            throw new Exception("HTTP Error: $httpCode");
        }
        
        return $response;
    }
    
    public function post($url, $data, $headers = [], $timeout = 30) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 400) {
            throw new Exception("HTTP Error: $httpCode");
        }
        
        return $response;
    }
}
