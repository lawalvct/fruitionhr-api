<?php

use App\Modules\Content\Controllers\PublicBlogController;
use Illuminate\Support\Facades\Route;

Route::get('blog', [PublicBlogController::class, 'index'])
    ->middleware('throttle:120,1')
    ->name('v1.blog.index');
Route::get('blog/{slug}', [PublicBlogController::class, 'show'])
    ->where('slug', '[A-Za-z0-9-]+')
    ->middleware('throttle:120,1')
    ->name('v1.blog.show');
