<?php

declare(strict_types=1);

namespace App\Modules\Sla\Http\Controllers;

use App\Modules\Sla\Exceptions\RecordInUse;
use App\Modules\Sla\Http\Requests\SaveCalendarRequest;
use App\Modules\Sla\Http\Resources\BusinessCalendarResource;
use App\Modules\Sla\Models\BusinessCalendar;
use App\Modules\Sla\Models\TicketSlaTimer;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

#[Group('SLA')]
final class CalendarController
{
    /** List business calendars. */
    public function index(): AnonymousResourceCollection
    {
        return BusinessCalendarResource::collection(BusinessCalendar::query()->with('holidays')->orderBy('name')->get());
    }

    /** Create a business calendar. */
    #[ScrambleResponse(status: 201, type: BusinessCalendarResource::class)]
    public function store(SaveCalendarRequest $request): JsonResponse
    {
        $calendar = DB::transaction(function () use ($request): BusinessCalendar {
            $data = $request->validated();
            if ($data['is_default'] ?? false) {
                BusinessCalendar::query()->where('is_default', true)->update(['is_default' => false]);
            }

            return BusinessCalendar::query()->create($data);
        });

        // refresh(): the response carries the column defaults the insert did not set.
        return (new BusinessCalendarResource($calendar->refresh()->load('holidays')))->response()->setStatusCode(201);
    }

    /** Get a business calendar. */
    public function show(BusinessCalendar $calendar): BusinessCalendarResource
    {
        return new BusinessCalendarResource($calendar->load('holidays'));
    }

    /** Update a business calendar. */
    public function update(SaveCalendarRequest $request, BusinessCalendar $calendar): BusinessCalendarResource
    {
        $data = $request->validated();
        $changesTime = (isset($data['timezone']) && $data['timezone'] !== $calendar->timezone)
            || (isset($data['weekly_hours']) && BusinessCalendar::normaliseWeeklyHours($data['weekly_hours'])
                !== BusinessCalendar::normaliseWeeklyHours($calendar->weekly_hours));
        if ($changesTime && $calendar->hasActiveTimers()) {
            throw RecordInUse::because(
                'A business calendar with unfinished SLA timers cannot change its hours or time zone.',
                'business_calendar',
                ['ticket_sla_timers'],
            );
        }
        DB::transaction(function () use ($data, $calendar): void {
            if ($data['is_default'] ?? false) {
                BusinessCalendar::query()->where('is_default', true)->whereKeyNot($calendar->id)->update(['is_default' => false]);
            }
            $calendar->update($data);
        });

        return new BusinessCalendarResource($calendar->refresh()->load('holidays'));
    }

    /** Delete a business calendar. */
    public function destroy(BusinessCalendar $calendar): Response
    {
        $usedBy = array_keys(array_filter([
            'default_calendar' => $calendar->is_default,
            'sla_policies' => $calendar->policies()->exists(),
            'ticket_sla_timers' => TicketSlaTimer::query()->where('calendar_id', $calendar->id)->exists(),
        ]));
        if ($usedBy !== []) {
            throw RecordInUse::because('This business calendar is still in use and cannot be deleted.', 'business_calendar', $usedBy);
        }
        $calendar->delete();

        return response()->noContent();
    }
}
