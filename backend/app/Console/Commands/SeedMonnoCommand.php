<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use Carbon\CarbonImmutable;
use HiEvents\Console\Commands\Demo\DemoOwner;
use HiEvents\Console\Commands\Demo\DemoSeedContext;
use HiEvents\Console\Commands\Monno\MonnoSeedContent;
use HiEvents\DataTransferObjects\AddressDTO;
use HiEvents\DomainObjects\Enums\EventCategory;
use HiEvents\DomainObjects\Enums\EventType;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\Enums\LocationType;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Models\User;
use HiEvents\Services\Application\Handlers\Account\CreateAccountHandler;
use HiEvents\Services\Application\Handlers\Account\DTO\CreateAccountDTO;
use HiEvents\Services\Application\Handlers\Event\CreateEventImageHandler;
use HiEvents\Services\Application\Handlers\Event\DTO\CreateEventDTO;
use HiEvents\Services\Application\Handlers\Event\DTO\CreateEventImageDTO;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use HiEvents\Services\Application\Handlers\Location\DTO\UpsertLocationDTO;
use HiEvents\Services\Application\Handlers\Organizer\CreateOrganizerHandler;
use HiEvents\Services\Application\Handlers\Organizer\DTO\CreateOrganizerDTO;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Domain\EventLocation\EventLocationData;
use HiEvents\Services\Domain\Product\DTO\ProductPriceDTO;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Throwable;

/**
 * Seed the database with the curated content the monno website shows.
 *
 * The website used to ship a hand-written demo catalogue; this replaces it with
 * real accounts, organizers and LIVE events, built through the same handlers the
 * app uses (so settings, default categories, occurrences and images are correct).
 *
 *   php artisan monno:seed            # wipe existing events/organizers, then seed
 *   php artisan monno:seed --keep     # add to whatever is there
 *
 * Also runs from `php artisan db:seed` (see DatabaseSeeder).
 */
class SeedMonnoCommand extends Command
{
    protected $signature = 'monno:seed
        {--force : Skip the confirmation prompt}
        {--keep : Do not wipe existing events, organizers and images first}';

    protected $description = 'Seed realistic monno organizer accounts and LIVE events (one per category) into the database, via the real handlers.';

    private const PASSWORD = 'MonnoPass123!';

    /** Wiped in this order; CASCADE clears dependent rows (occurrences, products, orders, …). */
    private const WIPE = 'TRUNCATE TABLE events, organizers, images CASCADE';

    public function handle(
        DemoSeedContext $ctx,
        CreateAccountHandler $createAccount,
        CreateOrganizerHandler $createOrganizer,
        CreateImageHandler $createImage,
        CreateEventImageHandler $createEventImage,
        DatabaseManager $db,
    ): int {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to seed in production without --force.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('This writes organizer accounts and events to '.$db->connection()->getDatabaseName().'. Continue?')) {
            return self::FAILURE;
        }

        if (! $this->option('keep')) {
            $this->warn('Wiping existing events, organizers and images …');
            $db->statement(self::WIPE);
            $this->wipeSeedAccounts($db);
        }

        try {
            $owners = $this->seedOrganizers($createAccount, $createOrganizer, $createImage, $db);
            $seeded = $this->seedEvents($ctx, $createEventImage, $owners);
        } catch (Throwable $e) {
            $this->error('Seeding failed: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());

            return self::FAILURE;
        }

        $this->report($owners, $seeded);

        return self::SUCCESS;
    }

    /**
     * Remove the accounts/users this command creates, so a reseed does not collide
     * with their emails. Scoped to `@monno.example` — nothing else is touched.
     */
    private function wipeSeedAccounts(DatabaseManager $db): void
    {
        $accountIds = $db->table('accounts')
            ->where('email', 'like', '%@monno.example')
            ->pluck('id');

        if ($accountIds->isNotEmpty()) {
            $db->table('account_users')->whereIn('account_id', $accountIds)->delete();
            $db->table('accounts')->whereIn('id', $accountIds)->delete();
        }

        $db->table('users')->where('email', 'like', '%@monno.example')->delete();
    }

