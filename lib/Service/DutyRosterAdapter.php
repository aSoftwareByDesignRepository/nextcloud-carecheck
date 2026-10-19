<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IUserSession;

/**
 * NH-02 / suite flange F12 (consumer half) — DutyCheck's published roster
 * suggests who is on duty for a date, so the Zentrale builds tours around
 * real shifts instead of guessing. Read-only, fail closed: DutyCheck
 * absent or mid-upgrade → empty list, never an error. Only uid-linked
 * employees are actionable (tours assign to actor refs), but unlinked
 * employees still surface so staffing gaps are visible.
 *
 * Rows come from DutyCheck's owner-side reader
 * (`MaintenanceCheckOnDutyReader::onDutyToday`) resolved through the
 * server container — this adapter never queries `dc_*` tables directly,
 * so published-period and standby/cancellation filtering stay in one
 * place. The feed only crosses when the DutyCheck admin opted in — the
 * same `mc_onduty_hook_enabled` flag that gates the `check.on-duty`
 * endpoint upstream.
 */
class DutyRosterAdapter
{
	private const DC_ONDUTY_FLAG = 'mc_onduty_hook_enabled';
	private const READER = 'OCA\\DutyCheck\\Integration\\MaintenanceCheckOnDutyReader';

	/**
	 * @param null|callable(?string, ?string): list<array<string, mixed>> $readerInvoker
	 *        Test seam — production resolves the DutyCheck reader via the
	 *        server container.
	 */
	public function __construct(
		private IAppManager $appManager,
		private IConfig $config,
		private ?IUserSession $userSession = null,
		private mixed $readerInvoker = null,
	) {
	}

	public function installed(): bool
	{
		try {
			return $this->appManager->isEnabledForUser('dutycheck');
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * On-duty employees for `date` (the owner reader already restricts to
	 * published/closed periods and non-cancelled, non-standby assignments —
	 * a draft roster must not leak into tour planning).
	 * @return array{available:bool, rows:list<array{employeeId:int,displayName:string,actorRef:?string,locationName:string,startTime:string,endTime:string}>}
	 */
	public function onDuty(string $date, ?string $actorUid = null): array
	{
		if (!$this->installed()) {
			return ['available' => false, 'rows' => []];
		}
		// Owner-side consent: no roster data crosses while the DutyCheck
		// on-duty hook is off — regardless of this app's own settings.
		if ($this->config->getAppValue('dutycheck', self::DC_ONDUTY_FLAG, '0') !== '1') {
			return ['available' => false, 'rows' => []];
		}
		// DutyCheck too old to expose the reader → not available, not empty.
		if ($this->readerInvoker === null && !class_exists(self::READER)) {
			return ['available' => false, 'rows' => []];
		}
		if ($actorUid === null) {
			$user = $this->userSession?->getUser();
			$actorUid = $user !== null ? $user->getUID() : null;
		}
		try {
			$readerRows = $this->readAssignments($date, $actorUid);
			$rows = [];
			foreach ($readerRows as $r) {
				$uid = trim((string)($r['linkedUserId'] ?? ''));
				$rows[] = [
					'employeeId' => (int)($r['employeeId'] ?? 0),
					'displayName' => (string)($r['displayName'] ?? ''),
					'actorRef' => $uid !== '' ? 'uid:' . $uid : null,
					'locationName' => (string)($r['locationName'] ?? ''),
					'startTime' => (string)($r['startTime'] ?? ''),
					'endTime' => (string)($r['endTime'] ?? ''),
				];
			}
			return ['available' => true, 'rows' => $rows];
		} catch (\Throwable) {
			return ['available' => false, 'rows' => []];
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function readAssignments(string $date, ?string $actorUid): array
	{
		if ($this->readerInvoker !== null) {
			/** @var list<array<string, mixed>> $rows */
			$rows = ($this->readerInvoker)($date, $actorUid);
			return is_array($rows) ? $rows : [];
		}
		if (!class_exists(self::READER)) {
			return [];
		}
		$reader = \OCP\Server::get(self::READER);
		if (!is_object($reader) || !method_exists($reader, 'onDutyToday')) {
			return [];
		}
		/** @var list<array<string, mixed>> $rows */
		$rows = $reader->onDutyToday($date, $actorUid);
		return is_array($rows) ? $rows : [];
	}
}
