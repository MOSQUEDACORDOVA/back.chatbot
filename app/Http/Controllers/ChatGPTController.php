<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use GuzzleHttp\Client;

class ChatGPTController extends Controller
{
    public function chat(Request $request)
    {
        

        $message = $request['message'];
        $apiKey = env('OPENAI_API_KEY');
        
        $client = new Client();
        try {
            $response = $client->request('POST', 'https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => 'gpt-3.5-turbo', // Cambia a 'gpt-4' si tienes acceso a este modelo
                    'messages' => [
                        ['role' => 'user', 'content' => $message],
                    ],
                ],
            ]);

            $responseData = json_decode($response->getBody(), true);
            $reply = $responseData['choices'][0]['message']['content'];

            return response()->json(['reply' => $reply]);
        } catch (\Exception $e) {
            \Log::error('Error contacting OpenAI API: ' . $e->getMessage());
            return response()->json(['error' => 'Error al comunicarse con la API'], 500);
        }
        //return response()->json(['Test' => 'Test'], 500);
    }
}
