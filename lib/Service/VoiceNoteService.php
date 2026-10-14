<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Db\VoiceNote;
use OCA\CareCheck\Db\VoiceNoteMapper;
use OCA\CareCheck\Exception\ApiException;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\TaskProcessing\IManager as ITaskManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\AudioToText;

/**
 * NH-01 — voice note → transcript via TaskProcessing `core:audio2text`.
 *
 * The audio blob is AES-256-GCM sealed in appdata (never plaintext at
 * rest). Transcription is asynchronous: a cron job materializes the
 * plaintext into a transient file owned by the task-owner uid (TaskInput
 * requires a File the task user can access), schedules the task, then a
 * later pass seals the transcript and deletes the temp file. Providers
 * are provider-side/on-prem (TaskProcessing); when the instance has no
 * audio2text provider the note stays `pending` — honest state, never
 * silently dropped.
 */
class VoiceNoteService
{
	/** ~10 min of compressed speech — generous cap, still bounded. */
	private const MAX_BYTES = 16777216; // 16 MiB

	private const MIME_ALLOW = [
		'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/mp4a-latm',
		'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/webm',
		'audio/3gpp', 'audio/3gpp2', 'audio/aac', 'audio/flac',
		'video/mp4', // m4a containers sniff as video/mp4 on some libmagic builds
	];

	public function __construct(
		private IDBConnection $db,
		private VoiceNoteMapper $notes,
		private ClientService $clients,
		private AclService $acl,
		private CryptoService $crypto,
		private JournalService $journal,
		private IAppData $appData,
		private IRootFolder $rootFolder,
		private IGroupManager $groups,
		private ITaskManager $tasks,
	) {
	}

	/** @return list<array<string,mixed>> metadata + transcript when done */
	public function list(string $actorRef, int $clientId): array
	{
		$this->clients->getForActor($actorRef, $clientId);
		$out = [];
		foreach ($this->notes->findByClient($clientId) as $n) {
			$row = [
				'id' => (int)$n->getId(),
				'visitId' => $n->getVisitId() !== null ? (int)$n->getVisitId() : null,
				'mime' => (string)$n->getMime(),
				'size' => (int)$n->getByteSize(),
				'status' => (string)$n->getStatus(),
				'createdAt' => $n->getCreatedAt()?->format('Y-m-d H:i:s'),
				'by' => (string)$n->getActorRef(),
				'transcript' => null,
			];
			if ($n->getStatus() === 'done' && $n->getTranscriptEnc() !== null) {
				$row['transcript'] = $this->crypto->decrypt((string)$n->getTranscriptEnc());
			}
			$out[] = $row;
		}
		return $out;
	}

	/** @return array{id:int,status:string} */
	public function store(string $actorRef, int $clientId, ?int $visitId, string $declaredMime, string $bytes): array
	{
		$this->clients->getForActor($actorRef, $clientId);
		if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
			throw ApiException::validation('Validation failed.', ['voicenote' => 'size']);
		}
		$mime = $this->sniffAudio($bytes);
		if ($mime === null) {
			throw ApiException::validation('Validation failed.', ['voicenote' => 'type']);
		}
		// declared mime is a hint only — the sniffed type is authoritative
		if ($visitId !== null) {
			$v = $this->visitRow($visitId);
			if ($v === null || (int)$v['client_id'] !== $clientId) {
				throw ApiException::notFound();
			}
		}

		$blobKey = bin2hex(random_bytes(16));
		$this->blobFolder($clientId)->newFile($blobKey)->putContent($this->crypto->encrypt($bytes));

