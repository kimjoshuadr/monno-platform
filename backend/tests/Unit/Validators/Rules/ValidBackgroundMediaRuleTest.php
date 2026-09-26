<?php

namespace Tests\Unit\Validators\Rules;

use HiEvents\Services\Infrastructure\Media\MediaProbe;
use HiEvents\Validators\Rules\ValidBackgroundMediaRule;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ValidBackgroundMediaRuleTest extends TestCase
{
    /**
     * Duration is injected so the rule is testable without ffmpeg (and without shipping a
     * sample clip): the tests below that care about duration use this fake.
     */
    private function rule(float|null $duration = 5.0): ValidBackgroundMediaRule
    {
        $probe = new class($duration) extends MediaProbe {
            public function __construct(private readonly float|null $duration) {}

            public function duration(string $path): ?float
            {
                return $this->duration;
            }
        };

        return new ValidBackgroundMediaRule($probe);
    }

    private function validate(UploadedFile $file, float|null $duration = 5.0): array
    {
        $failures = [];
        $this->rule($duration)->validate('media', $file, function ($message) use (&$failures) {
            $failures[] = $message;
        });

        return $failures;
    }

    public function test_accepts_a_background_image(): void
    {
        $image = UploadedFile::fake()->image('background.png', 1920, 1080);

        $this->assertSame([], $this->validate($image));
    }

    public function test_accepts_an_animated_gif(): void
    {
        // GIFs go through the same image path — no special case, just the same size cap.
        $gif = UploadedFile::fake()->image('background.gif', 1280, 720);

        $this->assertSame([], $this->validate($gif));
    }

    public function test_rejects_an_oversized_image(): void
    {
        $image = UploadedFile::fake()->image('background.png', 1920, 1080)
            ->size(ValidBackgroundMediaRule::MAX_IMAGE_BYTES / 1024 + 1024);

        $this->assertNotEmpty($this->validate($image));
    }

    public function test_rejects_an_unsupported_file_type(): void
    {
        $pdf = UploadedFile::fake()->create('background.pdf', 512, 'application/pdf');

        $this->assertNotEmpty($this->validate($pdf));
    }

    public function test_accepts_a_short_video(): void
    {
        $video = UploadedFile::fake()->create('background.mp4', 2048, 'video/mp4');

        $this->assertSame([], $this->validate($video, duration: 5.0));
    }

    public function test_rejects_a_video_longer_than_the_cap(): void
    {
        $video = UploadedFile::fake()->create('background.mp4', 2048, 'video/mp4');

        $this->assertNotEmpty($this->validate($video, duration: ValidBackgroundMediaRule::MAX_VIDEO_SECONDS + 5));
    }

    public function test_rejects_a_video_that_cannot_be_probed(): void
    {
        $video = UploadedFile::fake()->create('background.mp4', 2048, 'video/mp4');

        $this->assertNotEmpty($this->validate($video, duration: null));
    }

    public function test_rejects_an_oversized_video(): void
    {
        $video = UploadedFile::fake()->create(
            'background.mp4',
            ValidBackgroundMediaRule::MAX_VIDEO_BYTES / 1024 + 1024,
            'video/mp4',
        );

        $this->assertNotEmpty($this->validate($video));
    }
}
