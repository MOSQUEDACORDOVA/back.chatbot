<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Twilio\Rest\Client as ClientWhatsApp;
use GuzzleHttp\Client;
use App\Models\ConversationHistory; // Modelo para la tabla del historial
use App\Models\ConversationConfiguration;

use Illuminate\Support\Facades\Http; // Asegúrate de importar el facade Http

class WhatsAppController extends Controller
{

    public function receiveMessage(Request $request)
    {
        // Registrar toda la información que llega desde Baileys
        \Log::info('Datos recibidos desde Baileys: ' . json_encode($request->all()));
    
        // Verificar si el mensaje está presente
        if ($request->has('Body') and $request->input('From')!='status@broadcast') {
            $from = $request->input('From'); // Número de quien envía el mensaje
            $body = $request->input('Body') ?? 'Mensaje vacío'; // Cuerpo del mensaje recibido
            $name = $request->input('Name', 'Desconocido'); // Obtener el nombre, o usar 'Desconocido' si no está presente

            // Guardar el mensaje del usuario en la base de datos
            $this->storeMessage($from, 'user', $body, $name);

            // Log de éxito
            \Log::info("Mensaje recibido de $from: $body para enviar a ChatGPT");
    
            // Verificar si el bot está APAGADO para este número
            if ($this->isBotOffForNumber($from)) {
                \Log::info("El bot está APAGADO para el número $from. No se procesará el mensaje.");
                return response()->json(['status' => 'El bot está APAGADO para el número $from. No se procesará el mensaje.'], 403);
            }

            // Llamar a ChatGPT
            $this->chatGpt($body, $from);
            
            return response()->json(['status' => 'Message received and processed']);
        }
    
        // Log de no válido
        \Log::warning("Mensaje no válido recibido: " . json_encode($request->all()));
    
        return response()->json(['status' => 'No valid message received'], 400);
    }
    
    public function chatGpt(string $promt, string $from)
    {
        
        $apiKey = env('OPENAI_API_KEY');
        
        $client = new Client();
        // Obtener el historial de mensajes desde la base de datos
        $chatHistory = $this->getChatHistory($from);

        // Añadir el mensaje del sistema desde el archivo de configuración
        $principal_system_message = [
            'role' => 'system',
            'content' => config('openai.principal_system_message'), // Obtener el mensaje desde el archivo de configuración
        ];

        $system_message_informacion_de_los_productos = [
            'role' => 'system',
            'content' => config('openai.system_message_informacion_de_los_productos'), // Obtener el mensaje desde el archivo de configuración
        ];

        $objetivo_principal = [
            'role' => 'system',
            'content' => config('openai.objetivo_principal'), // Obtener el mensaje desde el archivo de configuración
        ];

        $instrucciones_principales = [
            'role' => 'system',
            'content' => config('openai.instrucciones_principales'), // Obtener el mensaje desde el archivo de configuración
        ];

        $instrucciones_tecnicas = [
            'role' => 'system',
            'content' => config('openai.instrucciones_tecnicas'), 
        ];

        
        // Añadir el mensaje del sistema al historial de chat
        $chatHistory[] = $principal_system_message;
        $chatHistory[] = $system_message_informacion_de_los_productos;
        $chatHistory[] = $objetivo_principal;
        $chatHistory[] = $instrucciones_principales;
        $chatHistory[] = $instrucciones_tecnicas;

        // Añadir el nuevo mensaje del usuario al historial
        $chatHistory[] = ['role' => 'user', 'content' => $promt];

        // Generar log del historial de chat
        \Log::info('Historial de chat de ChatGPT: ' . json_encode($chatHistory));


        try {
            $response = $client->request('POST', 'https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => 'gpt-4o', // Cambiar a 'gpt-4' si es necesario
                    'messages' => $chatHistory, // Enviar el historial completo
                ],
            ]);

            $responseData = json_decode($response->getBody(), true);
            $replyContent = $responseData['choices'][0]['message']['content'];

            // Registrar la respuesta para debugging
            \Log::info('Respuesta de ChatGPT: ' . $replyContent);

            // Eliminar delimitadores de bloque de código como ```json, ```javascript o simplemente ```
            $replyContent = preg_replace('/^```[\w]*|```$/m', '', $replyContent);

            // Extraer el bloque JSON de la respuesta (si tiene texto adicional)
            preg_match('/\{.*\}$/s', $replyContent, $matches);
            
            $cleanedContent = $matches[0] ?? '';

            // Intentar decodificar el contenido como JSON
            $replyData = json_decode($cleanedContent, true);

            // Registrar el contenido decodificado para debugging
            \Log::info('Contenido decodificado en $replyData: ' . print_r($replyData, true));

