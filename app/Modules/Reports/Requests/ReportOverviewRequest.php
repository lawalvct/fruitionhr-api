<?php

namespace App\Modules\Reports\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class ReportOverviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::REPORTS_VIEW) ?? false;
    }

    public function rules(): array
    {
        return [
            'year' => ['sometimes', 'integer', 'min:2000', 'max:'.(now()->year + 1)],
        ];
    }
}
