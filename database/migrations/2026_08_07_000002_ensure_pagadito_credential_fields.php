<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Ensure the Pagadito payment_config row exposes the `uid` and `wsk`
     * credential keys. The admin payment form renders its inputs dynamically
     * from the stored value keys, so if a prior save (before the `pagadito`
     * branch existed in payment_config_update) stripped them, the fields would
     * not render. This repairs the row without overwriting existing values.
     */
    public function up(): void
    {
        $row = DB::table('addon_settings')
            ->where('key_name', 'pagadito')
            ->where('settings_type', 'payment_config')
            ->first();

        if (!$row) {
            return;
        }

        foreach (['live_values', 'test_values'] as $column) {
            $values = json_decode($row->{$column} ?? '{}', true);
            if (!is_array($values)) {
                $values = [];
            }

            $values['gateway'] = $values['gateway'] ?? 'pagadito';
            $values['mode'] = $values['mode'] ?? ($row->mode ?? 'test');
            $values['status'] = $values['status'] ?? '0';
            if (!array_key_exists('uid', $values)) {
                $values['uid'] = '';
            }
            if (!array_key_exists('wsk', $values)) {
                $values['wsk'] = '';
            }

            DB::table('addon_settings')
                ->where('id', $row->id)
                ->update([$column => json_encode($values)]);
        }
    }

    public function down(): void
    {
        // No-op: we do not remove credential keys on rollback.
    }
};
