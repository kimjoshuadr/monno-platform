<?php

namespace Tests\Feature\Jobs;

use HiEvents\DomainObjects\Enums\ImageProcessingState;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\Jobs\Image\ProcessBackgroundVideoJob;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Infrastructure\Image\ImageStorageService;
use HiEvents\Services\Infrastructure\Media\MediaProbe;
use HiEvents\Services\Infrastructure\Media\MediaTranscoder;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProcessBackgroundVideoJobTest extends TestCase
{
    private const VIDEO_PATH = 'event_background/clip.mp4';

    private array $createdRows = [];

    private array $updates = [];

    private ?string $clipPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.public' => 'local']);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        if ($this->clipPath !== null && is_file($this->clipPath)) {
            unlink($this->clipPath);
        }

        Mockery::close();
        parent::tearDown();
    }

    public function test_transcodes_stores_poster_and_marks_the_video_ready(): void
    {
        $this->requireFfmpeg();

        Storage::disk('local')->put(self::VIDEO_PATH, file_get_contents($this->makeClip()));
        $video = $this->videoRow();

        $repository = $this->repository($video);

        $this->runJob($repository);

        $storedVideoPath = Storage::disk('local')->path(self::VIDEO_PATH);
        $this->assertFileExists($storedVideoPath);

        $streams = $this->probe($storedVideoPath);
        $this->assertSame('h264', $streams['codec_name']);
        $this->assertSame('yuv420p', $streams['pix_fmt']);
        $this->assertLessThanOrEqual(MediaTranscoder::MAX_WIDTH, (int) $streams['width']);
        $this->assertLessThanOrEqual(MediaTranscoder::MAX_HEIGHT, (int) $streams['height']);
        $this->assertTrue($this->isFastStart($storedVideoPath));

        $poster = collect($this->createdRows)->firstWhere('type', ImageType::EVENT_BACKGROUND_POSTER->name);
        $this->assertNotNull($poster, 'A poster image row should have been created.');
        $this->assertSame(1, $poster['entity_id']);
        $this->assertSame($video->getEntityType(), $poster['entity_type']);
        $this->assertSame(7, $poster['account_id']);
        $this->assertSame('image/jpeg', $poster['mime_type']);
        $this->assertSame(ImageProcessingState::READY->name, $poster['state']);
        $this->assertTrue(Storage::disk('local')->exists($poster['path']));

        $this->assertSame(ImageProcessingState::READY->name, $this->lastState());
    }

    public function test_marks_the_video_failed_without_leaving_orphans_when_the_file_is_corrupt(): void
    {
        $this->requireFfmpeg();

        Storage::disk('local')->put(self::VIDEO_PATH, 'not a video');

        $repository = Mockery::mock(ImageRepositoryInterface::class);
        $repository->shouldReceive('findFirst')->once()->andReturn($this->videoRow());
        $repository->shouldReceive('updateFromArray')->andReturnUsing($this->recordUpdate(...));
        $repository->shouldReceive('create')->never();

        $this->runJob($repository);

        $this->assertSame(ImageProcessingState::FAILED->name, $this->lastState());
        $this->assertNotEmpty($this->lastReason());
        $this->assertSame([], $this->createdRows);
        $this->assertFalse(Storage::disk('local')->exists(self::VIDEO_PATH));
    }

    private function runJob(ImageRepositoryInterface $repository): void
    {
        (new ProcessBackgroundVideoJob(1))->handle(
            app(MediaProbe::class),
            app(MediaTranscoder::class),
            app(ImageStorageService::class),
            $repository,
            app(FilesystemManager::class),
            app(LoggerInterface::class),
        );
    }

    private function repository(ImageDomainObject $video): ImageRepositoryInterface|MockInterface
    {
        $repository = Mockery::mock(ImageRepositoryInterface::class);
        $repository->shouldReceive('findFirst')->once()->andReturn($video);
        $repository->shouldReceive('updateFromArray')->andReturnUsing($this->recordUpdate(...));
        $repository->shouldReceive('create')->andReturnUsing(function (array $attributes) {
            $this->createdRows[] = $attributes;

            return (new ImageDomainObject)->setId(99);
        });

        return $repository;
    }

    private function recordUpdate(int $id, array $attributes): ImageDomainObject
    {
        $this->updates[] = $attributes;

        return new ImageDomainObject;
    }

    private function videoRow(): ImageDomainObject
    {
        return (new ImageDomainObject)
            ->setId(1)
            ->setAccountId(7)
            ->setEntityId(1)
            ->setEntityType('HiEvents\DomainObjects\EventDomainObject')
            ->setType(ImageType::EVENT_BACKGROUND->name)
            ->setDisk('local')
            ->setPath(self::VIDEO_PATH);
    }

    private function lastState(): ?string
    {
        return $this->updates[array_key_last($this->updates)]['state'] ?? null;
    }

    private function lastReason(): ?string
    {
        return $this->updates[array_key_last($this->updates)]['state_reason'] ?? null;
    }

    private function requireFfmpeg(): void
    {
        if (! app(MediaTranscoder::class)->available()) {
            $this->markTestSkipped('ffmpeg is not available on this host.');
        }
    }

    private function makeClip(): string
    {
        $this->clipPath = sys_get_temp_dir().'/bg_video_test_'.uniqid().'.mp4';

        $process = new Process([
            'ffmpeg', '-y', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'testsrc=size=1280x720:rate=25',
            '-t', '3', '-c:v', 'libx264', '-pix_fmt', 'yuv420p',
            $this->clipPath,
        ]);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($this->clipPath)) {
            $this->markTestSkipped('Could not generate a test clip with ffmpeg.');
        }

        return $this->clipPath;
    }

    private function probe(string $path): array
    {
        $process = new Process([
            'ffprobe', '-v', 'quiet', '-print_format', 'json',
            '-show_streams', '-select_streams', 'v:0', $path,
        ]);
        $process->run();

        $streams = json_decode($process->getOutput(), true)['streams'] ?? [];

        return $streams[0] ?? [];
    }

    private function isFastStart(string $path): bool
    {
        $head = file_get_contents($path, false, null, 0, 65536);
        $moov = strpos($head, 'moov');
        $mdat = strpos($head, 'mdat');

        return $moov !== false && ($mdat === false || $moov < $mdat);
    }
}
