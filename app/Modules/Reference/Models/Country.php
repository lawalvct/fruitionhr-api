<?php

namespace App\Modules\Reference\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'code', 'iso3', 'phone_code', 'currency_code', 'currency_name',
    'region', 'subregion', 'is_active',
])]
class Country extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function states(): HasMany
    {
        return $this->hasMany(State::class);
    }
}
