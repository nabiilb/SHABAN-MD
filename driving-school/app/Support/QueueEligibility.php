<?php

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;

/**
 * One answer to "may this teacher put this student in the line right now?".
 *
 * Carried around instead of a bare bool so the dialog, the backend refusal and
 * the audit trail all say the same thing for the same reason — and so a
 * cooldown can state when it lifts rather than only that it has not.
 */
final class QueueEligibility implements Arrayable
{
    public const ELIGIBLE = 'eligible';

    public const NOT_ACTIVE = 'not_active';

    public const NOT_YOURS = 'not_yours';

    public const ALREADY_WAITING = 'already_waiting';

    public const IN_TRAINING = 'in_training';

    public const ATTENDANCE_PENDING = 'attendance_pending';

    public const COOLING_DOWN = 'cooling_down';

    private function __construct(
        public readonly bool $eligible,
        public readonly string $code,
        public readonly ?string $reason = null,
        /** When a cooldown lifts. Null for every other answer. */
        public readonly ?Carbon $eligibleAt = null,
    ) {}

    public static function allowed(): self
    {
        return new self(true, self::ELIGIBLE);
    }

    public static function blocked(string $code, string $reason, ?Carbon $eligibleAt = null): self
    {
        return new self(false, $code, $reason, $eligibleAt);
    }

    /** How much of the cooldown is left, in whole seconds. Zero when none is. */
    public function remainingSeconds(): int
    {
        if (! $this->eligibleAt) {
            return 0;
        }

        return max(0, (int) ceil(now()->diffInRealSeconds($this->eligibleAt, false)));
    }

    /**
     * The clock time the student becomes available again, in the centre's own
     * timezone — "20:00", the form the teacher reads off the wall.
     */
    public function availableAt(): ?string
    {
        return $this->eligibleAt?->copy()->timezone(config('app.timezone'))->format('H:i');
    }

    /** The same wait as a duration — "3h 15m", or "12m" under the hour. */
    public function remainingLabel(): ?string
    {
        if (! $this->eligibleAt) {
            return null;
        }

        $seconds = $this->remainingSeconds();
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'eligible' => $this->eligible,
            'code' => $this->code,
            'reason' => $this->reason,
            'eligible_at' => $this->eligibleAt?->toIso8601String(),
            'available_at' => $this->availableAt(),
            'remaining_seconds' => $this->remainingSeconds(),
            'remaining_label' => $this->remainingLabel(),
        ];
    }
}