    /**
     * @return array<string, DemoOwner>
     */
    private function seedOrganizers(
        CreateAccountHandler $createAccount,
        CreateOrganizerHandler $createOrganizer,
        CreateImageHandler $createImage,
        DatabaseManager $db,
    ): array {
        $owners = [];

        foreach (MonnoSeedContent::organizers() as $handle => $definition) {
            $email = $handle.'@monno.example';

            $account = $createAccount->handle(new CreateAccountDTO(
                email: $email,
                password: self::PASSWORD,
                first_name: $definition['name'],
                last_name: '',
                timezone: $definition['timezone'],
                currency_code: $definition['currency'],
                locale: 'en',
            ));

            $accountId = $account->getId();

            // Publishing gates on a verified account (UpdateEventStatusHandler).
            $db->table('accounts')
                ->where('id', $accountId)
                ->update(['account_verified_at' => now()]);

            $user = User::where('email', $email)->firstOrFail();
            auth()->login($user);

            $organizer = $createOrganizer->handle(new CreateOrganizerDTO(
                name: $definition['name'],
                email: $email,
                account_id: $accountId,
                timezone: $definition['timezone'],
                currency: $definition['currency'],
                description: $definition['bio'],
            ));

            $organizerId = $organizer->getId();

            // A stable, human handle for the site's room URL display.
            $db->table('organizers')->where('id', $organizerId)->update([
                'status' => 'LIVE',
                'slug' => $handle,
            ]);

            $createImage->handle(new CreateImageDTO(
                userId: $user->id,
                accountId: $accountId,
                image: $this->makeLogo($definition['initials'], $definition['colour']),
                imageType: ImageType::ORGANIZER_LOGO,
                entityId: $organizerId,
            ));

            $owners[$handle] = new DemoOwner(
                account_id: $accountId,
                organizer_id: $organizerId,
                user_id: $user->id,
            );

            $this->line("  organizer <info>{$definition['name']}</info> (id {$organizerId})");
        }

        return $owners;
    }

    /**
     * @param  array<string, DemoOwner>  $owners
     * @return array<int, array{id:int, title:string, organizer:string}>
     */
    private function seedEvents(DemoSeedContext $ctx, CreateEventImageHandler $createEventImage, array $owners): array
    {
        $seeded = [];

        foreach (MonnoSeedContent::events() as $definition) {
            $owner = $owners[$definition['organizer']] ?? throw new RuntimeException('Unknown organizer '.$definition['organizer']);

            $timezone = $definition['timezone'];
            $start = CarbonImmutable::now($timezone)
                ->addDays((int) $definition['dayOffset'])
                ->setTime((int) $definition['hour'], (int) $definition['minute']);
            $end = $start->addHours((int) $definition['durationHours']);

            $location = $ctx->createLocation->handle(new UpsertLocationDTO(
                organizer_id: $owner->organizer_id,
                account_id: $owner->account_id,
                name: $definition['venue'],
                structured_address: new AddressDTO(
                    venue_name: $definition['venue'],
                    address_line_1: $definition['address'],
                    city: $definition['city'],
                    state_or_region: $definition['region'] ?: null,
                    zip_or_postal_code: $definition['zip'] ?: null,
                    country: $definition['country'],
                ),
                latitude: $definition['lat'],
                longitude: $definition['lng'],
            ));

            $event = $ctx->createEvent->handle(new CreateEventDTO(
                title: $definition['title'],
                organizer_id: $owner->organizer_id,
                account_id: $owner->account_id,
                user_id: $owner->user_id,
                start_date: $start->toDateTimeString(),
                end_date: $end->toDateTimeString(),
                description: $this->description($definition),
                timezone: $timezone,
                currency: 'USD',
                category: EventCategory::from($definition['category']),
                event_location: new EventLocationData(type: LocationType::IN_PERSON, location_id: $location->getId()),
                status: EventStatus::LIVE->name,
                type: EventType::SINGLE,
                tagline: $definition['tagline'],
                featured: (bool) $definition['featured'],
                image_alt: $definition['title'],
                agenda: $this->agenda($definition, $start, $end),
            ));

            $eventId = $event->getId();

            $tickets = $ctx->renameDefaultCategory($eventId, 'Tickets', 'Admission for this event.');

            $ctx->createProduct->handle(new UpsertProductDTO(
                account_id: $owner->account_id,
                event_id: $eventId,
                product_category_id: $tickets,
                title: $definition['price'] > 0 ? 'General admission' : 'Free entry',
                type: $definition['price'] > 0 ? ProductPriceType::PAID : ProductPriceType::FREE,
                product_type: ProductType::TICKET,
                prices: collect([new ProductPriceDTO(
                    price: (float) $definition['price'],
                    initial_quantity_available: (int) $definition['capacity'],
                )]),
                sale_end_date: $start->subHour()->toDateTimeString(),
                min_per_order: 1,
                max_per_order: 6,
                show_quantity_remaining: true,
                description: $definition['tagline'],
            ));

            $this->uploadEventImages($ctx, $createEventImage, $owner, $eventId, $definition['image']);

            // Offline payment is the only method in this deployment, so the rail
            // has a way to complete an order.
            $ctx->applySettings($owner->account_id, $eventId, [
                'payment_providers' => ['OFFLINE'],
                'offline_payment_instructions' => 'Pay at the door — bring the QR code from your confirmation email.',
            ]);

            $seeded[] = ['id' => $eventId, 'title' => $definition['title'], 'organizer' => $definition['organizer']];

            $this->line('  event     '.$definition['title'].' (id '.$eventId.')');
        }

        return $seeded;
    }

