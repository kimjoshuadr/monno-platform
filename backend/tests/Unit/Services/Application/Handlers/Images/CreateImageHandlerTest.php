<?php

namespace Tests\Unit\Services\Application\Handlers\Images;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use HiEvents\Services\Domain\Image\ImageUploadService;
use HiEvents\Services\Domain\Payment\PayRam\PayRamProjectProfileSyncService;
use Illuminate\Http\UploadedFile;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class CreateImageHandlerTest extends TestCase
{
    private ImageUploadService $imageUploadService;

    private OrganizerRepositoryInterface $organizerRepository;

    private ImageRepositoryInterface $imageRepository;

    private PayRamProjectProfileSyncService $payRamProfileSync;

    private CreateImageHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imageUploadService = m::mock(ImageUploadService::class);
        $this->organizerRepository = m::mock(OrganizerRepositoryInterface::class);
        $this->imageRepository = m::mock(ImageRepositoryInterface::class);
        $this->payRamProfileSync = m::mock(PayRamProjectProfileSyncService::class);
        $eventRepository = m::mock(EventRepositoryInterface::class);

        $this->handler = new CreateImageHandler(
            $this->imageUploadService,
            $this->organizerRepository,
            $eventRepository,
            $this->imageRepository,
            $this->payRamProfileSync,
        );
    }

    public function test_handle_successfully_creates_image(): void
    {
        $uploadedFile = m::mock(UploadedFile::class);
        $imageDomainObject = m::mock(ImageDomainObject::class);
        $accountId = 123;

        $dto = new CreateImageDTO(
            userId: 42,
            accountId: $accountId,
            image: $uploadedFile
        );

        $this->imageUploadService
            ->shouldReceive('upload')
            ->once()
            ->withArgs([
                $uploadedFile,
                42,
                UserDomainObject::class,
                ImageType::GENERIC->name,
                $accountId,
            ])
            ->andReturn($imageDomainObject);

        $result = $this->handler->handle($dto);

        $this->assertSame($imageDomainObject, $result);
    }

    public function test_uploading_an_organizer_logo_pushes_the_profile_to_payram(): void
    {
        // The logo is part of the PayRam project profile, and uploading it is a
        // separate action from saving settings — without this trigger a new logo
        // never reaches the gateway.
        $accountId = 123;
        $organizerId = 29;
        $uploadedFile = m::mock(UploadedFile::class);
        $imageDomainObject = m::mock(ImageDomainObject::class);

        $organizer = m::mock(OrganizerDomainObject::class);
        $organizer->shouldReceive('getAccountId')->andReturn($accountId);
        $this->organizerRepository->shouldReceive('findById')->with($organizerId)->andReturn($organizer);
        $this->imageRepository->shouldReceive('deleteWhere')->once();

        $this->imageUploadService->shouldReceive('upload')
            ->once()
            ->withArgs([
                $uploadedFile,
                $organizerId,
                OrganizerDomainObject::class,
                ImageType::ORGANIZER_LOGO->name,
                $accountId,
            ])
            ->andReturn($imageDomainObject);

        $this->payRamProfileSync->shouldReceive('syncForOrganizer')->once()->with($organizerId);

        $result = $this->handler->handle(new CreateImageDTO(
            userId: 42,
            accountId: $accountId,
            image: $uploadedFile,
            imageType: ImageType::ORGANIZER_LOGO,
            entityId: $organizerId,
        ));

        $this->assertSame($imageDomainObject, $result);
    }

    public function test_a_non_logo_upload_does_not_touch_payram(): void
    {
        $accountId = 123;
        $uploadedFile = m::mock(UploadedFile::class);
        $imageDomainObject = m::mock(ImageDomainObject::class);

        $this->imageUploadService->shouldReceive('upload')->once()->andReturn($imageDomainObject);
        $this->payRamProfileSync->shouldReceive('syncForOrganizer')->never();

        $result = $this->handler->handle(new CreateImageDTO(
            userId: 42,
            accountId: $accountId,
            image: $uploadedFile,
        ));

        $this->assertSame($imageDomainObject, $result);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
