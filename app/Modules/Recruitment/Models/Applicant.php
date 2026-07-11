<?php

namespace App\Modules\Recruitment\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\ApplicantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['first_name', 'last_name', 'email', 'phone', 'city', 'state', 'linkedin_url', 'resume_path', 'created_by'])]
class Applicant extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected static string $factory = ApplicantFactory::class;

    public function applications(): HasMany { return $this->hasMany(Application::class); }
    public function getFullNameAttribute(): string { return trim($this->first_name.' '.$this->last_name); }
}
