<?php

namespace Database\Factories;

use App\Models\Platform;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Shaped like the transaction data returned by the Markaz server.
 *
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    public const PAYMENT_METHODS = ['BNI', 'BRI', 'BCA', 'MANDIRI', 'PERMATA', 'QRIS', 'GOPAY', 'OVO', 'DANA', 'SHOPEEPAY'];

    /**
     * Product names per platform slug, following the Markaz product naming.
     */
    private const PRODUCTS = [
        'cerebrum' => 'Bimbel Persiapan UTBK SNBT 2027',
        'jadiasn' => 'Bimbel Persiapan Tes SKD CPNS 2026',
        'jadibumn' => 'Bimbel Persiapan Rekrutmen Bersama BUMN 2026',
        'jadipolisi' => 'Bimbel Persiapan Tes Masuk Polri 2026',
        'jadiprajurit' => 'Bimbel Persiapan Tes Masuk TNI 2026',
        'jadisekdin' => 'Bimbel Persiapan Sekolah Kedinasan 2026',
        'jadibeasiswa' => 'Bimbel Persiapan Beasiswa LPDP 2026',
        'psikoteskerja' => 'Latihan Psikotes Kerja 2026',
        'jagotpa' => 'Latihan Tes Potensi Akademik 2026',
        'jadipppk' => 'Bimbel Persiapan Seleksi PPPK 2026',
        'jadiojk' => 'Bimbel Persiapan Rekrutmen OJK 2026',
        'jadipcpm' => 'Bimbel Persiapan PCPM Bank Indonesia 2026',
        'toeflacademy' => 'Persiapan TOEFL ITP 2026',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'platform_id' => Platform::factory(),
            'markaz_user_id' => fake()->numberBetween(100000, 999999),
            'trx_id' => 'markaz-'.$this->markazId(),
            'paid_date' => fake()->dateTimeBetween('-6 months')->format('Y-m-d'),
            'paid_time' => fake()->time(),
            'payment_method' => fake()->randomElement(self::PAYMENT_METHODS),
            'nominal' => fake()->numberBetween(49, 499) * 1000,
            'promo_code' => null,
            'product' => $this->product(null),
            'product_type' => fake()->boolean(70) ? Transaction::TYPE_FIRST : Transaction::TYPE_RENEWAL,
            'customer_email' => self::customerEmail(),
            'status_message' => Transaction::STATUS_SUCCESS,
        ];
    }

    /**
     * Customer emails look like the ones Markaz returns, for example "amahs7391@gmail.com".
     */
    public static function customerEmail(): string
    {
        return strtolower(fake()->firstName()).fake()->numerify('####').'@gmail.com';
    }

    /**
     * A transaction of the given platform, with a Markaz-style trx_id ("jadiasn-476770") and product.
     */
    public function forPlatform(Platform $platform): static
    {
        return $this->state(fn () => [
            'platform_id' => $platform->id,
            'trx_id' => $platform->slug.'-'.$this->markazId(),
            'product' => $this->product($platform->slug),
        ]);
    }

    public function withoutPaymentMethod(): static
    {
        return $this->state(fn () => ['payment_method' => null]);
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status_message' => 'failed']);
    }

    private function markazId(): int
    {
        return fake()->unique()->numberBetween(100000, 9999999);
    }

    private function product(?string $platformSlug): string
    {
        $name = self::PRODUCTS[$platformSlug] ?? fake()->randomElement(self::PRODUCTS);

        return $name.' - Masa Aktif '.fake()->randomElement(['6 Bulan', '1 Tahun', '2 Tahun']);
    }
}
