<?php

namespace App\Services;

use OpenAI\Laravel\Facades\OpenAI;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIAssistantServiceDamaris
{

    // Crear un hilo y enviar el mensaje del usuario
    public function handleMessage($message, $assistantId, $threadId, $role)
    {
        // Agregar un mensaje en el hilo
        OpenAI::threads()->messages()->create($threadId,[
            'role' => $role,
            'content' => $message,
        ]);

        // Iniciar un Run
        $stream = OpenAI::threads()->runs()->createStreamed($threadId,[
            'assistant_id' => $assistantId,
        ]);

        $responseMessage = null; // Variable para guardar el mensaje generado

        do{
            foreach($stream as $response){
        
                switch($response->event){
                    case 'thread.run.created':
                    case 'thread.run.queued':
                    case 'thread.run.completed':
                    case 'thread.run.cancelling':
                        $run = $response->response;
                        break;
                    case 'thread.run.expired':
                    case 'thread.run.cancelled':
                    case 'thread.run.failed':
                        $run = $response->response;
                        break 3;
                    case 'thread.message.completed':
                        
                        Log::info('El Run generó un mensaje: '.$run->status.' y ' . json_encode($response->response));
                        // Aquí es donde capturamos el mensaje generado por el asistente
                        $messageData = $response->response;
                        $responseMessage = $messageData->content[0]->text->value;
                        break;
                    case 'thread.run.requires_action':

                        Log::info('El Run requiere una acción: '.$run->status.' y ' . json_encode($response->response));
                        $responseData = $response->response;
                        $contenedorFuncion = $responseData->requiredAction->submitToolOutputs->toolCalls[0];
                        
                        //esto se puede usar luego para identificar diferentes funciones
                        $functionNombre = $contenedorFuncion->function->name;
                        //end

                        $functionArguments = json_decode($contenedorFuncion->function->arguments, true);
                        
                        $propertyId = $functionArguments['propertyId'];
                        $infoInmueble = $this->getPropertyDetails($propertyId);
                        // Accede al primer toolCall (si hay más, puedes recorrerlos)
                        $toolCall = $responseData->requiredAction->submitToolOutputs->toolCalls[0];
                        
                        // Overwrite the stream with the new stream started by submitting the tool outputs
                        $stream = OpenAI::threads()->runs()->submitToolOutputsStreamed(
                            threadId: $run->threadId,
                            runId: $run->id,
                            parameters: [
                                'tool_outputs' => [
                                    [
                                        'tool_call_id' => $toolCall->id,
                                        'output' => $infoInmueble,
                                    ]
                                ],
                            ]
                        );
                        break;
                }
            }
        } while ($run->status != "completed");

        return $responseMessage; // Devuelve la información del Run
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
                //devolver como string 
                Log::info('Datos de propiedad: ', $response->json());
                return json_encode($data);
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
