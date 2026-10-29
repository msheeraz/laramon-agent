<?php

namespace Laramon\Agent\Watchers;

use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Laramon\Agent\Execution;
use Laramon\Agent\Support\Clock;

class NotificationWatcher extends Watcher
{
    /** @var array<string, array{start: float, fields: array<string, mixed>, execution: ?Execution}> */
    private array $pending = [];

    public function register(): void
    {
        $this->listen(NotificationSending::class, function (NotificationSending $event) {
            if ($this->recorder->current() === null || count($this->pending) > 200 || $this->rejected($event)) {
                return;
            }

            $this->pending[$this->key($event)] = [
                'start' => Clock::now(),
                'fields' => $this->fields($event),
                'execution' => $this->recorder->current(),
            ];
        });

        $this->listen(NotificationSent::class, fn (NotificationSent $e) => $this->complete($e, false));
        $this->listen(NotificationFailed::class, fn (NotificationFailed $e) => $this->complete($e, true));

        $this->recorder->ending(function (Execution $execution) {
            foreach ($this->pending as $key => $pending) {
                if ($pending['execution'] === $execution) {
                    unset($this->pending[$key]);
                    $this->recorder->record('notification', $pending['start'], $pending['fields'] + [
                        'failed' => true,
                        'duration' => Clock::us(Clock::now() - $pending['start']),
                    ], 'notifications');
                }
            }
        });
    }

    private function rejected(object $event): bool
    {
        return $this->hooks()->rejects('notifications', $event->notification, (string) (is_string($event->channel) ? $event->channel : get_debug_type($event->channel)), $event->notifiable);
    }

    private function complete(object $event, bool $failed): void
    {
        if ($this->rejected($event)) {
            unset($this->pending[$this->key($event)]);

            return;
        }

        $key = $this->key($event);
        $pending = $this->pending[$key] ?? null;
        unset($this->pending[$key]);

        $now = Clock::now();
        $start = $pending['start'] ?? $now;

        $this->recorder->record('notification', $start, ($pending['fields'] ?? $this->fields($event)) + [
            'failed' => $failed,
            'duration' => Clock::us($now - $start),
        ], 'notifications');
    }

    private function key(object $event): string
    {
        return spl_object_id($event->notification).'|'.$event->channel.'|'.spl_object_id($event->notifiable);
    }

    /** @return array<string, mixed> */
    private function fields(object $event): array
    {
        return [
            'notification' => $event->notification::class,
            'channel' => is_string($event->channel) ? $event->channel : get_debug_type($event->channel),
            'notifiable' => is_object($event->notifiable) ? $event->notifiable::class : get_debug_type($event->notifiable),
        ];
    }
}
