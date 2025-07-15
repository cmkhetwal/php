# NextGen PHP Application

A modern, scalable PHP web application built with enterprise-grade architecture, featuring microservices design, AWS cloud integration, HashiCorp Vault secrets management, and comprehensive DevOps pipeline.

## 🚀 Features

### Core Application
- **Modern PHP 8.3+** with strict typing and latest features
- **RESTful API** with JWT authentication
- **User Management** with role-based access control
- **File Upload** with AWS S3 and CloudFront CDN integration
- **Real-time Features** with WebSocket support
- **Background Jobs** processing with queue management

### Architecture & Infrastructure
- **Microservices Architecture** with API Gateway pattern
- **Docker Containerization** for consistent deployments
- **Kubernetes Ready** with Helm charts
- **AWS Cloud Integration** (S3, CloudFront, SQS, SNS)
- **Redis Caching** for high performance
- **MySQL Database** with optimized schema

### Security & Secrets Management
- **HashiCorp Vault** integration for secrets management
- **JWT Token Authentication** with refresh tokens
- **Rate Limiting** and CORS protection
- **Security Headers** and OWASP best practices
- **Input Validation** and SQL injection prevention

### DevOps & Monitoring
- **Jenkins CI/CD Pipeline** with automated testing
- **Blue-Green Deployments** for zero downtime
- **Health Checks** and monitoring endpoints
- **Structured Logging** with centralized collection
- **Prometheus Metrics** and Grafana dashboards

## 📋 Prerequisites

- **PHP 8.3+** with required extensions
- **Composer** for dependency management
- **Docker & Docker Compose** for containerization
- **MySQL 8.0+** for database
- **Redis** for caching
- **HashiCorp Vault** for secrets management
- **AWS Account** for cloud services
- **Jenkins** for CI/CD (optional)

## 🛠 Installation & Setup

### 1. Clone Repository

```bash
git clone git@github.com:cmkhetwal/php.git
cd php
```

### 2. Environment Configuration

Create environment file:
```bash
cp .env.example .env
```

Configure your environment variables:
```env
# Application
APP_NAME="NextGen PHP App"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

# Database
DB_HOST=your-mysql-host
DB_PORT=3306
DB_NAME=nextgen_app
DB_USER=your-db-user
DB_PASS=your-db-password

# Redis
REDIS_HOST=your-redis-host
REDIS_PORT=6379
REDIS_PASSWORD=your-redis-password

# Vault
VAULT_URL=https://your-vault-url:8200
VAULT_TOKEN=your-vault-token
VAULT_ROLE=nextgen-php-app

# AWS
AWS_REGION=us-east-1
AWS_S3_BUCKET=your-s3-bucket
AWS_CLOUDFRONT_DOMAIN=your-cloudfront-domain.cloudfront.net
```

### 3. Install Dependencies

```bash
composer install --optimize-autoloader --no-dev
```

### 4. Database Setup

```bash
# Create database and run migrations
mysql -h $DB_HOST -u $DB_USER -p$DB_PASS < database/schema.sql
```

### 5. Vault Configuration

Set up secrets in HashiCorp Vault:

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

## 🐳 Docker Deployment

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

```bash
# Build production image
docker build --target production -t nextgen-php-app:latest .

# Run with production configuration
docker run -d \
  --name nextgen-app \
  -p 80:80 \
  -e APP_ENV=production \
  -e DB_HOST=your-mysql-host \
  -e REDIS_HOST=your-redis-host \
  -e VAULT_URL=https://your-vault-url:8200 \
  nextgen-php-app:latest
```

## ☸️ Kubernetes Deployment

### Using Helm

```bash
# Add Helm repository
helm repo add nextgen-app ./helm

# Install application
helm install nextgen-app ./helm/nextgen-php-app \
  --set image.tag=latest \
  --set ingress.hosts[0].host=your-domain.com \
  --set vault.address=https://your-vault-url:8200
```

### Manual Deployment

```bash
# Apply Kubernetes manifests
kubectl apply -f k8s/namespace.yaml
kubectl apply -f k8s/configmap.yaml
kubectl apply -f k8s/secrets.yaml
kubectl apply -f k8s/deployment.yaml
kubectl apply -f k8s/service.yaml
kubectl apply -f k8s/ingress.yaml
```

## 🔧 Configuration

### Nginx Configuration

The application includes optimized Nginx configuration for:
- **Static Asset Caching** with CDN integration
- **Rate Limiting** for API endpoints
- **Security Headers** for protection
- **Gzip Compression** for performance

### AWS CloudFront CDN

