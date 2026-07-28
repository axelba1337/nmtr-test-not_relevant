<?php

use App\Http\Controllers\ChatbotController;
use Illuminate\Support\Facades\Route;

Route::get('/chatbot/suggestions', [ChatbotController::class, 'suggestions'])
    ->name('chatbot.suggestions');

Route::post('/chatbot/query', [ChatbotController::class, 'query'])
    ->middleware('throttle:chatbot')
    ->name('chatbot.query');
