<?php

namespace App\Modules\Company\Controllers;

use App\Modules\Company\Models\Branch;
use App\Modules\Company\Requests\BranchRequest;
use App\Modules\Company\Resources\BranchResource;
use Illuminate\Http\Request;

class BranchController extends CompanyResourceController
{
    public function index(Request $request): mixed
    {
        return $this->indexResponse($request);
    }

    public function store(BranchRequest $request): mixed
    {
        return $this->createdResponse($request, $request->validated());
    }

    public function show(Request $request, Branch $branch): mixed
    {
        return $this->showResponse($request, $branch);
    }

    public function update(BranchRequest $request, Branch $branch): mixed
    {
        return $this->updatedResponse($request, $branch, $request->validated());
    }

    public function destroy(Request $request, Branch $branch): mixed
    {
        return $this->deletedResponse($request, $branch);
    }

    protected function modelClass(): string
    {
        return Branch::class;
    }

    protected function resourceClass(): string
    {
        return BranchResource::class;
    }

    protected function searchFields(): array
    {
        return ['name', 'code', 'city', 'state'];
    }

    protected function sorts(): array
    {
        return ['name', 'code', 'city', 'state', 'created_at'];
    }
}
