<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Constants;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * F15 consumer (spec §4.4.2): proposes a draft VVT register entry in
 * PrivacyCheck. The facade only ever lands a DRAFT + seeded DPIA — the
 * privacy lead still reviews and publishes in PrivacyCheck. We store the
 * created activity id so repeat clicks never mint a second draft.
 *
 * Fail-closed: PrivacyCheck absent or the facade class missing →
 * `sibling_unavailable`; any sibling failure → `sibling_error`. Neither
 * path ever throws into the settings surface.
 */
final class PrivacyProposalService
{
	private const STORE_KEY = 'privacy_vvt_activity_id';

	public function __construct(
		private IAppManager $appManager,
		private IAppConfig $appConfig,
		private SettingsService $settings,
		private LoggerInterface $logger,
	) {
	}

	public function available(): bool
	{
		return $this->appManager->isEnabledForUser('privacycheck')
			&& class_exists(\OCA\PrivacyCheck\Service\SuiteProposalService::class);
	}

	/** @return array{available: bool, activityId: ?int} */
	public function state(): array
	{
		return ['available' => $this->available(), 'activityId' => $this->proposedActivityId()];
	}

	/**
	 * @return array{ok: bool, already?: bool, activityId?: int, code?: string, message?: string}
	 */
	public function propose(string $actorUid): array
	{
		$existing = $this->proposedActivityId();
		if ($existing !== null) {
			return ['ok' => true, 'already' => true, 'activityId' => $existing];
		}
		if (!$this->available()) {
			return ['ok' => false, 'code' => 'sibling_unavailable', 'message' => 'PrivacyCheck is not enabled.'];
		}
		$retentionYears = max(1, (int)$this->settings->get('retention_years'));
		$proposal = [
			'sourceApp' => Constants::APP_ID,
			'activity_name' => 'CareCheck — client documentation and care tours',
			'purpose' => 'Documentation of care visits, tasks and tour evidence for clients, incl. duty roster and absence planning.',
			'art9_flag' => true,
			'art9_justification' => 'Client records contain health-adjacent care documentation (Art. 9 GDPR).',
			// privacycheck Constants::LEGAL_BASES enum — contract performance;
			// the Art.-9 leg travels via art9_flag + justification.
			'legal_basis' => 'art6_1b',
			'subjects' => ['clients', 'care staff'],
			'dataCategories' => ['care documentation', 'visit evidence', 'tour and location data', 'roster data'],
			'retentionRule' => 'Provider-configured CareCheck retention period',
			'retentionDays' => $retentionYears * 365,
		];
		try {
			$service = \OCP\Server::get(\OCA\PrivacyCheck\Service\SuiteProposalService::class);
			$activity = $service->proposeActivity($proposal, $actorUid);
		} catch (\Throwable $e) {
			$this->logger->warning('privacycheck VVT proposal failed', ['exception' => $e]);
			return ['ok' => false, 'code' => 'sibling_error', 'message' => 'PrivacyCheck rejected the proposal.'];
		}
		$id = $activity->getId();
		$this->appConfig->setValueInt(Constants::APP_ID, self::STORE_KEY, $id);
		return ['ok' => true, 'activityId' => $id];
	}

	private function proposedActivityId(): ?int
	{
		$id = $this->appConfig->getValueInt(Constants::APP_ID, self::STORE_KEY, 0);
		return $id > 0 ? $id : null;
	}
}
