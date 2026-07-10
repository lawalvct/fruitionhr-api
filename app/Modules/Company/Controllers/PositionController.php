<?php

namespace App\Modules\Company\Controllers;

use App\Modules\Company\Models\Position;
use App\Modules\Company\Requests\PositionRequest;
use App\Modules\Company\Resources\PositionResource;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;

class PositionController extends CompanyResourceController
{
    public function index(Request $request): mixed
    {
        return $this->indexResponse($request);
    }

    public function store(PositionRequest $request): mixed
    {
        return $this->createdResponse($request, $request->validated());
    }

    public function show(Request $request, Position $position): mixed
    {
        return $this->showResponse($request, $position);
    }

    public function update(PositionRequest $request, Position $position): mixed
    {
        return $this->updatedResponse($request, $position, $request->validated());
    }

    public function destroy(Request $request, Position $position): mixed
    {
        return $this->deletedResponse($request, $position);
    }

    protected function modelClass(): string
    {
        return Position::class;
    }

    protected function resourceClass(): string
    {
        return PositionResource::class;
    }

    protected function searchFields(): array
    {
        return ['title', 'code'];
    }

    protected function sorts(): array
    {
        return ['title', 'code', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return 'title';
    }

    protected function relations(): array
    {
        return ['department', 'jobGrade'];
    }

    protected function allowedFilters(): array
    {
        return [...parent::allowedFilters(), AllowedFilter::exact('department_id'), AllowedFilter::exact('job_grade_id')];
    }
}
