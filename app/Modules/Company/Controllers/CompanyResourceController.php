<?php

namespace App\Modules\Company\Controllers;

use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Controller;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

abstract class CompanyResourceController extends Controller
{
    protected function authorizeView(Request $request): void
    {
        abort_unless($request->user()?->can(Permissions::COMPANY_VIEW), 403);
    }

    protected function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->can(Permissions::COMPANY_MANAGE), 403);
    }

    protected function indexResponse(Request $request): mixed
    {
        $this->authorizeView($request);

        $query = QueryBuilder::for($this->modelClass()::query())
            ->allowedFilters(...$this->allowedFilters())
            ->allowedSorts(...$this->allowedSorts())
            ->defaultSort($this->defaultSort());

        if ($this->relations() !== []) {
            $query->with($this->relations());
        }

        $perPage = min((int) $request->integer('per_page', 15), 100);
        $resourceClass = $this->resourceClass();

        return $resourceClass::collection($query->paginate($perPage)->appends($request->query()));
    }

    protected function showResponse(Request $request, Model $model): JsonResource
    {
        $this->authorizeView($request);

        return $this->resource($model);
    }

    protected function createdResponse(Request $request, array $data): JsonResponse
    {
        $this->authorizeManage($request);

        $model = $this->modelClass()::create([
            ...$data,
            'created_by' => $request->user()?->id,
        ]);

        return $this->resource($model)->response()->setStatusCode(201);
    }

    protected function updatedResponse(Request $request, Model $model, array $data): JsonResource
    {
        $this->authorizeManage($request);

        $model->update($data);

        return $this->resource($model->refresh());
    }

    protected function deletedResponse(Request $request, Model $model): JsonResponse
    {
        $this->authorizeManage($request);

        $model->delete();

        return response()->json(null, 204);
    }

    protected function resource(Model $model): JsonResource
    {
        if ($this->relations() !== []) {
            $model->loadMissing($this->relations());
        }

        $resourceClass = $this->resourceClass();

        return new $resourceClass($model);
    }

    protected function allowedFilters(): array
    {
        return [
            AllowedFilter::callback('search', function (Builder $query, mixed $value): void {
                $search = trim((string) $value);

                if ($search === '') {
                    return;
                }

                $query->where(function (Builder $inner) use ($search): void {
                    foreach ($this->searchFields() as $field) {
                        $inner->orWhere($field, 'like', "%{$search}%");
                    }
                });
            }),
        ];
    }

    protected function allowedSorts(): array
    {
        return $this->sorts();
    }

    protected function defaultSort(): string
    {
        return 'name';
    }

    protected function relations(): array
    {
        return [];
    }

    abstract protected function modelClass(): string;

    abstract protected function resourceClass(): string;

    abstract protected function searchFields(): array;

    abstract protected function sorts(): array;
}
