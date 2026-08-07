<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Seed the Pagadito payment gateway row so it appears in
     * Admin → Business Settings → Third-party → Payment Method
     * on existing installs (fresh installs get it from the SQL dump).
     */
    public function up(): void
    {
        $exists = DB::table('addon_settings')
            ->where('key_name', 'pagadito')
            ->where('settings_type', 'payment_config')
            ->exists();

        if ($exists) {
            return;
        }

        $values = json_encode([
            'gateway' => 'pagadito',
            'mode' => 'test',
            'status' => '0',
            'uid' => '',
            'wsk' => '',
        ]);

        DB::table('addon_settings')->insert([
            'id' => (string) Str::uuid(),
            'key_name' => 'pagadito',
            'live_values' => $values,
            'test_values' => $values,
            'settings_type' => 'payment_config',
            'mode' => 'test',
            'is_active' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'additional_data' => json_encode([
                'gateway_title' => 'Pagadito',
                'gateway_image' => null,
            ]),
        ]);
    }

    public function down(): void
    {
        DB::table('addon_settings')
            ->where('key_name', 'pagadito')
            ->where('settings_type', 'payment_config')
            ->delete();
    }
};
