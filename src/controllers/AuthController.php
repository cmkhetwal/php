<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use App\Services\JwtService;
use App\Services\CacheService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Authentication Controller
 * Handles user login, logout, registration, and JWT token management
 */
class AuthController
{
    private User $userModel;
    private JwtService $jwtService;
    private CacheService $cacheService;
    private LoggerInterface $logger;

    public function __construct(
        User $userModel,
        JwtService $jwtService,
        CacheService $cacheService,
        LoggerInterface $logger
    ) {
        $this->userModel = $userModel;
        $this->jwtService = $jwtService;
        $this->cacheService = $cacheService;
        $this->logger = $logger;
    }

    /**
     * User login
     */
    public function login(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);
            
            // Validate input
            if (empty($data['email']) || empty($data['password'])) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Email and password are required'
                ], 400);
            }

            // Rate limiting check
            $clientIp = $this->getClientIp($request);
            $rateLimitKey = "login_attempts:{$clientIp}";
            $attempts = $this->cacheService->get($rateLimitKey, 0);
            
            if ($attempts >= 5) {
                $this->logger->warning('Login rate limit exceeded', [
                    'ip' => $clientIp,
                    'email' => $data['email']
                ]);
                
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Too many login attempts. Please try again later.'
                ], 429);
            }

            // Authenticate user
            $user = $this->userModel->authenticate($data['email'], $data['password']);
            
            if (!$user) {
                // Increment failed attempts
                $this->cacheService->set($rateLimitKey, $attempts + 1, 900); // 15 minutes
                
                $this->logger->info('Failed login attempt', [
                    'email' => $data['email'],
                    'ip' => $clientIp
                ]);
                
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Invalid credentials'
                ], 401);
            }

            // Clear failed attempts on successful login
            $this->cacheService->delete($rateLimitKey);

            // Generate JWT token
            $tokenData = [
                'user_id' => $user->get('id'),
                'email' => $user->get('email'),
                'role' => $user->get('role'),
                'iat' => time(),
                'exp' => time() + (24 * 60 * 60) // 24 hours
            ];

            $token = $this->jwtService->encode($tokenData);
            
            // Store token in cache for logout functionality
            $tokenKey = "user_token:{$user->get('id')}";
            $this->cacheService->set($tokenKey, $token, 24 * 60 * 60);

            $this->logger->info('User logged in successfully', [
                'user_id' => $user->get('id'),
                'email' => $user->get('email'),
                'ip' => $clientIp
            ]);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'token' => $token,
                    'user' => [
                        'id' => $user->get('id'),
                        'name' => $user->get('name'),
                        'email' => $user->get('email'),
                        'role' => $user->get('role')
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Login error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'An error occurred during login'
            ], 500);
        }
    }

    /**
     * User registration
     */
    public function register(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);
            
            // Validate input
            $requiredFields = ['name', 'email', 'password'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => "Field '{$field}' is required"
                    ], 400);
                }
            }

            // Validate email format
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Invalid email format'
                ], 400);
            }

            // Validate password strength
            if (strlen($data['password']) < 8) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Password must be at least 8 characters long'
                ], 400);
            }

            // Create user
            $userId = $this->userModel->create([
                'name' => trim($data['name']),
                'email' => strtolower(trim($data['email'])),
                'password' => $data['password'],
                'role' => 'user' // Default role
            ]);

            if (!$userId) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Failed to create user'
                ], 500);
            }

            $this->logger->info('User registered successfully', [
                'user_id' => $userId,
                'email' => $data['email']
            ]);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'User registered successfully',
                'data' => [
                    'user_id' => $userId
                ]
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => $e->getMessage()
            ], 400);

        } catch (\Exception $e) {
            $this->logger->error('Registration error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'An error occurred during registration'
            ], 500);
        }
    }

    /**
     * User logout
     */
    public function logout(Request $request, Response $response): Response
    {
        try {
            $token = $this->extractTokenFromRequest($request);
            
            if (!$token) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'No token provided'
                ], 400);
            }

            $tokenData = $this->jwtService->decode($token);
            
            if ($tokenData) {
                // Remove token from cache
                $tokenKey = "user_token:{$tokenData['user_id']}";
                $this->cacheService->delete($tokenKey);
                
                // Add token to blacklist
                $blacklistKey = "blacklisted_token:" . hash('sha256', $token);
                $this->cacheService->set($blacklistKey, true, $tokenData['exp'] - time());

                $this->logger->info('User logged out successfully', [
                    'user_id' => $tokenData['user_id']
                ]);
            }

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Logout successful'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Logout error', [
                'error' => $e->getMessage()
            ]);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Logout successful'
            ]);
        }
    }

    /**
     * Get current user profile
     */
    public function profile(Request $request, Response $response): Response
    {
        try {
            $user = $request->getAttribute('user');
            
            if (!$user) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'id' => $user->get('id'),
                    'name' => $user->get('name'),
                    'email' => $user->get('email'),
                    'role' => $user->get('role'),
                    'status' => $user->get('status'),
                    'created_at' => $user->get('created_at')
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Profile error', [
                'error' => $e->getMessage()
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'An error occurred while fetching profile'
            ], 500);
        }
    }

    /**
     * Refresh JWT token
     */
    public function refresh(Request $request, Response $response): Response
    {
        try {
            $token = $this->extractTokenFromRequest($request);
            
            if (!$token) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'No token provided'
                ], 400);
            }

            $tokenData = $this->jwtService->decode($token);
            
            if (!$tokenData) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Invalid token'
                ], 401);
            }

            // Generate new token
            $newTokenData = [
                'user_id' => $tokenData['user_id'],
                'email' => $tokenData['email'],
                'role' => $tokenData['role'],
                'iat' => time(),
                'exp' => time() + (24 * 60 * 60) // 24 hours
            ];

            $newToken = $this->jwtService->encode($newTokenData);
            
            // Update token in cache
            $tokenKey = "user_token:{$tokenData['user_id']}";
            $this->cacheService->set($tokenKey, $newToken, 24 * 60 * 60);

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'token' => $newToken
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Token refresh error', [
                'error' => $e->getMessage()
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to refresh token'
            ], 500);
        }
    }

    /**
     * Extract JWT token from request
     */
    private function extractTokenFromRequest(Request $request): ?string
    {
        $authHeader = $request->getHeaderLine('Authorization');
        
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $matches[1];
        }
        
        return null;
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
     * Create JSON response
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
