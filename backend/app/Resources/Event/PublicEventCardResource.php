<?php

namespace HiEvents\Resources\Event;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\Status\EventOccurrenceStatus;
use HiEvents\Helper\Url;
use HiEvents\Resources\BaseResource;
use HiEvents\Resources\EventLocation\EventLocationResourcePublic;
use Illuminate\Http\Request;

/**
 * The event as a directory card — the projection the website's listings read.
 *
 * `EventResourcePublic` is the full page payload: description HTML, agenda,
 * questions, product categories, settings. That is fine for one event and ruinous
 * for a list (it is ~4 KB each, and the platform has thousands of LIVE events).
 * This carries exactly what a card, a globe pin or a filter needs, and nothing
 * else.
 *
 * @mixin EventDomainObject
 */
class PublicEventCardResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        [$lowestPrice, $sold, $remaining, $showRemaining] = $this->ticketSummary();
        $next = $this->representativeOccurrence();

        return [
            'id' => $this->getId(),
            'title' => $this->getTitle(),
            'tagline' => $this->getTagline(),
            'description_preview' => $this->getDescriptionPreview(),
            'image_alt' => $this->getImageAlt(),
            'featured' => $this->isFeatured(),
            'category' => $this->getCategory(),
            'slug' => $this->getSlug(),
            'status' => $this->getStatus(),
            // The next active occurrence's window — what "the event's date" means
            // on a card. A recurring event's first-ever occurrence is often past.
            'start_date' => $next?->getStartDate(),
            'end_date' => $next?->getEndDate() ?? $next?->getStartDate(),
            'timezone' => $this->getTimezone(),
            'currency' => $this->getCurrency(),
            'event_location' => $this->when(
                condition: $this->getEventLocation() !== null,
                value: fn () => new EventLocationResourcePublic($this->getEventLocation()),
            ),
            'images' => $this->when(
                condition: $this->getImages() !== null,
                value: fn () => $this->getImages()->map(fn ($image) => [
                    'url' => Url::getCdnUrl($image->getPath()),
                    'type' => $image->getType(),
                ])->values(),
            ),
            // The organizer is inlined and trimmed: the shared public organizer
            // resource drags in the whole theme/settings blob per event.
            'organizer' => $this->when(
                condition: $this->getOrganizer() !== null,
                value: fn () => [
                    'id' => $this->getOrganizer()->getId(),
                    'name' => $this->getOrganizer()->getName(),
                    'slug' => $this->getOrganizer()->getSlug(),
                    'description' => $this->getOrganizer()->getDescription(),
                    'images' => $this->getOrganizer()->getImages()?->map(fn ($image) => [
                        'url' => Url::getCdnUrl($image->getPath()),
                        'type' => $image->getType(),
                    ])->values(),
                ],
            ),
            'lowest_price' => $lowestPrice,
            // Sold is a count of tickets, never of buyers — already public on the
            // price resource. Remaining stays behind the organizer's own setting.
            'quantity_sold' => $sold,
            $this->mergeWhen($showRemaining, fn () => ['quantity_remaining' => $remaining]),
        ];
    }

    /** The occurrence that dates the card: the next upcoming, or the last one past. */
    private function representativeOccurrence(): ?EventOccurrenceDomainObject
    {
        $occurrences = ($this->getEventOccurrences() ?? collect())
            ->filter(fn (EventOccurrenceDomainObject $occurrence) => $occurrence->getStatus() === EventOccurrenceStatus::ACTIVE->name);

        $upcoming = $occurrences
            ->filter(fn (EventOccurrenceDomainObject $occurrence) => ! $occurrence->isPast())
            ->sortBy(fn (EventOccurrenceDomainObject $occurrence) => $occurrence->getStartDate())
            ->first();

        return $upcoming ?? $occurrences
            ->sortByDesc(fn (EventOccurrenceDomainObject $occurrence) => $occurrence->getStartDate())
            ->first();
    }

    /**
     * @return array{0: float, 1: int, 2: int, 3: bool} lowest price, sold, remaining, whether remaining is public
     */
    private function ticketSummary(): array
    {
        $lowest = null;
        $sold = 0;
        $remaining = 0;
        $showRemaining = false;

        foreach ($this->getProductCategories() ?? collect() as $category) {
            foreach ($category->getProducts() ?? collect() as $product) {
                $prices = $product->getProductPrices();

                if ($prices === null || $prices->isEmpty()) {
                    $price = $product->getPrice();
                    if ($price !== null) {
                        $lowest = $lowest === null ? $price : min($lowest, $price);
                    }
                    $sold += $product->getQuantitySold();
                    continue;
                }

                foreach ($prices as $price) {
                    $value = $price->getPrice();
                    if ($value !== null) {
                        $lowest = $lowest === null ? $value : min($lowest, $value);
                    }
                    $sold += $price->getQuantitySold();
                    $remaining += $this->remainingFor($price);
                    if ($product->getShowQuantityRemaining()) {
                        $showRemaining = true;
                    }
                }
            }
        }

        return [(float) ($lowest ?? 0), $sold, $remaining, $showRemaining];
    }

    /**
     * A price's remaining stock. `quantity_available` is only written when the
     * event uses capacity assignments; normal products leave it null and derive
     * it from `initial_quantity_available − quantity_sold`, exactly as the full
     * event payload does. Reading the raw column made a card whose only sale left
     * 4,999 seats free advertise "Sold out".
     */
    private function remainingFor(object $price): int
    {
        $available = $price->getQuantityAvailable();

        if ($available !== null) {
            return max(0, (int) $available);
        }

        $initial = $price->getInitialQuantityAvailable();

        return $initial === null ? 0 : max(0, (int) $initial - (int) $price->getQuantitySold());
    }
}
