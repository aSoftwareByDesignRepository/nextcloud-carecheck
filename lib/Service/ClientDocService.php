<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Db\ClientDoc;
use OCA\CareCheck\Db\ClientDocMapper;
use OCA\CareCheck\Db\Notice;
use OCA\CareCheck\Db\NoticeMapper;
use OCA\CareCheck\Exception\ApiException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

/**
 * NH-19 + NH-15 — client document links (provider documents living in
 * Nextcloud Files) attached to a client, with expiry warnings.
 *
 * Documents are LINKED, never copied: the file stays in Files, we store
 * its filecache id. Adding resolves a path under the acting staff user's
 * files; reading streams through the scope-checked companion/web
 * endpoint — a paired caregiver reading an attached Handlungsanweisung
 * is the intended access path, so the proxy read uses the instance-wide
 * file index (provider documents are deliberately shared, like the
 * client's address).
 *
 * Expiry (NH-15): daily sweep emits one notice per (doc, expiry-date) —
 * deduped by idem key; `reminded_on` prevents repeat sweeps for the same
 * expiry day even if notices get resolved.
 */
class ClientDocService
{
	/** Days before expiry the warning fires. */
	public const WARN_DAYS = 30;

	public function __construct(
		private ClientDocMapper $docs,
		private NoticeMapper $notices,
		private AclService $acl,
		private IRootFolder $rootFolder,
	) {
	}

	/** @return list<array{id:int,label:string,expiresOn:?string}> */
	public function list(string $actorRef, int $clientId): array
	{
		$this->acl->assertClientScope($actorRef, $clientId);
		$out = [];
		foreach ($this->docs->findByClient($clientId) as $d) {
			$out[] = [
				'id' => (int)$d->getId(),
				'label' => $d->getLabel(),
				'expiresOn' => $d->getExpiresOn()?->format('Y-m-d'),
			];
		}
		return $out;
	}

	public function add(string $actorRef, int $clientId, string $label, string $filePath, ?string $expiresOn): array
	{
		$this->acl->assertStaff($actorRef);
		$this->acl->assertClientScope($actorRef, $clientId);
		$fields = [];
		$label = trim($label);
		if ($label === '' || mb_strlen($label) > 190) {
			$fields['label'] = 'invalid';
		}
		$exp = null;
		if ($expiresOn !== null && $expiresOn !== '') {
			$exp = \DateTime::createFromFormat('Y-m-d', $expiresOn);
			if ($exp === false || $exp->format('Y-m-d') !== $expiresOn) {
				$fields['expires_on'] = 'invalid';
			}
		}
		// resolve under the acting staff user's files — only uid actors
		// have a Files home; member actors are rejected by assertStaff.
		$uid = str_starts_with($actorRef, 'uid:') ? substr($actorRef, 4) : null;
		$fileId = null;
		if ($uid === null) {
			$fields['file'] = 'invalid';
		} else {
			$path = '/' . ltrim(trim($filePath), '/');
			try {
				$node = $this->rootFolder->getUserFolder($uid)->get($path);
				if (!$node instanceof File) {
					$fields['file'] = 'not_a_file';
				} else {
					$fileId = $node->getId();
				}
			} catch (\Throwable) {
				$fields['file'] = 'invalid';
			}
		}
		if ($fields !== []) {
			throw ApiException::validation('Validation failed.', $fields);
		}
		$d = new ClientDoc();
		$d->setClientId($clientId);
		$d->setLabel($label);
		$d->setFileId($fileId);
		$d->setExpiresOn($exp);
		$d->setCreatedBy($actorRef);
		$d->setCreatedAt(new \DateTime());
		$d = $this->docs->insert($d);
		return ['id' => (int)$d->getId()];
	}

	public function remove(string $actorRef, int $clientId, int $docId): void
	{
		$this->acl->assertStaff($actorRef);
		$this->acl->assertClientScope($actorRef, $clientId);
		$d = $this->docs->find($docId);
		if ($d === null || (int)$d->getClientId() !== $clientId) {
			throw ApiException::notFound();
		}
		$this->docs->delete($d);
	}

	/** Stream the underlying file for a scope-checked actor. */
	public function fileFor(string $actorRef, int $clientId, int $docId): File
	{
		$this->acl->assertClientScope($actorRef, $clientId);
		$d = $this->docs->find($docId);
		if ($d === null || (int)$d->getClientId() !== $clientId) {
			throw ApiException::notFound();
		}
		$nodes = $this->rootFolder->getById((int)$d->getFileId());
		foreach ($nodes as $n) {
			if ($n instanceof File) {
				return $n;
			}
		}
		throw ApiException::notFound();
	}

	/**
	 * Daily sweep — one 'info' notice per (doc, expiry date) when expiry is
	 * within WARN_DAYS or already past. `reminded_on` + the idem key make
	 * re-runs and overlapping jobs safe.
	 * @return int number of notices emitted
	 */
	public function expirySweep(): int
	{
		$today = (new \DateTime('today'))->format('Y-m-d');
		$horizon = (new \DateTime('today'))->modify('+' . self::WARN_DAYS . ' days')->format('Y-m-d');
		$emitted = 0;
		foreach ($this->docs->findExpiring() as $d) {
			$exp = $d->getExpiresOn();
			if ($exp === null) {
				continue;
			}
			$expStr = $exp->format('Y-m-d');
			if ($expStr > $horizon) {
				continue;
			}
			$reminded = $d->getRemindedOn()?->format('Y-m-d');
			if ($reminded !== null && $reminded >= $expStr) {
				continue; // already warned for this expiry
			}
			$n = new Notice();
			$n->setClientId((int)$d->getClientId());
			$n->setActorRef('system');
			$n->setKind('info');
			$n->setPriority('normal');
			// label is non-encrypted by design (it's a doc title like
			// 'Betreuungsverfügung', not health data) — keep the note free
			// of it anyway; the client journal shows the doc row itself.
			$n->setNoteEnc(null);
			$n->setState('open');
			$n->setIdemKey('doc-exp:' . (int)$d->getId() . ':' . $expStr);
			$n->setCreatedAt(new \DateTime());
			try {
				$this->notices->insert($n);
				$d->setRemindedOn(new \DateTime($today));
				$this->docs->update($d);
				$emitted++;
			} catch (\OCP\DB\Exception $e) {
				if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				// concurrent run emitted it — still mark reminded
				$d->setRemindedOn(new \DateTime($today));
				$this->docs->update($d);
			}
		}
		return $emitted;
	}
}
