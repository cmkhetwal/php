<?php

declare(strict_types=1);

use Slim\Factory\AppFactory;
use Slim\Factory\ServerRequestCreatorFactory;
use DI\Container;
use Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use App\Config\AppConfig;
use App\Middleware\AuthMiddleware;
use App\Middleware\CorsMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Controllers\AuthController;
use App\Controllers\ApiController;
use App\Controllers\HealthController;
use App\Models\User;
use App\Services\VaultService;
use App\Services\JwtService;
use App\Services\CacheService;
use App\Services\AwsService;

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Set timezone
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'UTC');

// Create DI container
$container = new Container();

// Configure services
$container->set('config', function () {
    return AppConfig::getInstance();
});

$container->set('logger', function () {
    $config = AppConfig::getInstance();
    $logger = new Logger($config->get('logging.channel', 'app'));
    
    // Add handlers based on environment
    if ($config->get('app.environment') === 'production') {
        $logger->pushHandler(new RotatingFileHandler(
            $config->get('logging.path', '/var/log/app.log'),
            $config->get('logging.max_files', 30),
            Logger::INFO
        ));
    } else {
        $logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));
    }
    
    return $logger;
});

$container->set('vault', function ($container) {
    return new VaultService($container->get('logger'));
});

$container->set('cache', function ($container) {
    return new CacheService($container->get('logger'));
});

$container->set('jwt', function ($container) {
    return new JwtService($container->get('vault'), $container->get('logger'));
});

$container->set('aws', function ($container) {
    return new AwsService($container->get('logger'));
});

$container->set('db', function () {
    $config = AppConfig::getInstance();
    $dbConfig = $config->get('database');
    
    $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']};charset=utf8mb4";
    return new PDO($dsn, $dbConfig['username'], $dbConfig['password'], $dbConfig['options']);
});

$container->set('user', function ($container) {
    return new User($container->get('db'));
});

// Controllers
$container->set(AuthController::class, function ($container) {
    return new AuthController(
        $container->get('user'),
        $container->get('jwt'),
        $container->get('cache'),
        $container->get('logger')
    );
});

$container->set(ApiController::class, function ($container) {
    return new ApiController(
        $container->get('user'),
        $container->get('cache'),
        $container->get('aws'),
        $container->get('logger')
    );
});

$container->set(HealthController::class, function ($container) {
    return new HealthController(
        $container->get('db'),
        $container->get('cache'),
        $container->get('vault'),
        $container->get('logger')
    );
});

// Create Slim app
AppFactory::setContainer($container);
$app = AppFactory::create();

// Add error middleware
$errorMiddleware = $app->addErrorMiddleware(
    AppConfig::getInstance()->get('app.debug', false),
    true,
    true
);

// Add middleware
$app->add(new CorsMiddleware());
$app->add(new RateLimitMiddleware($container->get('cache')));

// Routes
$app->group('/api/v1', function ($group) {
    // Public routes
    $group->post('/auth/login', [AuthController::class, 'login']);
    $group->post('/auth/register', [AuthController::class, 'register']);
    
    // Health check
    $group->get('/health', [HealthController::class, 'check']);
    $group->get('/health/detailed', [HealthController::class, 'detailed']);
    
    // Protected routes
    $group->group('', function ($group) {
        $group->post('/auth/logout', [AuthController::class, 'logout']);
        $group->post('/auth/refresh', [AuthController::class, 'refresh']);
        $group->get('/auth/profile', [AuthController::class, 'profile']);
        
        // API endpoints
        $group->get('/users', [ApiController::class, 'getUsers']);
        $group->get('/users/{id}', [ApiController::class, 'getUser']);
        $group->put('/users/{id}', [ApiController::class, 'updateUser']);
        $group->delete('/users/{id}', [ApiController::class, 'deleteUser']);
        
        // File upload
        $group->post('/upload', [ApiController::class, 'uploadFile']);
        
    })->add(new AuthMiddleware($container->get('jwt'), $container->get('cache'), $container->get('user')));
});

// Serve static files with CDN headers in development
$app->get('/assets/{path:.*}', function ($request, $response, $args) use ($container) {
    $path = $args['path'];
    $filePath = __DIR__ . '/assets/' . $path;
    
    if (!file_exists($filePath) || !is_file($filePath)) {
        return $response->withStatus(404);
    }
    
    $config = AppConfig::getInstance();
    $aws = $container->get('aws');
    
    // In production, redirect to CDN
    if ($config->get('app.environment') === 'production' && $config->get('cdn.enabled')) {
        $cdnUrl = $config->get('cdn.base_url') . '/' . $path;
        return $response->withHeader('Location', $cdnUrl)->withStatus(301);
    }
    
    // Serve file directly in development
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $filePath);
    finfo_close($finfo);
    
    $response->getBody()->write(file_get_contents($filePath));
    
    return $response
        ->withHeader('Content-Type', $mimeType)
        ->withHeader('Cache-Control', 'public, max-age=3600')
        ->withHeader('ETag', md5_file($filePath));
});

// Default route - serve login page
$app->get('/', function ($request, $response) {
    $html = file_get_contents(__DIR__ . '/login.html');
    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html');
});

// Catch-all route for SPA
$app->get('/{routes:.+}', function ($request, $response) {
    $html = file_get_contents(__DIR__ . '/app.html');
    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html');
});

// Run app
try {
    $serverRequestCreator = ServerRequestCreatorFactory::create();
    $request = $serverRequestCreator->createServerRequestFromGlobals();
    $app->run($request);
} catch (Throwable $e) {
    $logger = $container->get('logger');
    $logger->critical('Application crashed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}
