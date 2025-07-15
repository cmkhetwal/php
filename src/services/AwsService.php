<?php

declare(strict_types=1);

namespace App\Services;

use Aws\CloudFront\CloudFrontClient;
use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use Aws\Sns\SnsClient;
use App\Config\AppConfig;
use Psr\Log\LoggerInterface;

/**
 * AWS Service for CloudFront CDN, S3, SQS, and SNS integration
 */
class AwsService
{
    private AppConfig $config;
    private LoggerInterface $logger;
    private ?S3Client $s3Client = null;
    private ?CloudFrontClient $cloudFrontClient = null;
    private ?SqsClient $sqsClient = null;
    private ?SnsClient $snsClient = null;

    public function __construct(LoggerInterface $logger = null)
    {
        $this->config = AppConfig::getInstance();
        $this->logger = $logger ?? new \Monolog\Logger('aws');
    }

    /**
     * Get S3 client instance
     */
    public function getS3Client(): S3Client
    {
        if ($this->s3Client === null) {
            $awsConfig = $this->config->get('aws');
            
            $this->s3Client = new S3Client([
                'version' => 'latest',
                'region' => $awsConfig['region'],
                'credentials' => [
                    'key' => $awsConfig['access_key_id'],
                    'secret' => $awsConfig['secret_access_key'],
                ],
            ]);
        }

        return $this->s3Client;
    }

    /**
     * Get CloudFront client instance
     */
    public function getCloudFrontClient(): CloudFrontClient
    {
        if ($this->cloudFrontClient === null) {
            $awsConfig = $this->config->get('aws');
            
            $this->cloudFrontClient = new CloudFrontClient([
                'version' => 'latest',
                'region' => $awsConfig['region'],
                'credentials' => [
                    'key' => $awsConfig['access_key_id'],
                    'secret' => $awsConfig['secret_access_key'],
                ],
            ]);
        }

        return $this->cloudFrontClient;
    }

