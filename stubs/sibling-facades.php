<?php

/**
 * Minimal sibling-app facade stubs for PHPStan — the real classes live in
 * the sibling Check apps and are resolved at runtime via \OCP\Server::get /
 * class_exists feature-detection. Keep signatures byte-equivalent to the
 * owner apps (suite FACADE_VERSION contract, see
 * planning/check-productivity-suite/FACADE-CONTRACTS.md).
 *
 * @copyright Copyright (c) 2026, Alexander Mäule
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Public;

class ApprovedAbsenceReadFacade
{
	public const FACADE_VERSION = 1;

	/** @return list<array{userId:string,startDate:string,endDate:string,status:string}> */
	public function listApprovedForRange(string $from, string $to, ?string $uid = null): array
	{
		return [];
	}
}

namespace OCA\CustomerCheck\Public;

class CrmCompanyLinkReadFacade
{
	public const FACADE_VERSION = 1;

	/** @return array{crmCompanyId:int,name:string,slug:string,deepLink:string,pcCustomerId:?int}|null */
	public function findByPcCustomerId(string $actorUid, int $pcCustomerId): ?array
	{
		return null;
	}

	/** @return array{crmCompanyId:int,name:string,slug:string,deepLink:string,pcCustomerId:?int}|null */
	public function findByCompanyId(string $actorUid, int $companyId): ?array
	{
		return null;
	}

	/** @return list<array{crmCompanyId:int,name:string,deepLink:string}> */
	public function searchForPicker(string $actorUid, string $query, int $limit = 10): array
	{
		return [];
	}
}

namespace OCA\InvoiceCheck\Facade;

class CareBillingDraftWriteFacade
{
	public const FACADE_VERSION = 1;

	/**
	 * @param list<array<string,mixed>> $lines
	 * @return array{invoiceId:int,created:bool,lineCount:int}|null
	 */
	public function createDraft(string $actorUid, int $pcCustomerId, string $periodFrom, string $periodTo, array $lines, string $sourceRef): ?array
	{
		return null;
	}
}

namespace OCA\PrivacyCheck\Db;

class Activity
{
	public function getId(): int
	{
		return 0;
	}
}

namespace OCA\PrivacyCheck\Service;

class SuiteProposalService
{
	public function proposeActivity(array $proposal, string $uid): \OCA\PrivacyCheck\Db\Activity
	{
		return new \OCA\PrivacyCheck\Db\Activity();
	}
}

namespace OCA\DutyCheck\Integration;

class MaintenanceCheckOnDutyReader
{
	/** @return list<array{employeeId:int,displayName:string,linkedUserId:?string,locationName:string,startTime:string,endTime:string}> */
	public function onDutyToday(?string $day = null, ?string $actorUserId = null): array
	{
		return [];
	}
}
