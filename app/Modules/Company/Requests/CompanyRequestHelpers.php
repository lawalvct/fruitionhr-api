<?php

namespace App\Modules\Company\Requests;

use App\Support\Tenancy\CurrentTenant;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait CompanyRequestHelpers
{
    protected function tenantId(): int
    {
        return (int) app(CurrentTenant::class)->id();
    }

    protected function tenantExists(string $table): mixed
    {
        return Rule::exists($table, 'id')->where('tenant_id', $this->tenantId());
    }

    protected function tenantUnique(string $table, string $column, ?int $ignore = null): Unique
    {
        $rule = Rule::unique($table, $column)->where('tenant_id', $this->tenantId());

        return $ignore === null ? $rule : $rule->ignore($ignore);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('company.manage') ?? false;
    }
}
