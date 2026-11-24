<?php

namespace Tests\Feature\Http\Actions\Events;

use HiEvents\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * The public event payload is the contract the monno website's event page is
 * built on: its date picker renders only when `occurrences` carries more than
 * one upcoming date, and checkout links scope tickets with
 * `?event_occurrence_id=`.
 */
class GetEventPublicActionTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private int $accountId;

    private int $organizerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withAccount()->create();
        $this->accountId = $this->user->accounts()->first()->id;
        $this->organizerId = $this->makeOrganizer();
    }

    public function test_multi_date_event_embeds_all_upcoming_occurrences_and_excludes_past_ones(): void
    {
        $event = $this->makeEvent('QA Multi-Date Public Event', type: 'RECURRING');
        $ids = $this->makeOccurrences($event, [
            ['2020-01-10 10:00:00', '2020-01-10 12:00:00', 'Past date'],
            ['2030-01-10 10:00:00', '2030-01-10 12:00:00', 'Winter date'],
            ['2030-02-10 10:00:00', '2030-02-10 12:00:00', 'Spring date'],
            ['2030-03-10 10:00:00', '2030-03-10 12:00:00', 'Late date'],
        ]);
        [$pastId, $winterId, $springId, $lateId] = $ids;

        $response = $this->getJson("/public/events/{$event}");

        $response->assertOk();
        $occurrences = collect($response->json('data.occurrences'));

        $this->assertSame(
            [$winterId, $springId, $lateId],
            $occurrences->pluck('id')->all(),
            'Every upcoming occurrence must be embedded, past ones excluded.',
        );

        $first = $occurrences->firstWhere('id', $winterId);
        $this->assertSame('2030-01-10T10:00:00.000000Z', $first['start_date']);
        $this->assertSame('2030-01-10T12:00:00.000000Z', $first['end_date']);
        $this->assertSame('Winter date', $first['label']);
        $this->assertSame('ACTIVE', $first['status']);
        $this->assertTrue($first['is_active']);
        $this->assertFalse($first['is_past']);
        $this->assertTrue($first['is_future']);
    }

    public function test_single_date_event_payload_is_unchanged(): void
    {
        $event = $this->makeEvent('QA Single-Date Public Event', type: 'SINGLE');
        [$onlyId] = $this->makeOccurrences($event, [
            ['2030-06-03 19:00:00', '2030-06-03 22:00:00', 'Opening night'],
        ]);

        $response = $this->getJson("/public/events/{$event}");

        $response->assertOk();
        $occurrences = collect($response->json('data.occurrences'));

        $this->assertSame([$onlyId], $occurrences->pluck('id')->all());
        $this->assertSame('Opening night', $occurrences->first()['label']);
        $this->assertNull($response->json('data.occurrences_month'));
    }

    public function test_event_occurrence_id_still_scopes_products(): void
    {
        $event = $this->makeEvent('QA Scoped Public Event', type: 'RECURRING');
        [$winterId, $springId] = $this->makeOccurrences($event, [
            ['2030-01-10 10:00:00', '2030-01-10 12:00:00', 'Winter date'],
            ['2030-02-10 10:00:00', '2030-02-10 12:00:00', 'Spring date'],
        ]);

        $categoryId = DB::table('product_categories')->insertGetId([
            'name' => 'Tickets',
            'is_hidden' => false,
            'order' => 0,
            'event_id' => $event,
        ]);
        $generalId = $this->makeProduct($event, $categoryId, 'General admission');
        $vipId = $this->makeProduct($event, $categoryId, 'VIP');

        // Only General admission is visible on the Winter date.
        DB::table('product_occurrence_visibility')->insert([
            'event_occurrence_id' => $winterId,
            'product_id' => $generalId,
        ]);

        $scoped = $this->getJson("/public/events/{$event}?event_occurrence_id={$winterId}");
        $scoped->assertOk();
        $this->assertSame(
            ['General admission'],
            collect($scoped->json('data.product_categories.0.products'))->pluck('title')->all(),
        );

        // Both occurrences stay in the list while the products are scoped.
        $this->assertSame(
            [$winterId, $springId],
            collect($scoped->json('data.occurrences'))->pluck('id')->all(),
        );

        $unscoped = $this->getJson("/public/events/{$event}");
        $unscoped->assertOk();
        $this->assertSame(
            ['General admission', 'VIP'],
            collect($unscoped->json('data.product_categories.0.products'))->pluck('title')->all(),
        );
    }

    private function makeOrganizer(): int
    {
        return DB::table('organizers')->insertGetId([
            'account_id' => $this->accountId,
            'name' => 'Public Event Organizer',
            'email' => 'public-events-' . uniqid() . '@test.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeEvent(string $title, string $type): int
    {
        return DB::table('events')->insertGetId([
            'title' => $title,
            'short_id' => 'ev_' . uniqid(),
            'account_id' => $this->accountId,
            'user_id' => $this->user->id,
            'organizer_id' => $this->organizerId,
            'start_date' => '2030-01-10 10:00:00',
            'end_date' => '2030-01-10 12:00:00',
            'description' => 'Fixture event for the public event endpoint.',
            'status' => 'LIVE',
            'currency' => 'USD',
            'timezone' => 'UTC',
            'type' => $type,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<array{0: string, 1: string, 2: string}> $occurrences [start, end, label]
     * @return array<int, int> inserted ids, in the order given
     */
    private function makeOccurrences(int $eventId, array $occurrences): array
    {
        $ids = [];
        foreach ($occurrences as [$start, $end, $label]) {
            $ids[] = DB::table('event_occurrences')->insertGetId([
                'short_id' => 'oc_' . uniqid(),
                'event_id' => $eventId,
                'start_date' => $start,
                'end_date' => $end,
                'status' => 'ACTIVE',
                'capacity' => 100,
                'used_capacity' => 0,
                'label' => $label,
                'is_overridden' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    private function makeProduct(int $eventId, int $categoryId, string $title): int
    {
        $productId = DB::table('products')->insertGetId([
            'title' => $title,
            'event_id' => $eventId,
            'type' => 'PAID',
            'product_type' => 'TICKET',
            'product_category_id' => $categoryId,
            'max_per_order' => 10,
            'min_per_order' => 1,
            'sales_volume' => '0.00',
            'sales_tax_volume' => '0.00',
            'hide_before_sale_start_date' => false,
            'hide_after_sale_end_date' => false,
            'hide_when_sold_out' => false,
            'show_quantity_remaining' => true,
            'is_hidden_without_promo_code' => false,
            'is_hidden' => false,
            'is_addon_only' => false,
            'start_collapsed' => false,
            'is_highlighted' => false,
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_prices')->insertGetId([
            'product_id' => $productId,
            'price' => '25.00',
            'label' => 'General',
            'initial_quantity_available' => 100,
            'quantity_sold' => 0,
            'is_hidden' => false,
            'order' => 1,
            'quantity_applies_to' => 'EVENT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $productId;
    }
}
