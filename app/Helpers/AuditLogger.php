<?php

namespace App\Helpers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogger
{
    public static function log(
        string $action,
        string $module,
        $recordId = null,
        array $oldValues = [],
        array $newValues = [],
        ?int $userId = null
    ): void {
        try {
            $request = app(Request::class);
            AuditLog::create([
                'user_id'    => $userId ?? (auth('api')->check() ? auth('api')->id() : null),
                'action'     => $action,
                'module'     => $module,
                'record_id'  => $recordId,
                'old_values' => $oldValues ?: null,
                'new_values' => $newValues ?: null,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (\Exception $e) {
            // Never let audit logging break a transaction
        }
    }
}
