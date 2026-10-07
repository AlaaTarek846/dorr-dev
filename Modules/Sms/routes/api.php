<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    // Intentionally left empty — SMS routes are served through the admin guard.
});
