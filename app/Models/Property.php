<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Property extends Model
{
    use HasFactory;
    
    // Definir las columnas que pueden ser asignadas masivamente
    protected $fillable = [
        'owner',
        'property_code',
        'type_of_property',
        'physical_address',
        'building_complex',
        'town',
        'map',
        'bedrooms',
        'bathrooms',
        'king_bed',
        'queen_bed',
        'twin_bed',
        'sofa_bed',
        'access_information',
        'cleaning_instructions',
        'inspection_instructions',
        'maintenance_instructions',
        'owner_closet',
        'documents',
        'payment_hskp',
        'client_charge'
    ];
}
