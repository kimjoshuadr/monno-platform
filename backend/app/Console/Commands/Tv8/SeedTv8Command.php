<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands\Tv8;

use Carbon\CarbonImmutable;
use HiEvents\Console\Commands\Demo\DemoSeedContext;
use HiEvents\DataTransferObjects\AddressDTO;
use HiEvents\DomainObjects\Enums\EventCategory;
use HiEvents\DomainObjects\Enums\EventType;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\Enums\LocationType;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Models\User;
use HiEvents\Services\Application\Handlers\Account\CreateAccountHandler;
use HiEvents\Services\Application\Handlers\Account\DTO\CreateAccountDTO;
use HiEvents\Services\Application\Handlers\Event\CreateEventHandler;
use HiEvents\Services\Application\Handlers\Event\CreateEventImageHandler;
use HiEvents\Services\Application\Handlers\Event\DTO\CreateEventDTO;
use HiEvents\Services\Application\Handlers\Event\DTO\CreateEventImageDTO;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use HiEvents\Services\Application\Handlers\Location\DTO\UpsertLocationDTO;
use HiEvents\Services\Application\Handlers\Organizer\CreateOrganizerHandler;
use HiEvents\Services\Application\Handlers\Organizer\DTO\CreateOrganizerDTO;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Application\Handlers\Question\DTO\UpsertQuestionDTO;
use HiEvents\Services\Domain\EventLocation\EventLocationData;
use HiEvents\Services\Domain\Product\DTO\ProductPriceDTO;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Stand up a real TV8 Media Productions / iRunPH organizer on monno, with all of
 * their Runtime events, race-category tickets and registration questions.
 *
 * This is pitch data, not demo filler: every number, date and ticket is theirs,
 * taken from the Runtime public API and bundled in resources/demo/tv8/. Run it on
 * an environment a prospective organizer will look at:
 *
 *   php artisan tv8:seed --force      # (re)create the organizer and its events
 *   php artisan tv8:seed --keep       # leave existing TV8 events in place
 *
 * It is idempotent: the account is reused, and the TV8 events/locations/images are
 * wiped and rebuilt on each run, so it also repairs a database after `monno:seed`
 * (which truncates events and organizers).
 */
class SeedTv8Command extends Command
{
    protected $signature = 'tv8:seed
        {--force : Skip the production guard and the confirmation prompt}
        {--keep : Do not wipe the existing TV8 events, locations and images first}';

    protected $description = 'Seed the TV8 Media Productions / iRunPH organizer and all of their events, tickets and registration questions.';

    /** Shared with the pitch: hand these to the organizer to let them log in. */
    private const PASSWORD = 'MonnoTv8!2026';

    private const SIZES = ['2XS', 'XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL'];

    public function handle(
        DemoSeedContext $ctx,
        CreateAccountHandler $createAccount,
        CreateOrganizerHandler $createOrganizer,
        CreateImageHandler $createImage,
        CreateEventHandler $createEvent,
        CreateEventImageHandler $createEventImage,
        DatabaseManager $db,
    ): int {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to seed in production without --force.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('This writes the TV8 organizer and events to '.$db->connection()->getDatabaseName().'. Continue?')) {
            return self::FAILURE;
        }

