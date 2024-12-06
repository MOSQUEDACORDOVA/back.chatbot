<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Twilio\Rest\Client as ClientWhatsApp;
use GuzzleHttp\Client;
use App\Models\ConversationHistory; // Modelo para la tabla del historial
use Illuminate\Support\Facades\Http; // Asegúrate de importar el facade Http
use App\Models\ConversationConfiguration;

class WhatsAppController extends Controller
{

    public function receiveMessage(Request $request)
    {
        // Registrar toda la información que llega desde Baileys
        \Log::info('Datos recibidos desde Baileys: ' . json_encode($request->all()));
    
        // Verificar si el mensaje está presente
        if ($request->has('Body') and $request->input('From')!='status@broadcast') {
            $from = $request->input('From'); // Número de quien envía el mensaje
            $body = $request->input('Body') ?? 'Ve al grano y ofrece el producto'; // Cuerpo del mensaje recibido
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

        // Inicializar $chatHistory como un arreglo de mensajes
        
        $systemMessages = [
            [
                'role' => 'system',
                'content' => config('openai.principal_system_message'), // Obtener el mensaje desde el archivo de configuración
            ],
            [
                'role' => 'system',
                'content' => config('openai.system_message_informacion_de_los_productos'), // Obtener el mensaje desde el archivo de configuración
            ],
            [
                'role' => 'system',
                'content' => config('openai.objetivo_principal'), // Obtener el mensaje desde el archivo de configuración
            ],
            [
                'role' => 'system',
                'content' => config('openai.instrucciones_principales'), // Obtener el mensaje desde el archivo de configuración
            ],
            [
                'role' => 'system',
                'content' => config('openai.instrucciones_tecnicas'), 
            ],
        ];

        // Obtener el historial de mensajes desde la base de datos
        $userHistory = $this->getChatHistory($from);

        
        // Si no hay historial, agregar el mensaje genérico
        // Determinar si es el primer mensaje enviado por el usuario
        if (count($userHistory) === 1) {
            // Registrar el historial vacio en los logs para debugging
            \Log::info('Es el primer mensaje de chat recuperado para el usuario userHistory ' . $from . ': ' . print_r($userHistory, true));

            $userHistory[] = [
                'role' => 'assistant',
                'content' => 'Ve al grano y ofrece el producto.',
            ];
        }

        // Obtener el historial de mensajes desde la base de datos y combinarlo con los mensajes del sistema
        $chatHistory = array_merge($userHistory, $systemMessages);

        // Añadir el nuevo mensaje del usuario al historial
        //$chatHistory[] = ['role' => 'user', 'content' => $promt];

        // Generar log del historial de chat
        \Log::info('Historial de chat antes del primer promt de ChatGPT: ' . json_encode($chatHistory));


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

            // Registrar la respuesta para debugging
            \Log::info('Respuesta de ChatGPT: ' . print_r($responseData, true));

            // Obtener el contenido del mensaje de respuesta
            $replyContent = $responseData['choices'][0]['message']['content'];

            // Eliminar delimitadores de bloque de código y extraer el bloque JSON
            preg_match('/\{.*\}/s', $replyContent, $matches);
            $cleanedContent = $matches[0] ?? '';

            // Intentar decodificar el contenido como JSON una sola vez
            $replyData = json_decode($cleanedContent, true);

            // Registrar el contenido decodificado para debugging
            \Log::info('Contenido decodificado en $replyData: ' . print_r($replyData, true));

            // Verificar si el formato es JSON y contiene mensajes
            if (isset($replyData['mensajes']) && is_array($replyData['mensajes'])) {
                // Iterar sobre los mensajes y enviarlos individualmente
                foreach ($replyData['mensajes'] as $msg) {

                    $messageType = $msg['type'];
                    // Lógica para determinar el contenido del mensaje
                    if (in_array($messageType, ['video', 'audio', 'image'])) {
                        // Si el tipo es video, audio o imagen, la URL se encuentra en 'message'
                        $messageContent = $msg['url'];
                    } else {
                        // Para otros tipos, el contenido del mensaje es simplemente el texto
                        $messageContent = $msg['message'];
                    }
                    $caption = $msg['caption'] ?? null; // Opcional

                    // Lógica de envío basada en el tipo de mensaje
                    $this->sendWhatsAppMessage($messageContent, $from, $messageType, $caption);

                    // Guardar cada mensaje en la base de datos
                    //importante, la bd no entiende con tipo de mensaje solo texto, corregir
                    $this->storeMessage($from, 'assistant', $messageContent, 'assistant');
                }
            } else {
                // Si la respuesta no es JSON o no tiene múltiples mensajes, envíala como un solo mensaje
                $this->sendWhatsAppMessage("Hola Linda, en unos minutos te envío toda la información.", $from);
                $this->storeMessage($from, 'assistant', $replyContent, 'assistant');
                // Registrar la respuesta para debugging
                \Log::error('ChatGPT no respondió con un JSON ' . $replyContent);

                //se debe enviar un mensaje de error al admin
                $solicitudHuman = 'El cliente: '.$from.' Necesita ayuda. . .';
                $this->sendWhatsAppMessage($solicitudHuman, "51969647875@c.us");
                \Log::error('Se solicitó ayuda al administrador ');

            }

            if (isset($replyData['acciones']) && is_array($replyData['acciones'])) {
                foreach ($replyData['acciones'] as $action) {
                    $actionType = $action['type'];
                    $formattedFrom = '+' . explode('@', $from)[0];

                    $actionMessage = $action['message']." | Número de cliente: ".$formattedFrom;
                    
                    \Log::info('Se detectó una solicitud de acción: ' . $actionMessage . 'Cliente: ' . $formattedFrom);
                        
                    if ($actionType === 'solicitud_de_intervencion_humana') {
                        // Notificar a los agentes o realizar otra acción necesaria
                        \Log::info('Intervención humana requerida: ' . $actionMessage . 'Cliente: ' . $formattedFrom);
                        
                        // Aquí podrías enviar un mensaje a un agente, registrar una alerta, etc.
                        $this->sendWhatsAppMessage($actionMessage, "51969647875@c.us");
                    }
                }
            }
            return response()->json(['reply' => $replyContent]);

        } catch (\Exception $e) {

            \Log::error('Error contacting OpenAI API: ' . $e->getMessage());
            
            $reply = "Lo siento, tu consulta es muy extensa, ¿podrias darme más detalles por favor?";
            
            // Guardar la respuesta del asistente en la base de datos
            $this->storeMessage($from, 'assistant', $reply, 'assistant');

            // Enviar la respuesta vía WhatsApp
            $this->sendWhatsAppMessage($reply, $from);

            //se debe enviar un mensaje de error al admin
            $solicitudHuman = 'El cliente: '.$from.' Necesita ayuda. . .';
            $this->sendWhatsAppMessage($solicitudHuman, "51969647875@c.us");

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
    private function storeMessage(string $userPhone, string $role, string $message, ?string $name = null)
    {
        // Construir el arreglo con los valores obligatorios
        $data = [
            'user_phone' => $userPhone,
            'role' => $role,
            'message' => $message,
            'name' => $name,
        ];

        

        // Crear el registro en la base de datos
        ConversationHistory::create($data);
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
