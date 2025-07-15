# NextGen PHP Application - API Documentation

## Overview

The NextGen PHP Application provides a comprehensive RESTful API for user management, authentication, and file operations. All API endpoints are secured with JWT authentication and include rate limiting for protection against abuse.

## Base URL

```
http://localhost:8080/api/v1  (Development)
https://your-domain.com/api/v1  (Production)
```

## Authentication

### JWT Token Authentication

All protected endpoints require a JWT token in the Authorization header:

```
Authorization: Bearer <your_jwt_token>
```

### Token Lifecycle

- **Expiration**: 24 hours
- **Refresh**: Use `/auth/refresh` endpoint
- **Revocation**: Use `/auth/logout` endpoint

## Rate Limiting

- **API Endpoints**: 60 requests per minute
- **Login Endpoint**: 5 requests per minute
- **Headers**: Rate limit information is included in response headers

## API Endpoints

### Authentication Endpoints

#### POST /auth/login
Authenticate user and receive JWT token.

**Request Body:**
```json
{
  "email": "user@example.com",
  "password": "password123"
}
```

**Response (Success):**
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "user": {
      "id": 1,
      "name": "John Doe",
      "email": "user@example.com",
      "role": "user"
    }
  }
}
```

**Response (Error):**
```json
{
  "success": false,
  "message": "Invalid credentials"
}
```

#### POST /auth/register
Register a new user account.

**Request Body:**
```json
{
  "name": "John Doe",
  "email": "user@example.com",
  "password": "password123"
}
```

**Response (Success):**
```json
{
  "success": true,
  "message": "User registered successfully",
  "data": {
    "user_id": 123
  }
}
```

#### POST /auth/logout
Logout user and invalidate JWT token.

**Headers:**
```
Authorization: Bearer <token>
```

**Response:**
```json
{
  "success": true,
  "message": "Logout successful"
}
```

#### POST /auth/refresh
Refresh JWT token.

**Headers:**
```
Authorization: Bearer <token>
```

**Response:**
```json
{
  "success": true,
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."
  }
}
```

#### GET /auth/profile
Get current user profile.

**Headers:**
```
Authorization: Bearer <token>
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "John Doe",
    "email": "user@example.com",
    "role": "user",
    "status": "active",
    "created_at": "2023-06-15T12:34:56Z"
  }
}
```

### User Management Endpoints

#### GET /users
Get list of users with pagination.

**Headers:**
```
Authorization: Bearer <token>
```

**Query Parameters:**
- `page` (optional): Page number (default: 1)
- `limit` (optional): Items per page (default: 20)
- `status` (optional): Filter by status (active, inactive, suspended)
- `role` (optional): Filter by role (admin, user, moderator)
- `search` (optional): Search by name or email

**Example Request:**
```
GET /users?page=1&limit=10&status=active&search=john
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "John Doe",
      "email": "john@example.com",
      "role": "user",
      "status": "active",
      "created_at": "2023-06-15T12:34:56Z"
    }
  ],
  "meta": {
    "total": 100,
    "page": 1,
    "limit": 10,
    "pages": 10
  }
}
```

#### GET /users/{id}
Get user by ID.

**Headers:**
```
Authorization: Bearer <token>
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "role": "user",
    "status": "active",
    "last_login_at": "2023-06-15T10:30:00Z",
    "created_at": "2023-06-15T12:34:56Z"
  }
}
```

#### PUT /users/{id}
Update user information.

**Headers:**
```
Authorization: Bearer <token>
```

**Request Body:**
```json
{
  "name": "John Smith",
  "email": "johnsmith@example.com",
  "status": "active"
}
```

**Response:**
```json
{
  "success": true,
  "message": "User updated successfully"
}
```

#### DELETE /users/{id}
Delete user (Admin only).

**Headers:**
```
Authorization: Bearer <token>
```

**Response:**
```json
{
  "success": true,
  "message": "User deleted successfully"
}
```

### File Upload Endpoints

#### POST /upload
Upload file to S3 with CDN delivery.

**Headers:**
```
Authorization: Bearer <token>
Content-Type: multipart/form-data
```

**Request Body:**
```
file: <binary_file_data>
```

**Response:**
```json
{
  "success": true,
  "message": "File uploaded successfully",
  "data": {
    "url": "https://cdn.example.com/uploads/2023/06/15/1/abc123.jpg",
    "filename": "image.jpg",
    "size": "2.5 MB",
    "type": "image/jpeg"
  }
}
```

### Health Check Endpoints

#### GET /health
Basic health check.

**Response:**
```json
{
  "status": "healthy",
  "timestamp": "2023-06-15T12:34:56+00:00",
  "version": "1.0.0",
  "environment": "production"
}
```

#### GET /health/detailed
Detailed health check with dependencies.

**Response:**
```json
{
  "status": "healthy",
  "timestamp": "2023-06-15T12:34:56+00:00",
  "version": "1.0.0",
  "environment": "production",
  "response_time_ms": 45.23,
  "checks": {
    "database": {
      "status": "healthy",
      "response_time_ms": 5.23,
      "message": "Database connection successful"
    },
    "cache": {
      "status": "healthy",
      "response_time_ms": 2.45,
      "message": "Cache operations successful"
    },
    "vault": {
      "status": "healthy",
      "response_time_ms": 15.67,
      "message": "Vault connection successful"
    },
    "disk": {
      "status": "healthy",
      "message": "Disk space sufficient",
      "details": {
        "total": "100 GB",
        "used": "45 GB",
        "free": "55 GB",
        "usage_percent": 45.0
      }
    },
    "memory": {
      "status": "healthy",
      "message": "Memory usage normal",
      "details": {
        "limit": "512 MB",
        "current": "256 MB",
        "peak": "300 MB",
        "usage_percent": 50.0
      }
    }
  },
  "system": {
    "php_version": "8.3.0",
    "memory_usage": "256 MB",
    "memory_peak": "300 MB",
    "uptime": "5d 12h 30m"
  }
}
```

## Error Responses

### Standard Error Format

All error responses follow this format:

```json
{
  "success": false,
  "message": "Error description"
}
```

### HTTP Status Codes

- `200` - Success
- `201` - Created
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `429` - Too Many Requests
- `500` - Internal Server Error

### Common Error Messages

#### Authentication Errors
- `No token provided` - Missing Authorization header
- `Invalid token` - Token is malformed or expired
- `Token has expired` - Token needs to be refreshed
- `Token has been revoked` - Token was invalidated via logout

#### Validation Errors
- `Field 'email' is required` - Missing required field
- `Invalid email format` - Email format validation failed
- `Password must be at least 8 characters long` - Password validation failed

#### Permission Errors
- `Permission denied` - User lacks required permissions
- `Cannot delete your own account` - Self-deletion prevention

#### Rate Limiting
- `Too many requests. Please try again later.` - Rate limit exceeded

## Rate Limit Headers

Rate limit information is included in response headers:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1623758400
```

