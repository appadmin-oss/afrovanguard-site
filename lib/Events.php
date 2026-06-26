<?php
/**
 * lib/Events.php — a tiny in-process event seam.
 *
 * Domain code emits named events (e.g. 'diary.published', 'donation.completed')
 * without knowing or caring who listens. Today the built-in consumer is the
 * webhook dispatcher; tomorrow you can register more sinks (Slack, a digest
 * mailer, analytics) with Events::on() — that's the "…and other means" hook.
 *
 * Emission is always best-effort: a listener that throws is logged and never
 * propagates, so emitting an event can never break the action that triggered it.
 */
declare(strict_types=1);

final class Events
{
    /** @var array<string, callable[]> */
    private static array $listeners = [];

    /** Register a listener for an event name. */
    public static function on(string $event, callable $fn): void
    {
        self::$listeners[$event][] = $fn;
    }

    /**
     * Emit an event with an associative payload. Fans out to registered listeners
     * and (always) to the webhook dispatcher. Never throws.
     */
    public static function emit(string $event, array $payload = []): void
    {
        foreach (self::$listeners[$event] ?? [] as $fn) {
            try { $fn($payload, $event); }
            catch (Throwable $e) { error_log('[events] listener for ' . $event . ' failed: ' . $e->getMessage()); }
        }
        if (class_exists('Webhooks')) {
            try { Webhooks::dispatch($event, $payload); }
            catch (Throwable $e) { error_log('[events] webhook dispatch for ' . $event . ' failed: ' . $e->getMessage()); }
        }
    }

    /** The canonical event names the app emits (for docs + the admin UI). */
    public static function catalog(): array
    {
        return [
            'diary.published'    => 'A Diary article was published',
            'member.created'     => 'A new member/learner account was created',
            'donation.completed' => 'A donation was completed',
            'contact.received'   => 'A contact form was submitted',
            'enrollment.created' => 'A learner enrolled in a course',
        ];
    }
}
