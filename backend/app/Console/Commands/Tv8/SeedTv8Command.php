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
 * taken from the Runtime public API and bundled in resources/demo/tv8/.
 *
 *   php artisan tv8:seed            # create missing events, refresh the rest in place
 *   php artisan tv8:seed --fresh    # wipe the TV8 events first (new event ids)
 *
 * Events are matched by title and updated in place, so their platform ids — and
 * therefore every shared /event/{id}/ link — stay stable across runs. Only
 * `--fresh` reassigns ids. Tickets, questions and images are rebuilt each run.
 */
class SeedTv8Command extends Command
{
    protected $signature = 'tv8:seed
        {--force : Skip the production guard and the confirmation prompt}
        {--fresh : Delete the TV8 events first and recreate them with new ids}';

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

            if ($this->option('fresh')) {
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

        // Re-assert the organizer's identity on every run, so a rename or a new
        // logo lands without a fresh database.
        $db->table('organizers')->where('id', $organizerId)->update([
            'name' => $org['name'],
            'email' => $email,
            'website' => $org['social']['site'] ?? null,
            'description' => $org['bio'],
            'timezone' => $org['timezone'],
            'currency' => $org['currency'],
            'status' => 'LIVE',
            'slug' => $org['slug'],
        ]);

        // One logo (and cover, when we have their banner), no duplicates on re-run.
        $db->table('images')->where('entity_id', $organizerId)->delete();
        $createImage->handle(new CreateImageDTO(
            userId: $user->id,
            accountId: $accountId,
            image: $this->uploaded($org['logo']),
            imageType: ImageType::ORGANIZER_LOGO,
            entityId: $organizerId,
        ));

        if (! empty($org['banner'])) {
            $coverPath = $this->imagesPath().'/'.$org['banner'];

            if (is_file($coverPath)) {
                $createImage->handle(new CreateImageDTO(
                    userId: $user->id,
                    accountId: $accountId,
                    image: new UploadedFile($coverPath, basename($coverPath), mime_content_type($coverPath) ?: 'image/jpeg', null, true),
                    imageType: ImageType::ORGANIZER_COVER,
                    entityId: $organizerId,
                ));
            }
        }

        $this->line("  organizer <info>{$org['name']}</info> (id {$organizerId})");

        return [$accountId, $user->id, $organizerId];
    }

