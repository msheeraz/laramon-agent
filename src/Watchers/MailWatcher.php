<?php

namespace Laramon\Agent\Watchers;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Laramon\Agent\Execution;
use Laramon\Agent\Support\Clock;

class MailWatcher extends Watcher
{
    /** @var list<array{start: float, fields: array<string, mixed>, execution: ?Execution}> */
    private array $pending = [];

    public function register(): void
    {
        $this->listen(MessageSending::class, function (MessageSending $event) {
            if ($this->recorder->current() === null || count($this->pending) > 100) {
                return;
            }

            // Symfony clones the message before sending, so MessageSent cannot be
            // matched by identity; sending is synchronous so the latest pending wins.
            $this->pending[] = [
                'start' => Clock::now(),
                'fields' => $this->fields($event->message, $event->data ?? []),
                'execution' => $this->recorder->current(),
            ];
        });

        $this->listen(MessageSent::class, function (MessageSent $event) {
            $message = method_exists($event->sent, 'getOriginalMessage') ? $event->sent->getOriginalMessage() : null;
            $pending = array_pop($this->pending);

            $now = Clock::now();
            $start = $pending['start'] ?? $now;
            $fields = $pending['fields'] ?? $this->fields($message, $event->data ?? []);
            if ($this->hooks()->rejects('mail', $fields)) {
                return;
            }

            $this->recorder->record('mail', $start, $this->redactSubject($fields) + [
                'failed' => false,
                'duration' => Clock::us($now - $start),
            ], 'mail');
        });

        // Messages that started sending but never completed during the execution failed.
        $this->recorder->ending(function (Execution $execution) {
            foreach ($this->pending as $id => $pending) {
                if ($pending['execution'] !== $execution) {
                    continue;
                }
                unset($this->pending[$id]);
                if ($this->hooks()->rejects('mail', $pending['fields'])) {
                    continue;
                }

                $this->recorder->record('mail', $pending['start'], $this->redactSubject($pending['fields']) + [
                    'failed' => true,
                    'duration' => Clock::us(Clock::now() - $pending['start']),
                ], 'mail');
            }
        });
    }

    /** @param array<string, mixed> $fields */
    private function redactSubject(array $fields): array
    {
        if (is_string($fields['subject'] ?? null)) {
            $fields['subject'] = mb_substr($this->hooks()->redact('mail_subjects', $fields['subject']), 0, 512);
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function fields(?object $message, array $data): array
    {
        $count = fn (string $method) => $message !== null && method_exists($message, $method) ? count((array) $message->{$method}()) : 0;

        return [
            'mailable' => (string) ($data['__laravel_mailable'] ?? $data['__laravel_notification'] ?? 'Illuminate\Mail\Mailable'),
            'mailer' => (string) ($data['mailer'] ?? config('mail.default')),
            'subject' => $message !== null && method_exists($message, 'getSubject') ? mb_substr((string) $message->getSubject(), 0, 512) : null,
            'to' => $count('getTo'),
            'cc' => $count('getCc'),
            'bcc' => $count('getBcc'),
            'attachments' => $count('getAttachments'),
        ];
    }
}
