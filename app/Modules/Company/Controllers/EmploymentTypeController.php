<?php

namespace App\Modules\Company\Controllers;

use App\Modules\Company\Models\EmploymentType;
use App\Modules\Company\Requests\EmploymentTypeRequest;
use App\Modules\Company\Resources\EmploymentTypeResource;
use Illuminate\Http\Request;

class EmploymentTypeController extends CompanyResourceController
{
    public function index(Request $request): mixed
    {
        return $this->indexResponse($request);
    }

    public function store(EmploymentTypeRequest $request): mixed
    {
        return $this->createdResponse($request, $request->validated());
    }

    public function show(Request $request, EmploymentType $employmentType): mixed
    {
        return $this->showResponse($request, $employmentType);
    }

    public function update(EmploymentTypeRequest $request, EmploymentType $employmentType): mixed
    {
        return $this->updatedResponse($request, $employmentType, $request->validated());
    }

    public function destroy(Request $request, EmploymentType $employmentType): mixed
    {
        return $this->deletedResponse($request, $employmentType);
    }

    protected function modelClass(): string
    {
        return EmploymentType::class;
    }

    protected function resourceClass(): string
    {
        return EmploymentTypeResource::class;
    }

    protected function searchFields(): array
    {
        return ['name'];
    }

    protected function sorts(): array
    {
        return ['name', 'created_at'];
    }
}
