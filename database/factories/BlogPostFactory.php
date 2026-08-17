<?php

namespace Database\Factories;

use App\Modules\Content\Models\BlogPost;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<BlogPost> */
class BlogPostFactory extends Factory
{
    protected $model = BlogPost::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = rtrim($this->faker->sentence(6), '.');

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.$this->faker->unique()->numberBetween(1, 999999),
            'excerpt' => $this->faker->sentence(14),
            'body' => '<p>'.$this->faker->paragraph(5).'</p>',
            'cover_image_url' => null,
            'status' => BlogPost::STATUS_DRAFT,
            'published_at' => null,
            // Mirrors the column default explicitly: a model built by create()
            // only carries the attributes that were inserted, so without this
            // ->views reads as null on a freshly made post.
            'views' => 0,
            'author_user_id' => null,
            'seo_title' => null,
            'seo_description' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => BlogPost::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);
    }

    /** A published post dated in the future must stay hidden from visitors. */
    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'status' => BlogPost::STATUS_PUBLISHED,
            'published_at' => now()->addWeek(),
        ]);
    }
}
