<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/reports/{report}/exports`: the format and the report parameters as for a run
 * (`period` or `from`+`to`, `group`, `measures`, `filter`, `compare`), validated against the report.
 */
final class StoreReportExportRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // csv or xlsx.
            'format' => ['required', 'string', 'in:csv,xlsx'],
            /**
             * As for `POST /v1/reports/{report}/run`; the report's defaults when omitted.
             *
             * @var array{period?: string, from?: string, to?: string, group?: string, measures?: string, filter?: array<string, string>, compare?: bool}
             */
            'parameters' => ['sometimes', 'array'],
        ];
    }
}