            // Verificar si el formato es JSON y contiene mensajes
            if (isset($replyData['mensajes']) && is_array($replyData['mensajes'])) {
                // Iterar sobre los mensajes y enviarlos individualmente
                foreach ($replyData['mensajes'] as $msg) {
                    $messageType = $msg['type'];
                    $messageContent = $msg['message'];
                    $caption = $msg['caption'] ?? null; // Opcional

                    // Lógica de envío basada en el tipo de mensaje
                    $this->sendWhatsAppMessage($messageContent, $from, $messageType, $caption);

                    // Guardar cada mensaje en la base de datos
                    //importante, la bd no entiende con tipo de mensaje solo texto, corregir
                    $this->storeMessage($from, 'assistant', $messageContent, 'assistant');
                }
            } else {
                // Si la respuesta no es JSON o no tiene múltiples mensajes, envíala como un solo mensaje
                $this->sendWhatsAppMessage("Perdona, tenemos un problema técnico, pronto estaremos de vuelta.", $from);
                $this->storeMessage($from, 'assistant', $replyContent, 'assistant');
                // Registrar la respuesta para debugging
                //se debe enviar un mensaje de error al admin
                \Log::error('ChatGPT no respondió con un JSON ' . $replyContent);
            }

            if (isset($replyData['acciones']) && is_array($replyData['acciones'])) {
                foreach ($replyData['acciones'] as $action) {
                    $actionType = $action['tipo'];
                    $formattedFrom = '+' . explode('@', $from)[0];

                    $actionMessage = $action['message']." | Número de cliente: ".$formattedFrom;
                    
                    if ($actionType === 'solicitud_de_intervencion_humana') {
                        // Notificar a los agentes o realizar otra acción necesaria
                        \Log::info('Intervención humana requerida: ' . $actionMessage . 'Cliente: ' . $formattedFrom);
                        
                        // Aquí podrías enviar un mensaje a un agente, registrar una alerta, etc.
                        $this->sendWhatsAppMessage($actionMessage, "51945692831@c.us");
                    }
                }
            }
            return response()->json(['reply' => $replyContent]);

        } catch (\Exception $e) {

            \Log::error('Error contacting OpenAI API: ' . $e->getMessage());
            
            $reply = "Lo siento, parece que tu consulta es muy extensa, ¿podrias darme más detalles por favor?";
            
            // Guardar la respuesta del asistente en la base de datos
            $this->storeMessage($from, 'assistant', $reply, 'assistant');

            // Enviar la respuesta vía WhatsApp
            $this->sendWhatsAppMessage($reply, $from);

            return response()->json(['error' => 'Error al comunicarse con la API'], 500);

        }
    }

    public function sendWhatsAppMessage(string $message, string $recipient, string $type = 'text', string $caption = null)
    {
        try {
            // URL de tu API de Baileys
            $url = env('BAILEYS_API_URL') . '/send'; // Define esta URL en tu archivo .env

            // Preparar la solicitud
            $response = Http::post($url, [
                'chatId' => $recipient,
                'message' => $message,
                'type' => $type,
                'caption' => $caption, // El caption es opcional
            ]);

            // Verifica si la respuesta fue exitosa
            if ($response->successful()) {
                return response()->json(['status' => 'success', 'message' => 'Mensaje enviado']);
            } else {
                \Log::error("Error sending WhatsApp message: " . $response->body());
                return response()->json(['error' => 'Failed to send message'], 500);
            }
        } catch (\Exception $e) {
            // Maneja el error según sea necesario
            \Log::error("Error sending WhatsApp message: " . $e->getMessage());
            return response()->json(['error' => 'Failed to send message'], 500);
        }
    }

    // Función para almacenar mensajes en la base de datos
    private function storeMessage(string $userPhone, string $role, string $message, string $name)
    {
        ConversationHistory::create([
            'user_phone' => $userPhone,
            'role' => $role,
            'message' => $message,
            'name' => $name,
        ]);
    }

    // Función para recuperar el historial de conversación de la base de datos
    private function getChatHistory(string $userPhone)
    {
        // Obtener todos los mensajes previos de este usuario ordenados por creación
        $history = ConversationHistory::where('user_phone', $userPhone)
            ->orderBy('created_at', 'asc')
            ->get(['role', 'message']);

        // Convertir el formato de los mensajes para enviarlos a la API de OpenAI
        return $history->map(function ($message) {
            return [
                'role' => $message->role,
                'content' => $message->message,
            ];
        })->toArray();
    }

    private function isBotOffForNumber($from)
    {

        // Registrar el número de teléfono recibido
        \Log::info("Verificando configuración de conversación para el número: $from");

        // Buscar configuración de conversación para el número
        $config = ConversationConfiguration::where('user_phone', $from)->first();

        if ($config) {
            \Log::info("Configuración encontrada para $from: conversación habilitada = " . ($config->conversation_enabled ? 'true' : 'false'));
        } else {
            \Log::info("No se encontró configuración para el número: $from");
        }

        // Si existe y está deshabilitado, devolver true; de lo contrario, false
        return $config && !$config->conversation_enabled;
    }
}
