<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Db\GroupParticipant;
use OCA\CareCheck\Db\GroupParticipantMapper;
use OCA\CareCheck\Db\GroupSession;
use OCA\CareCheck\Db\GroupSessionMapper;
use OCA\CareCheck\Exception\ApiException;
use OCP\IDBConnection;

/**
 * NH-12 — group sessions (1 worker : N clients, Teilnehmerliste artefact).
 *
 * The 1:1 visit model stays untouched: a session records attendance,
 * and closing it writes one journaled `group_session` entry per
 * participant client — so every client's evidence chain shows the
 * worker + session, and the Teilnehmerliste CSV is derivable proof.
 * Every participant id is scope-checked for the acting worker before
 * insert; idempotent on (actor_ref, idem_key) for offline retries.
 */
class GroupSessionService
{
	public function __construct(
		private IDBConnection $db,
		private GroupSessionMapper $sessions,
		private GroupParticipantMapper $parts,
		private ClientService $clients,
		private AclService $acl,
		private JournalService $journal,
	) {
	}

	/**
	 * Open a session. Every participant client must be in the actor's
	 * scope — a single out-of-scope id fails the whole call (uniform 404,
	 * no partial insert).
	 * @param list<int> $clientIds
	 * @return array{id:int,status:string}
	 */
	public function open(string $actorRef, string $title, array $clientIds, string $idemKey): array
	{
		$title = trim($title);
		if ($title === '') {
			throw ApiException::validation('Validation failed.', ['title' => 'required']);
		}
		if ($idemKey === '') {
			throw ApiException::validation('Validation failed.', ['idem_key' => 'required']);
		}
		$clientIds = array_values(array_unique(array_map('intval', $clientIds)));
		if ($clientIds === []) {
			throw ApiException::validation('Validation failed.', ['client_ids' => 'required']);
		}
		if (count($clientIds) > 50) {
			throw ApiException::validation('Validation failed.', ['client_ids' => 'too_many']);
		}
		// idempotent replay — offline retries must not double-create
		$existing = $this->sessions->findByIdem($actorRef, $idemKey);
		if ($existing !== null) {
			return ['id' => (int)$existing->getId(), 'status' => (string)$existing->getStatus(), 'idempotent' => true];
		}
		// scope-check ALL participants first — uniform 404, no partial open
		foreach ($clientIds as $cid) {
			$this->clients->getForActor($actorRef, $cid);
		}
		$s = new GroupSession();
		$s->setActorRef($actorRef);
		$s->setTitle($title);
		$s->setStatus('open');
		$s->setIdemKey($idemKey);
		$s->setStartedAt(new \DateTime());
		$this->db->beginTransaction();
		try {
			$this->sessions->insert($s);
			foreach ($clientIds as $cid) {
				$p = new GroupParticipant();
				$p->setSessionId((int)$s->getId());
				$p->setClientId($cid);
				$p->setAttended(1);
				$this->parts->insert($p);
			}
			foreach ($clientIds as $cid) {
				$this->journal->append($cid, 'group_session_open', $actorRef, [
					'session_id' => (int)$s->getId(), 'title' => $title,
				]);
			}
			$this->db->commit();
		} catch (\Throwable $t) {
			$this->db->rollBack();
			if ($t instanceof \OCP\DB\Exception
				&& $t->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION
			) {
				// Two writers raced the same actor-scoped idem key (same gap as
				// VisitService::checkin). Re-read and replay — same response.
				$dup = $this->sessions->findByIdem($actorRef, $idemKey);
				if ($dup !== null) {
					return ['id' => (int)$dup->getId(), 'status' => (string)$dup->getStatus(), 'idempotent' => true];
				}
			}
			throw $t;
		}
		$this->acl->audit($actorRef, 'group_session', null, 'open:' . (int)$s->getId());
		return ['id' => (int)$s->getId(), 'status' => 'open'];
	}

	/**
	 * Mark one participant absent/present. Session must be open and owned
	 * by the actor (or staff).
	 */
	public function setAttended(string $actorRef, int $sessionId, int $clientId, bool $attended): void
	{
		$s = $this->sessionFor($actorRef, $sessionId);
		if ((string)$s->getStatus() !== 'open') {
			throw ApiException::conflict('Session already closed.');
		}
		foreach ($this->parts->findBySession($sessionId) as $p) {
			if ((int)$p->getClientId() === $clientId) {
				$p->setAttended($attended ? 1 : 0);
				$this->parts->update($p);
				return;
			}
		}
		throw ApiException::notFound();
	}

