<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Twilio\Rest\Client as ClientWhatsApp;
use GuzzleHttp\Client;
use App\Models\ConversationHistory; // Modelo para la tabla del historial
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;


class TwilioWhatsAppController extends Controller
{
    public function receiveMessage(Request $request)
    {
        // Registrar toda la información que llega desde Twilio
        \Log::info('Datos recibidos desde Twilio: ' . json_encode($request->all()));

        // Verificar si es un mensaje de texto normal (SMS/WhatsApp) y no un evento de conversación
        if ($request->has('SmsMessageSid')) {
            $from = $request->input('From', $request->input('Author')); // Asignar 'From' o 'Author' // Número de quien envía el mensaje
            $name = $request->input('ProfileName', null); // Nombre del remitente, si está disponible
            $body = $request['Body']; // Cuerpo del mensaje recibido

            // Guardar el mensaje del usuario en la base de datos
            $this->storeMessage($from, 'user', $body, $name);

            // Llamar a ChatGPT
            $this->chatGpt($body, $from);

            return response()->json(['status' => 'Message received and processed']);
        }
        
    }

    public function chatGpt(string $promt, string $from)
    {
        
        $apiKey = env('OPENAI_API_KEY');
        
        $client = new Client();
        // Obtener el historial de mensajes desde la base de datos
        $chatHistory = $this->getChatHistory($from);

        // Añadir el nuevo mensaje del usuario al historial
        $chatHistory[] = ['role' => 'user', 'content' => $promt];

        // Añadir el mensaje del sistema desde el archivo de configuración
        $systemMessage = [
            'role' => 'system',
            'content' => config('openai.system_message'), // Obtener el mensaje desde el archivo de configuración
        ];

        // Añadir el mensaje del sistema al historial de chat
        $chatHistory[] = $systemMessage;
        

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
            $reply = $responseData['choices'][0]['message']['content'];

            // Registrar la respuesta para debugging
            \Log::info('Respuesta de ChatGPT: ' . $reply);

            // Verificar si ChatGPT solicitó una consulta 
            if (preg_match('/propertyId\s*[:=]\s*["\'\s]*([A-Za-z0-9+]+)["\'\s]*[^a-zA-Z0-9]*\s*/', $reply, $matches)) {
                $condition = $matches[1];
                \Log::info('El valor de propertyId es: ' . $condition);

                try{
                    $propiedadInfo = $this->getPropertyDetails($condition);
                    \Log::info('Respuesta del servicio externo: ' . json_encode($propiedadInfo));

                    $chatHistory[] = ['role' => 'system', 'content' => 'Esta es la información que tenemos:' . json_encode($propiedadInfo)];

                } catch (\Exception $e) {
                    \Log::error('Error en la base de datos: ' . $e->getMessage());
                    $chatHistory[] = ['role' => 'system', 'content' => 'SYSTEMA-666: Dile al usuario que tienes un problema de conexion con nuestros servicios'];
                }
                

                // Volver a llamar a ChatGPT para que genere una respuesta utilizando esos datos
                $response = $client->request('POST', 'https://api.openai.com/v1/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => 'gpt-3.5-turbo',
                        'messages' => $chatHistory,
                    ],
                ]);
                $responseData = json_decode($response->getBody(), true);
                $reply = $responseData['choices'][0]['message']['content'];
                \Log::info('ChatGPT le responde al usuario: '.$reply);
            }else{
                \Log::info('No hubo propertyId, ChatGPT dijo: ' . $reply);
            }

            // Guardar la respuesta del asistente en la base de datos
            $this->storeMessage($from, 'assistant', $reply);

            // Enviar la respuesta vía WhatsApp
            $this->sendWhatsAppMessage($reply, $from);

            return response()->json(['reply' => $reply]);

        } catch (\Exception $e) {

            \Log::error('Error contacting OpenAI API: ' . $e->getMessage());
            
            $reply = "Lo siento, tu consulta es muy extensa, ¿podrias darme más detalles por favor?";
            
            // Guardar la respuesta del asistente en la base de datos
            $this->storeMessage($from, 'assistant', $reply);

            // Enviar la respuesta vía WhatsApp
            $this->sendWhatsAppMessage($reply, $from);

            return response()->json(['error' => 'Error al comunicarse con la API'], 500);

        }
    }

    public function sendWhatsAppMessage(string $message, string $recipient)
    {
        try {
            $twilio_whatsapp_number = env('TWILIO_WHATSAPP_NUMBER');
            $account_sid = env("TWILIO_SID");
            $auth_token = env("TWILIO_AUTH_TOKEN");

            $client = new ClientWhatsApp($account_sid, $auth_token);
            return $client->messages->create($recipient, [
                'from' => "whatsapp:$twilio_whatsapp_number",
                'body' => $message,
            ]);
        } catch (\Exception $e) {
            // Maneja el error según sea necesario
            \Log::error("Error sending WhatsApp message: " . $e->getMessage());
            return response()->json(['error' => 'Failed to send message'], 500);
        }
    }

    // Función para almacenar mensajes en la base de datos
    private function storeMessage(string $userPhone, string $role, string $message, ?string $name = null)
    {
        ConversationHistory::create([
            'user_phone' => $userPhone,
            'name' => $name,
            'role' => $role,
            'message' => $message,
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

    private function getPropertyDetails($propertyId)
    {
        // Construir la URL con el propertyId
        $url = "http://qmsapi-b0g9adbfbncygua0.canadacentral-01.azurewebsites.net/api/Properties?propertyId={$propertyId}";

        try {
            // Realizar la solicitud GET
            $response = Http::get($url);

            // Verificar si la respuesta es exitosa
            if ($response->successful()) {
                // Obtener los datos de la respuesta
                $data = $response->json();
                return $data;
            } elseif ($response->clientError()) {
                // Error del lado del cliente (4xx)
                Log::error('Error del cliente al obtener detalles de la propiedad', ['propertyId' => $propertyId, 'status' => $response->status(), 'response' => $response->body()]);
                return response()->json(['error' => 'Error del cliente al obtener la información del propertyId'], 400);
            } elseif ($response->serverError()) {
                // Error del servidor (5xx)
                Log::error('Error del servidor al obtener detalles de la propiedad', ['propertyId' => $propertyId, 'status' => $response->status(), 'response' => $response->body()]);
                return response()->json(['error' => 'Error del servidor al obtener la información del propertyId'], 500);
            } else {
                // Otro tipo de error no esperado
                Log::error('Respuesta inesperada al obtener detalles de la propiedad', ['propertyId' => $propertyId, 'status' => $response->status(), 'response' => $response->body()]);
                return response()->json(['error' => 'Error inesperado al obtener la información del propertyId'], 500);
            }
        } catch (\Exception $e) {
            // Manejo de excepciones (errores de red, no disponible, etc.)
            Log::error('Error de conexión al obtener detalles de la propiedad', [
                'propertyId' => $propertyId,
                'exception' => $e->getMessage()
            ]);
            return response()->json(['error' => 'No se pudo conectar al servicio de propiedades'], 503);
        }
    }

}
