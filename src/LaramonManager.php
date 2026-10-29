<?php

namespace Laramon\Agent;

use Closure;

/**
 * Public API behind the Laramon facade.
 *
 * Rejectors return true to drop a record; redactors return the transformed value.
 */
class LaramonManager
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Hooks $hooks,
    ) {
    }

    /** Run the callback without recording anything it produces. */
    public function ignore(callable $callback): mixed
    {
        return $this->recorder->ignore($callback);
    }

    public function pause(): void
    {
        $this->recorder->pause();
    }

    public function resume(): void
    {
        $this->recorder->resume();
    }

    public function isRecording(): bool
    {
        return $this->recorder->isRecording();
    }

    /** @param Closure(string $sql, string $connection): bool $callback */
    public function rejectQueries(Closure $callback): static
    {
        return $this->reject('queries', $callback);
    }

    /** @param Closure(string $key, string $kind, string $store): bool $callback */
    public function rejectCacheKeys(Closure $callback): static
    {
        return $this->reject('cache', $callback);
    }

    /** @param Closure(array $mail): bool $callback receives mailable, mailer, subject, to, cc, bcc */
    public function rejectMail(Closure $callback): static
    {
        return $this->reject('mail', $callback);
    }

    /** @param Closure(object $notification, string $channel, mixed $notifiable): bool $callback */
    public function rejectNotifications(Closure $callback): static
    {
        return $this->reject('notifications', $callback);
    }

    /** @param Closure(string $method, string $url, object $request): bool $callback */
    public function rejectOutgoingRequests(Closure $callback): static
    {
        return $this->reject('outgoing', $callback);
    }

    /** @param Closure(string $name, object $job): bool $callback */
    public function rejectJobs(Closure $callback): static
    {
        return $this->reject('jobs', $callback);
    }

    /** @param Closure(string $sql): string $callback */
    public function redactQueries(Closure $callback): static
    {
        return $this->redact('queries', $callback);
    }

    /** @param Closure(string $message): string $callback */
    public function redactExceptions(Closure $callback): static
    {
        return $this->redact('exceptions', $callback);
    }

    /** @param Closure(array $record): array $callback receives the full request record (url, ip, headers…) */
    public function redactRequests(Closure $callback): static
    {
        return $this->redact('requests', $callback);
    }

    /** @param Closure(string $subject): string $callback */
    public function redactMailSubjects(Closure $callback): static
    {
        return $this->redact('mail_subjects', $callback);
    }

    /** @param Closure(string $key): string $callback */
    public function redactCacheKeys(Closure $callback): static
    {
        return $this->redact('cache_keys', $callback);
    }

    /** @param Closure(string $commandLine): string $callback */
    public function redactCommands(Closure $callback): static
    {
        return $this->redact('commands', $callback);
    }

    public function hooks(): Hooks
    {
        return $this->hooks;
    }

    private function reject(string $type, Closure $callback): static
    {
        $this->hooks->addRejector($type, $callback);

        return $this;
    }

    private function redact(string $type, Closure $callback): static
    {
        $this->hooks->addRedactor($type, $callback);

        return $this;
    }
}
