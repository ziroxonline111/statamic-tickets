<?php

use Illuminate\Support\Facades\Route;
use KareliaLabs\Tickets\Http\Controllers\CheckoutController;
use KareliaLabs\Tickets\Http\Controllers\WebhookController;

// Served under /!/statamic-tickets/ on the front end.

Route::post('checkout', [CheckoutController::class, 'checkout'])->middleware('throttle:20,1');
Route::get('return/{order}/{token}', [CheckoutController::class, 'return']);
Route::get('cancel/{order}/{token}', [CheckoutController::class, 'cancel']);
Route::get('orders/{order}/{token}', [CheckoutController::class, 'show']);
Route::get('orders/{order}/{token}/event.ics', [CheckoutController::class, 'calendar']);

// Stripe cannot send a CSRF token; the signature check takes its place.
Route::post('webhook', WebhookController::class)->withoutMiddleware(array_values(array_filter([
    'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
    'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
    'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
    'App\Http\Middleware\VerifyCsrfToken',
], 'class_exists')));
