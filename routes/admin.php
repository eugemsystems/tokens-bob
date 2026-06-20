<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::livewire('/', 'pages::admin.dashboard')->name('dashboard');
    Route::livewire('/categories', 'pages::admin.categories')->name('categories');
    Route::livewire('/tokens', 'pages::admin.tokens')->name('tokens');
    Route::livewire('/transactions', 'pages::admin.transactions')->name('transactions');
    Route::livewire('/gateways', 'pages::admin.gateways')->name('gateways');
    Route::livewire('/admins', 'pages::admin.admins')->name('admins');
});
