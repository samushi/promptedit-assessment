<?php

declare(strict_types=1);

namespace App\Domain\Reports\Requests;

use App\Domain\Reports\Actions\GetAdminReportAction;
use App\Support\FormRequest;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Validator;

/**
 * Inclusive range of UTC dates. Defaults to the last 14 days.
 */
class AdminReportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->start()->greaterThan($this->end())) {
                    $validator->errors()->add('start', 'The start date must be on or before the end date.');
                } elseif ($this->start()->diffInDays($this->end()) >= GetAdminReportAction::MAX_DAYS) {
                    $validator->errors()->add('start', 'Pixelforge reports at most '.GetAdminReportAction::MAX_DAYS.' days at a time.');
                }
            },
        ];
    }

    public function start(): CarbonImmutable
    {
        return $this->date('start', 'Y-m-d', 'UTC')?->toImmutable()->startOfDay()
            ?? $this->end()->subDays(13);
    }

    public function end(): CarbonImmutable
    {
        return $this->date('end', 'Y-m-d', 'UTC')?->toImmutable()->startOfDay()
            ?? CarbonImmutable::now('UTC')->startOfDay();
    }
}
