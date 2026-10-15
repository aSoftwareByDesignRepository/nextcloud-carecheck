<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Exception\ApiException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager as ITaskManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToTextSummary;

/**
 * NH-16 — on-prem KI-Sprachdoku: summarize a client's recent visit notes
 * into a journal digest via Nextcloud TaskProcessing. Runs wherever the
 * instance's configured provider lives — with the on-prem providers that
 * is literally "KI die nie das Haus verlässt" (nothing leaves the box).
 *
 * Fail closed: no TaskProcessing backend → clean UNAVAILABLE error, the
 * manual journal keeps working. The digest lands as a `digest` journal
 * entry (audited, append-only like everything else).
 */
class DigestService
{
	public function __construct(
		private IDBConnection $db,
		private AclService $acl,
		private CryptoService $crypto,
		private JournalService $journal,
		private ITaskManager $tasks,
		private IUserSession $session,
	) {
	}

	public function available(): bool
	{
		try {
			return $this->tasks->hasProviders()
				&& in_array(TextToTextSummary::ID, $this->tasks->getAvailableTaskTypeIds(), true);
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Summarize the client's `days`-day note history; append the digest to
	 * the journal. Returns the digest text.
	 */
	public function digest(string $actorRef, int $clientId, int $days = 7): string
	{
		$this->acl->assertClientScope($actorRef, $clientId);
		if ($days < 1 || $days > 90) {
			throw ApiException::validation('Validation failed.', ['days' => 'invalid']);
		}
		if (!$this->available()) {
			throw new ApiException('UNAVAILABLE', 'AI summarization is not configured on this instance.', [], 503);
		}
		// Gather decrypted visit notes in the window (scope-checked above).
		$since = (new \DateTime('-' . $days . ' days'))->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->select('note_enc')->from('cck_visit')
			->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('note_enc'))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since)))
			->orderBy('created_at', 'ASC')
			->setMaxResults(100);
		$notes = [];
		foreach ($qb->executeQuery()->iterateAssociative() as $r) {
			try {
				$n = trim($this->crypto->decrypt((string)$r['note_enc']));
				if ($n !== '') {
					$notes[] = $n;
				}
			} catch (\Throwable) {
			}
		}
		if ($notes === []) {
			throw new ApiException('UNAVAILABLE', 'No notes in the selected period.', [], 422);
		}
		$task = new Task(
			TextToTextSummary::ID,
			['input' => implode("\n", $notes)],
			'carecheck',
			$this->session->getUser()?->getUID(),
			'cck-digest:' . $clientId . ':' . $days,
		);
		$task = $this->tasks->runTask($task);
		$out = $task->getOutput();
		$rawOut = is_array($out) ? ($out['output'] ?? '') : '';
		$text = is_string($rawOut) ? trim($rawOut) : '';
		if ($text === '') {
			throw new ApiException('UNAVAILABLE', 'The AI backend returned no summary.', [], 503);
		}
		$this->journal->append($clientId, 'digest', $actorRef, [
			'days' => $days,
			'notes' => count($notes),
			'text' => $text,
		]);
		return $text;
	}
}