## Examples

### cURL Examples

#### Login
```bash
curl -X POST http://localhost:8080/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"admin123"}'
```

#### Get Users
```bash
curl -X GET http://localhost:8080/api/v1/users \
  -H "Authorization: Bearer your_jwt_token"
```

#### Upload File
```bash
curl -X POST http://localhost:8080/api/v1/upload \
  -H "Authorization: Bearer your_jwt_token" \
  -F "file=@/path/to/your/file.jpg"
```

### JavaScript Examples

#### Login with Fetch
```javascript
const response = await fetch('/api/v1/auth/login', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    email: 'admin@example.com',
    password: 'admin123'
  })
});

const data = await response.json();
if (data.success) {
  localStorage.setItem('token', data.data.token);
}
```

#### API Request with Token
```javascript
const token = localStorage.getItem('token');
const response = await fetch('/api/v1/users', {
  headers: {
    'Authorization': `Bearer ${token}`
  }
});

const users = await response.json();
```

## SDK and Libraries

### PHP SDK
For PHP applications, you can use the included models and services:

```php
use App\Models\User;
use App\Services\JwtService;

$user = new User();
$jwtService = new JwtService();

// Authenticate user
$authenticatedUser = $user->authenticate($email, $password);
if ($authenticatedUser) {
    $token = $jwtService->generateUserToken(
        $authenticatedUser->get('id'),
        $authenticatedUser->get('email'),
        $authenticatedUser->get('role')
    );
}
```

---

This API documentation provides comprehensive information for integrating with the NextGen PHP Application. For additional support or questions, please refer to the main documentation or create an issue in the GitHub repository.
