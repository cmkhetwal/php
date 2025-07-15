<?php

declare(strict_types=1);

namespace App\Models;

use App\Config\AppConfig;
use PDO;

/**
 * User Model
 * Handles user authentication and management
 */
class User
{
    private PDO $db;
    private array $data = [];

    public function __construct(PDO $db = null)
    {
        if ($db === null) {
            $config = AppConfig::getInstance();
            $dbConfig = $config->get('database');
            
            $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']};charset=utf8mb4";
            $this->db = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], $dbConfig['options']);
        } else {
            $this->db = $db;
        }
    }

    /**
     * Find user by ID
     */
    public function findById(int $id): ?self
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$userData) {
            return null;
        }
        
        $this->data = $userData;
        return $this;
    }

    /**
     * Find user by email
     */
    public function findByEmail(string $email): ?self
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$userData) {
            return null;
        }
        
        $this->data = $userData;
        return $this;
    }

    /**
     * Create a new user
     */
    public function create(array $userData): ?int
    {
        // Validate required fields
        $requiredFields = ['email', 'password', 'name'];
        foreach ($requiredFields as $field) {
            if (empty($userData[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        // Check if email already exists
        if ($this->findByEmail($userData['email'])) {
            throw new \InvalidArgumentException("Email already exists");
        }

        // Hash password
        $userData['password'] = password_hash($userData['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        
        // Set default values
        $userData['created_at'] = date('Y-m-d H:i:s');
        $userData['updated_at'] = date('Y-m-d H:i:s');
        $userData['status'] = $userData['status'] ?? 'active';
        $userData['role'] = $userData['role'] ?? 'user';

        // Insert user
        $columns = implode(', ', array_keys($userData));
        $placeholders = ':' . implode(', :', array_keys($userData));
        
        $stmt = $this->db->prepare("INSERT INTO users ({$columns}) VALUES ({$placeholders})");
        $stmt->execute($userData);
        
        $userId = (int)$this->db->lastInsertId();
        
        if ($userId) {
            $this->data = $userData;
            $this->data['id'] = $userId;
            return $userId;
        }
        
        return null;
    }

    /**
     * Update user data
     */
    public function update(array $userData): bool
    {
        if (empty($this->data['id'])) {
            throw new \RuntimeException("Cannot update user: no user loaded");
        }

        // Don't allow updating certain fields
        unset($userData['id'], $userData['created_at']);
        
        // Hash password if provided
        if (!empty($userData['password'])) {
            $userData['password'] = password_hash($userData['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        }
        
        // Set updated timestamp
        $userData['updated_at'] = date('Y-m-d H:i:s');
        
        // Build update query
        $updates = [];
        foreach (array_keys($userData) as $field) {
            $updates[] = "{$field} = :{$field}";
        }
        
        $updateString = implode(', ', $updates);
        
        // Execute update
        $userData['id'] = $this->data['id'];
        $stmt = $this->db->prepare("UPDATE users SET {$updateString} WHERE id = :id");
        $result = $stmt->execute($userData);
        
        if ($result) {
            $this->data = array_merge($this->data, $userData);
            return true;
        }
        
        return false;
    }

    /**
     * Verify password
     */
    public function verifyPassword(string $password): bool
    {
        if (empty($this->data['password'])) {
            return false;
        }
        
        return password_verify($password, $this->data['password']);
    }

    /**
     * Authenticate user
     */
    public function authenticate(string $email, string $password): ?self
    {
        $user = $this->findByEmail($email);
        
        if (!$user || !$user->verifyPassword($password)) {
            return null;
        }
        
        // Check if user is active
        if ($user->get('status') !== 'active') {
            return null;
        }
        
        return $user;
    }

    /**
     * Get user data
     */
    public function get(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->data;
        }
        
        return $this->data[$key] ?? $default;
    }

    /**
     * Check if user has a specific role
     */
    public function hasRole(string $role): bool
    {
        return $this->get('role') === $role;
    }

    /**
     * Get all users with pagination
     */
    public function getAll(int $page = 1, int $limit = 20, array $filters = []): array
    {
        $offset = ($page - 1) * $limit;
        
        $where = [];
        $params = [];
        
        // Apply filters
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }
        
        if (!empty($filters['role'])) {
            $where[] = 'role = :role';
            $params['role'] = $filters['role'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = '(name LIKE :search OR email LIKE :search)';
            $params['search'] = "%{$filters['search']}%";
        }
        
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        // Get total count
        $countSql = "SELECT COUNT(*) FROM users {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        
        // Get users
        $sql = "SELECT * FROM users {$whereClause} ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        
        // Bind parameters
        foreach ($params as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }
        
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'data' => $users,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ];
    }

    /**
     * Delete user
     */
    public function delete(): bool
    {
        if (empty($this->data['id'])) {
            throw new \RuntimeException("Cannot delete user: no user loaded");
        }
        
        $stmt = $this->db->prepare('DELETE FROM users WHERE id = :id');
        return $stmt->execute(['id' => $this->data['id']]);
    }
}
