<?php
/**
 * Result calculation and turnout statistics.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden.');
}

final class ResultService
{
    /**
     * Calculate the official tally for one election.
     *
     * Results are derived directly from the anonymous votes table. Nothing is
     * cached, so displayed totals always reflect committed database rows.
     */
    public static function summary(array $election): array
    {
        $pdo = Database::connection();
        $electionId = (int) $election['id'];

        $candidateStmt = $pdo->prepare(
            'SELECT c.id, c.full_name, c.party, c.tagline, c.bio, c.photo_url,
                    c.display_order, COUNT(v.id) AS vote_count
             FROM candidates c
             LEFT JOIN votes v
                    ON v.candidate_id = c.id AND v.election_id = c.election_id
             WHERE c.election_id = ?
             GROUP BY c.id
             ORDER BY vote_count DESC, c.display_order ASC, c.full_name ASC'
        );
        $candidateStmt->execute([$electionId]);
        $candidates = $candidateStmt->fetchAll();

        $ballotStmt = $pdo->prepare('SELECT COUNT(*) FROM votes WHERE election_id = ?');
        $ballotStmt->execute([$electionId]);
        $totalBallots = (int) $ballotStmt->fetchColumn();

        $participationStmt = $pdo->prepare('SELECT COUNT(*) FROM vote_participation WHERE election_id = ?');
        $participationStmt->execute([$electionId]);
        $totalParticipants = (int) $participationStmt->fetchColumn();

        $eligible = (int) $pdo->query('SELECT COUNT(*) FROM voters WHERE is_active = 1')->fetchColumn();

        foreach ($candidates as &$candidate) {
            $candidate['id'] = (int) $candidate['id'];
            $candidate['vote_count'] = (int) $candidate['vote_count'];
            $candidate['percentage'] = $totalBallots > 0
                ? round(($candidate['vote_count'] / $totalBallots) * 100, 1)
                : 0.0;
        }
        unset($candidate);

        $maxVotes = 0;
        foreach ($candidates as $candidate) {
            $maxVotes = max($maxVotes, $candidate['vote_count']);
        }
        $winners = $maxVotes > 0
            ? array_values(array_filter($candidates, static fn (array $c): bool => $c['vote_count'] === $maxVotes))
            : [];

        $turnout = $eligible > 0 ? round(($totalParticipants / $eligible) * 100, 1) : 0.0;

        return [
            'election'            => $election,
            'candidates'          => $candidates,
            'total_ballots'       => $totalBallots,
            'total_participants'  => $totalParticipants,
            'eligible_voters'     => $eligible,
            'turnout'             => $turnout,
            'winners'             => $winners,
            'has_tie'             => count($winners) > 1,
            'max_votes'           => $maxVotes,
            'integrity_balanced'  => $totalBallots === $totalParticipants,
        ];
    }

    /** Published results visible to voters. */
    public static function publishedElections(): array
    {
        $stmt = Database::connection()->query(
            "SELECT * FROM elections
             WHERE results_published = 1 AND status <> 'draft'
             ORDER BY end_at DESC"
        );
        return $stmt->fetchAll();
    }

    /** Vote totals grouped by day for the administrator dashboard (last 7 days). */
    public static function recentVoteActivity(int $days = 7): array
    {
        $days = max(1, min(30, $days));
        $stmt = Database::connection()->prepare(
            'SELECT DATE(cast_at) AS vote_day, COUNT(*) AS total
             FROM votes
             WHERE cast_at >= DATE_SUB(CURDATE(), INTERVAL ' . ($days - 1) . ' DAY)
             GROUP BY DATE(cast_at)
             ORDER BY vote_day ASC'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['vote_day']] = (int) $row['total'];
        }

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = (new DateTimeImmutable('today', new DateTimeZone(APP_TIMEZONE)))
                ->modify('-' . $i . ' days')->format('Y-m-d');
            $series[] = [
                'date'  => $date,
                'label' => (new DateTimeImmutable($date))->format('D'),
                'total' => $indexed[$date] ?? 0,
            ];
        }
        return $series;
    }
}
