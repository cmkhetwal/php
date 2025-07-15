# NextGen PHP Application - Comprehensive Deployment Guide

## Table of Contents
1. [Introduction](#introduction)
2. [Application Overview](#application-overview)
3. [Getting Started](#getting-started)
4. [Authentication & Login](#authentication--login)
5. [HashiCorp Vault Integration](#hashicorp-vault-integration)
6. [AWS Integration](#aws-integration)
7. [Docker Deployment](#docker-deployment)
8. [EC2 Deployment](#ec2-deployment)
9. [Database Configuration](#database-configuration)
10. [Nginx Configuration](#nginx-configuration)
11. [Monitoring & Maintenance](#monitoring--maintenance)
12. [Security Considerations](#security-considerations)
13. [Troubleshooting](#troubleshooting)

## Introduction

The NextGen PHP Application is a modern, enterprise-grade web application built with PHP 8.3+, featuring a microservices architecture, secure authentication, AWS cloud integration, and HashiCorp Vault for secrets management. This application serves as a robust foundation for building secure, scalable web services with best practices in security, performance, and DevOps.

## Application Overview

### Purpose
This application provides:
- User authentication and management system
- Secure file upload with AWS S3 and CloudFront CDN
- RESTful API with rate limiting and security features
- Integration with HashiCorp Vault for secrets management
- Containerized deployment with Docker
- CI/CD pipeline with Jenkins

### Key Features
- Modern PHP 8.3+ with strict typing
- JWT token-based authentication
- Role-based access control
- AWS CloudFront CDN for static content
- Redis caching for performance
- MySQL database with optimized schema
- Comprehensive health checks and monitoring
- Docker containerization for consistent deployments

### Architecture
The application follows a microservices architecture with:
- Frontend layer (HTML, CSS, JavaScript)
- API Gateway (Nginx)
- Application services (PHP)
- Data storage (MySQL)
- Caching layer (Redis)
- Secrets management (HashiCorp Vault)
- File storage (AWS S3)
- Content delivery (AWS CloudFront)

## Getting Started

### Prerequisites
- PHP 8.3+ with required extensions
- Composer for dependency management
- Docker & Docker Compose
- MySQL 8.0+
- Redis
- HashiCorp Vault
- AWS account (for S3, CloudFront)

### Local Development Setup

1. **Clone the repository**:
   ```bash
   git clone https://github.com/cmkhetwal/php.git
   cd php
   ```

2. **Configure environment variables**:
   ```bash
   cp .env.example .env
   # Edit .env with your local configuration
   ```

3. **Start the development environment**:
   ```bash
   docker-compose up -d
   ```

4. **Install dependencies**:
   ```bash
   docker-compose exec app composer install
   ```

5. **Set up the database**:
   ```bash
   # The database schema will be automatically applied
   # from database/schema.sql when MySQL container starts
   ```

6. **Access the application**:
   Open http://localhost:8080 in your browser

## Authentication & Login

### Default Credentials
The application comes with a default admin user:
- **Email**: admin@example.com
- **Password**: admin123

### Login Process
1. Navigate to http://localhost:8080 (or your deployed URL)
2. You'll see the login screen with email and password fields
3. Enter your credentials and click "Sign In"
4. Upon successful authentication, you'll receive a JWT token
5. You'll be redirected to the dashboard

### Registration
1. Click "Sign up here" on the login page
2. Fill in your name, email, and password
3. Click "Sign Up"
4. You can now log in with your new account

### API Authentication
For API access, include the JWT token in the Authorization header:
```
Authorization: Bearer your_jwt_token_here
```

### Token Management
- Tokens expire after 24 hours
- Use the `/api/v1/auth/refresh` endpoint to get a new token
- Logout with `/api/v1/auth/logout` to invalidate the token

## HashiCorp Vault Integration

### What is Vault Used For
In this application, HashiCorp Vault securely stores:
1. Database credentials
2. AWS access keys
3. JWT signing keys
4. API keys and other secrets

### Vault Configuration

#### 1. Configure Vault URL and Authentication
Edit the `.env` file:
```
VAULT_URL=http://your-vault-server:8200
VAULT_TOKEN=your_vault_token
VAULT_ROLE=nextgen-php-app
VAULT_AUTH_METHOD=aws  # Options: aws, kubernetes, token
```

#### 2. Set Up Required Secrets in Vault
```bash
# Database credentials
vault kv put secret/database/mysql \
    host="your-mysql-host" \
    port="3306" \
    database="nextgen_app" \
    username="your-db-user" \
    password="your-db-password"

# Security keys
vault kv put secret/security/keys \
    jwt_secret="your-jwt-secret" \
    encryption_key="your-encryption-key" \
    session_secret="your-session-secret"

# AWS credentials
vault kv put secret/aws/credentials \
    access_key_id="your-aws-access-key" \
    secret_access_key="your-aws-secret-key"
```

#### 3. Vault Authentication Methods
The application supports multiple Vault authentication methods:

**AWS EC2 Authentication** (for EC2 deployments):
```
VAULT_AUTH_METHOD=aws
VAULT_ROLE=nextgen-php-app
```

**Kubernetes Authentication** (for K8s deployments):
```
VAULT_AUTH_METHOD=kubernetes
VAULT_ROLE=nextgen-php-app
```

**Token Authentication** (for development):
```
VAULT_AUTH_METHOD=token
VAULT_TOKEN=your-vault-token
```

### Where Vault Integration is Configured
The Vault integration is primarily configured in:
- `src/services/VaultService.php` - Main service for Vault communication
- `src/config/AppConfig.php` - Loads configuration from Vault
- Environment variables in `.env` file

## AWS Integration

### AWS Services Used
1. **S3** - For file storage
2. **CloudFront** - For CDN content delivery
3. **SQS/SNS** - For message queuing (optional)

### AWS Configuration

#### 1. Configure AWS Credentials
Either in Vault (recommended) or in `.env`:
```
AWS_REGION=us-east-1
AWS_ACCESS_KEY_ID=your_access_key
AWS_SECRET_ACCESS_KEY=your_secret_key
```

#### 2. S3 Bucket Setup
```
AWS_S3_BUCKET=your-bucket-name
AWS_S3_REGION=us-east-1
```

#### 3. CloudFront Configuration
```
AWS_CLOUDFRONT_DISTRIBUTION_ID=your-distribution-id
AWS_CLOUDFRONT_DOMAIN=your-distribution-domain.cloudfront.net
```

### Where AWS Integration is Configured
- `src/services/AwsService.php` - Main service for AWS operations
- `src/controllers/ApiController.php` - File upload to S3
- Environment variables in `.env` file

## Docker Deployment

### Development Environment

```bash
# Start all services
docker-compose up -d

# View logs
docker-compose logs -f app

# Access application
open http://localhost:8080
```

### Production Deployment

#### 1. Build Production Image
```bash
docker build --target production -t nextgen-php-app:latest .
```

#### 2. Run with Production Configuration
```bash
docker run -d \
  --name nextgen-app \
  -p 80:80 \
  -e APP_ENV=production \
  -e DB_HOST=your-mysql-host \
  -e REDIS_HOST=your-redis-host \
  -e VAULT_URL=https://your-vault-url:8200 \
  -e VAULT_ROLE=nextgen-php-app \
  -e VAULT_AUTH_METHOD=aws \
  nextgen-php-app:latest
```

#### 3. Docker Compose for Production
Create a `docker-compose.prod.yml` file:
```yaml
version: '3.8'

services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
      target: production
    ports:
      - "80:80"
    environment:
      - APP_ENV=production
      - DB_HOST=your-mysql-host
      - REDIS_HOST=your-redis-host
      - VAULT_URL=https://your-vault-url:8200
      - VAULT_ROLE=nextgen-php-app
      - VAULT_AUTH_METHOD=aws
    restart: always
```

Run with:
```bash
docker-compose -f docker-compose.prod.yml up -d
```

### Docker Configuration Files
- `Dockerfile` - Multi-stage build for development and production
- `docker-compose.yml` - Development environment with all services
- `docker/nginx/` - Nginx configuration
- `docker/php/` - PHP configuration

## EC2 Deployment

### Setting Up on EC2

#### 1. Launch EC2 Instance
- Use Amazon Linux 2 or Ubuntu
- Minimum t3.small recommended
- Configure security groups for HTTP/HTTPS

#### 2. Install Docker and Docker Compose
```bash
# Amazon Linux 2
sudo yum update -y
sudo amazon-linux-extras install docker
sudo service docker start
sudo usermod -a -G docker ec2-user
sudo curl -L "https://github.com/docker/compose/releases/download/1.29.2/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose
```

#### 3. Clone Repository and Configure
```bash
git clone https://github.com/cmkhetwal/php.git
cd php
cp .env.example .env
# Edit .env with production values
```

#### 4. Deploy with Docker Compose
```bash
docker-compose -f docker-compose.prod.yml up -d
```

### EC2 IAM Role for Vault Authentication
For AWS EC2 authentication with Vault, create an IAM role with:
1. EC2 instance profile
2. Policy allowing the instance to authenticate with Vault
3. Configure Vault to trust this role

## Database Configuration

### MySQL Setup

#### 1. Create Database and User
```sql
CREATE DATABASE nextgen_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'app_user'@'%' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON nextgen_app.* TO 'app_user'@'%';
FLUSH PRIVILEGES;
```

#### 2. Import Schema
```bash
mysql -h your-mysql-host -u app_user -p nextgen_app < database/schema.sql
```

### Database Configuration in Application
Configure in `.env` or Vault:
```
DB_HOST=your-mysql-host
DB_PORT=3306
DB_NAME=nextgen_app
DB_USER=app_user
DB_PASS=your_secure_password
```

### Database Files
- `database/schema.sql` - Complete database schema
- `src/models/User.php` - User model for database operations

## Nginx Configuration

### Nginx as Reverse Proxy

#### 1. Default Configuration
The application includes optimized Nginx configuration in:
- `docker/nginx/nginx.conf` - Main Nginx configuration
- `docker/nginx/default.conf` - Virtual host configuration

#### 2. Custom Domain Configuration
To use a custom domain, modify `docker/nginx/default.conf`:
```nginx
server {
    listen 80;
    server_name your-domain.com;
    # ...
}
```

#### 3. SSL Configuration
For HTTPS, add SSL configuration:
```nginx
server {
    listen 443 ssl;
    server_name your-domain.com;

    ssl_certificate /etc/nginx/ssl/your-cert.crt;
    ssl_certificate_key /etc/nginx/ssl/your-key.key;
    # ...
}
```

## Monitoring & Maintenance

### Health Checks
The application provides health check endpoints:
- Basic health: `GET /api/v1/health`
- Detailed health: `GET /api/v1/health/detailed`

Example response:
```json
{
  "status": "healthy",
  "timestamp": "2023-06-15T12:34:56+00:00",
  "version": "1.0.0",
  "environment": "production",
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
    }
  }
}
```

### Logging
Logs are available in:
- Application logs: `/var/log/app.log`
- Access logs: `/var/log/nginx/access.log`
- Error logs: `/var/log/nginx/error.log`

### Backup and Restore

#### Database Backup
```bash
mysqldump -h your-mysql-host -u app_user -p nextgen_app > backup.sql
```

#### Database Restore
```bash
mysql -h your-mysql-host -u app_user -p nextgen_app < backup.sql
```

## Security Considerations

### Security Features
1. **JWT Authentication** with refresh tokens
2. **Rate Limiting** to prevent abuse
3. **CORS Protection** with configurable origins
4. **Security Headers** (XSS, CSRF protection)
5. **Input Validation** to prevent injection attacks
6. **Secrets Management** with HashiCorp Vault

### Security Best Practices
1. **Keep secrets in Vault**, not in environment variables
2. **Use HTTPS** in production
3. **Regularly update dependencies**
4. **Enable rate limiting** for all endpoints
5. **Implement proper access controls**
6. **Monitor logs** for suspicious activity

## Troubleshooting

### Common Issues and Solutions

#### 1. Connection to Vault fails
- Check Vault URL and authentication method
- Verify network connectivity to Vault
- Check Vault logs for authentication errors

#### 2. Database connection issues
- Verify database credentials in Vault or .env
- Check network connectivity to database
- Ensure database user has proper permissions

#### 3. File uploads not working
- Verify AWS credentials in Vault
- Check S3 bucket permissions
- Ensure CloudFront distribution is properly configured

#### 4. Application not starting
- Check Docker logs: `docker-compose logs app`
- Verify all required environment variables are set
- Check PHP error logs

#### 5. Login not working
- Verify database connection
- Check if users table exists and has data
- Try the default admin user: admin@example.com / admin123

### Getting Help
For additional support:
- Check the application logs
- Review the GitHub repository issues
- Consult the PHP and Docker documentation

---

This comprehensive guide covers all aspects of deploying, configuring, and maintaining the NextGen PHP Application. For specific customizations or advanced configurations, refer to the source code and comments within the application files.
