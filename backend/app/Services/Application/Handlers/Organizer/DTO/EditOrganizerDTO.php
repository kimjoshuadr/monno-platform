<?php

namespace HiEvents\Services\Application\Handlers\Organizer\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use Illuminate\Http\UploadedFile;

class EditOrganizerDTO extends BaseDataObject
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public int $account_id,
        public string $timezone,
        public string $currency,
        public ?string $phone = null,
        public ?string $website = null,
        public ?string $description = null,
        public ?UploadedFile $logo = null,
        // Optional handle. The handler only writes it when non-null, so callers
        // that don't expose a handle (the quick-edit modal) can't wipe it.
        public ?string $slug = null,
    ) {}
}
