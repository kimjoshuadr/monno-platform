<?php

namespace HiEvents\Validators\Rules;

use Closure;
use HiEvents\DomainObjects\Enums\HomepageBlockType;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a homepage's section list: every block needs an id and a known
 * type, and the types whose content is authored inline (rather than derived
 * from the event/organizer) need their required settings present.
 *
 * Types without extra requirements — HERO, ABOUT, AGENDA, TICKETS, VENUE,
 * ORGANIZER, ATTENDEES — pull their content from elsewhere, so an empty
 * `settings` object is correct for them.
 */
class ValidHomepageBlocks implements ValidationRule
{
    private const MAX_BLOCKS = 50;
    private const MAX_ITEMS = 50;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_array($value)) {
            $fail(__('The sections must be a list.'));

            return;
        }

        if (count($value) > self::MAX_BLOCKS) {
            $fail(__('A page can have at most :count sections.', ['count' => self::MAX_BLOCKS]));

            return;
        }

        $types = HomepageBlockType::valuesArray();

        foreach ($value as $index => $block) {
            $path = "{$attribute}.{$index}";

            if (! is_array($block)) {
                $fail(__(':path must be a section.', ['path' => $path]));

                continue;
            }

            $id = $block['id'] ?? null;
            if (! is_string($id) || $id === '' || strlen($id) > 64) {
                $fail(__(':path is missing a valid id.', ['path' => $path]));
            }

            $type = $block['type'] ?? null;
            if (! is_string($type) || ! in_array($type, $types, true)) {
                $fail(__(':path has an unknown section type.', ['path' => $path]));

                continue;
            }

            $settings = $block['settings'] ?? [];
            if ($settings === null) {
                $settings = [];
            }
            if (! is_array($settings)) {
                $fail(__(':path has invalid settings.', ['path' => $path]));

                continue;
            }

            $this->validateSettings(HomepageBlockType::fromName($type), $settings, $path, $fail);
        }
    }

    private function validateSettings(HomepageBlockType $type, array $settings, string $path, Closure $fail): void
    {
        switch ($type) {
            case HomepageBlockType::TEXT:
                $this->requireString($settings, 'body', $path, $fail, 20000);
                break;

            case HomepageBlockType::CTA:
                $this->requireString($settings, 'label', $path, $fail, 120);
                $this->requireUrl($settings, 'url', $path, $fail);
                break;

            case HomepageBlockType::EMBED:
                $this->requireUrl($settings, 'url', $path, $fail);
                break;

            case HomepageBlockType::FAQ:
                $this->requireItems($settings, 'items', $path, $fail, function (array $item, string $itemPath, Closure $fail) {
                    $this->requireString($item, 'question', $itemPath, $fail, 300);
                    $this->requireString($item, 'answer', $itemPath, $fail, 5000);
                });
                break;

            case HomepageBlockType::LINEUP:
                $this->requireItems($settings, 'artists', $path, $fail, function (array $item, string $itemPath, Closure $fail) {
                    $this->requireString($item, 'name', $itemPath, $fail, 200);
                });
                break;

            case HomepageBlockType::GALLERY:
                $this->requireItems($settings, 'images', $path, $fail, function (array $item, string $itemPath, Closure $fail) {
                    $this->requireUrl($item, 'url', $itemPath, $fail);
                    $this->requireString($item, 'alt', $itemPath, $fail, 255);
                });
                break;

            default:
                // Data-driven sections carry no authored settings.
                break;
        }
    }

    private function requireString(array $settings, string $key, string $path, Closure $fail, int $max): void
    {
        $value = $settings[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            $fail(__(':path needs a :key.', ['path' => $path, 'key' => $key]));

            return;
        }

        if (mb_strlen($value) > $max) {
            $fail(__(':path :key is too long.', ['path' => $path, 'key' => $key]));
        }
    }

    private function requireUrl(array $settings, string $key, string $path, Closure $fail): void
    {
        $value = $settings[$key] ?? null;

        if (! is_string($value) || $value === '') {
            $fail(__(':path needs a :key.', ['path' => $path, 'key' => $key]));

            return;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            $fail(__(':path :key must be a valid URL.', ['path' => $path, 'key' => $key]));
        }
    }

    private function requireItems(array $settings, string $key, string $path, Closure $fail, callable $validateItem): void
    {
        $items = $settings[$key] ?? null;

        if (! is_array($items) || $items === []) {
            $fail(__(':path needs at least one entry for :key.', ['path' => $path, 'key' => $key]));

            return;
        }

        if (count($items) > self::MAX_ITEMS) {
            $fail(__(':path has too many :key.', ['path' => $path, 'key' => $key]));

            return;
        }

        foreach (array_values($items) as $index => $item) {
            $itemPath = "{$path}.{$key}.{$index}";

            if (! is_array($item)) {
                $fail(__(':path must be an entry.', ['path' => $itemPath]));

                continue;
            }

            $validateItem($item, $itemPath, $fail);
        }
    }
}
