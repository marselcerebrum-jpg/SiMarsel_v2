<?php

namespace App\Http\Requests\Performance;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Ekspor Daftar Transaksi: the same filters as the list, plus the file format. The export has no pages, so
 * page and per_page are accepted but do not change the file.
 */
class TransactionExportRequest extends TransactionListRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'format' => ['required', Rule::in(['xlsx', 'csv'])],
        ];
    }

    /**
     * @return 'xlsx'|'csv'
     */
    public function exportFormat(): string
    {
        return $this->validated('format');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'format' => 'Format',
        ];
    }
}
