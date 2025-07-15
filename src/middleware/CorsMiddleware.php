<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Config\AppConfig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * CORS Middleware
 * Handles Cross-Origin Resource Sharing headers
 */
class CorsMiddleware implements MiddlewareInterface
{
    private array $corsConfig;

    public function __construct()
    {
        $config = AppConfig::getInstance();
        $this->corsConfig = $config->get('security.cors', [
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
        ]);
    }

    /**
     * Process the request
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
        // Handle preflight OPTIONS request
        if ($request->getMethod() === 'OPTIONS') {
            $response = new \Slim\Psr7\Response();
            return $this->addCorsHeaders($response, $request);
        }

        $response = $handler->handle($request);
        return $this->addCorsHeaders($response, $request);
    }

    /**
     * Add CORS headers to response
     */
    private function addCorsHeaders(Response $response, Request $request): Response
    {
        $origin = $request->getHeaderLine('Origin');
        
        // Check if origin is allowed
        if ($this->isOriginAllowed($origin)) {
            $response = $response->withHeader('Access-Control-Allow-Origin', $origin);
        } elseif (in_array('*', $this->corsConfig['allowed_origins'])) {
            $response = $response->withHeader('Access-Control-Allow-Origin', '*');
        }

        $response = $response
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->corsConfig['allowed_methods']))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->corsConfig['allowed_headers']))
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Max-Age', '86400'); // 24 hours

        return $response;
    }

    /**
     * Check if origin is allowed
     */
    private function isOriginAllowed(string $origin): bool
    {
        if (empty($origin)) {
            return false;
        }

        return in_array($origin, $this->corsConfig['allowed_origins']);
    }
}
