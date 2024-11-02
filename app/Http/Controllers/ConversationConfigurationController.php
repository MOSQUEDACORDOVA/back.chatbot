<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ConversationConfiguration;

class ConversationConfigurationController extends Controller
{
    public function toggleBot(Request $request)
    {
        $request->validate([
            'user_phone' => 'required|string',
            'enabled' => 'required|boolean',
        ]);

        $userPhone = $request->input('user_phone');
        $enabled = $request->input('enabled');

        // Busca o crea una configuración para el número proporcionado
        $config = ConversationConfiguration::firstOrCreate(
            ['user_phone' => $userPhone],
            ['conversation_enabled' => $enabled]
        );

        // Actualiza el estado de conversación si ya existía
        if (!$config->wasRecentlyCreated) {
            $config->update(['conversation_enabled' => $enabled]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Bot status updated successfully',
            'data' => [
                'user_phone' => $userPhone,
                'conversation_enabled' => $enabled,
            ],
        ]);
    }
}