    /**
     * `--fresh` only: `events` is referenced by a handful of tables without a
     * cascade (`orders`, `questions`, `promo_codes`, stats …), so those are
     * cleared explicitly before the events themselves. Everything else cascades.
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

            // Match on title so a re-run updates in place — the platform id, and
            // every shared /event/{id}/ link, stays put.
            $eventId = (int) $db->table('events')
                ->where('organizer_id', $organizerId)
                ->where('title', $definition['title'])
                ->value('id');

            if ($eventId !== 0) {
                $this->clearEventChildren($db, $eventId);
                $db->table('events')->where('id', $eventId)->update([
                    'description' => $this->description($definition),
                    'start_date' => $ctx->toUtc($start->format('Y-m-d H:i:s'), $timezone),
                    'end_date' => $ctx->toUtc($end->format('Y-m-d H:i:s'), $timezone),
                    'timezone' => $timezone,
                    'currency' => $currency,
                    'category' => EventCategory::FITNESS->name,
                    'status' => EventStatus::LIVE->name,
                    'tagline' => $definition['tagline'],
                    'featured' => $this->isFeatured($definition),
                    'image_alt' => $definition['title'],
                    'agenda' => json_encode($this->agenda($definition, $start, $end), JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
                $this->line(sprintf('  event     %s (id %d, updated)', $definition['title'], $eventId));
            } else {
                // The venue only needs resolving when the event is first created;
                // `events.event_location_id` points at an `event_locations` row the
                // handler builds, so an update leaves it well alone.
                $locationId = $this->ensureLocation($ctx, $db, $definition, $organizerId, $accountId);

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
                    category: EventCategory::FITNESS,
                    event_location: new EventLocationData(type: LocationType::IN_PERSON, location_id: $locationId),
                    status: EventStatus::LIVE->name,
                    type: EventType::SINGLE,
                    tagline: $definition['tagline'],
                    featured: $this->isFeatured($definition),
                    image_alt: $definition['title'],
                    agenda: $this->agenda($definition, $start, $end),
                ));
                $eventId = $event->getId();
                $this->line(sprintf('  event     %s (id %d)', $definition['title'], $eventId));
            }

            $categoryId = $ctx->renameDefaultCategory($eventId, 'Race Categories', 'One ticket per runner. Pick your distance.');
            $productIds = $this->seedProducts($ctx, $db, $definition, $accountId, $eventId, $categoryId, $start);
            $this->seedQuestions($ctx, $db, $definition, $eventId, $productIds);
            $this->uploadEventImages($createEventImage, $definition, $accountId, $eventId);

            // Offline payment is the only method available on this deployment, so
            // the rail has a way to complete an order. The theme and the section
            // list are theirs — the page background, the accent, the poster
            // gallery and the race-kit advisories.
            $ctx->applySettings($accountId, $eventId, [
                'payment_providers' => ['OFFLINE'],
                'offline_payment_instructions' => 'Pay via bank transfer or GCash, then keep your confirmation email — it carries the QR code we scan at the race village.',
                'homepage_theme_settings' => $definition['theme'],
                'homepage_blocks' => $definition['blocks'],
            ]);

            $seeded[] = ['id' => $eventId, 'title' => $definition['title']];
        }

        return $seeded;
    }

    private function ensureLocation(DemoSeedContext $ctx, DatabaseManager $db, array $definition, int $organizerId, int $accountId): int
    {
        $existing = (int) $db->table('locations')
            ->where('organizer_id', $organizerId)
            ->where('name', $definition['location']['name'])
            ->value('id');

        if ($existing !== 0) {
            return $existing;
        }

        return $ctx->createLocation->handle(new UpsertLocationDTO(
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
        ))->getId();
    }

    /** Drop the previous tickets, questions and images for an event before rebuilding them. */
    private function clearEventChildren(DatabaseManager $db, int $eventId): void
    {
        // A product or question that an order points at cannot be deleted, so once
        // an event has orders we leave its tickets and questions alone and only
        // fill in whatever is missing (see seedProducts / seedQuestions).
        if ($db->table('orders')->where('event_id', $eventId)->exists()) {
            $this->warn("    event {$eventId} has orders — keeping its tickets and questions, filling in any gaps");
        } else {
            $questionIds = $db->table('questions')->where('event_id', $eventId)->pluck('id')->all();

            $this->deleteWhereIn($db, 'question_answers', 'question_id', $questionIds);
            $this->deleteWhereIn($db, 'product_questions', 'question_id', $questionIds);
            $this->deleteWhereIn($db, 'questions', 'event_id', [$eventId]);
            $this->deleteWhereIn($db, 'products', 'event_id', [$eventId]);
        }

        $this->deleteWhereIn($db, 'images', 'entity_id', [$eventId]);
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
        DatabaseManager $db,
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

            // One ticket per race category, priced at the live amount and labelled
            // as on sale — not two rows for the same race. The standard price is
            // stated in the ticket's own copy.
            $onSale = $discounted !== null && $regular !== null && $discounted < $regular;
            $price = $discounted ?? $regular ?? 0.0;

            $description = $product['description'];
            if ($onSale) {
                $description = trim(($description ? $description.' ' : '')
                    .'On sale — regular '.$this->money($regular).' afterwards.');
            }

            $existingId = (int) $db->table('products')
                ->where('event_id', $eventId)
                ->where('title', $product['title'])
                ->value('id');

            if ($existingId !== 0) {
                // A product an order points at cannot be replaced, so its tiers are
                // collapsed to the single live price in place.
                $this->collapseProductPrices($db, $existingId, $price, $onSale ? 'On sale' : null);
                $db->table('products')->where('id', $existingId)->update([
                    'description' => $description,
                    'sale_end_date' => $start->toDateTimeString(),
                    'updated_at' => now(),
                ]);
                $ids[] = $existingId;

                continue;
            }

            $prices = new Collection([
                new ProductPriceDTO(
                    price: $price,
                    label: $onSale ? 'On sale' : null,
                    initial_quantity_available: $capacity,
                ),
            ]);

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
                description: $description,
            ))->getId();
        }

        return $ids;
    }

    /** Reduce a product's tiers to the one live price, keeping any tier an order paid for. */
    private function collapseProductPrices(DatabaseManager $db, int $productId, float $price, ?string $label): void
    {
        $priceIds = $db->table('product_prices')->where('product_id', $productId)->orderBy('id')->pluck('id')->all();

        if ($priceIds === []) {
            return;
        }

        $referenced = $db->table('order_items')->whereIn('product_price_id', $priceIds)->value('product_price_id');
        $keep = (int) ($referenced ?? $priceIds[0]);

        foreach ($priceIds as $id) {
            if ($id === $keep || $db->table('order_items')->where('product_price_id', $id)->exists()) {
                continue;
            }

            $db->table('product_prices')->where('id', $id)->delete();
        }

        $db->table('product_prices')->where('id', $keep)->update([
            'price' => $price,
            'label' => $label,
            'updated_at' => now(),
        ]);
    }

    private function money(float $amount): string
    {
        return 'PHP '.number_format($amount, 0);
    }

    /**
     * Runtime's runner form, rebuilt as monno event questions. Product questions are
     * asked once per ticket; the waiver is asked once per order.
     *
     * Only question types the checkout renders are used: ADDRESS, CHECKBOX,
     * DROPDOWN, RADIO, SINGLE_LINE_TEXT, DATE. Runtime's phone fields are text and
     * its multi-selects are checkboxes until the checkout supports those two types.
     *
     * @param  array<int, int>  $productIds
     */
    private function seedQuestions(DemoSeedContext $ctx, DatabaseManager $db, array $definition, int $eventId, array $productIds): void
    {
        $categories = $definition['categories'] ?: ['3K', '5K', '10K'];

        $exists = fn (string $title): bool => $db->table('questions')
            ->where('event_id', $eventId)
            ->where('title', $title)
            ->exists();

        $product = function (string $title, QuestionTypeEnum $type, bool $required, ?array $options = null, ?string $description = null) use ($ctx, $exists, $eventId, $productIds): void {
            if ($exists($title)) {
                return;
            }

            $ctx->createQuestion->handle(new UpsertQuestionDTO(
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
        };

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

        if (! $exists('Liability Waiver and Race Agreement')) {
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
    }

    private function uploadEventImages(CreateEventImageHandler $createEventImage, array $definition, int $accountId, int $eventId): void
    {
        // The square logo is the event's cover (the site shows a 1:1 plate); the
        // wide banner is kept as the page cover.
        $map = [
            'image' => ImageType::EVENT_IMAGE,
            'banner' => ImageType::EVENT_COVER,
        ];

        foreach ($map as $field => $type) {
            if (empty($definition[$field])) {
                continue;
            }

            $path = $this->imagesPath().'/'.$definition[$field];

            if (! is_file($path)) {
                $this->warn('    missing image '.$path);

                continue;
            }

            $createEventImage->handle(new CreateEventImageDTO(
                eventId: $eventId,
                accountId: $accountId,
                image: new UploadedFile($path, basename($path), mime_content_type($path) ?: 'image/jpeg', null, true),
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

        return new UploadedFile($path, basename($asset), mime_content_type($path) ?: 'image/jpeg', null, true);
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
