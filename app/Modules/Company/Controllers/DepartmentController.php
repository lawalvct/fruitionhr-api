<?php

namespace App\Modules\Company\Controllers;

use App\Modules\Company\Models\Department;
use App\Modules\Company\Requests\DepartmentRequest;
use App\Modules\Company\Resources\DepartmentResource;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;

class DepartmentController extends CompanyResourceController
{
    public function index(Request $request): mixed
    {
        return $this->indexResponse($request);
    }

    public function store(DepartmentRequest $request): mixed
    {
        return $this->createdResponse($request, $request->validated());
    }

    public function show(Request $request, Department $department): mixed
    {
        return $this->showResponse($request, $department);
    }

    public function update(DepartmentRequest $request, Department $department): mixed
    {
        return $this->updatedResponse($request, $department, $request->validated());
    }

    public function destroy(Request $request, Department $department): mixed
    {
        return $this->deletedResponse($request, $department);
    }

    protected function modelClass(): string
    {
        return Department::class;
    }

    protected function resourceClass(): string
    {
        return DepartmentResource::class;
    }

    protected function searchFields(): array
    {
        return ['name', 'code'];
    }

    protected function sorts(): array
    {
        return ['name', 'code', 'created_at'];
    }

    protected function relations(): array
    {
        return ['branch', 'parent'];
    }

    protected function allowedFilters(): array
    {
        return [...parent::allowedFilters(), AllowedFilter::exact('branch_id'), AllowedFilter::exact('parent_id')];
    }
}
