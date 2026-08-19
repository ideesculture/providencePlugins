<?php
/* Book Creator plugin for CollectiveAccess
 *
 * Plugin by idéesculture – Gautier MICHELIN
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License v3. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * ----------------------------------------------------------------------
 */

/**
 * Generation queue of the bookCreator plugin (table `plugin_book_jobs`).
 *
 * One row per generation request. The web side only ever submits a job and
 * polls its status; the rendering itself happens in bin/bookworker.php, which
 * is the only consumer of claimNext()/updateProgress()/finish()/fail().
 *
 * Design rules, deliberate:
 *  - prepared statements only. Every value goes through a placeholder, exactly
 *    like plugin_books; no value is ever concatenated into SQL.
 *  - the queue holds no business logic. It does not know what a book is, what
 *    a section is or how a PDF is produced: it hands out work and records
 *    state, so that it can be reasoned about (and locked) on its own.
 *  - a claim is a single UPDATE. Reading a pending row and then updating it
 *    would let two workers pick the same job in the window between the two
 *    statements; see claimNext() for why the single statement cannot.
 *  - nothing here writes to stdout or throws on a database error. Callers get
 *    false or null and decide what to do, because the same methods are used
 *    from a controller (where an exception is a 500) and from a CLI worker
 *    (where it is an exit code).
 */
class BookJobModel {

	const TABLE = 'plugin_book_jobs';

	/** Job lifecycle. pending -> running -> done | error, with running -> pending on requeue. */
	const STATUS_PENDING = 'pending';
	const STATUS_RUNNING = 'running';
	const STATUS_DONE    = 'done';
	const STATUS_ERROR   = 'error';

