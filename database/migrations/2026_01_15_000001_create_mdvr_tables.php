<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Devices table
        Schema::create('mdvr_devices', function (Blueprint $table) {
            $table->id();
            $table->string('phone_number', 20)->unique()->comment('Terminal phone/SIM number');
            $table->string('terminal_id', 30)->nullable()->comment('Device terminal ID');
            $table->string('manufacturer_id', 11)->nullable()->comment('Manufacturer ID');
            $table->string('terminal_model', 30)->nullable()->comment('Device model');
            $table->string('plate_number', 20)->nullable()->comment('Vehicle plate number');
            $table->tinyInteger('plate_color')->default(0)->comment('Plate color code');
            $table->string('imei', 15)->nullable()->comment('Device IMEI');
            $table->string('firmware_version', 50)->nullable()->comment('Firmware version');
            $table->string('auth_code', 50)->nullable()->comment('Authentication code');
            $table->boolean('is_online')->default(false)->comment('Online status');
            $table->timestamp('last_heartbeat_at')->nullable()->comment('Last heartbeat time');
            $table->timestamp('registered_at')->nullable()->comment('Registration time');
            $table->string('last_ip', 45)->nullable()->comment('Last known IP address');
            $table->timestamps();

            $table->index('phone_number');
            $table->index('plate_number');
            $table->index('is_online');
        });

        // Device locations table
        Schema::create('mdvr_device_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('mdvr_devices')->onDelete('cascade');
            $table->decimal('latitude', 10, 6)->comment('Latitude');
            $table->decimal('longitude', 11, 6)->comment('Longitude');
            $table->smallInteger('altitude')->default(0)->comment('Altitude in meters');
            $table->decimal('speed', 5, 1)->default(0)->comment('Speed in km/h');
            $table->smallInteger('direction')->default(0)->comment('Direction 0-359 degrees');
            $table->integer('mileage')->default(0)->comment('Mileage in 0.1km');
            $table->boolean('acc_on')->default(false)->comment('ACC status');
            $table->boolean('located')->default(true)->comment('GPS located');
            $table->boolean('vehicle_running')->default(false)->comment('Vehicle running status');
            $table->unsignedInteger('alarm_flags')->default(0)->comment('Alarm flags bitmap');
            $table->unsignedInteger('status_flags')->default(0)->comment('Status flags bitmap');
            $table->tinyInteger('signal_strength')->nullable()->comment('Signal strength 0-100');
            $table->tinyInteger('satellites')->nullable()->comment('Number of satellites');
            $table->json('additional_info')->nullable()->comment('Additional info JSON');
            $table->timestamp('device_time')->nullable()->comment('Time from device');
            $table->timestamps();

            $table->index(['device_id', 'created_at']);
            $table->index('device_time');
        });

        // Device alarms table
        Schema::create('mdvr_device_alarms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('mdvr_devices')->onDelete('cascade');
            $table->unsignedInteger('alarm_id')->comment('Alarm ID from device');
            $table->string('alarm_type', 50)->comment('Alarm type code');
            $table->string('alarm_name', 100)->nullable()->comment('Alarm type name');
            $table->string('alarm_category', 20)->comment('Category: ADAS, DSM, BSD, AGGRESSIVE');
            $table->tinyInteger('alarm_level')->default(1)->comment('Alarm level 1-10');
            $table->decimal('latitude', 10, 6)->nullable();
            $table->decimal('longitude', 11, 6)->nullable();
            $table->tinyInteger('speed')->nullable()->comment('Speed at alarm time');
            $table->string('alarm_identification', 64)->nullable()->comment('Alarm identification hex');
            $table->boolean('has_attachment')->default(false)->comment('Has video/image attachment');
            $table->string('attachment_status', 20)->default('none')->comment('Attachment upload status');
            $table->json('alarm_data')->nullable()->comment('Full alarm data JSON');
            $table->json('attachments')->nullable()->comment('List of attachment files');
            $table->timestamp('device_time')->nullable()->comment('Time from device');
            $table->timestamps();

            $table->index(['device_id', 'created_at']);
            $table->index('alarm_type');
            $table->index('alarm_category');
        });

        // Attachment files table
        Schema::create('mdvr_attachment_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alarm_id')->nullable()->constrained('mdvr_device_alarms')->onDelete('set null');
            $table->foreignId('device_id')->constrained('mdvr_devices')->onDelete('cascade');
            $table->string('filename', 255)->comment('Original filename');
            $table->string('file_path', 500)->comment('Storage path');
            $table->string('file_type', 20)->comment('Type: IMAGE, VIDEO, AUDIO, TEXT');
            $table->unsignedInteger('file_size')->default(0)->comment('File size in bytes');
            $table->string('alarm_number', 64)->nullable()->comment('Related alarm number');
            $table->boolean('is_complete')->default(false)->comment('Upload complete');
            $table->timestamps();

            $table->index(['device_id', 'created_at']);
            $table->index('alarm_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mdvr_attachment_files');
        Schema::dropIfExists('mdvr_device_alarms');
        Schema::dropIfExists('mdvr_device_locations');
        Schema::dropIfExists('mdvr_devices');
    }
};