        try {
            $data = $this->loadData();
            [$accountId, $userId, $organizerId] = $this->ensureOrganizer($data, $createAccount, $createOrganizer, $createImage, $db);

            if (! $this->option('keep')) {
                $this->wipeOrganizerContent($db, $organizerId);
            }

            $seeded = $this->seedEvents($ctx, $createEvent, $createEventImage, $data, $accountId, $userId, $organizerId, $db);
        } catch (Throwable $e) {
            $this->error('TV8 seeding failed: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());

            return self::FAILURE;
        }

        $this->report($data, $organizerId, $seeded);

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function loadData(): array
    {
        $path = resource_path('demo/tv8/events.json');

        if (! is_file($path)) {
            throw new RuntimeException('TV8 seed data missing: '.$path);
        }

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{0:int,1:int,2:int} account, user, organizer ids
     */
    private function ensureOrganizer(
        array $data,
        CreateAccountHandler $createAccount,
        CreateOrganizerHandler $createOrganizer,
        CreateImageHandler $createImage,
        DatabaseManager $db,
    ): array {
        $org = $data['organizer'];
        $email = $org['email'];

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $account = $createAccount->handle(new CreateAccountDTO(
                email: $email,
                password: self::PASSWORD,
                first_name: $org['name'],
                last_name: '',
                timezone: $org['timezone'],
                currency_code: $org['currency'],
                locale: 'en',
            ));
            $accountId = $account->getId();
            $user = User::where('email', $email)->firstOrFail();
        } else {
            $accountId = (int) $db->table('account_users')->where('user_id', $user->id)->value('account_id');
        }

        $db->table('accounts')->where('id', $accountId)->update(['account_verified_at' => now()]);
        auth()->login($user);

        $organizerId = (int) $db->table('organizers')->where('account_id', $accountId)->value('id');

        if ($organizerId === 0) {
            $organizer = $createOrganizer->handle(new CreateOrganizerDTO(
                name: $org['name'],
                email: $email,
                account_id: $accountId,
                timezone: $org['timezone'],
                currency: $org['currency'],
                website: $org['social']['site'] ?? null,
                description: $org['bio'],
            ));
            $organizerId = $organizer->getId();
        }

        $db->table('organizers')->where('id', $organizerId)->update([
            'status' => 'LIVE',
            'slug' => $org['slug'],
        ]);

        // One logo, no duplicates on re-run.
        $db->table('images')->where('entity_id', $organizerId)->delete();
        $createImage->handle(new CreateImageDTO(
            userId: $user->id,
            accountId: $accountId,
            image: $this->uploaded($org['logo']),
            imageType: ImageType::ORGANIZER_LOGO,
            entityId: $organizerId,
        ));

        $this->line("  organizer <info>{$org['name']}</info> (id {$organizerId})");

        return [$accountId, $user->id, $organizerId];
    }

    /**
     * Rebuild the organizer's content. `events` is referenced by a handful of
     * tables without a cascade (`orders`, `questions`, `promo_codes`, stats …), so
     * those are cleared explicitly before the events themselves. Everything else
     * cascades.
     */
    private function wipeOrganizerContent(DatabaseManager $db, int $organizerId): void
    {
        $this->warn('Wiping existing TV8 events, locations and images …');

        $eventIds = $db->table('events')->where('organizer_id', $organizerId)->pluck('id')->all();

        if ($eventIds !== []) {
            $orderIds = $db->table('orders')->whereIn('event_id', $eventIds)->pluck('id')->all();
            $questionIds = $db->table('questions')->whereIn('event_id', $eventIds)->pluck('id')->all();

            $this->deleteWhereIn($db, 'attendee_check_ins', 'order_id', $orderIds);
            $this->deleteWhereIn($db, 'question_answers', 'order_id', $orderIds);
            $this->deleteWhereIn($db, 'question_answers', 'question_id', $questionIds);
            $this->deleteWhereIn($db, 'product_questions', 'question_id', $questionIds);
            $this->deleteWhereIn($db, 'attendees', 'order_id', $orderIds);
            $this->deleteWhereIn($db, 'order_items', 'order_id', $orderIds);

            foreach ([
                'stripe_payments', 'invoices', 'order_refunds', 'order_application_fees',
                'order_payment_platform_fees', 'order_audit_logs', 'waitlist_entries',
            ] as $table) {
                $this->deleteWhereIn($db, $table, 'order_id', $orderIds);
            }

            foreach ([
                'orders', 'promo_codes', 'questions', 'messages', 'event_statistics',
                'event_daily_statistics', 'event_occurrence_statistics',
                'event_occurrence_daily_statistics', 'event_spam_checks',
            ] as $table) {
                $this->deleteWhereIn($db, $table, 'event_id', $eventIds);
            }

            $db->table('events')->whereIn('id', $eventIds)->delete();
        }

        $db->table('locations')->where('organizer_id', $organizerId)->delete();
    }

    /** @param array<int, int> $ids */
    private function deleteWhereIn(DatabaseManager $db, string $table, string $column, array $ids): void
    {
        if ($ids === [] || ! Schema::hasTable($table)) {
            return;
        }

        $db->table($table)->whereIn($column, $ids)->delete();
    }

    /**
     * @return array<int, array{id:int, title:string}>
     */
    private function seedEvents(
        DemoSeedContext $ctx,
        CreateEventHandler $createEvent,
        CreateEventImageHandler $createEventImage,
        array $data,
        int $accountId,
        int $userId,
        int $organizerId,
        DatabaseManager $db,
    ): array {
        $timezone = $data['organizer']['timezone'];
        $currency = $data['organizer']['currency'];
        $seeded = [];

        foreach ($data['events'] as $definition) {
            $start = CarbonImmutable::createFromFormat('Y-m-d H:i', $definition['start_local'], $timezone);
            $end = $start->addHours((int) $definition['duration_hours']);

            $location = $ctx->createLocation->handle(new UpsertLocationDTO(
                organizer_id: $organizerId,
                account_id: $accountId,
                name: $definition['location']['name'],
                structured_address: new AddressDTO(
                    venue_name: $definition['location']['name'],
                    address_line_1: $definition['location']['address'],
                    city: $definition['location']['city'],
                    state_or_region: $definition['location']['region'] ?: null,
                    zip_or_postal_code: $definition['location']['zip'] ?: null,
                    country: $definition['location']['country'],
                ),
                latitude: (float) $definition['location']['lat'],
                longitude: (float) $definition['location']['lng'],
            ));

            $event = $createEvent->handle(new CreateEventDTO(
                title: $definition['title'],
                organizer_id: $organizerId,
                account_id: $accountId,
                user_id: $userId,
                start_date: $start->toDateTimeString(),
                end_date: $end->toDateTimeString(),
                description: $this->description($definition),
                timezone: $timezone,
                currency: $currency,
                category: EventCategory::SPORTS,
                event_location: new EventLocationData(type: LocationType::IN_PERSON, location_id: $location->getId()),
                status: EventStatus::LIVE->name,
                type: EventType::SINGLE,
                tagline: $definition['tagline'],
                featured: $this->isFeatured($definition),
                image_alt: $definition['title'],
                agenda: $this->agenda($definition, $start, $end),
            ));

            $eventId = $event->getId();
            $categoryId = $ctx->renameDefaultCategory($eventId, 'Race Categories', 'One ticket per runner. Pick your distance.');

            $productIds = $this->seedProducts($ctx, $definition, $accountId, $eventId, $categoryId, $start);
            $this->seedQuestions($ctx, $definition, $eventId, $productIds);

            $this->uploadBanner($createEventImage, $definition, $accountId, $eventId);

            // Offline payment is the only method available on this deployment, so
            // the rail has a way to complete an order.
            $ctx->applySettings($accountId, $eventId, [
                'payment_providers' => ['OFFLINE'],
                'offline_payment_instructions' => 'Pay via bank transfer or GCash, then keep your confirmation email — it carries the QR code we scan at the race village.',
            ]);

            $seeded[] = ['id' => $eventId, 'title' => $definition['title']];
            $this->line(sprintf('  event     %s (id %d) %s', $definition['title'], $eventId, $start->format('D j M Y H:i')));
        }

        return $seeded;
    }

    /**
     * Each race category is a ticket. Where Runtime is running an early-bird price,
     * the discounted price is the live tier and the regular price is the standard
     * tier that opens with it.
     *
     * @return array<int, int>
     */
    private function seedProducts(
        DemoSeedContext $ctx,
        array $definition,
        int $accountId,
        int $eventId,
        int $categoryId,
        CarbonImmutable $start,
    ): array {
        $ids = [];

        foreach ($definition['products'] as $product) {
            $regular = $product['regular'] !== null ? (float) $product['regular'] : null;
            $discounted = $product['discounted'] !== null ? (float) $product['discounted'] : null;
            $capacity = (int) $product['capacity'];

            $prices = new Collection();
            if ($discounted !== null && $regular !== null && $discounted < $regular) {
                $prices->push(new ProductPriceDTO(price: $discounted, label: 'Early bird', initial_quantity_available: $capacity));
                $prices->push(new ProductPriceDTO(price: $regular, label: 'Standard', initial_quantity_available: $capacity));
            } else {
                $prices->push(new ProductPriceDTO(price: $discounted ?? $regular ?? 0.0, initial_quantity_available: $capacity));
            }

            $ids[] = $ctx->createProduct->handle(new UpsertProductDTO(
                account_id: $accountId,
                event_id: $eventId,
                product_category_id: $categoryId,
                title: $product['title'],
                type: ProductPriceType::PAID,
                product_type: ProductType::TICKET,
                prices: $prices,
                sale_end_date: $start->toDateTimeString(),
                min_per_order: 1,
                max_per_order: 10,
                show_quantity_remaining: true,
                description: $product['description'],
            ))->getId();
        }

        return $ids;
    }

    /**
     * Runtime's runner form, rebuilt as monno event questions. Product questions are
     * asked once per ticket; the waiver is asked once per order.
     *
     * @param  array<int, int>  $productIds
     */
    private function seedQuestions(DemoSeedContext $ctx, array $definition, int $eventId, array $productIds): void
    {
        $categories = $definition['categories'] ?: ['3K', '5K', '10K'];

        $product = fn (string $title, QuestionTypeEnum $type, bool $required, ?array $options = null, ?string $description = null) => $ctx->createQuestion->handle(new UpsertQuestionDTO(
            title: $title,
            type: $type,
            required: $required,
            options: $options,
            event_id: $eventId,
            product_ids: $productIds,
            is_hidden: false,
            belongs_to: QuestionBelongsTo::PRODUCT,
            description: $description,
        ));

        $product('Category', QuestionTypeEnum::DROPDOWN, true, $categories, 'Pick the distance you are entering.');
        $product('First Name', QuestionTypeEnum::SINGLE_LINE_TEXT, true);
        $product('Middle Name', QuestionTypeEnum::SINGLE_LINE_TEXT, false);
        $product('Last Name', QuestionTypeEnum::SINGLE_LINE_TEXT, true);
        $product('Birthdate', QuestionTypeEnum::DATE, true, null, 'Used for age-group results and the junior waiver.');
        $product('Gender', QuestionTypeEnum::DROPDOWN, true, ['Male', 'Female', 'LGBTQIA+']);
        $product('Running Club', QuestionTypeEnum::SINGLE_LINE_TEXT, true, null, 'Put N/A if you are not a member of a running club.');
        $product('Contact Number', QuestionTypeEnum::SINGLE_LINE_TEXT, true);
        $product('Name on Race Bib', QuestionTypeEnum::SINGLE_LINE_TEXT, false, null, 'Leave blank to use your first name.');
        $product('Address', QuestionTypeEnum::ADDRESS, true);
        $product('Singlet Size', QuestionTypeEnum::DROPDOWN, true, self::SIZES);
        $product('Finisher Shirt Size', QuestionTypeEnum::DROPDOWN, true, self::SIZES, 'Finisher shirts are only awarded within the official cut-off time.');
        $product('Medical Condition', QuestionTypeEnum::CHECKBOX, false, ['Hypertension', 'Diabetes', 'Asthma', 'Heart Disease'], 'Select anything our medical team should know about.');
        $product('Emergency Contact Person', QuestionTypeEnum::SINGLE_LINE_TEXT, true);
        $product('Emergency Contact No.', QuestionTypeEnum::SINGLE_LINE_TEXT, true);
        $product('Have you attended an iRunPH event before?', QuestionTypeEnum::RADIO, true, ['Yes', 'No - This is my first time.']);
        $product('Please specify the event/s.', QuestionTypeEnum::CHECKBOX, false, ['Leg 1', 'Leg 2', 'Pasko Run', 'Sub60', 'Sharp Run', 'South Run']);

        $ctx->createQuestion->handle(new UpsertQuestionDTO(
            title: 'Liability Waiver and Race Agreement',
            type: QuestionTypeEnum::CHECKBOX,
            required: true,
            options: ['I have read, understood and agreed to the Liability Waiver and Race Agreement.'],
            event_id: $eventId,
            product_ids: [],
            is_hidden: false,
            belongs_to: QuestionBelongsTo::ORDER,
            description: $definition['waiver'] ?: null,
        ));
    }

    private function uploadBanner(CreateEventImageHandler $createEventImage, array $definition, int $accountId, int $eventId): void
    {
        if (empty($definition['banner'])) {
            return;
        }

        $path = $this->imagesPath().'/'.$definition['banner'];

        if (! is_file($path)) {
            $this->warn('    missing banner '.$path);

            return;
        }

        $mime = mime_content_type($path) ?: 'image/jpeg';

        foreach ([ImageType::EVENT_IMAGE, ImageType::EVENT_COVER] as $type) {
            $createEventImage->handle(new CreateEventImageDTO(
                eventId: $eventId,
                accountId: $accountId,
                image: new UploadedFile($path, basename($path), $mime, null, true),
                imageType: $type,
            ));
        }
    }

    private function description(array $definition): string
    {
        $paragraphs = array_map(
            static fn (string $line): string => '<p>'.htmlspecialchars($line, ENT_QUOTES).'</p>',
            $definition['description']
        );

        return implode('', $paragraphs);
    }

    /** The two still-open events are highlighted on the site; the rest live in the room archive. */
    private function isFeatured(array $definition): bool
    {
        return in_array($definition['key'], ['space-run-2026', 'irunph-pasko-run-2'], true);
    }

    /**
     * @return array<int, array{time:string, title:string, detail:string}>
     */
    private function agenda(array $definition, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return [
            ['time' => $start->subHour()->format('H:i'), 'title' => 'Assembly', 'detail' => 'Race village opens — claim your kit and warm up.'],
            ['time' => $start->format('H:i'), 'title' => 'Gun start', 'detail' => $definition['tagline']],
            ['time' => $end->subHour()->format('H:i'), 'title' => 'Cut-off', 'detail' => 'Course closes; medals and loot at the finish line.'],
        ];
    }

    private function uploaded(string $asset): UploadedFile
    {
        $path = $this->imagesPath().'/'.$asset;

        if (! is_file($path)) {
            throw new RuntimeException('TV8 image missing: '.$path);
        }

        return new UploadedFile($path, basename($path), mime_content_type($path) ?: 'image/jpeg', null, true);
    }

    private function imagesPath(): string
    {
        return resource_path('demo/tv8');
    }

    private function report(array $data, int $organizerId, array $seeded): void
    {
        $this->newLine();
        $this->info(sprintf('Seeded %s — organizer id %d, %d events.', $data['organizer']['name'], $organizerId, count($seeded)));
        $this->newLine();

        $this->table(
            ['Event', 'Platform id'],
            array_map(static fn (array $row): array => [$row['title'], $row['id']], $seeded),
        );

        $this->table(
            ['Organizer login', 'Password'],
            [[$data['organizer']['email'], self::PASSWORD]],
        );

        $this->line('Open the website room at /o/'.$organizerId.'/ and any event at /event/{id}/.');
    }
}
