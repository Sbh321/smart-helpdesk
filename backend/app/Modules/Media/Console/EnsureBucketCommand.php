<?php

declare(strict_types=1);

namespace App\Modules\Media\Console;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

/**
 * Creates the object-storage bucket and applies the browser CORS rules that presigned
 * uploads need (docs/09-infrastructure/local-development.md, runbooks.md). Idempotent.
 */
final class EnsureBucketCommand extends Command
{
    protected $signature = 'storage:ensure-bucket
        {--disk=s3 : Filesystem disk that points at the bucket}
        {--check : Only report the bucket and CORS state, change nothing}';

    protected $description = 'Create the object-storage bucket and apply CORS for the app origins';

    public function handle(): int
    {
        $disk = Storage::disk((string) $this->option('disk'));

        if (! $disk instanceof AwsS3V3Adapter) {
            $this->error('The disk is not an S3 disk.');

            return self::FAILURE;
        }

        /** @var S3Client $client */
        $client = $disk->getClient();
        $bucket = (string) $disk->getConfig()['bucket'];
        $rules = $this->corsRules();

        $exists = $client->doesBucketExistV2($bucket);

        if ($this->option('check')) {
            $this->line(sprintf('bucket %s: %s', $bucket, $exists ? 'present' : 'missing'));
            $current = $exists ? $this->currentRules($client, $bucket) : [];
            $this->line(sprintf('cors: %s', $current === $rules ? 'up to date' : 'differs'));

            return $exists && $current === $rules ? self::SUCCESS : self::FAILURE;
        }

        if (! $exists) {
            $client->createBucket(['Bucket' => $bucket]);
            $client->waitUntil('BucketExists', ['Bucket' => $bucket]);
            $this->info("Created bucket {$bucket}.");
        }

        $client->putBucketCors([
            'Bucket' => $bucket,
            'CORSConfiguration' => ['CORSRules' => $rules],
        ]);
        $this->info(sprintf('Applied CORS for %s.', implode(', ', $rules[0]['AllowedOrigins'])));

        return self::SUCCESS;
    }

    /**
     * @return list<array{AllowedHeaders: list<string>, AllowedMethods: list<string>, AllowedOrigins: list<string>, ExposeHeaders: list<string>, MaxAgeSeconds: int}>
     */
    private function corsRules(): array
    {
        /** @var list<string> $origins */
        $origins = array_values(array_filter((array) config('cors.allowed_origins')));

        return [[
            'AllowedHeaders' => ['*'],
            'AllowedMethods' => ['GET', 'HEAD', 'PUT', 'POST'],
            'AllowedOrigins' => $origins,
            'ExposeHeaders' => ['ETag'],
            'MaxAgeSeconds' => 3600,
        ]];
    }

    /**
     * @return array<int, mixed>
     */
    private function currentRules(S3Client $client, string $bucket): array
    {
        try {
            $rules = $client->getBucketCors(['Bucket' => $bucket])->get('CORSRules');
        } catch (S3Exception) {
            return [];
        }

        return is_array($rules) ? array_values($rules) : [];
    }
}
