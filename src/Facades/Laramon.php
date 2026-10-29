<?php

namespace Laramon\Agent\Facades;

use Illuminate\Support\Facades\Facade;
use Laramon\Agent\LaramonManager;

/**
 * @method static mixed ignore(callable $callback)
 * @method static void pause()
 * @method static void resume()
 * @method static bool isRecording()
 * @method static \Laramon\Agent\LaramonManager rejectQueries(\Closure $callback)
 * @method static \Laramon\Agent\LaramonManager rejectCacheKeys(\Closure $callback)
 * @method static \Laramon\Agent\LaramonManager rejectMail(\Closure $callback)
 * @method static \Laramon\Agent\LaramonManager rejectNotifications(\Closure $callback)
 * @method static \Laramon\Agent\LaramonManager rejectOutgoingRequests(\Closure $callback)
 * @method static \Laramon\Agent\LaramonManager rejectJobs(\Closure $callback)
 * @method static \Laramon\Agent\LaramonManager redactQueries(\Closure $callback)
 * @method static \Laramon\Agent\LaramonManager redactExceptions(\Closure $callback)
 * @method static \Laramon\Agent\LaramonManager redactRequests(\Closure $callback)
 * @method static \Laramon\Agent\LaramonManager redactMailSubjects(\Closure $callback)
 * @method static \Laramon\Agent\LaramonManager redactCacheKeys(\Closure $callback)
 * @method static \Laramon\Agent\LaramonManager redactCommands(\Closure $callback)
 *
 * @see LaramonManager
 */
class Laramon extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LaramonManager::class;
    }
}
