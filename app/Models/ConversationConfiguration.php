<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConversationConfiguration extends Model
{
    use HasFactory;

    protected $table = 'conversation_configurations';

    // Define los campos que se pueden asignar masivamente
    protected $fillable = [
        'user_phone',
        'conversation_enabled',
    ];

    // Opcionalmente, puedes deshabilitar timestamps si no los necesitas
    public $timestamps = true;
}
