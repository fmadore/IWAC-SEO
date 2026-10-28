<?php
declare(strict_types=1);

namespace IwacSeo\Service;

/**
 * What became of one IndexNow submission, and so of the outbox rows it
 * carried.
 *
 * Two states were not enough. A failure used to be a failure: every one backed
 * off and was resent, so a submission IndexNow had refused outright — a key it
 * could not verify, URLs that are not of the submitted host — was sent four
 * more times over about half an hour, failing the same way each time, before
 * the rows ran out of attempts. A refusal is not a transient condition, and is
 * now parked at once where the dashboard counts it and
 * `scripts/drain-indexnow.php --retry-failed` re-queues it, which is the right
 * thing to do only after the cause is fixed.
 */
enum PingOutcome: string
{
    /** IndexNow accepted the batch (2xx): the rows are done and deleted. */
    case Accepted = 'accepted';

    /**
     * No answer, a timeout, rate limiting or a server error: worth sending
     * again, after the outbox's backoff.
     */
    case Retry = 'retry';

    /**
     * Any other answer (400 bad request, 403 key not valid, 422 URLs not of
     * this host): the same batch would fail the same way, so the rows are
     * marked exhausted rather than retried — kept, not deleted, so they can be
     * re-queued once the key or host is fixed.
     */
    case Refused = 'refused';

    /** Classify the HTTP status {@see Pinger::submitIndexNow()} returned. */
    public static function fromStatus(int $status): self
    {
        if (Pinger::accepted($status)) {
            return self::Accepted;
        }
        return Pinger::retryable($status) ? self::Retry : self::Refused;
    }
}
