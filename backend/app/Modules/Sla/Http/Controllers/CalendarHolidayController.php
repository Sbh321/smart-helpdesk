<?php

declare(strict_types=1);

namespace App\Modules\Sla\Http\Controllers;

use App\Modules\Sla\Exceptions\RecordInUse;
use App\Modules\Sla\Http\Requests\SaveHolidayRequest;
use App\Modules\Sla\Http\Resources\BusinessCalendarResource;
use App\Modules\Sla\Models\BusinessCalendar;
use App\Modules\Sla\Models\CalendarHoliday;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Response;

#[Group('SLA')]
final class CalendarHolidayController
{
    /** Add a holiday to a calendar. */
    public function store(SaveHolidayRequest $request, BusinessCalendar $calendar): BusinessCalendarResource
    {
        $this->rejectActiveTimerEdit($calendar);
        $calendar->holidays()->create($request->validated());

        return new BusinessCalendarResource($calendar->refresh()->load('holidays'));
    }

    /** Remove a holiday from a calendar. */
    public function destroy(BusinessCalendar $calendar, CalendarHoliday $holiday): Response
    {
        abort_unless($holiday->calendar_id === $calendar->id, 404);
        $this->rejectActiveTimerEdit($calendar);
        $holiday->delete();

        return response()->noContent();
    }

    private function rejectActiveTimerEdit(BusinessCalendar $calendar): void
    {
        if ($calendar->hasActiveTimers()) {
            throw RecordInUse::because(
                'A business calendar with unfinished SLA timers cannot change its holidays.',
                'business_calendar',
                ['ticket_sla_timers'],
            );
        }
    }
}
