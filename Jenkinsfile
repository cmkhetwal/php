pipeline {
    agent any
    
    environment {
        // Application settings
        APP_NAME = 'nextgen-php-app'
        DOCKER_REGISTRY = 'your-registry.com'
        DOCKER_IMAGE = "${DOCKER_REGISTRY}/${APP_NAME}"
        
        // Vault settings for secrets
        VAULT_ADDR = credentials('vault-address')
        VAULT_ROLE_ID = credentials('vault-role-id')
        VAULT_SECRET_ID = credentials('vault-secret-id')
        
        // AWS settings
        AWS_REGION = 'us-east-1'
        AWS_ACCOUNT_ID = credentials('aws-account-id')
        ECR_REPOSITORY = "${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com/${APP_NAME}"
        
        // Deployment settings
        KUBECONFIG = credentials('kubeconfig')
        DEPLOYMENT_NAMESPACE = 'production'
    }
    
    stages {
        stage('Checkout') {
            steps {
                checkout scm
                script {
                    env.GIT_COMMIT_SHORT = sh(
                        script: 'git rev-parse --short HEAD',
                        returnStdout: true
                    ).trim()
                    env.BUILD_TAG = "${env.BUILD_NUMBER}-${env.GIT_COMMIT_SHORT}"
                }
            }
        }
        
        stage('Get Secrets from Vault') {
            steps {
                script {
                    // Authenticate with Vault using AppRole
                    def vaultToken = sh(
                        script: """
                            curl -s -X POST ${VAULT_ADDR}/v1/auth/approle/login \
                                -d '{"role_id":"${VAULT_ROLE_ID}","secret_id":"${VAULT_SECRET_ID}"}' \
                                | jq -r '.auth.client_token'
                        """,
                        returnStdout: true
                    ).trim()
                    
                    // Get database secrets
                    def dbSecrets = sh(
                        script: """
                            curl -s -H "X-Vault-Token: ${vaultToken}" \
                                ${VAULT_ADDR}/v1/secret/data/database/mysql \
                                | jq -r '.data.data'
                        """,
                        returnStdout: true
                    ).trim()
                    
                    // Get AWS secrets
                    def awsSecrets = sh(
                        script: """
                            curl -s -H "X-Vault-Token: ${vaultToken}" \
                                ${VAULT_ADDR}/v1/secret/data/aws/credentials \
                                | jq -r '.data.data'
                        """,
                        returnStdout: true
                    ).trim()
                    
                    // Store secrets as environment variables
                    env.DB_SECRETS = dbSecrets
                    env.AWS_SECRETS = awsSecrets
                    env.VAULT_TOKEN = vaultToken
                }
            }
        }
        
        stage('Install Dependencies') {
            steps {
                sh '''
                    # Install Composer dependencies
                    docker run --rm -v $(pwd):/app composer:latest install --no-dev --optimize-autoloader
                    
                    # Install Node.js dependencies for frontend assets
                    if [ -f "package.json" ]; then
                        npm ci --production
                        npm run build
                    fi
                '''
            }
        }
        
        stage('Run Tests') {
            parallel {
                stage('Unit Tests') {
                    steps {
                        sh '''
                            # Run PHPUnit tests
                            docker run --rm -v $(pwd):/app \
                                -e DB_HOST=mysql-test \
                                -e DB_NAME=test_db \
                                php:8.3-cli \
                                php vendor/bin/phpunit --configuration phpunit.xml
                        '''
                    }
                    post {
                        always {
                            publishTestResults testResultsPattern: 'tests/results/*.xml'
                            publishHTML([
                                allowMissing: false,
                                alwaysLinkToLastBuild: true,
                                keepAll: true,
                                reportDir: 'tests/coverage',
                                reportFiles: 'index.html',
                                reportName: 'Code Coverage Report'
                            ])
                        }
                    }
                }
                
                stage('Code Quality') {
                    steps {
                        sh '''
                            # Run PHP CodeSniffer
                            docker run --rm -v $(pwd):/app php:8.3-cli \
                                php vendor/bin/phpcs src tests --standard=PSR12 --report=checkstyle --report-file=checkstyle.xml
                            
                            # Run PHPStan
                            docker run --rm -v $(pwd):/app php:8.3-cli \
                                php vendor/bin/phpstan analyse src tests --level=8 --error-format=checkstyle > phpstan.xml
                        '''
                    }
                    post {
                        always {
                            recordIssues enabledForFailure: true, tools: [
                                checkStyle(pattern: 'checkstyle.xml'),
                                phpStan(pattern: 'phpstan.xml')
                            ]
                        }
                    }
                }
                
                stage('Security Scan') {
                    steps {
                        sh '''
                            # Run security audit
                            docker run --rm -v $(pwd):/app composer:latest audit
                            
                            # Scan for vulnerabilities
                            if command -v trivy &> /dev/null; then
                                trivy fs --format json --output security-report.json .
                            fi
                        '''
                    }
                }
            }
        }
        
        stage('Build Docker Image') {
            steps {
                script {
                    // Build production Docker image
                    def image = docker.build("${DOCKER_IMAGE}:${BUILD_TAG}", "--target production .")
                    
                    // Tag as latest
                    image.tag('latest')
                    
                    // Store image for later use
                    env.DOCKER_IMAGE_FULL = "${DOCKER_IMAGE}:${BUILD_TAG}"
                }
            }
        }
        
        stage('Push to Registry') {
            steps {
                script {
                    // Login to AWS ECR
                    sh '''
                        aws ecr get-login-password --region ${AWS_REGION} | \
                        docker login --username AWS --password-stdin ${ECR_REPOSITORY}
                    '''
                    
                    // Tag and push to ECR
                    sh '''
                        docker tag ${DOCKER_IMAGE}:${BUILD_TAG} ${ECR_REPOSITORY}:${BUILD_TAG}
                        docker tag ${DOCKER_IMAGE}:${BUILD_TAG} ${ECR_REPOSITORY}:latest
                        docker push ${ECR_REPOSITORY}:${BUILD_TAG}
                        docker push ${ECR_REPOSITORY}:latest
                    '''
                }
            }
        }
        
        stage('Deploy to Staging') {
            when {
                branch 'develop'
            }
            steps {
                script {
                    // Deploy to staging environment
                    sh '''
                        # Update Kubernetes deployment
                        kubectl set image deployment/${APP_NAME} \
                            ${APP_NAME}=${ECR_REPOSITORY}:${BUILD_TAG} \
                            --namespace=staging
                        
                        # Wait for rollout to complete
                        kubectl rollout status deployment/${APP_NAME} --namespace=staging --timeout=300s
                        
                        # Run smoke tests
                        kubectl run smoke-test-${BUILD_NUMBER} \
                            --image=curlimages/curl:latest \
                            --rm -i --restart=Never \
                            --namespace=staging \
                            -- curl -f http://${APP_NAME}-service.staging.svc.cluster.local/api/v1/health
                    '''
                }
            }
        }
        
        stage('Deploy to Production') {
            when {
                branch 'main'
            }
            steps {
                script {
                    // Require manual approval for production deployment
                    input message: 'Deploy to production?', ok: 'Deploy'
                    
                    // Blue-green deployment
                    sh '''
                        # Create new deployment with blue-green strategy
                        kubectl patch deployment ${APP_NAME} \
                            -p '{"spec":{"template":{"spec":{"containers":[{"name":"'${APP_NAME}'","image":"'${ECR_REPOSITORY}:${BUILD_TAG}'"}]}}}}' \
                            --namespace=${DEPLOYMENT_NAMESPACE}
                        
                        # Wait for rollout
                        kubectl rollout status deployment/${APP_NAME} --namespace=${DEPLOYMENT_NAMESPACE} --timeout=600s
                        
                        # Run health checks
                        kubectl run health-check-${BUILD_NUMBER} \
                            --image=curlimages/curl:latest \
                            --rm -i --restart=Never \
                            --namespace=${DEPLOYMENT_NAMESPACE} \
                            -- curl -f http://${APP_NAME}-service.${DEPLOYMENT_NAMESPACE}.svc.cluster.local/api/v1/health
                    '''
                    
                    // Update CDN cache
                    sh '''
                        # Invalidate CloudFront cache
                        aws cloudfront create-invalidation \
                            --distribution-id ${CLOUDFRONT_DISTRIBUTION_ID} \
                            --paths "/*"
                    '''
                }
            }
        }
        
        stage('Database Migration') {
            when {
                anyOf {
                    branch 'main'
                    branch 'develop'
                }
            }
            steps {
                script {
                    def namespace = env.BRANCH_NAME == 'main' ? 'production' : 'staging'
                    
                    sh """
                        # Run database migrations
                        kubectl run migration-${BUILD_NUMBER} \
                            --image=${ECR_REPOSITORY}:${BUILD_TAG} \
                            --rm -i --restart=Never \
                            --namespace=${namespace} \
                            --env="DB_HOST=\$(kubectl get secret db-credentials -o jsonpath='{.data.host}' | base64 -d)" \
                            --env="DB_USER=\$(kubectl get secret db-credentials -o jsonpath='{.data.username}' | base64 -d)" \
                            --env="DB_PASS=\$(kubectl get secret db-credentials -o jsonpath='{.data.password}' | base64 -d)" \
                            -- php scripts/migrate.php
                    """
                }
            }
        }
    }
    
    post {
        always {
            // Clean up
            sh '''
                docker system prune -f
                kubectl delete pod --field-selector=status.phase==Succeeded --namespace=staging
                kubectl delete pod --field-selector=status.phase==Succeeded --namespace=production
            '''
        }
        
        success {
            // Notify success
            script {
                if (env.BRANCH_NAME == 'main') {
                    slackSend(
                        channel: '#deployments',
                        color: 'good',
                        message: "✅ Production deployment successful: ${APP_NAME} v${BUILD_TAG}"
                    )
                }
            }
        }
        
        failure {
            // Notify failure
            slackSend(
                channel: '#deployments',
                color: 'danger',
                message: "❌ Deployment failed: ${APP_NAME} v${BUILD_TAG} - ${env.BUILD_URL}"
            )
            
            // Rollback on production failure
            script {
                if (env.BRANCH_NAME == 'main') {
                    sh '''
                        kubectl rollout undo deployment/${APP_NAME} --namespace=${DEPLOYMENT_NAMESPACE}
                        kubectl rollout status deployment/${APP_NAME} --namespace=${DEPLOYMENT_NAMESPACE}
                    '''
                }
            }
        }
        
        unstable {
            // Handle unstable builds
            emailext(
                subject: "Unstable Build: ${APP_NAME} #${BUILD_NUMBER}",
                body: "Build ${BUILD_NUMBER} is unstable. Please check the test results.",
                to: "${env.CHANGE_AUTHOR_EMAIL}"
            )
        }
    }
}
