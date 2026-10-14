<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Db\Photo;
use OCA\CareCheck\Db\PhotoMapper;
use OCA\CareCheck\Exception\ApiException;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;

/**
 * NH-04 — clinical photo documentation (consent-gated).
 *
 * Hard rules:
 * - the CLIENT's `photo_consent` payload flag must be set — the server
 *   refuses to store without it (consent is never inferred from the
 *   uploader's claim, it is provider master data);
 * - bytes are mime-sniffed server-side against a strict allow-list
 *   (declared mime is never trusted) and hard-capped;
 * - the blob is AES-256-GCM sealed and lives in appdata, keyed by a
 *   random `blob_key` — the DB row holds only metadata + a plaintext
 *   sha256 for integrity proof;
 * - list/read/delete are scope-checked and access-audited; stores and
 *   deletes are journaled (append-only evidence).
 */
class PhotoService
{
	/** Hard cap — clinical snapshots, not RAW archives. */
	private const MAX_BYTES = 5242880; // 5 MiB

	private const MIME_BY_EXT = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

	public function __construct(
		private IDBConnection $db,
		private PhotoMapper $photos,
		private ClientService $clients,
		private AclService $acl,
		private CryptoService $crypto,
		private JournalService $journal,
		private IAppData $appData,
	) {
	}

	/** @return list<array<string,mixed>> metadata only — bytes via fileFor() */
	public function list(string $actorRef, int $clientId): array
	{
		$this->clients->getForActor($actorRef, $clientId);
		$out = [];
		foreach ($this->photos->findByClient($clientId) as $p) {
			$out[] = [
				'id' => (int)$p->getId(),
				'visitId' => $p->getVisitId() !== null ? (int)$p->getVisitId() : null,
				'mime' => (string)$p->getMime(),
				'size' => (int)$p->getByteSize(),
				'createdAt' => $p->getCreatedAt()?->format('Y-m-d H:i:s'),
				'by' => (string)$p->getActorRef(),
			];
		}
		return $out;
	}

	/**
	 * Store one photo. `visitId` is optional (wound doc outside a visit is
	 * legal) but when given must belong to the same client.
	 * @return array<string,mixed> the stored metadata row
	 */
	public function store(string $actorRef, int $clientId, ?int $visitId, string $declaredMime, string $bytes): array
	{
		$client = $this->clients->getForActor($actorRef, $clientId);
		$payload = $this->clients->hydrated($client)['payload'];
		if (empty($payload['photo_consent'])) {
			throw ApiException::validation('Validation failed.', ['photo' => 'consent_required']);
		}
		if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
			throw ApiException::validation('Validation failed.', ['photo' => 'size']);
		}
		$mime = $this->sniff($bytes);
		if ($mime === null || $mime !== $declaredMime) {
			throw ApiException::validation('Validation failed.', ['photo' => 'type']);
		}
		if ($visitId !== null) {
			$v = $this->visitRow($visitId);
			if ($v === null || (int)$v['client_id'] !== $clientId) {
				throw ApiException::notFound();
			}
		}

		$blobKey = bin2hex(random_bytes(16));
		$this->blobFolder($clientId)->newFile($blobKey)->putContent($this->crypto->encrypt($bytes));

		$p = new Photo();
		$p->setClientId($clientId);
		$p->setVisitId($visitId);
		$p->setActorRef($actorRef);
		$p->setMime($mime);
		$p->setByteSize(strlen($bytes));
		$p->setSha256(hash('sha256', $bytes));
		$p->setBlobKey($blobKey);
		$p->setCreatedAt(new \DateTime());
		$this->db->beginTransaction();
		try {
			$this->photos->insert($p);
			$this->journal->append($clientId, 'photo_add', $actorRef, [
				'photo_id' => (int)$p->getId(), 'sha256' => $p->getSha256(), 'visit_id' => $visitId,
			]);
			$this->db->commit();
		} catch (\Throwable $t) {
			$this->db->rollBack();
			try {
				$this->blobFolder($clientId)->getFile($blobKey)->delete();
			} catch (\Throwable) {
			}
			throw $t;
		}
		$this->acl->audit($actorRef, 'photo_add', $clientId, (string)$p->getId());
		return ['id' => (int)$p->getId(), 'mime' => $mime, 'size' => strlen($bytes)];
	}

	/** @return array{mime:string,bytes:string,sha256:string} */
	public function fileFor(string $actorRef, int $clientId, int $photoId): array
	{
		$this->clients->getForActor($actorRef, $clientId);
		$p = $this->photos->find($photoId);
		if ($p === null || (int)$p->getClientId() !== $clientId) {
			throw ApiException::notFound();
		}
		try {
			$bytes = $this->crypto->decrypt(
				$this->blobFolder($clientId)->getFile((string)$p->getBlobKey())->getContent()
			);
		} catch (NotFoundException|\RuntimeException) {
			throw ApiException::notFound();
		}
		if (!hash_equals((string)$p->getSha256(), hash('sha256', $bytes))) {
			throw new \RuntimeException('photo integrity failure');
		}
		$this->acl->audit($actorRef, 'photo_read', $clientId, (string)$photoId);
		return ['mime' => (string)$p->getMime(), 'bytes' => $bytes, 'sha256' => (string)$p->getSha256()];
	}

	public function remove(string $actorRef, int $clientId, int $photoId): void
	{
		$this->acl->assertStaff($actorRef);
		$p = $this->photos->find($photoId);
		if ($p === null || (int)$p->getClientId() !== $clientId) {
			throw ApiException::notFound();
		}
		$this->db->beginTransaction();
		try {
			$this->photos->delete($p);
			$this->journal->append($clientId, 'photo_del', $actorRef, [
				'photo_id' => $photoId, 'sha256' => (string)$p->getSha256(),
			]);
			$this->db->commit();
		} catch (\Throwable $t) {
			$this->db->rollBack();
			throw $t;
		}
		try {
			$this->blobFolder($clientId)->getFile((string)$p->getBlobKey())->delete();
		} catch (NotFoundException) {
		}
		$this->acl->audit($actorRef, 'photo_del', $clientId, (string)$photoId);
	}

	// ---- internals ----

	/** Sniff the real type — declared mime is untrusted input. */
	private function sniff(string $bytes): ?string
	{
		$tmp = tempnam(sys_get_temp_dir(), 'cckph');
		if ($tmp === false) {
			return null;
		}
		try {
			file_put_contents($tmp, $bytes);
			$info = getimagesize($tmp);
			if ($info === false) {
				return null;
			}
			$mime = image_type_to_mime_type((int)$info[2]);
			return in_array($mime, self::MIME_BY_EXT, true) ? $mime : null;
		} finally {
			@unlink($tmp);
		}
	}

	/** @return array<string,mixed>|null */
	private function visitRow(int $visitId): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'client_id')->from('cck_visit')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($visitId)));
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : $row;
	}

	private function blobFolder(int $clientId): ISimpleFolder
	{
		try {
			$root = $this->appData->getFolder('photos');
		} catch (NotFoundException) {
			$root = $this->appData->newFolder('photos');
		}
		try {
			return $root->getFolder((string)$clientId);
		} catch (NotFoundException) {
			return $root->newFolder((string)$clientId);
		}
	}
}
