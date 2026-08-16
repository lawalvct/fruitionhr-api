<?php

namespace App\Modules\Content\Requests;

use App\Modules\Content\Models\BlogPost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBlogPostRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'min:3', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => ['sometimes', 'string', 'max:200000'],
            'cover_image_url' => ['nullable', 'url', 'max:2048'],
            'cover_image_path' => ['nullable', 'string', 'max:2048', 'starts_with:blog/covers/'],
            'status' => ['sometimes', Rule::in([BlogPost::STATUS_DRAFT, BlogPost::STATUS_PUBLISHED])],
            'published_at' => ['nullable', 'date'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers and hyphens.',
        ];
    }
}
