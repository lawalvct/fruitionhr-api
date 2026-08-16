<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\PlatformActivity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PlatformActivityService
{
    private const SENSITIVE_KEY_FRAGMENTS = [
        'authorization',
        'cookie',
        'password',
        'remember_token',
        'secret',
        'token',
    ];

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(
        Request $request,
        string $action,
        string $subjectType,
        ?int $subjectId,
        string $subjectLabel,
        array $before = [],
        array $after = [],
        ?string $reason = null,
    ): PlatformActivity {
        return PlatformActivity::query()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label' => $subjectLabel,
            'before_values' => $this->nullableValues($this->sanitize($before)),
            'after_values' => $this->nullableValues($this->sanitize($after)),
            'reason' => $this->nullableText($reason),
            'ip_address' => $request->ip(),
            'user_agent' => $this->nullableText(
                Str::limit((string) $request->userAgent(), 1024, '')
            ),
        ])->load('actor:id,name,email');
    }

    /** @return Collection<int, PlatformActivity> */
    public function recent(int $limit = 8): Collection
    {
        return PlatformActivity::query()
            ->with('actor:id,name,email')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<PlatformActivity>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = PlatformActivity::query()->with('actor:id,name,email');

        if (($filters['action'] ?? null) !== null) {
            $query->where('action', $filters['action']);
        }

        if (($filters['subject_type'] ?? null) !== null) {
            $query->where('subject_type', $filters['subject_type']);
        }

        if (($filters['actor_id'] ?? null) !== null) {
            $query->where('actor_user_id', $filters['actor_id']);
        }

        if (($filters['from'] ?? null) !== null) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (($filters['to'] ?? null) !== null) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        $direction = ($filters['sort'] ?? '-created_at') === 'created_at' ? 'asc' : 'desc';

        return $query
            ->orderBy('created_at', $direction)
            ->orderBy('id', $direction)
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->appends($filters);
    }

    /** @param array<string, mixed> $values */
    private function sanitize(array $values): array
    {
        $sanitized = [];

        foreach ($values as $key => $value) {
            if (Str::contains(
                Str::lower((string) $key),
                self::SENSITIVE_KEY_FRAGMENTS,
            )) {
                continue;
            }

            $sanitized[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $sanitized;
    }

    private function nullableText(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $values */
    private function nullableValues(array $values): ?array
    {
        return $values === [] ? null : $values;
    }
}
