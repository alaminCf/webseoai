<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    use ApiResponseTrait;

    /**
     * List audit logs for the active organization.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $logs = AuditLog::with('user:id,name,email')
            ->where('organization_id', $org->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 25));

        $data = $logs->getCollection()->map(function (AuditLog $log) {
            return [
                'id' => $log->id,
                'action' => $log->action,
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                ] : null,
                'auditable_type' => $log->auditable_type ? class_basename($log->auditable_type) : null,
                'auditable_id' => $log->auditable_id,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at->toIso8601String(),
            ];
        });

        return $this->success($data, [
            'current_page' => $logs->currentPage(),
            'per_page' => $logs->perPage(),
            'total' => $logs->total(),
            'last_page' => $logs->lastPage(),
        ]);
    }
}
