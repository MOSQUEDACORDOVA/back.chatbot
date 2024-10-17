<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id(); // ID autoincremental
            $table->string('owner')->nullable(); // Dueño del inmueble
            $table->string('property_code'); // Código de la propiedad
            $table->string('type_of_property')->nullable(); // Tipo de propiedad
            $table->string('physical_address')->nullable(); // Dirección física
            $table->string('building_complex')->nullable(); // Edificio o complejo
            $table->string('town')->nullable(); // Ciudad o pueblo
            $table->text('map')->nullable(); // Mapa 
            $table->string('bedrooms')->nullable(); // Número de habitaciones
            $table->string('bathrooms')->nullable(); // Número de baños
            $table->string('king_bed')->nullable(); // Número de camas king
            $table->string('queen_bed')->nullable(); // Número de camas queen
            $table->string('twin_bed')->nullable(); // Número de camas twin
            $table->string('sofa_bed')->nullable(); // Número de sofá cama
            $table->text('access_information')->nullable(); // Información de acceso
            $table->text('cleaning_instructions')->nullable(); // Instrucciones de limpieza
            $table->text('inspection_instructions')->nullable(); // Instrucciones de inspección
            $table->text('maintenance_instructions')->nullable(); // Instrucciones de mantenimiento
            $table->string('owner_closet')->nullable(); // Closet del propietario
            $table->string('documents')->nullable(); // Archivos/documentos relacionados (almacenados como JSON)
            $table->string('payment_hskp')->nullable(); // Pago de housekeeping
            $table->string('client_charge')->nullable(); // Cobro al cliente
            $table->timestamps(); // timestamps para created_at y updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
