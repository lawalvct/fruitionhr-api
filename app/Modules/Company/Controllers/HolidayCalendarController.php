<?php

namespace App\Modules\Company\Controllers;

use App\Modules\Company\Models\HolidayCalendar;
use App\Modules\Company\Requests\HolidayCalendarRequest;
use App\Modules\Company\Resources\HolidayCalendarResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;

class HolidayCalendarController extends CompanyResourceController
{
    public function index(Request $request): mixed
    {
        return $this->indexResponse($request);
    }

    public function store(HolidayCalendarRequest $request): mixed
    {
        $this->authorizeManage($request);

        $calendar = DB::transaction(function () use ($request): HolidayCalendar {
            $calendar = HolidayCalendar::create([
                ...$request->safe()->except('dates'),
                'created_by' => $request->user()?->id,
            ]);

            foreach ($request->validated('dates', []) as $date) {
                $calendar->dates()->create([
                    ...$date,
                    'created_by' => $request->user()?->id,
                ]);
            }

            return $calendar;
        });

        return $this->resource($calendar)->response()->setStatusCode(201);
    }

    public function show(Request $request, HolidayCalendar $holidayCalendar): mixed
    {
        return $this->showResponse($request, $holidayCalendar);
    }

    public function update(HolidayCalendarRequest $request, HolidayCalendar $holidayCalendar): mixed
    {
        $this->authorizeManage($request);

        DB::transaction(function () use ($request, $holidayCalendar): void {
            $holidayCalendar->update($request->safe()->except('dates'));

            if ($request->has('dates')) {
                $holidayCalendar->dates()->delete();

                foreach ($request->validated('dates', []) as $date) {
                    $holidayCalendar->dates()->create([
                        ...$date,
                        'created_by' => $request->user()?->id,
                    ]);
                }
            }
        });

        return $this->resource($holidayCalendar->refresh());
    }

    public function destroy(Request $request, HolidayCalendar $holidayCalendar): mixed
    {
        return $this->deletedResponse($request, $holidayCalendar);
    }

    protected function modelClass(): string
    {
        return HolidayCalendar::class;
    }

    protected function resourceClass(): string
    {
        return HolidayCalendarResource::class;
    }

    protected function searchFields(): array
    {
        return ['name'];
    }

    protected function sorts(): array
    {
        return ['name', 'year', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return '-year';
    }

    protected function relations(): array
    {
        return ['dates'];
    }

    protected function allowedFilters(): array
    {
        return [...parent::allowedFilters(), AllowedFilter::exact('year')];
    }
}
