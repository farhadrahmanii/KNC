<?php

use App\Http\Controllers\SharwaliTarifa;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin/login');
Route::middleware('auth')->group(function () {
    Route::get('/download-invoice/{id}', [SharwaliTarifa::class, 'downloadInvoice'])->name('download.invoice');
    Route::get('/download-Docs/{id}', [SharwaliTarifa::class, 'downloadsoldDocs'])->name('download.soldDocs');
    Route::get('/downloadWord/{id}', [SharwaliTarifa::class, 'downloadsoldWord'])->name('download.word');
});
