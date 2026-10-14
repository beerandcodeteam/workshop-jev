<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::livewire('/watch', 'pages::watch')->name('watch');

Route::livewire('/inbox', 'pages::inbox')->name('inbox');

Route::livewire('/screening', 'pages::screening')->name('screening');