	/**
	 * Close the session: writes the Teilnehmerliste journal entry per
	 * participant (attended flag + session ref) — append-only evidence.
	 */
	public function close(string $actorRef, int $sessionId): void
	{
		$s = $this->sessionFor($actorRef, $sessionId);
		if ((string)$s->getStatus() !== 'open') {
			return; // idempotent close
		}
		$parts = $this->parts->findBySession($sessionId);
		$this->db->beginTransaction();
		try {
			$s->setStatus('closed');
			$s->setClosedAt(new \DateTime());
			$this->sessions->update($s);
			foreach ($parts as $p) {
				$this->journal->append((int)$p->getClientId(), 'group_session_close', $actorRef, [
					'session_id' => $sessionId,
					'title' => (string)$s->getTitle(),
					'attended' => (int)$p->getAttended() === 1,
					'participants' => count($parts),
				]);
			}
			$this->db->commit();
		} catch (\Throwable $t) {
			$this->db->rollBack();
			throw $t;
		}
		$this->acl->audit($actorRef, 'group_session', null, 'close:' . $sessionId);
	}

	/** Teilnehmerliste rows for export/UI — scoped to owner or staff. */
	public function list(string $actorRef, int $sessionId): array
	{
		$s = $this->sessionFor($actorRef, $sessionId);
		$out = [];
		foreach ($this->parts->findBySession($sessionId) as $p) {
			$cid = (int)$p->getClientId();
			try {
				$c = $this->clients->getForActor($actorRef, $cid);
				$name = (string)($this->clients->hydrated($c)['payload']['name'] ?? '');
			} catch (ApiException) {
				$name = ''; // scope revoked after the fact — never leak
			}
			$out[] = ['clientId' => $cid, 'name' => $name, 'attended' => (int)$p->getAttended() === 1];
		}
		return [
			'id' => $sessionId,
			'title' => (string)$s->getTitle(),
			'status' => (string)$s->getStatus(),
			'startedAt' => $s->getStartedAt()?->format('Y-m-d H:i:s'),
			'closedAt' => $s->getClosedAt()?->format('Y-m-d H:i:s'),
			'by' => (string)$s->getActorRef(),
			'participants' => $out,
		];
	}

	/**
	 * CSV Teilnehmerliste — injection-safe (formula prefix), journaled as
	 * an export on each participant's client chain? No: the export rows
	 * name every participant — auditing on each client would leak the
	 * group roster across per-client audit trails. Audit once, no client.
	 * @return array{filename:string,mime:string,content:string}
	 */
	public function exportCsv(string $actorRef, int $sessionId): array
	{
		$sess = $this->list($actorRef, $sessionId);
		$out = fopen('php://temp', 'r+');
		if ($out === false) {
			throw new \RuntimeException('temp stream failed');
		}
		fputcsv($out, ['session', 'title', 'client_id', 'name', 'attended', 'by', 'started', 'closed'], ';', '"', '');
		foreach ($sess['participants'] as $p) {
			fputcsv($out, [
				$sessionId,
				$this->csvSafe((string)$sess['title']),
				$p['clientId'],
				$this->csvSafe((string)$p['name']),
				$p['attended'] ? '1' : '0',
				$this->csvSafe((string)$sess['by']),
				(string)$sess['startedAt'],
				(string)($sess['closedAt'] ?? ''),
			], ';', '"', '');
		}
		rewind($out);
		$content = (string)stream_get_contents($out);
		$this->acl->audit($actorRef, 'export', null, 'group_session:' . $sessionId);
		return [
			'filename' => 'teilnehmerliste-' . $sessionId . '.csv',
			'mime' => 'text/csv; charset=utf-8',
			'content' => $content,
		];
	}

	private function sessionFor(string $actorRef, int $sessionId): GroupSession
	{
		$s = $this->sessions->find($sessionId);
		if ($s === null) {
			throw ApiException::notFound();
		}
		// owner or staff — a foreign session is indistinguishable from absent
		if ((string)$s->getActorRef() !== $actorRef && !$this->acl->isStaff($actorRef)) {
			throw ApiException::notFound();
		}
		return $s;
	}

	private function csvSafe(string $v): string
	{
		if ($v !== '' && preg_match('/^[=+\-@\x09\x0D\x0B]/', $v) === 1) {
			return "'" . $v;
		}
		return $v;
	}
}
