<?php
/**
 * Vote submission.
 *
 * The voter's identity is written to vote_participation. The selected
 * candidate is written to votes (an anonymous ballot) with no voter_id.
 * Both writes happen inside one transaction, protected by a locked election
 * row and a UNIQUE(voter_id, election_id) constraint.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden.');
}

final class VoteException extends RuntimeException
{
    public int $httpStatus;

    public function __construct(string $message, int $httpStatus = 409)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }
}

final class VoteService
{
    /**
     * Cast one ballot.
     *
     * @return array{participation_id: int, receipt_code: string, cast_at: string}
     */
    public static function cast(int $voterId, int $electionId, int $candidateId): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            // Serialising votes per election closes the check-then-insert race.
            $stmt = $pdo->prepare('SELECT * FROM elections WHERE id = ? FOR UPDATE');
            $stmt->execute([$electionId]);
            $election = $stmt->fetch();

            if (!$election) {
                throw new VoteException('This election could not be found.', 404);
            }

            $dbNow = (string) $pdo->query('SELECT NOW()')->fetchColumn();
            $now = new DateTimeImmutable($dbNow, new DateTimeZone(APP_TIMEZONE));
            $effectiveStatus = Election::status($election, $now);

            if ($effectiveStatus === Election::UPCOMING) {
                throw new VoteException('Voting for this election has not started yet.', 403);
            }
            if ($effectiveStatus === Election::ENDED) {
                throw new VoteException('Voting for this election has ended.', 403);
            }
            if ($effectiveStatus !== Election::LIVE) {
                throw new VoteException('Voting is not open for this election.', 403);
            }

            $voterStmt = $pdo->prepare('SELECT id, is_active FROM voters WHERE id = ? LIMIT 1');
            $voterStmt->execute([$voterId]);
            $voter = $voterStmt->fetch();
            if (!$voter || (int) $voter['is_active'] !== 1) {
                throw new VoteException('Your voter account is not active. Contact the election office.', 403);
            }

            $existingStmt = $pdo->prepare(
                'SELECT id FROM vote_participation WHERE voter_id = ? AND election_id = ? LIMIT 1'
            );
            $existingStmt->execute([$voterId, $electionId]);
            if ($existingStmt->fetch()) {
                throw new VoteException('You have already voted in this election. A ballot cannot be changed.', 409);
            }

            $candidateStmt = $pdo->prepare(
                'SELECT id FROM candidates WHERE id = ? AND election_id = ? LIMIT 1'
            );
            $candidateStmt->execute([$candidateId, $electionId]);
            if (!$candidateStmt->fetch()) {
                throw new VoteException('The selected candidate is not on this ballot.', 422);
            }

            $receipt = self::generateReceiptCode();

            $participation = $pdo->prepare(
                'INSERT INTO vote_participation (voter_id, election_id, receipt_code, cast_at)
                 VALUES (?, ?, ?, NOW())'
            );
            $participation->execute([$voterId, $electionId, $receipt]);
            $participationId = (int) $pdo->lastInsertId();

            // Anonymous ballot: deliberately no voter_id.
            $ballot = $pdo->prepare(
                'INSERT INTO votes (election_id, candidate_id, cast_at) VALUES (?, ?, NOW())'
            );
            $ballot->execute([$electionId, $candidateId]);

            $pdo->commit();

            return [
                'participation_id' => $participationId,
                'receipt_code'     => $receipt,
                'cast_at'          => $dbNow,
            ];
        } catch (VoteException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // 23000 = integrity constraint violation (duplicate participation).
            if ((string) $exception->getCode() === '23000') {
                throw new VoteException('You have already voted in this election. A ballot cannot be changed.', 409);
            }
            error_log('Vote transaction failed: ' . $exception->getMessage());
            throw new RuntimeException('The ballot could not be recorded. Please try again.', 0, $exception);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** Has this voter already participated in the election? */
    public static function hasVoted(int $voterId, int $electionId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT id FROM vote_participation WHERE voter_id = ? AND election_id = ? LIMIT 1'
        );
        $stmt->execute([$voterId, $electionId]);
        return (bool) $stmt->fetch();
    }

    /** Fetch one participation record belonging to the signed-in voter. */
    public static function findParticipation(int $participationId, int $voterId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.*, e.title AS election_title, e.end_at, e.results_published
             FROM vote_participation p
             JOIN elections e ON e.id = p.election_id
             WHERE p.id = ? AND p.voter_id = ?
             LIMIT 1'
        );
        $stmt->execute([$participationId, $voterId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** 64-bit random receipt in a typo-resistant grouped format. */
    private static function generateReceiptCode(): string
    {
        $hex = strtoupper(bin2hex(random_bytes(8)));
        return 'OVS-' . implode('-', str_split($hex, 4));
    }
}