		$n = new VoiceNote();
		$n->setClientId($clientId);
		$n->setVisitId($visitId);
		$n->setActorRef($actorRef);
		$n->setMime($mime);
		$n->setByteSize(strlen($bytes));
		$n->setSha256(hash('sha256', $bytes));
		$n->setBlobKey($blobKey);
		$n->setStatus('pending');
		$now = new \DateTime();
		$n->setCreatedAt($now);
		$n->setUpdatedAt($now);
		$this->db->beginTransaction();
		try {
			$this->notes->insert($n);
			$this->journal->append($clientId, 'voicenote_add', $actorRef, [
				'voicenote_id' => (int)$n->getId(), 'sha256' => $n->getSha256(), 'visit_id' => $visitId,
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
		$this->acl->audit($actorRef, 'voicenote_add', $clientId, (string)$n->getId());
		return ['id' => (int)$n->getId(), 'status' => 'pending'];
	}

	/**
	 * Cron pipeline: submit pending notes, then collect finished tasks.
	 * Bounded per run so a backlog cannot monopolize the cron slot.
	 */
	public function transcribePending(int $limit = 5): void
	{
		$this->collectDone();
		if (!$this->audioToTextAvailable()) {
			return;
		}
		foreach (array_slice($this->notes->findPending(), 0, $limit) as $n) {
			try {
				$this->submit($n);
			} catch (\Throwable) {
				// leave pending — transient provider/fs issues retry next pass
			}
		}
	}

	// ---- TaskProcessing pipeline ----

	private function audioToTextAvailable(): bool
	{
		try {
			return isset($this->tasks->getAvailableTaskTypes(true)[AudioToText::ID]);
		} catch (\Throwable) {
			return false;
		}
	}

	private function submit(VoiceNote $n): void
	{
		$uid = $this->taskOwnerUid((string)$n->getActorRef());
		if ($uid === null) {
			return; // no uid can own a task — stays pending, honest state
		}
		$bytes = $this->crypto->decrypt(
			$this->blobFolder((int)$n->getClientId())->getFile((string)$n->getBlobKey())->getContent()
		);
		$folder = $this->rootFolder->getUserFolder($uid);
		try {
			$dir = $folder->get('.carecheck-stt');
		} catch (NotFoundException) {
			$dir = $folder->newFolder('.carecheck-stt');
		}
		$ext = str_contains((string)$n->getMime(), '/') ? substr((string)$n->getMime(), strpos((string)$n->getMime(), '/') + 1) : 'bin';
		$file = $dir->newFile('vnote-' . (int)$n->getId() . '.' . preg_replace('/[^a-z0-9]/i', '', $ext));
		$file->putContent($bytes);
		// The task row stores the fileId; the File is resolved lazily when a
		// provider picks the task up — the temp file must live until then.
		$task = new Task(
			AudioToText::ID,
			['input' => $file->getId()],
			'carecheck',
			$uid,
			'vnote:' . (int)$n->getId(),
		);
		$this->tasks->scheduleTask($task);
		$n->setStatus('submitted');
		$n->setTaskId($task->getId());
		$n->setUpdatedAt(new \DateTime());
		$this->notes->update($n);
	}

	private function collectDone(): void
	{
		foreach ($this->notes->findSubmitted() as $n) {
			try {
				$task = $this->tasks->getTask((int)$n->getTaskId());
			} catch (\Throwable) {
				$n->setStatus('failed');
				$n->setUpdatedAt(new \DateTime());
				$this->notes->update($n);
				$this->cleanupTemp($n);
				continue;
			}
			$st = $task->getStatus();
			if ($st === Task::STATUS_SUCCESSFUL) {
				$text = (string)($task->getOutput()['output'] ?? '');
				$this->db->beginTransaction();
				try {
					$n->setTranscriptEnc($this->crypto->encrypt($text));
					$n->setStatus('done');
					$n->setUpdatedAt(new \DateTime());
					$this->notes->update($n);
					$this->journal->append((int)$n->getClientId(), 'voicenote_transcribed', (string)$n->getActorRef(), [
						'voicenote_id' => (int)$n->getId(),
						'transcript_sha256' => hash('sha256', $text),
					]);
					$this->db->commit();
				} catch (\Throwable $t) {
					$this->db->rollBack();
					throw $t;
				}
				$this->cleanupTemp($n);
				try {
					$this->tasks->deleteTask($task);
				} catch (\Throwable) {
				}
			} elseif ($st === Task::STATUS_FAILED || $st === Task::STATUS_CANCELLED) {
				$n->setStatus('failed');
				$n->setUpdatedAt(new \DateTime());
				$this->notes->update($n);
				$this->cleanupTemp($n);
				try {
					$this->tasks->deleteTask($task);
				} catch (\Throwable) {
				}
			}
		}
	}

	/**
	 * Task owner uid: the actor when it is a uid; for paired members the
	 * first Zentrale/admin uid (server-side processing, provider-owned).
	 */
	private function taskOwnerUid(string $actorRef): ?string
	{
		if (str_starts_with($actorRef, 'uid:')) {
			return substr($actorRef, 4);
		}
		foreach ([\OCA\CareCheck\Constants::GROUP_ZENTRALE, 'admin'] as $gid) {
			$g = $this->groups->get($gid);
			if ($g === null) {
				continue;
			}
			foreach ($g->getUsers() as $u) {
				return $u->getUID();
			}
		}
		return null;
	}

	// ---- internals ----

	/** Remove the provider-facing temp copy once the task is terminal. */
	private function cleanupTemp(VoiceNote $n): void
	{
		$uid = $this->taskOwnerUid((string)$n->getActorRef());
		if ($uid === null) {
			return;
		}
		try {
			$dir = $this->rootFolder->getUserFolder($uid)->get('.carecheck-stt');
			foreach ($dir->getDirectoryListing() as $f) {
				if (str_starts_with($f->getName(), 'vnote-' . (int)$n->getId() . '.')) {
					$f->delete();
				}
			}
		} catch (\Throwable) {
		}
	}

	private function sniffAudio(string $bytes): ?string
	{
		$tmp = tempnam(sys_get_temp_dir(), 'cckvn');
		if ($tmp === false) {
			return null;
		}
		try {
			file_put_contents($tmp, $bytes);
			$mime = mime_content_type($tmp) ?: null;
			return $mime !== null && in_array($mime, self::MIME_ALLOW, true) ? $mime : null;
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

	private function blobFolder(int $clientId): \OCP\Files\SimpleFS\ISimpleFolder
	{
		try {
			$root = $this->appData->getFolder('voicenotes');
		} catch (NotFoundException) {
			$root = $this->appData->newFolder('voicenotes');
		}
		try {
			return $root->getFolder((string)$clientId);
		} catch (NotFoundException) {
			return $root->newFolder((string)$clientId);
		}
	}
}
