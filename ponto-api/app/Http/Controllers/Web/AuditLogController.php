<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(): Response
    {
        $logs = AuditLog::with('user:id,name')
            ->orderByDesc('id')
            ->paginate(20)
            ->through(fn (AuditLog $l) => [
                'id' => $l->id,
                'created_at' => $l->created_at->format('d/m/Y H:i:s'),
                'user' => $l->user?->name ?? 'Sistema',
                'action' => $l->action,
                'entity' => $l->entity_type ? $l->entity_type.' #'.$l->entity_id : null,
                'before' => $l->before,
                'after' => $l->after,
                'ip' => $l->ip,
            ]);

        return Inertia::render('Audit/Index', ['logs' => $logs]);
    }
}