	/** Statuses that mean "this job still has work to do". */
	const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING];

	/** Columns read back by get()/claimNext(); also the shape returned to callers. */
	const COLUMNS = [
		'job_id', 'book_id', 'user_id', 'status', 'progress',
		'message', 'pdf_path', 'created_on', 'started_on', 'finished_on', 'worker_id',
	];

	/** @var Db */
	private $db;

	/** Message of the last statement that failed, for callers that report it. */
	private $last_error = null;

	public function __construct($db = null) {
		$this->db = $db ? $db : new Db();
	}

	/**
	 * Runs a statement and returns false instead of throwing.
	 *
	 * Db::query() throws a DatabaseException on a SQL error rather than
	 * returning false (Db/mysqli.php:328). On the web side that surfaces as a
	 * Providence system error page; in the worker it kills the process in the
	 * middle of a render, leaving the job stuck in 'running' until reapStale()
	 * picks it up an hour later. Both are worse than a job that fails cleanly,
	 * so every statement of this class goes through here.
	 *
	 * @return mixed the query result, or false when the statement failed
	 */
	private function query(string $sql, array $params = array()) {
		$this->last_error = null;
		try {
			$qr = $this->db->query($sql, $params);
		} catch (Exception $e) {
			$this->last_error = $e->getMessage();
			return false;
		}
		// Same as plugin_books::run(): a SQL error arrives as an exception, never
		// as numErrors(), so the catch above is the whole mechanism.
		return $qr;
	}

	/**
	 * Clears the recorded output path of jobs whose file has been deleted.
	 *
	 * Called by the worker after it removes the superseded PDF of a book. The
	 * status is left alone — the job did run and did produce a file — only the
	 * path is forgotten, so a caller reading an old job knows there is nothing
	 * to serve rather than pointing at a missing file.
	 *
	 * @param string[] $paths absolute paths that no longer exist
	 */
	public function forgetOutputs(array $paths): int {
		$paths = array_values(array_filter($paths, 'strlen'));
		if (!$paths) { return 0; }

		$placeholders = join(', ', array_fill(0, sizeof($paths), '?'));
		$qr = $this->query(
			"UPDATE `" . self::TABLE . "` SET pdf_path = NULL WHERE pdf_path IN ({$placeholders})",
			$paths
		);
		return $qr ? (int)$this->db->affectedRows() : 0;
	}

	/**
	 * Message of the last failed statement, or null when the last one worked.
	 */
	public function getLastError(): ?string {
		return $this->last_error;
	}

	# -------------------------------------------------------
	# Submission (web side)
	# -------------------------------------------------------

	/**
	 * Queues a generation job for a book and returns its id.
	 *
	 * Submitting twice is the normal case, not an edge case: the editor clicks
	 * "Generate", nothing visible happens for a few seconds, and the click is
	 * repeated. A second row would mean the same 200-page book rendered twice
	 * in parallel, so an already pending or running job for the same book wins
	 * and its id is returned instead. The caller cannot tell the difference,
	 * and does not need to: it polls that id either way.
	 *
	 * A job is a whole book. The queue carried a second kind — one section on
	 * its own — that nothing ever submitted: the editor previews a section as
	 * HTML, it never queues it. What it did do was rot, and its half-valid
	 * states rendered books without folios and with an empty table of contents.
	 *
	 * @return int job id, or 0 when the insert failed
	 */
	public function submit(int $bookId, ?int $userId = null): int {
		if ($bookId <= 0) { return 0; }

		if ($existing = $this->getActiveForBook($bookId)) {
			return (int)$existing['job_id'];
		}

		// The user is recorded because the worker runs without a session and
		// still has to honour the permissions of whoever asked: a section can
		// point at any set or representation by id.
		$qr = $this->query(
			"INSERT INTO `" . self::TABLE . "` (book_id, user_id, status, progress, created_on)
			 VALUES (?, ?, ?, 0, ?)",
			[$bookId, ($userId > 0 ? $userId : null), self::STATUS_PENDING, time()]
		);
		if (!$qr) { return 0; }

		return (int)$this->db->getLastInsertID();
	}

	# -------------------------------------------------------
	# Claiming (worker side)
	# -------------------------------------------------------

	/**
	 * Atomically claims the oldest pending job for the given worker.
	 *
	 * Why the single UPDATE is safe, and a SELECT-then-UPDATE is not:
	 *
	 *  - InnoDB evaluates the WHERE clause and writes the row inside the same
	 *    statement, holding an exclusive lock on the row it touches. A second
	 *    worker issuing the same UPDATE at the same instant blocks on that
	 *    lock; when it is released, the UPDATE re-reads the freshly committed
	 *    version of the row (an UPDATE always reads the latest committed data,
	 *    whatever the isolation level) and finds status = 'running', so the row
	 *    no longer matches `WHERE status = 'pending'`. It moves on to the next
	 *    pending row, or matches nothing at all. Two workers can therefore
	 *    never leave this statement holding the same job_id.
	 *  - a SELECT ... WHERE status='pending' followed by an UPDATE has a window
	 *    between the two statements during which both workers have read the
	 *    same row and both believe they own it. The window is short, which is
	 *    exactly what makes the bug rare enough to reach production.
	 *
	 * The read-back is not a second chance to lose the race: the row is stamped
	 * with a token unique to this claim (worker id plus 8 random bytes), never
	 * reused, so `WHERE worker_id = <token>` can only ever match the row this
	 * very statement just took. Reusing the plain worker id would be ambiguous
	 * — a worker restarted with the same pid on the same host could read back a
	 * stale row left behind by its predecessor.
	 *
	 * MySQL's LAST_INSERT_ID(job_id) trick is an equally atomic alternative;
	 * the token was preferred because it survives connection pooling and reads
	 * as what it is.
	 *
	 * @param string $workerId human readable worker identity, e.g. host:pid
	 * @return array|null the claimed job, already running, or null when the queue is empty
	 */
	public function claimNext(string $workerId): ?array {
		$token = $this->claimToken($workerId);

		$qr = $this->query(
			"UPDATE `" . self::TABLE . "`
			 SET status = ?, worker_id = ?, started_on = ?, progress = 0, message = NULL
			 WHERE status = ?
			 ORDER BY created_on, job_id
			 LIMIT 1",
			[self::STATUS_RUNNING, $token, time(), self::STATUS_PENDING]
		);
		if (!$qr || (int)$this->db->affectedRows() < 1) { return null; }

		return $this->getByClaimToken($token);
	}

	/**
	 * Claims one specific job, for `bookworker.php --job=N`.
	 *
	 * Same statement as claimNext(), narrowed to a single id: a job already
	 * running or already finished does not match and null is returned, so a
	 * manual replay can never fight with the cron worker over the same row.
	 */
	public function claim(int $jobId, string $workerId): ?array {
		if ($jobId <= 0) { return null; }
		$token = $this->claimToken($workerId);

		$qr = $this->query(
			"UPDATE `" . self::TABLE . "`
			 SET status = ?, worker_id = ?, started_on = ?, progress = 0, message = NULL
			 WHERE job_id = ? AND status = ?",
			[self::STATUS_RUNNING, $token, time(), $jobId, self::STATUS_PENDING]
		);
		if (!$qr || (int)$this->db->affectedRows() < 1) { return null; }

		return $this->getByClaimToken($token);
	}

	# -------------------------------------------------------
	# Progress and completion (worker side)
	# -------------------------------------------------------

	/**
	 * Records progress of a running job, after each rendered section.
	 *
	 * A null message leaves the current one untouched, so a caller can push a
	 * percentage without erasing the label the user is reading. The percentage
	 * is clamped rather than rejected: the column is a TINYINT UNSIGNED and a
	 * rounding error upstream must not abort a two-minute render.
	 */
	public function updateProgress(int $jobId, int $percent, ?string $message = null, ?string $claimToken = null): bool {
		if ($jobId <= 0) { return false; }
		$percent = max(0, min(100, $percent));

		// truncateMessage() like every other message writer. This one was the
		// exception, and it is the one the worker uses to report its warnings:
		// past roughly nine hundred of them the TEXT column overflows, strict
		// mode rejects the statement, and every warning of the job is lost —
		// precisely the case the warning mechanism exists for.
		if ($message !== null) { $message = $this->truncateMessage($message); }

		$sql = "UPDATE `" . self::TABLE . "` SET progress = ?"
			.($message === null ? '' : ', message = ?')
			." WHERE job_id = ? AND status = ?";
		$params = [$percent];
		if ($message !== null) { $params[] = $message; }
		$params[] = $jobId;
		$params[] = self::STATUS_RUNNING;

		// The claim token narrows the write to the worker that holds the job;
		// see finish() for why that matters.
		if ($claimToken !== null) {
			$sql .= " AND worker_id = ?";
			$params[] = $claimToken;
		}

		// affectedRows(), like finish(), fail(), cancel() and release(). Returning
		// true as soon as the statement ran meant a worker whose job had been
		// requeued kept "progressing" successfully through an entire render and
		// never found out.
		$qr = $this->query($sql, $params);
		return ($qr && (int)$this->db->affectedRows() > 0);
	}

	/**
	 * Marks a job done and records where the PDF landed.
	 *
	 * Restricted to a running job on purpose. A job reaped as stale (see
	 * reapStale) may already have been claimed by another worker; the late
	 * worker then gets false here instead of overwriting a fresher run.
	 */
	public function finish(int $jobId, string $pdfPath, ?string $claimToken = null): bool {
		if ($jobId <= 0) { return false; }

		// The claim token, not merely the status. reapStale() requeues the same
		// job_id, so a job reaped while its worker is still alive is claimed a
		// second time and is running for the newcomer: testing the status alone
		// let the stale worker finish first and record its own file, after which
		// the fresh one found the job done and threw its result away — the exact
		// opposite of what this method used to promise. The token is unique to
		// one claim, so only the worker that currently holds the job can close
		// it.
		$sql = "UPDATE `" . self::TABLE . "`
			 SET status = ?, progress = 100, pdf_path = ?, finished_on = ?
			 WHERE job_id = ? AND status = ?";
		$params = [self::STATUS_DONE, $pdfPath, time(), $jobId, self::STATUS_RUNNING];

		if ($claimToken !== null) {
			$sql .= " AND worker_id = ?";
			$params[] = $claimToken;
		}

		$qr = $this->query($sql, $params);
		return ($qr && (int)$this->db->affectedRows() > 0);
	}

	/**
	 * Marks a job failed, keeping the message the UI will display.
	 *
	 * The message is truncated to something a TEXT column and a notification
	 * can both carry: a PHP stack trace or a full WeasyPrint log belongs in the
	 * worker output, not in a row polled every two seconds.
	 */
	public function fail(int $jobId, string $message, ?string $claimToken = null): bool {
		if ($jobId <= 0) { return false; }

		$sql = "UPDATE `" . self::TABLE . "`
			 SET status = ?, message = ?, finished_on = ?
			 WHERE job_id = ? AND status = ?";
		$params = [self::STATUS_ERROR, $this->truncateMessage($message), time(), $jobId, self::STATUS_RUNNING];

		// Same reasoning as finish(): a stale worker must not be able to mark
		// failed a job another worker is running.
		if ($claimToken !== null) {
			$sql .= " AND worker_id = ?";
			$params[] = $claimToken;
		}

		$qr = $this->query($sql, $params);
		return ($qr && (int)$this->db->affectedRows() > 0);
	}

	/**
	 * Cancels a job that has not been picked up yet.
	 *
	 * Without this a book can be stuck for good: submit() deliberately returns
	 * the pending job of a book rather than queueing a second one, so if the
	 * worker is not running — the normal state of a fresh install, and the one
	 * the README warns about — the button keeps handing back the same job and
	 * the editor has no way out.
	 *
	 * Only a pending job can be cancelled. A running one belongs to a worker
	 * that is writing files right now; marking it cancelled from the web side
	 * would leave that worker finishing a job nobody expects any more. Such a
	 * job is dealt with by reapStale(), which is the mechanism for it.
	 */
	public function cancel(int $jobId): bool {
		if ($jobId <= 0) { return false; }

		$qr = $this->query(
			"UPDATE `" . self::TABLE . "`
			 SET status = ?, message = ?, finished_on = ?
			 WHERE job_id = ? AND status = ?",
			[self::STATUS_ERROR, $this->truncateMessage(IdC::_t('Cancelled before it started.')), time(), $jobId, self::STATUS_PENDING]
		);
		return ($qr && (int)$this->db->affectedRows() > 0);
	}

	/**
	 * Puts a running job back in the queue, without counting it as a failure.
	 *
	 * Used when the worker is asked to stop (SIGTERM from a cron wrapper, pod
	 * eviction under Kubernetes): the job returns to pending and the next
	 * worker picks it up from the start, rather than sitting in running until
	 * the reaper notices an hour later.
	 */
	public function release(int $jobId, ?string $message = null): bool {
		if ($jobId <= 0) { return false; }

		$qr = $this->query(
			"UPDATE `" . self::TABLE . "`
			 SET status = ?, worker_id = NULL, started_on = NULL, progress = 0, message = ?
			 WHERE job_id = ? AND status = ?",
			[self::STATUS_PENDING, ($message === null ? null : $this->truncateMessage($message)), $jobId, self::STATUS_RUNNING]
		);
		return ($qr && (int)$this->db->affectedRows() > 0);
	}

	# -------------------------------------------------------
	# Reads (AJAX polling, diagnostics)
	# -------------------------------------------------------

	/** One job by id, or null when it does not exist. */
	public function get(int $jobId): ?array {
		if ($jobId <= 0) { return null; }

		$qr = $this->query(
			"SELECT " . $this->columnList() . " FROM `" . self::TABLE . "` WHERE job_id = ?",
			[$jobId]
		);
		if (!$qr || !$qr->nextRow()) { return null; }

		return $this->hydrate($qr->getRow());
	}

	/**
	 * Most recent job of a book, whatever its status.
	 *
	 * This is what the progress endpoint polls: while the job runs it returns
	 * the live percentage, and once it is over it keeps returning the finished
	 * row, so the page can show the download link (or the error) without a
	 * second query and without the client having to remember a job id.
	 */
	public function getForBook(int $bookId): ?array {
		if ($bookId <= 0) { return null; }

		$qr = $this->query(
			"SELECT " . $this->columnList() . " FROM `" . self::TABLE . "`
			 WHERE book_id = ?
			 ORDER BY created_on DESC, job_id DESC
			 LIMIT 1",
			[$bookId]
		);
		if (!$qr || !$qr->nextRow()) { return null; }

		return $this->hydrate($qr->getRow());
	}

	/** Pending or running job of a book, used by submit() to refuse duplicates. */
	public function getActiveForBook(int $bookId): ?array {
		if ($bookId <= 0) { return null; }

		$qr = $this->query(
			"SELECT " . $this->columnList() . " FROM `" . self::TABLE . "`
			 WHERE book_id = ? AND status IN (?, ?)
			 ORDER BY created_on, job_id
			 LIMIT 1",
			[$bookId, self::STATUS_PENDING, self::STATUS_RUNNING]
		);
		if (!$qr || !$qr->nextRow()) { return null; }

		return $this->hydrate($qr->getRow());
	}


	# -------------------------------------------------------
	# Housekeeping
	# -------------------------------------------------------

	/**
	 * Requeues jobs left running by a worker that died without finishing.
	 *
	 * A killed pod, an OOM, a cron worker cut short mid-render: the row stays
	 * in running for ever, submit() then keeps returning that dead job and the
	 * book can never be generated again. Anything running for longer than the
	 * threshold goes back to pending, and the next worker starts it over.
	 *
	 * The threshold has to stay well above the longest legitimate render — the
	 * 220-page catalogue takes minutes, not an hour — because a job requeued
	 * while its worker is still alive would be rendered twice. The live worker
	 * loses the race harmlessly: its finish() no longer matches a running row
	 * and returns false.
	 *
	 * @return int number of jobs requeued
	 */
	public function reapStale(int $olderThanSeconds = 3600): int {
		if ($olderThanSeconds < 1) { return 0; }
		$cutoff = time() - $olderThanSeconds;

		$qr = $this->query(
			"UPDATE `" . self::TABLE . "`
			 SET status = ?, worker_id = NULL, started_on = NULL, progress = 0, message = ?
			 WHERE status = ? AND (started_on IS NULL OR started_on < ?)",
			[self::STATUS_PENDING, IdC::_t('Requeued: the previous worker stopped without finishing.'), self::STATUS_RUNNING, $cutoff]
		);
		if (!$qr) { return 0; }

		return (int)$this->db->affectedRows();
	}

	# -------------------------------------------------------
	# Internals
	# -------------------------------------------------------

	/**
	 * Token stamped on a claimed row, unique to one claim.
	 *
	 * Shape: <worker id, trimmed>#<16 hex chars>, at most 57 characters, which
	 * fits worker_id VARCHAR(64) with room to spare. The readable prefix is
	 * what an operator greps for when hunting a stuck job; the suffix is what
	 * makes the read-back unambiguous.
	 */
	private function claimToken(string $workerId): string {
		$workerId = preg_replace('/[^A-Za-z0-9_.:\-]/', '-', $workerId);
		if ($workerId === '') { $workerId = 'worker'; }
		return substr($workerId, 0, 40) . '#' . bin2hex(random_bytes(8));
	}

	/** Reads back the row a claim statement just stamped. */
	private function getByClaimToken(string $token): ?array {
		$qr = $this->query(
			"SELECT " . $this->columnList() . " FROM `" . self::TABLE . "`
			 WHERE worker_id = ? AND status = ?
			 LIMIT 1",
			[$token, self::STATUS_RUNNING]
		);
		if (!$qr || !$qr->nextRow()) { return null; }

		return $this->hydrate($qr->getRow());
	}

	/** Backtick-quoted column list; built from the constant, never from input. */
	private function columnList(): string {
		return '`' . join('`, `', self::COLUMNS) . '`';
	}

	/** Keeps a message short enough to be displayed as is by the UI. */
	private function truncateMessage(string $message): string {
		$message = trim($message);
		return (mb_strlen($message) > 2000) ? mb_substr($message, 0, 1997) . '...' : $message;
	}

	/**
	 * Turns a raw row into the array the rest of the plugin works with.
	 *
	 * Integers come back as strings from the driver; the polling endpoint
	 * encodes them to JSON, where "progress": "42" and "progress": 42 are not
	 * the same thing for the JavaScript reading it.
	 */
	private function hydrate(array $row): array {
		return [
			'job_id'      => (int)$row['job_id'],
			'book_id'     => (int)$row['book_id'],
			'user_id'     => isset($row['user_id']) ? (int)$row['user_id'] : null,
			'status'      => (string)$row['status'],
			'progress'    => (int)$row['progress'],
			'message'     => isset($row['message']) ? (string)$row['message'] : null,
			'pdf_path'    => isset($row['pdf_path']) ? (string)$row['pdf_path'] : null,
			'created_on'  => isset($row['created_on']) ? (int)$row['created_on'] : null,
			'started_on'  => isset($row['started_on']) ? (int)$row['started_on'] : null,
			'finished_on' => isset($row['finished_on']) ? (int)$row['finished_on'] : null,
			'worker_id'   => isset($row['worker_id']) ? (string)$row['worker_id'] : null,
		];
	}
}
