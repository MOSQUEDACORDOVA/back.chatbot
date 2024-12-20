<?php

namespace App\Services;

use OpenAI\Laravel\Facades\OpenAI;
use Illuminate\Support\Facades\Log;

class OpenAIAssistantService
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
                                        'output' => '12',
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


}
