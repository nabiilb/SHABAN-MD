<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever anything on the training board moves: a session started,
 * ended, was extended or evaluated, or the queue changed.
 *
 * The dashboards keep themselves current by polling the board endpoint, which
 * needs no extra infrastructure. This event exists so that switching to real
 * push (Reverb, Pusher, Ably) is a broadcasting-driver change rather than a
 * rewrite: set BROADCAST_CONNECTION and subscribe to the `training-board`
 * channel, and the same payload arrives over the socket.
 */
class TrainingBoardChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $reason,
        public array $payload = [],
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new Channel('training-board')];
    }

    public function broadcastAs(): string
    {
        return 'training.board.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'reason' => $this->reason,
            'payload' => $this->payload,
            'at' => now()->toIso8601String(),
        ];
    }
}
