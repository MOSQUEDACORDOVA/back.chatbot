<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $fillable = [
        'name',
        'whatsapp_number',
        'insurance_expiry_date',
    ];
    
    use HasFactory;
}