    /**
     * Upload file to S3 and return CDN URL
     */
    public function uploadToS3(string $filePath, string $key, array $metadata = []): string
    {
        try {
            $s3Config = $this->config->get('aws.s3');
            $s3Client = $this->getS3Client();

            $result = $s3Client->putObject([
                'Bucket' => $s3Config['bucket'],
                'Key' => $key,
                'SourceFile' => $filePath,
                'ACL' => 'public-read',
                'Metadata' => $metadata,
                'CacheControl' => $this->getCacheControlHeader($key),
            ]);

            $this->logger->info('File uploaded to S3', [
                'bucket' => $s3Config['bucket'],
                'key' => $key,
                'etag' => $result['ETag']
            ]);

            // Return CDN URL if CloudFront is configured
            $cdnDomain = $this->config->get('aws.cloudfront.domain');
            if ($cdnDomain) {
                return "https://{$cdnDomain}/{$key}";
            }

            // Fallback to S3 URL
            return $result['ObjectURL'];

        } catch (\Exception $e) {
            $this->logger->error('Failed to upload file to S3', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Upload content directly to S3
     */
    public function uploadContentToS3(string $content, string $key, string $contentType = 'text/plain'): string
    {
        try {
            $s3Config = $this->config->get('aws.s3');
            $s3Client = $this->getS3Client();

            $result = $s3Client->putObject([
                'Bucket' => $s3Config['bucket'],
                'Key' => $key,
                'Body' => $content,
                'ACL' => 'public-read',
                'ContentType' => $contentType,
                'CacheControl' => $this->getCacheControlHeader($key),
            ]);

            $this->logger->info('Content uploaded to S3', [
                'bucket' => $s3Config['bucket'],
                'key' => $key,
                'size' => strlen($content)
            ]);

            // Return CDN URL if CloudFront is configured
            $cdnDomain = $this->config->get('aws.cloudfront.domain');
            if ($cdnDomain) {
                return "https://{$cdnDomain}/{$key}";
            }

            return $result['ObjectURL'];

        } catch (\Exception $e) {
            $this->logger->error('Failed to upload content to S3', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create CloudFront invalidation for cache busting
     */
    public function invalidateCloudFrontCache(array $paths): string
    {
        try {
            $distributionId = $this->config->get('aws.cloudfront.distribution_id');
            
            if (!$distributionId) {
                throw new \Exception('CloudFront distribution ID not configured');
            }

            $cloudFrontClient = $this->getCloudFrontClient();

            $result = $cloudFrontClient->createInvalidation([
                'DistributionId' => $distributionId,
                'InvalidationBatch' => [
                    'Paths' => [
                        'Quantity' => count($paths),
                        'Items' => $paths,
                    ],
                    'CallerReference' => uniqid('invalidation-', true),
                ],
            ]);

            $invalidationId = $result['Invalidation']['Id'];

            $this->logger->info('CloudFront invalidation created', [
                'distribution_id' => $distributionId,
                'invalidation_id' => $invalidationId,
                'paths' => $paths
            ]);

            return $invalidationId;

        } catch (\Exception $e) {
            $this->logger->error('Failed to create CloudFront invalidation', [
                'paths' => $paths,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Send message to SQS queue
     */
    public function sendSqsMessage(string $messageBody, array $attributes = []): string
    {
        try {
            $queueUrl = $this->config->get('aws.sqs.queue_url');
            
            if (!$queueUrl) {
                throw new \Exception('SQS queue URL not configured');
            }

            $sqsClient = $this->getSqsClient();

            $result = $sqsClient->sendMessage([
                'QueueUrl' => $queueUrl,
                'MessageBody' => $messageBody,
                'MessageAttributes' => $attributes,
            ]);

            $messageId = $result['MessageId'];

            $this->logger->info('Message sent to SQS', [
                'queue_url' => $queueUrl,
                'message_id' => $messageId
            ]);

            return $messageId;

        } catch (\Exception $e) {
            $this->logger->error('Failed to send SQS message', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Publish message to SNS topic
     */
    public function publishSnsMessage(string $message, string $subject = ''): string
    {
        try {
            $topicArn = $this->config->get('aws.sns.topic_arn');
            
            if (!$topicArn) {
                throw new \Exception('SNS topic ARN not configured');
            }

            $snsClient = $this->getSnsClient();

            $result = $snsClient->publish([
                'TopicArn' => $topicArn,
                'Message' => $message,
                'Subject' => $subject,
            ]);

            $messageId = $result['MessageId'];

            $this->logger->info('Message published to SNS', [
                'topic_arn' => $topicArn,
                'message_id' => $messageId
            ]);

            return $messageId;

        } catch (\Exception $e) {
            $this->logger->error('Failed to publish SNS message', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get appropriate cache control header based on file type
     */
    private function getCacheControlHeader(string $key): string
    {
        $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        $cacheConfig = $this->config->get('cdn.cache_control');

        switch ($extension) {
            case 'css':
                return $cacheConfig['css'] ?? 'public, max-age=31536000';
            case 'js':
                return $cacheConfig['js'] ?? 'public, max-age=31536000';
            case 'jpg':
            case 'jpeg':
            case 'png':
            case 'gif':
            case 'webp':
            case 'svg':
                return $cacheConfig['images'] ?? 'public, max-age=2592000';
            case 'woff':
            case 'woff2':
            case 'ttf':
            case 'eot':
                return $cacheConfig['fonts'] ?? 'public, max-age=31536000';
            default:
                return 'public, max-age=3600';
        }
    }

    /**
     * Get SQS client instance
     */
    private function getSqsClient(): SqsClient
    {
        if ($this->sqsClient === null) {
            $awsConfig = $this->config->get('aws');
            
            $this->sqsClient = new SqsClient([
                'version' => 'latest',
                'region' => $awsConfig['region'],
                'credentials' => [
                    'key' => $awsConfig['access_key_id'],
                    'secret' => $awsConfig['secret_access_key'],
                ],
            ]);
        }

        return $this->sqsClient;
    }

    /**
     * Get SNS client instance
     */
    private function getSnsClient(): SnsClient
    {
        if ($this->snsClient === null) {
            $awsConfig = $this->config->get('aws');
            
            $this->snsClient = new SnsClient([
                'version' => 'latest',
                'region' => $awsConfig['region'],
                'credentials' => [
                    'key' => $awsConfig['access_key_id'],
                    'secret' => $awsConfig['secret_access_key'],
                ],
            ]);
        }

        return $this->snsClient;
    }
}
