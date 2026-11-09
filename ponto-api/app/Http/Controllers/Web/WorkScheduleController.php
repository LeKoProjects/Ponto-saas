<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\WorkScheduleRequest;
use App\Models\WorkSchedule;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class WorkScheduleController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): Response
    {
        return Inertia::render('Schedules/Index', [
            'schedules' => WorkSchedule::withCount('employees')->orderBy('name')->paginate(20),
            'weekdays' => WorkSchedule::WEEKDAYS,
        ]);
    }

    public function store(WorkScheduleRequest $request): RedirectResponse
    {
        $schedule = WorkSchedule::create($request->validated());
        $this->audit->log('schedule.created', $schedule, null, $schedule->toArray());

        return back()->with('success', 'Jornada cadastrada.');
    }

    public function update(WorkScheduleRequest $request, WorkSchedule $schedule): RedirectResponse
    {
        $this->audit->update($schedule, $request->validated(), 'schedule.updated');

        return back()->with('success', 'Jornada atualizada.');
    }
}
