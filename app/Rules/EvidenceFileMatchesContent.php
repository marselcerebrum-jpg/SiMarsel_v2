<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * The file's name must claim the type its content really has. The mimes and extensions rules each accept any
 * allowed type, so a PDF renamed to .png would pass both; this rule rejects it.
 */
class EvidenceFileMatchesContent implements ValidationRule
{
    /**
     * Extensions that name the same type.
     */
    private const ALIASES = ['jpeg' => 'jpg'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $claimed = $this->normalize($value->getClientOriginalExtension());
        $detected = $this->normalize((string) $value->guessExtension());

        if ($claimed !== $detected) {
            $fail('Isi file tidak sesuai dengan ekstensinya. Unggah file asli tanpa mengganti ekstensinya.');
        }
    }

    private function normalize(string $extension): string
    {
        $extension = strtolower($extension);

        return self::ALIASES[$extension] ?? $extension;
    }
}
