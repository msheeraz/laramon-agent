<?php

namespace Laramon\Agent\Watchers;

use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Login;

/** Attaches the authenticated user to the running execution. */
class UserWatcher extends Watcher
{
    public function register(): void
    {
        $this->listen([Authenticated::class, Login::class], function ($event) {
            $execution = $this->recorder->current();
            if ($execution !== null && $execution->userId === null) {
                $this->recorder->identify($execution, $event->user);
            }
        });
    }
}
