<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WhatsAppController;
use App\Http\Controllers\ConversationConfigurationController;
use App\Http\Controllers\ChatGPTController; //SOLO PARA PRUEBAS

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/webhook/whatsapp', function (Request $request) {

    $verifyToken = 'mi_token_secreto'; // Token definido por ti.

    // Verifica que el token recibido coincida
    if ($request->input('hub_mode') === 'subscribe' &&
        $request->input('hub_verify_token') === $verifyToken) {
        return response($request->input('hub_challenge'), 200); // Devuelve el 'hub_challenge'
    }

    return response('Verificación fallida', 403); // Si no coincide, devuelve un error 403.
});

Route::post('/webhook/whatsapp', [WhatsAppController::class, 'mensajeRecibido']);

Route::post('/webhook/bot-toggle', [ConversationConfigurationController::class, 'toggleBot']);

Route::post('/chatgpt', [ChatGPTController::class, 'chat']); //solo para pruebas 
