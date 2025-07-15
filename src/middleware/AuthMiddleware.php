<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\JwtService;
use App\Services\CacheService;
use App\Models\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Authentication Middleware
 * Validates JWT tokens and attaches user to request
 */
class AuthMiddleware implements MiddlewareInterface
{
    private JwtService $jwtService;
    private CacheService $cacheService;
    private User $userModel;

    public function __construct(JwtService $jwtService, CacheService $cacheService, User $userModel)
    {
        $this->jwtService = $jwtService;
        $this->cacheService = $cacheService;
        $this->userModel = $userModel;
    }

    /**
     * Process the request
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
        $token = $this->extractTokenFromRequest($request);
        
        if (!$token) {
            return $this->unauthorizedResponse('No token provided');
        }

        // Check if token is blacklisted
        $blacklistKey = "blacklisted_token:" . hash('sha256', $token);
        if ($this->cacheService->exists($blacklistKey)) {
            return $this->unauthorizedResponse('Token has been revoked');
        }

        $tokenData = $this->jwtService->decode($token);
        
        if (!$tokenData) {
            return $this->unauthorizedResponse('Invalid token');
        }

        // Check token expiration
        if (!isset($tokenData['exp']) || $tokenData['exp'] < time()) {
            return $this->unauthorizedResponse('Token has expired');
        }

        // Load user
        if (!isset($tokenData['user_id'])) {
            return $this->unauthorizedResponse('Invalid token payload');
        }

        $user = $this->userModel->findById((int)$tokenData['user_id']);
        
        if (!$user) {
            return $this->unauthorizedResponse('User not found');
        }

        // Check if user is active
        if ($user->get('status') !== 'active') {
            return $this->unauthorizedResponse('User account is not active');
        }

        // Attach user and token data to request
        $request = $request->withAttribute('user', $user);
        $request = $request->withAttribute('token', $tokenData);

        return $handler->handle($request);
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
     * Create unauthorized response
     */
    private function unauthorizedResponse(string $message): Response
    {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode([
            'success' => false,
            'message' => $message
        ]));
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(401);
    }
}
