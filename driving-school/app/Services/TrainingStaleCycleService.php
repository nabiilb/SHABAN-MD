<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\TrainingQueueEntry;
use App\Models\TrainingSession;
use App\Models\User;

/**
 * Closing training cycles nobody ever finished.
 *
 * A student put in the queue at 08:00 who is never trained would otherwise hold
 * their one open cycle for ever: the school-wide guard means nobody else can
 * queue them, and a day boundary no longer releases them. So twelve hours after
 * the CURRENT unresolved state began, the cycle is closed.
 *
 * This is NOT the post-training cooldown, and the two must never be confused:
 *
 *   Stale timeout   an unfinished cycle cannot stay open beyond 12 hours.
 *                   Nothing happened. No attendance, no evaluation, no lesson,
 *                   and — crucially — NO cooldown, because the student did not
 *                   train. They are free to be queued again immediately.
 *
 *   Cooldown        after a session that really was completed, the student may
 *                   not train again for 12 hours. That rule lives in
 *                   TrainingEligibilityService and is untouched by any of this.
 *
 * Which timestamp each state is measured from is TrainingQueueEntry::
 * openedCurrentStateAt(); see that method for the table. Nothing here deletes a
 * row: every expired cycle stays in the history saying why it ended.
 */
class TrainingStaleCycleService
{
    /** How long an unfinished cycle may stay open. */
    public const STALE_HOURS = 12;

    public function __construct(
        private readonly TrainingQueueService $queue,
        private readonly TrainingSessionService $sessions,
    ) {}

    public function staleHours(): int
    {
        return max(1, (int) Setting::get('training_stale_cycle_hours', self::STALE_HOURS));
    }

    /**
     * Closes every open cycle that has been in its current state too long.
     *
     * Safe to call on every request and safe to call repeatedly: a cycle that
     * is not stale is left alone, and one already closed is not closed twice.
     * It never adds anybody to a queue and never starts a session — cleanup
     * only ever removes work, never creates it.
     *
     * @return int how many cycles were closed
     */
    public function expireStale(?User $actor = null): int
    {
        $deadline = now()->copy()->subHours($this->staleHours());
        $closed = 0;

        // Only entries old enough to possibly be stale are read; the exact
        // deadline depends on the state, so the cheap filter is the row's own
        // last write.
        $candidates = TrainingQueueEntry::query()
            ->open()
            ->where('updated_at', '<=', now())
            ->with(['student', 'sessions'])
            ->orderBy('id')
            ->get();

        foreach ($candidates as $entry) {
            $since = $entry->openedCurrentStateAt();

            if ($since === null || $since->greaterThan($deadline)) {
                continue;
            }

            $closed += $this->expire($entry, $actor) ? 1 : 0;
        }

        // A live session whose queue entry has already gone terminal — or that
        // never had one — would otherwise keep occupying its teacher for ever.
        $closed += $this->expireOrphanSessions($deadline, $actor);

        return $closed;
    }

    /**
     * Closes one stale cycle, in the safest way for the state it is in.
     *
     * A cycle that got as far as a real session keeps that session's own
     * cancellation path, so started_at and ended_at stay exactly as they were
     * and the history still says what actually happened.
     */
    public function expire(TrainingQueueEntry $entry, ?User $actor = null): bool
    {
        $reason = match ($entry->status) {
            TrainingQueueEntry::ATTENDANCE_PENDING => TrainingQueueService::AUTO_EXPIRED_PENDING,
            default => TrainingQueueService::AUTO_EXPIRED,
        };

        // Any live session behind this entry is cancelled first, through the
        // session service's own workflow — never by fabricating a completion.
        foreach ($entry->sessions()->whereIn('status', TrainingSession::LIVE_STATUSES)->get() as $session) {
            $this->sessions->cancel($session, $actor ?? $this->systemActor(), __($reason));
        }

        // cancel() puts the student back in the line, which is right when a
        // person abandons a session and wrong here: the whole cycle is over.
        return $this->queue->closeCycle($entry->fresh(), $reason, $actor);
    }

    /**
     * Live sessions with no open queue entry left to represent them.
     *
     * Measured from when the session's own current state began — started_at
     * while it runs, ended_at once it is waiting on an evaluation.
     */
    protected function expireOrphanSessions($deadline, ?User $actor = null): int
    {
        $sessions = TrainingSession::query()
            ->whereIn('status', TrainingSession::LIVE_STATUSES)
            ->where(fn ($q) => $q
                ->whereNull('training_queue_entry_id')
                ->orWhereDoesntHave('queueEntry', fn ($e) => $e->open()))
            ->get();

        $closed = 0;

        foreach ($sessions as $session) {
            $since = $session->status === TrainingSession::ATTENDANCE_PENDING
                ? ($session->ended_at ?? $session->started_at)
                : $session->started_at;

            if ($since === null || $since->greaterThan($deadline)) {
                continue;
            }

            $reason = $session->status === TrainingSession::ATTENDANCE_PENDING
                ? TrainingQueueService::AUTO_EXPIRED_PENDING
                : TrainingQueueService::AUTO_EXPIRED;

            $this->sessions->cancel($session, $actor ?? $this->systemActor(), __($reason));
            $closed++;
        }

        return $closed;
    }

    /**
     * Who the audit trail names when the clock, rather than a person, closed a
     * cycle. An unsaved User carries no id, so closed_by stays null.
     */
    protected function systemActor(): User
    {
        return new User(['id' => null]);
    }

    /** How many open cycles are currently past their deadline, without closing any. */
    public function staleCount(): int
    {
        $deadline = now()->copy()->subHours($this->staleHours());

        return TrainingQueueEntry::query()
            ->open()
            ->with('sessions')
            ->get()
            ->filter(function (TrainingQueueEntry $entry) use ($deadline) {
                $since = $entry->openedCurrentStateAt();

                return $since !== null && $since->lessThanOrEqualTo($deadline);
            })
            ->count();
    }
}
