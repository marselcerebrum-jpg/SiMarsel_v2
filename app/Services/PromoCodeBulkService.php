<?php

namespace App\Services;

use App\Models\PromoCode;
use App\Support\PromoCodeRules;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Bulk Entry of promo codes: every row is validated with the Single Entry rules and saved on its own,
 * so valid rows are kept even when other rows are invalid.
 */
class PromoCodeBulkService
{
    public const STATUS_CREATED = 'created';

    public const STATUS_FAILED = 'failed';

    private const FIELDS = ['code', 'platform_id', 'funnel_id', 'source_id'];

    public function __construct(private PromoCodeService $promoCodeService) {}

    /**
     * @param  list<array<string, mixed>|null>  $rows  In form order; row numbers start at 1.
     * @return array{
     *     summary: array{total: int, succeeded: int, failed: int},
     *     rows: list<array{row: int, client_row_id: string|null, status: string, code: mixed, promo_code: PromoCode|null, errors: array<string, list<string>>}>
     * }
     */
    public function store(array $rows): array
    {
        $results = [];
        $savedRows = [];

        foreach ($rows as $index => $row) {
            // Leftover empty lines of the spreadsheet form are ignored, not counted as failed.
            if ($this->isEmpty($row)) {
                continue;
            }

            $number = $index + 1;
            $clientRowId = $row['client_row_id'] ?? null;
            $row = PromoCodeRules::normalize(array_intersect_key($row, array_flip(self::FIELDS)));
            $key = $this->key($row);

            // A repeat of a row saved earlier in this submission points to that row.
            $errors = $key !== null && isset($savedRows[$key])
                ? ['code' => ["Duplikat dengan baris {$savedRows[$key]}."]]
                : $this->validate($row);

            $promoCode = $errors === [] ? $this->save($row, $errors) : null;

            if ($promoCode !== null) {
                $savedRows[$key] = $number;
            }

            $results[] = [
                'row' => $number,
                'client_row_id' => $clientRowId,
                'status' => $promoCode === null ? self::STATUS_FAILED : self::STATUS_CREATED,
                'code' => $row['code'] ?? null,
                'promo_code' => $promoCode,
                'errors' => $errors,
            ];
        }

        $succeeded = count(array_filter($results, fn (array $result) => $result['status'] === self::STATUS_CREATED));

        return [
            'summary' => [
                'total' => count($results),
                'succeeded' => $succeeded,
                'failed' => count($results) - $succeeded,
            ],
            'rows' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, list<string>>
     */
    private function validate(array $row): array
    {
        return Validator::make(
            $row,
            PromoCodeRules::create(fn () => $row['platform_id'] ?? null),
            PromoCodeRules::messages(),
            PromoCodeRules::attributes(),
        )->errors()->toArray();
    }

    /**
     * Same as Single Entry: the first metadata version is created and earlier unmapped usages are linked.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, list<string>>  $errors  Filled when another user saved the same code first.
     */
    private function save(array $row, array &$errors): ?PromoCode
    {
        try {
            return $this->promoCodeService->register(
                (int) $row['platform_id'],
                $row['code'],
                isset($row['funnel_id']) ? (int) $row['funnel_id'] : null,
                isset($row['source_id']) ? (int) $row['source_id'] : null,
            )->load(['platform', 'funnel', 'source'])->loadCount('usages');
        } catch (ValidationException $exception) {
            // PromoCodeService reports a duplicate saved by a concurrent request as a validation error.
            $errors = $exception->errors();

            return null;
        }
    }

    /**
     * The platform + normalized code pair of a row, or null when either is missing.
     *
     * @param  array<string, mixed>  $row
     */
    private function key(array $row): ?string
    {
        $code = $row['code'] ?? null;
        $platformId = $row['platform_id'] ?? null;

        return is_string($code) && $code !== '' && is_numeric($platformId) ? ((int) $platformId).'|'.$code : null;
    }

    private function isEmpty(mixed $row): bool
    {
        if (! is_array($row)) {
            return true;
        }

        foreach (self::FIELDS as $field) {
            $value = $row[$field] ?? null;

            // A non-text value is not empty; validation reports it as invalid.
            if ($value !== null && (! is_scalar($value) || trim((string) $value) !== '')) {
                return false;
            }
        }

        return true;
    }
}