    private function uploadEventImages(
        DemoSeedContext $ctx,
        CreateEventImageHandler $createEventImage,
        DemoOwner $owner,
        int $eventId,
        string $asset,
    ): void {
        $path = $this->imagesPath().'/'.$asset;

        if (! is_file($path)) {
            $this->warn('    missing image '.$path.' — event left without a cover');

            return;
        }

        $mime = mime_content_type($path) ?: 'image/jpeg';

        foreach ([ImageType::EVENT_IMAGE, ImageType::EVENT_COVER] as $type) {
            $createEventImage->handle(new CreateEventImageDTO(
                eventId: $eventId,
                accountId: $owner->account_id,
                image: new UploadedFile($path, basename($path), $mime, null, true),
                imageType: $type,
            ));
        }
    }

    /** Where the site's event photography lives (the seed reuses it). */
    private function imagesPath(): string
    {
        $configured = env('MONNO_IMAGES_PATH');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        return dirname(base_path(), 2).'/monno/public/images';
    }

    private function description(array $definition): string
    {
        $venue = htmlspecialchars((string) $definition['venue'], ENT_QUOTES);
        $city = htmlspecialchars((string) $definition['city'], ENT_QUOTES);

        return '<p>'.htmlspecialchars((string) $definition['blurb'], ENT_QUOTES).'</p>'
            .'<p>Doors open 30 minutes before the start. The venue is '.$venue.', '.$city.' — '
            .'step-free access at the main entrance, and the room holds its capacity comfortably.</p>';
    }

    /**
     * @return array<int, array{time:string, title:string, detail:string}>
     */
    private function agenda(array $definition, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return [
            ['time' => $start->format('H:i'), 'title' => 'Doors', 'detail' => 'Venue opens; settle in and find your place.'],
            ['time' => $start->addMinutes(30)->format('H:i'), 'title' => 'Starts', 'detail' => (string) $definition['tagline']],
            ['time' => $end->subMinutes(30)->format('H:i'), 'title' => 'Close', 'detail' => 'Last orders and the walk home.'],
        ];
    }

    /** A flat PNG mark with the organizer's initials — the upload API rejects SVG. */
    private function makeLogo(string $initials, string $colour): UploadedFile
    {
        $hex = ltrim($colour, '#');
        [$r, $g, $b] = sscanf($hex, '%02x%02x%02x');

        $canvas = imagecreatetruecolor(48, 48);
        $background = imagecolorallocate($canvas, (int) $r, (int) $g, (int) $b);
        $ink = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, 48, 48, $background);

        $font = 5;
        $width = imagefontwidth($font) * strlen($initials);
        $height = imagefontheight($font);
        imagestring($canvas, $font, (int) ((48 - $width) / 2), (int) ((48 - $height) / 2), $initials, $ink);

        $scaled = imagescale($canvas, 256, 256);
        imagedestroy($canvas);

        $path = tempnam(sys_get_temp_dir(), 'monno-logo').'.png';
        imagepng($scaled, $path);
        imagedestroy($scaled);

        return new UploadedFile($path, 'logo-'.strtolower($initials).'.png', 'image/png', null, true);
    }

    /**
     * @param  array<string, DemoOwner>  $owners
     * @param  array<int, array{id:int, title:string, organizer:string}>  $seeded
     */
    private function report(array $owners, array $seeded): void
    {
        $this->newLine();
        $this->info('Seeded '.count($owners).' organizer accounts and '.count($seeded).' events.');
        $this->newLine();

        $this->table(
            ['Organizer', 'Login email', 'Password', 'Organizer id'],
            array_map(
                fn (string $handle, DemoOwner $owner) => [
                    MonnoSeedContent::organizers()[$handle]['name'],
                    $handle.'@monno.example',
                    self::PASSWORD,
                    $owner->organizer_id,
                ],
                array_keys($owners),
                array_values($owners),
            ),
        );

        $this->line('The website shows every LIVE event; open http://localhost:3000/ to see them.');
    }
}