Configure CloudFront distribution:
1. Create S3 bucket for static assets
2. Set up CloudFront distribution
3. Configure cache behaviors for different file types
4. Update environment variables with CDN domain

### Vault Authentication

The application supports multiple Vault authentication methods:
- **AWS EC2** authentication (recommended for EC2 deployments)
- **Kubernetes** authentication (for K8s deployments)
- **Token** authentication (for development)

## 📊 Monitoring & Observability

### Health Checks

- **Basic Health**: `GET /api/v1/health`
- **Detailed Health**: `GET /api/v1/health/detailed`

### Metrics

The application exposes Prometheus metrics at `/metrics` endpoint:
- Request duration and count
- Database connection pool status
- Cache hit/miss ratios
- Background job queue status

### Logging

Structured logging with multiple levels:
- **Application logs**: `/var/log/app.log`
- **Access logs**: `/var/log/nginx/access.log`
- **Error logs**: `/var/log/nginx/error.log`

## 🔐 Security

### Authentication Flow

1. User submits credentials to `/api/v1/auth/login`
2. Application validates against database
3. JWT token generated and returned
4. Token included in `Authorization: Bearer <token>` header
5. Middleware validates token on protected routes

### Rate Limiting

- **API endpoints**: 60 requests per minute
- **Login endpoint**: 5 requests per minute
- **Configurable** via environment variables

### Security Headers

- X-Frame-Options: SAMEORIGIN
- X-XSS-Protection: 1; mode=block
- X-Content-Type-Options: nosniff
- Content-Security-Policy: Configured for application needs

## 🚀 CI/CD Pipeline

### Jenkins Pipeline

The included Jenkinsfile provides:
- **Automated Testing** (Unit, Integration, Security)
- **Code Quality** checks (PHPStan, CodeSniffer)
- **Docker Image** building and pushing
- **Blue-Green Deployment** to Kubernetes
- **Database Migrations** automation
- **Rollback** capabilities

### Pipeline Stages

1. **Checkout** - Get source code
2. **Get Secrets** - Fetch from Vault
3. **Install Dependencies** - Composer and NPM
4. **Run Tests** - PHPUnit, security scans
5. **Build Image** - Docker production image
6. **Push Registry** - AWS ECR
7. **Deploy Staging** - Automated deployment
8. **Deploy Production** - Manual approval required
9. **Database Migration** - Schema updates

## 📝 API Documentation

### Authentication Endpoints

```
POST /api/v1/auth/login
POST /api/v1/auth/register
POST /api/v1/auth/logout
POST /api/v1/auth/refresh
GET  /api/v1/auth/profile
```

### User Management

```
GET    /api/v1/users
GET    /api/v1/users/{id}
PUT    /api/v1/users/{id}
DELETE /api/v1/users/{id}
```

### File Upload

```
POST /api/v1/upload
```

## 🧪 Testing

### Run Tests

```bash
# Unit tests
composer test

# Code coverage
composer test-coverage

# Code quality
composer analyse

# Security audit
composer audit
```

### Test Structure

- **Unit Tests**: `tests/Unit/`
- **Integration Tests**: `tests/Integration/`
- **Feature Tests**: `tests/Feature/`

## 🔧 Development

### Local Development

```bash
# Start development environment
docker-compose up -d

# Install development dependencies
composer install

# Run in development mode
composer start
```

### Code Quality

```bash
# Fix code style
composer cs-fix

# Run static analysis
composer analyse

# Run all quality checks
composer quality
```

## 📚 Architecture

### Directory Structure

```
├── src/                    # Application source code
│   ├── config/            # Configuration classes
│   ├── controllers/       # HTTP controllers
│   ├── middleware/        # HTTP middleware
│   ├── models/           # Database models
│   ├── services/         # Business logic services
│   └── utils/            # Utility classes
├── public/               # Web root
├── database/             # Database schema and migrations
├── docker/               # Docker configuration
├── tests/                # Test suite
├── scripts/              # Utility scripts
└── docs/                 # Documentation
```

### Design Patterns

- **Dependency Injection** for loose coupling
- **Repository Pattern** for data access
- **Service Layer** for business logic
- **Middleware Pattern** for request processing
- **Observer Pattern** for event handling

## 🤝 Contributing

1. Fork the repository
2. Create feature branch (`git checkout -b feature/amazing-feature`)
3. Commit changes (`git commit -m 'Add amazing feature'`)
4. Push to branch (`git push origin feature/amazing-feature`)
5. Open Pull Request

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🆘 Support

For support and questions:
- **Issues**: GitHub Issues
- **Documentation**: `/docs` directory
- **Email**: support@example.com

## 🔄 Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history and updates.

---

**Built with ❤️ for modern PHP development**
