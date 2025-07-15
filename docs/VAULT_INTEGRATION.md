# HashiCorp Vault Integration Guide

This document provides detailed information about how the NextGen PHP Application integrates with HashiCorp Vault for secrets management.

## Overview

HashiCorp Vault is used to securely store and manage sensitive information such as:
- Database credentials
- AWS access keys
- JWT signing keys
- API keys and other secrets

By using Vault, we avoid storing sensitive information in environment variables, configuration files, or the codebase, significantly improving the security posture of the application.

## Vault Setup

### 1. Install Vault

#### On EC2
```bash
# Download and install Vault
curl -fsSL https://apt.releases.hashicorp.com/gpg | sudo apt-key add -
sudo apt-add-repository "deb [arch=amd64] https://apt.releases.hashicorp.com $(lsb_release -cs) main"
sudo apt-get update && sudo apt-get install vault
```

#### Using Docker
```bash
docker run -d --name vault \
  -p 8200:8200 \
  -e 'VAULT_DEV_ROOT_TOKEN_ID=dev-root-token' \
  -e 'VAULT_DEV_LISTEN_ADDRESS=0.0.0.0:8200' \
  vault:latest
```

### 2. Initialize Vault

```bash
# Initialize Vault (production)
vault operator init

# This will output 5 unseal keys and an initial root token
# IMPORTANT: Save these keys securely!
```

### 3. Unseal Vault

```bash
# Unseal Vault with 3 of the 5 keys
vault operator unseal <unseal-key-1>
vault operator unseal <unseal-key-2>
vault operator unseal <unseal-key-3>
```

### 4. Enable Secrets Engine

```bash
# Login to Vault
vault login <root-token>

# Enable KV secrets engine version 2
vault secrets enable -version=2 kv
vault secrets enable -path=secret kv-v2
```

### 5. Configure Authentication Methods

#### AWS EC2 Authentication (for EC2 deployments)

```bash
# Enable AWS auth method
vault auth enable aws

# Configure AWS auth method
vault write auth/aws/config/client \
    access_key=<AWS_ACCESS_KEY> \
    secret_key=<AWS_SECRET_KEY> \
    region=us-east-1

# Create a role for the application
vault write auth/aws/role/nextgen-php-app \
    auth_type=ec2 \
    bound_ami_id=<AMI_ID> \
    bound_account_id=<AWS_ACCOUNT_ID> \
    bound_iam_role_arn=<IAM_ROLE_ARN> \
    policies=nextgen-php-app \
    max_ttl=24h
```

#### Kubernetes Authentication (for K8s deployments)

```bash
# Enable Kubernetes auth method
vault auth enable kubernetes

# Configure Kubernetes auth method
vault write auth/kubernetes/config \
    kubernetes_host=<KUBERNETES_API_URL> \
    kubernetes_ca_cert=@/path/to/ca.crt \
    token_reviewer_jwt=<SERVICE_ACCOUNT_JWT>

# Create a role for the application
vault write auth/kubernetes/role/nextgen-php-app \
    bound_service_account_names=nextgen-php-app \
    bound_service_account_namespaces=default \
    policies=nextgen-php-app \
    ttl=24h
```

#### Token Authentication (for development)

```bash
# Create a token with appropriate policies
vault token create -policy=nextgen-php-app
```

### 6. Create Vault Policies

```bash
# Create policy file
cat > nextgen-php-app-policy.hcl << EOF
# Database secrets
path "secret/data/database/mysql" {
  capabilities = ["read"]
}

# Security keys
path "secret/data/security/keys" {
  capabilities = ["read"]
}

# AWS credentials
path "secret/data/aws/credentials" {
  capabilities = ["read"]
}
EOF

# Apply policy
vault policy write nextgen-php-app nextgen-php-app-policy.hcl
```

## Storing Secrets in Vault

### 1. Database Credentials

```bash
vault kv put secret/database/mysql \
    host="your-mysql-host" \
    port="3306" \
    database="nextgen_app" \
    username="your-db-user" \
    password="your-db-password"
```

### 2. Security Keys

```bash
vault kv put secret/security/keys \
    jwt_secret="your-jwt-secret" \
    encryption_key="your-encryption-key" \
    session_secret="your-session-secret"
```

### 3. AWS Credentials

```bash
vault kv put secret/aws/credentials \
    access_key_id="your-aws-access-key" \
    secret_access_key="your-aws-secret-key"
```

## Application Configuration

### 1. Environment Variables

Configure the application to connect to Vault by setting these environment variables:

