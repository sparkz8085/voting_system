<?php
/**
 * Election state helpers.
 *
 * The "effective" status combines the administrator's status flag with the
 * server clock. Voting is only ever allowed when the stored status is active
 * AND the current time falls inside the configured window.
 */

declare(strict_types=1);

final class Election
{
    public const DRAFT = 'draft';
    public const UPCOMING = 'upcoming';
    public const LIVE = 'live';
    public const ENDED = 'ended';
    public const CLOSED = 'closed';

    /** Resolve the effective status of an election row. */
    public static function status(array $election, ?DateTimeImmutable $now = null): string
    {
        $stored = (string) ($election['status'] ?? self::DRAFT);
        if ($stored === 'draft') {
            return self::DRAFT;
        }
        if ($stored === 'closed') {
            return self::CLOSED;
        }

        $timezone = new DateTimeZone(APP_TIMEZONE);
        $now ??= new DateTimeImmutable('now', $timezone);

        try {
            $start = new DateTimeImmutable((string) $election['start_at'], $timezone);
            $end = new DateTimeImmutable((string) $election['end_at'], $timezone);
        } catch (Exception) {
            return self::DRAFT;
        }

        if ($now < $start) {
            return self::UPCOMING;
        }
        if ($now > $end) {
            return self::ENDED;
        }
        return self::LIVE;
    }

    /** Presentation metadata for a status. */
    public static function meta(string $status): array
    {
        return match ($status) {
            self::LIVE => [
                'label' => 'Live now',
                'class' => 'live',
                'icon'  => 'bi-broadcast',
                'tone'  => 'success',
            ],
            self::UPCOMING => [
                'label' => 'Scheduled',
                'class' => 'upcoming',
                'icon'  => 'bi-calendar-event',
                'tone'  => 'info',
            ],
            self::ENDED => [
                'label' => 'Voting ended',
                'class' => 'ended',
                'icon'  => 'bi-clock-history',
                'tone'  => 'warning',
            ],
            self::CLOSED => [
                'label' => 'Closed',
                'class' => 'closed',
                'icon'  => 'bi-lock',
                'tone'  => 'dark',
            ],
            default => [
                'label' => 'Draft',
                'class' => 'draft',
                'icon'  => 'bi-pencil-square',
                'tone'  => 'secondary',
            ],
        };
    }

    /** Whether the election can receive votes right now. */
    public static function acceptsVotes(array $election, ?DateTimeImmutable $now = null): bool
    {
        return self::status($election, $now) === self::LIVE;
    }

    /** A user-facing explanation when voting is unavailable. */
    public static function blockReason(array $election): ?string
    {
        return match (self::status($election)) {
            self::LIVE => null,
            self::UPCOMING => 'Voting opens on ' . format_datetime((string) $election['start_at']) . '.',
            self::ENDED => 'Voting for this election ended on ' . format_datetime((string) $election['end_at']) . '.',
            self::CLOSED => 'Voting for this election is closed.',
            default => 'This election has not been published to voters yet.',
        };
    }

    /** Whether an administrator may edit the schedule. */
    public static function canEdit(array $election, int $voteCount = 0): bool
    {
        if ($voteCount > 0) {
            return false;
        }
        return in_array(self::status($election), [self::DRAFT, self::UPCOMING], true);
    }

    /** Whether candidates may be added, edited, or removed. */
    public static function canManageCandidates(array $election): bool
    {
        return in_array(self::status($election), [self::DRAFT, self::UPCOMING], true);
    }

    /** Whether results are allowed to be published. */
    public static function canPublishResults(array $election): bool
    {
        if ((int) ($election['results_published'] ?? 0) === 1) {
            return false;
        }
        return in_array(self::status($election), [self::ENDED, self::CLOSED], true);
    }

    /** Whether this election has finished from a voter's point of view. */
    public static function hasEnded(array $election): bool
    {
        return in_array(self::status($election), [self::ENDED, self::CLOSED], true);
    }

    /** Short countdown copy used server-side (JS enhances it live). */
    public static function timingHint(array $election): string
    {
        $status = self::status($election);
        if ($status === self::LIVE) {
            return 'Closes in ' . time_until((string) $election['end_at']);
        }
        if ($status === self::UPCOMING) {
            return 'Opens in ' . time_until((string) $election['start_at']);
        }
        if ($status === self::ENDED) {
            return 'Ended ' . time_ago((string) $election['end_at']);
        }
        if ($status === self::CLOSED) {
            return 'Closed ' . time_ago((string) $election['end_at']);
        }
        return 'Not yet published';
    }
}
