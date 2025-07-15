<?php
/**
 * Database Configuration
 * Fetches database credentials from HashiCorp Vault
 */

class DatabaseConfig {
    private $vaultService;
    
    public function __construct() {
        $this->vaultService = new VaultService();
    }
    
    public function getConnection() {
        try {
            // Fetch database credentials from Vault
            $dbSecrets = $this->vaultService->getSecret('database/mysql');
            
            $host = $dbSecrets['host'] ?? getenv('DB_HOST') ?? 'localhost';
            $dbname = $dbSecrets['database'] ?? getenv('DB_NAME') ?? 'php_app';
            $username = $dbSecrets['username'] ?? getenv('DB_USER') ?? 'root';
            $password = $dbSecrets['password'] ?? getenv('DB_PASS') ?? '';
            $port = $dbSecrets['port'] ?? getenv('DB_PORT') ?? '3306';
            
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            return new PDO($dsn, $username, $password, $options);
            
        } catch (Exception $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed");
        }
    }
}
