<?php

declare(strict_types=1);

namespace App\Modules\Media\Health;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Throwable;

/**
 * Object storage is reachable and the bucket exists (docs/11-operations/runbooks.md).
 */
final class StorageCheck extends Check
{
    private string $disk = 's3';

    public function disk(string $disk): self
    {
        $this->disk = $disk;

        return $this;
    }

    public function run(): Result
    {
        $result = Result::make()->meta(['disk' => $this->disk]);

        try {
            $disk = Storage::disk($this->disk);

            if (! $disk instanceof AwsS3V3Adapter) {
                // Local disks (tests, single-machine installs without S3) only need to be writable.
                $disk->put('.health', 'ok');

                return $result->ok()->shortSummary('writable');
            }

            $bucket = (string) $disk->getConfig()['bucket'];
            $exists = $disk->getClient()->doesBucketExistV2($bucket);
        } catch (Throwable $e) {
            return $result->failed('Object storage is unreachable: '.class_basename($e))->shortSummary('unreachable');
        }

        return $exists
            ? $result->ok()->shortSummary('bucket present')
            : $result->failed("Bucket {$bucket} is missing; run storage:ensure-bucket.")->shortSummary('bucket missing');
    }
}
