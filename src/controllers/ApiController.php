<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use App\Services\CacheService;
use App\Services\AwsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * API Controller
 * Handles API endpoints for user management and file uploads
 */
class ApiController
{
    private User $userModel;
    private CacheService $cacheService;
    private AwsService $awsService;
    private LoggerInterface $logger;

    public function __construct(
        User $userModel,
        CacheService $cacheService,
        AwsService $awsService,
        LoggerInterface $logger
    ) {
        $this->userModel = $userModel;
        $this->cacheService = $cacheService;
        $this->awsService = $awsService;
        $this->logger = $logger;
    }

    /**
     * Get all users with pagination
     */
    public function getUsers(Request $request, Response $response): Response
    {
        try {
            $queryParams = $request->getQueryParams();
            $page = isset($queryParams['page']) ? (int)$queryParams['page'] : 1;
            $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 20;
            
            // Apply filters
            $filters = [];
            if (isset($queryParams['status'])) {
                $filters['status'] = $queryParams['status'];
            }
            
            if (isset($queryParams['role'])) {
                $filters['role'] = $queryParams['role'];
            }
            
            if (isset($queryParams['search'])) {
                $filters['search'] = $queryParams['search'];
            }
            
            // Get users with pagination
            $result = $this->userModel->getAll($page, $limit, $filters);
            
            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $result['data'],
                'meta' => [
                    'total' => $result['total'],
                    'page' => $result['page'],
                    'limit' => $result['limit'],
                    'pages' => $result['pages']
                ]
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Error fetching users', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to fetch users'
            ], 500);
        }
    }

    /**
     * Get user by ID
     */
    public function getUser(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = (int)$args['id'];
            
            // Check if current user has permission
            $currentUser = $request->getAttribute('user');
            if (!$currentUser->hasRole('admin') && $currentUser->get('id') !== $userId) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Permission denied'
                ], 403);
            }
            
            // Get user from cache or database
            $cacheKey = "user:{$userId}";
            $userData = $this->cacheService->get($cacheKey);
            
            if (!$userData) {
                $user = $this->userModel->findById($userId);
                
                if (!$user) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => 'User not found'
                    ], 404);
                }
                
                $userData = $user->get();
                
                // Cache user data for 5 minutes
                $this->cacheService->set($cacheKey, $userData, 300);
            }
            
            // Remove sensitive data
            unset($userData['password']);
            
            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $userData
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Error fetching user', [
                'error' => $e->getMessage(),
                'user_id' => $args['id'] ?? null
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to fetch user'
            ], 500);
        }
    }

    /**
     * Update user
     */
    public function updateUser(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = (int)$args['id'];
            $data = json_decode($request->getBody()->getContents(), true);
            
            // Check if current user has permission
            $currentUser = $request->getAttribute('user');
            if (!$currentUser->hasRole('admin') && $currentUser->get('id') !== $userId) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Permission denied'
                ], 403);
            }
            
            // Find user
            $user = $this->userModel->findById($userId);
            
            if (!$user) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }
            
            // Validate input
            $allowedFields = ['name', 'email', 'password', 'status'];
            
            // Only admins can update role
            if ($currentUser->hasRole('admin')) {
                $allowedFields[] = 'role';
            }
            
            $updateData = array_intersect_key($data, array_flip($allowedFields));
            
            // Update user
            $success = $user->update($updateData);
            
            if ($success) {
                // Clear cache
                $this->cacheService->delete("user:{$userId}");
                
                return $this->jsonResponse($response, [
                    'success' => true,
                    'message' => 'User updated successfully'
                ]);
            } else {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Failed to update user'
                ], 500);
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Error updating user', [
                'error' => $e->getMessage(),
                'user_id' => $args['id'] ?? null
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to update user: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete user
     */
    public function deleteUser(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = (int)$args['id'];
            
            // Only admins can delete users
            $currentUser = $request->getAttribute('user');
            if (!$currentUser->hasRole('admin')) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Permission denied'
                ], 403);
            }
            
            // Find user
            $user = $this->userModel->findById($userId);
            
            if (!$user) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }
            
            // Prevent deleting own account
            if ($currentUser->get('id') === $userId) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Cannot delete your own account'
                ], 400);
            }
            
            // Delete user
            $success = $user->delete();
            
            if ($success) {
                // Clear cache
                $this->cacheService->delete("user:{$userId}");
                
                return $this->jsonResponse($response, [
                    'success' => true,
                    'message' => 'User deleted successfully'
                ]);
            } else {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Failed to delete user'
                ], 500);
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Error deleting user', [
                'error' => $e->getMessage(),
                'user_id' => $args['id'] ?? null
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to delete user: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload file to S3 and CDN
     */
    public function uploadFile(Request $request, Response $response): Response
    {
        try {
            $uploadedFiles = $request->getUploadedFiles();
            
            if (empty($uploadedFiles['file'])) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'No file uploaded'
                ], 400);
            }
            
            $file = $uploadedFiles['file'];
            
            if ($file->getError() !== UPLOAD_ERR_OK) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Upload failed: ' . $this->getUploadErrorMessage($file->getError())
                ], 400);
            }
            
            // Validate file size
            $maxSize = (int)getenv('MAX_UPLOAD_SIZE') ?: 10485760; // 10MB default
            if ($file->getSize() > $maxSize) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'File too large. Maximum size is ' . $this->formatBytes($maxSize)
                ], 400);
            }
            
            // Validate file type
            $allowedTypes = explode(',', getenv('ALLOWED_FILE_TYPES') ?: 'jpg,jpeg,png,gif,pdf,doc,docx');
            $extension = strtolower(pathinfo($file->getClientFilename(), PATHINFO_EXTENSION));
            
            if (!in_array($extension, $allowedTypes)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'File type not allowed. Allowed types: ' . implode(', ', $allowedTypes)
                ], 400);
            }
            
            // Save file temporarily
            $tmpDir = sys_get_temp_dir();
            $tmpFile = $tmpDir . '/' . uniqid('upload_', true) . '.' . $extension;
            $file->moveTo($tmpFile);
            
            // Get current user
            $user = $request->getAttribute('user');
            
            // Generate S3 key
            $s3Key = 'uploads/' . date('Y/m/d') . '/' . $user->get('id') . '/' . uniqid() . '.' . $extension;
            
            // Upload to S3 with metadata
            $metadata = [
                'user_id' => (string)$user->get('id'),
                'original_name' => $file->getClientFilename(),
                'content_type' => $file->getClientMediaType()
            ];
            
            $cdnUrl = $this->awsService->uploadToS3($tmpFile, $s3Key, $metadata);
            
            // Clean up temporary file
            unlink($tmpFile);
            
            // Log upload
            $this->logger->info('File uploaded successfully', [
                'user_id' => $user->get('id'),
                'file_name' => $file->getClientFilename(),
                's3_key' => $s3Key,
                'cdn_url' => $cdnUrl
            ]);
            
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'File uploaded successfully',
                'data' => [
                    'url' => $cdnUrl,
                    'filename' => $file->getClientFilename(),
                    'size' => $this->formatBytes($file->getSize()),
                    'type' => $file->getClientMediaType()
                ]
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('File upload error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to upload file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get upload error message
     */
    private function getUploadErrorMessage(int $error): string
    {
        switch ($error) {
            case UPLOAD_ERR_INI_SIZE:
                return 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
            case UPLOAD_ERR_FORM_SIZE:
                return 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form';
            case UPLOAD_ERR_PARTIAL:
                return 'The uploaded file was only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing a temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'A PHP extension stopped the file upload';
            default:
                return 'Unknown upload error';
        }
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
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
