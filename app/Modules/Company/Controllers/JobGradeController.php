<?php

namespace App\Modules\Company\Controllers;

use App\Modules\Company\Models\JobGrade;
use App\Modules\Company\Requests\JobGradeRequest;
use App\Modules\Company\Resources\JobGradeResource;
use Illuminate\Http\Request;

class JobGradeController extends CompanyResourceController
{
    public function index(Request $request): mixed
    {
        return $this->indexResponse($request);
    }

    public function store(JobGradeRequest $request): mixed
    {
        return $this->createdResponse($request, $request->validated());
    }

    public function show(Request $request, JobGrade $jobGrade): mixed
    {
        return $this->showResponse($request, $jobGrade);
    }

    public function update(JobGradeRequest $request, JobGrade $jobGrade): mixed
    {
        return $this->updatedResponse($request, $jobGrade, $request->validated());
    }

    public function destroy(Request $request, JobGrade $jobGrade): mixed
    {
        return $this->deletedResponse($request, $jobGrade);
    }

    protected function modelClass(): string
    {
        return JobGrade::class;
    }

    protected function resourceClass(): string
    {
        return JobGradeResource::class;
    }

    protected function searchFields(): array
    {
        return ['name', 'code'];
    }

    protected function sorts(): array
    {
        return ['name', 'code', 'level', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return 'level';
    }
}
