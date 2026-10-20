<?php

use Illuminate\Support\Facades\Route;
use KareliaLabs\Tickets\Http\Controllers\CheckInController;
use KareliaLabs\Tickets\Http\Controllers\OrdersController;
use KareliaLabs\Tickets\Http\Controllers\TicketsController;

Route::prefix('tickets')->name('karelia-tickets.')->group(function () {
    Route::get('/', [TicketsController::class, 'index'])->name('index');

    Route::get('check-in', [CheckInController::class, 'index'])->name('check-in');
    Route::post('check-in', [CheckInController::class, 'store'])->name('check-in.store');
    Route::post('check-in/undo', [CheckInController::class, 'undo'])->name('check-in.undo');

    Route::get('events/{event}', [TicketsController::class, 'show'])->name('show');
    Route::get('events/{event}/attendees.csv', [TicketsController::class, 'export'])->name('export');
    Route::post('events/{event}/complimentary', [OrdersController::class, 'complimentary'])->name('complimentary');

    Route::post('orders/{order}/refund', [OrdersController::class, 'refund'])->name('refund');
    Route::post('orders/{order}/cancel', [OrdersController::class, 'cancel'])->name('cancel');
    Route::post('orders/{order}/resend', [OrdersController::class, 'resend'])->name('resend');
});
