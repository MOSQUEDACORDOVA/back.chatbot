<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\OpenAIAssistantService;

class WeatherAssistantController extends Controller
{
    protected $assistantService;

    public function __construct(OpenAIAssistantService $assistantService)
    {
        $this->assistantService = $assistantService;
    }

    public function askWeather(Request $request)
    {
        $message = $request->input('message');

        // Enviar el mensaje al asistente
        $run = $this->assistantService->handleMessage($message,'asst_2y7Q2UWyFD8F6wW2iGkKKn8w','thread_N31serrIHpWOYAGHux4pBFnv', 'user');

        return response()->json(['message' => $run]);
    }
}
