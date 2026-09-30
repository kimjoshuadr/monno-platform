<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands\Storage;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Copies everything under storage/app/public into the S3-compatible public
 * disk (Cloudflare R2 on staging), keeping every object key identical.
 *
 * Keys are deliberately preserved so `Url::getCdnUrl()` output changes only
 * because APP_CDN_URL changes: the rows in the `images` table store a path,
 * and every cover, logo and gallery URL on the site is derived from it. Move
 * the files, repoint APP_CDN_URL, and nothing 404s.
 *
 * Safe to re-run: objects already present on the destination are skipped, so
 * an interrupted migration picks up where it stopped.
 */
class MigratePublicStorageCommand extends Command
{
    protected $signature = 'monno:storage-to-s3
        {--dry-run : Report what would be copied without writing anything}
        {--only= : Only copy keys containing this substring}';

    protected $description = 'Copy storage/app/public into the S3-compatible public disk, keeping identical keys';

    public function handle(): int
    {
        $disk = config('filesystems.disks.s3-public');

        if (($disk['driver'] ?? null) !== 's3') {
            $this->error('filesystems.disks.s3-public is not an s3 disk.');

            return self::FAILURE;
        }

        foreach (['key' => 'AWS_ACCESS_KEY_ID', 'secret' => 'AWS_SECRET_ACCESS_KEY', 'bucket' => 'AWS_PUBLIC_BUCKET', 'endpoint' => 'AWS_ENDPOINT'] as $field => $envVar) {
            if (empty($disk[$field])) {
                $this->error(sprintf('Missing %s for the s3-public disk — set %s first.', $field, $envVar));

                return self::FAILURE;
            }
        }

        $source = Storage::disk('public');
        $destination = Storage::disk('s3-public');
        $only = (string) ($this->option('only') ?? '');
        $dryRun = (bool) $this->option('dry-run');

        $files = array_filter($source->allFiles(), static fn (string $path): bool => $only === '' || str_contains($path, $only));

        if ($files === []) {
            $this->warn('Nothing found on the public disk.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%s %d files (%.1f MB) to bucket %s%s',
            $dryRun ? 'Would copy' : 'Copying',
            count($files),
            array_sum(array_map(fn (string $path): int => $source->size($path), $files)) / 1048576,
            $disk['bucket'],
            $dryRun ? ' [dry-run]' : '',
        ));

        $copied = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($files as $path) {
            if ($destination->exists($path)) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line('  + '.$path);
                $copied++;

                continue;
            }

            try {
                $stream = $source->readStream($path);

                if ($stream === null) {
                    throw new \RuntimeException('unreadable source');
                }

                $written = $destination->writeStream($path, $stream, [
                    'ContentType' => $source->mimeType($path) ?: 'application/octet-stream',
                    // R2 has no object ACLs: public readability comes from the bucket's
                    // public-access setting, and sending a canned ACL is rejected outright.
                    'visibility' => 'private',
                ]);

                if (is_resource($stream)) {
                    fclose($stream);
                }

                if (! $written) {
                    throw new \RuntimeException('write reported failure');
                }

                $copied++;
                $this->line('  ✓ '.$path);
            } catch (Throwable $exception) {
                $failed++;
                $this->error('  ✗ '.$path.' — '.$exception->getMessage());
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. %d copied, %d already present, %d failed.',
            $copied,
            $skipped,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