```
VAULT_URL=http://your-vault-server:8200
VAULT_TOKEN=your_vault_token  # For token auth method
VAULT_ROLE=nextgen-php-app    # For AWS or Kubernetes auth
VAULT_AUTH_METHOD=aws         # Options: aws, kubernetes, token
```

### 2. Authentication Methods

The application supports multiple Vault authentication methods:

#### AWS EC2 Authentication
```
VAULT_AUTH_METHOD=aws
VAULT_ROLE=nextgen-php-app
```

#### Kubernetes Authentication
```
VAULT_AUTH_METHOD=kubernetes
VAULT_ROLE=nextgen-php-app
```

#### Token Authentication
```
VAULT_AUTH_METHOD=token
VAULT_TOKEN=your-vault-token
```

## How the Application Uses Vault

### 1. VaultService Class

The `VaultService` class (`src/services/VaultService.php`) is responsible for:
- Authenticating with Vault using the configured method
- Retrieving secrets from Vault
- Caching secrets for performance
- Handling Vault connection errors

Key methods:
- `authenticate()` - Authenticates with Vault
- `getSecret(string $path)` - Retrieves a secret from Vault
- `healthCheck()` - Checks Vault connectivity

### 2. Configuration Loading

The `AppConfig` class (`src/config/AppConfig.php`) loads configuration from:
1. Vault (primary source)
2. Environment variables (fallback)
3. Default values (last resort)

This ensures that even if Vault is temporarily unavailable, the application can still function with environment variables.

### 3. Secret Usage

Secrets are used in various parts of the application:

#### Database Connection
```php
// In DatabaseConfig.php
$dbSecrets = $this->vaultService->getSecret('database/mysql');
$host = $dbSecrets['host'] ?? getenv('DB_HOST') ?? 'localhost';
$username = $dbSecrets['username'] ?? getenv('DB_USER') ?? 'root';
$password = $dbSecrets['password'] ?? getenv('DB_PASS') ?? '';
```

#### JWT Authentication
```php
// In JwtService.php
$securitySecrets = $this->vaultService->getSecret('security/keys');
$this->secret = $securitySecrets['jwt_secret'] ?? '';
```

#### AWS Integration
```php
// In AwsService.php
$awsSecrets = $this->vaultService->getSecret('aws/credentials');
$credentials = [
    'key' => $awsSecrets['access_key_id'],
    'secret' => $awsSecrets['secret_access_key'],
];
```

## Authentication Flow

### AWS EC2 Authentication

1. The application retrieves the EC2 instance identity document and signature
2. These are sent to Vault's AWS auth endpoint
3. Vault validates the instance against the configured role
4. If valid, Vault returns a client token
5. The application uses this token for subsequent requests

### Kubernetes Authentication

1. The application reads the service account token from the pod
2. This token is sent to Vault's Kubernetes auth endpoint
3. Vault validates the token with the Kubernetes API
4. If valid, Vault returns a client token
5. The application uses this token for subsequent requests

### Token Authentication

1. The application uses the provided token directly
2. This method is simpler but less secure for production

## Troubleshooting

### Common Issues

#### 1. Connection to Vault fails
- Check Vault URL and port
- Verify network connectivity
- Ensure Vault is unsealed

#### 2. Authentication fails
- Verify the role exists in Vault
- Check the authentication method configuration
- Ensure the instance/pod has the correct identity

#### 3. Permission denied
- Check the policy attached to the role
- Ensure the path is correct
- Verify the capabilities (read, write, etc.)

### Debugging

Enable debug logging to see detailed Vault interactions:

```
LOG_LEVEL=debug
```

Check the application logs for Vault-related messages:

```bash
docker-compose logs app | grep Vault
```

## Security Best Practices

1. **Least Privilege**: Grant only the permissions needed
2. **Token Renewal**: Implement token renewal for long-running applications
3. **Response Wrapping**: Use response wrapping for sensitive operations
4. **Audit Logging**: Enable audit logging in Vault
5. **TLS**: Use TLS for all Vault communications
6. **Auto-unseal**: Use auto-unseal for production deployments

## Additional Resources

- [HashiCorp Vault Documentation](https://www.vaultproject.io/docs)
- [Vault AWS Auth Method](https://www.vaultproject.io/docs/auth/aws)
- [Vault Kubernetes Auth Method](https://www.vaultproject.io/docs/auth/kubernetes)
- [Vault Policies](https://www.vaultproject.io/docs/concepts/policies)

---

This guide provides comprehensive information about integrating HashiCorp Vault with the NextGen PHP Application. For additional support or questions, please refer to the main documentation or create an issue in the GitHub repository.
