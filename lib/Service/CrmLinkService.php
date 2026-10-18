<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Db\ClientMapper;
use OCA\CareCheck\Exception\ApiException;
use OCP\App\IAppManager;

/**
 * F14 (consumer half) — soft link care client → CustomerCheck company
 * (`crm_company_id` on `cck_client`, MN soft-link pattern; no silent merge).
 *
 * All reads go through CustomerCheck's `CrmCompanyLinkReadFacade` (version
 * probe + `searchForPicker` capability) — CareCheck never queries `crm_*`
 * tables directly, so CUC workspace scoping and archival semantics stay
 * authoritative. The CustomerCheck company remains the billing/CRM system
 * of record; the link exists so the Zentrale can answer "which payer/Träger
 * covers this client" and so suite consumers (F13 drafts) can resolve it.
 *
 * Fail closed: CustomerCheck absent, mid-upgrade, or facade older than v1
 * → the picker is empty and linking returns the uniform 404.
 */
class CrmLinkService
{
	private const FACADE = \OCA\CustomerCheck\Public\CrmCompanyLinkReadFacade::class;

	public function __construct(
		private IAppManager $appManager,
		private ClientMapper $clients,
		private AclService $acl,
	) {
	}

	public function installed(): bool
	{
		try {
			return $this->appManager->isEnabledForUser('customercheck');
		} catch (\Throwable) {
			return false;
		}
	}

	private function facade(): ?object
	{
		if (!$this->installed()
			|| !class_exists(self::FACADE)
			|| !method_exists(self::FACADE, 'searchForPicker')) {
			return null;
		}
		try {
			$f = \OCP\Server::get(self::FACADE);
		} catch (\Throwable) {
			return null;
		}
		return (($f)::FACADE_VERSION ?? 0) >= 1 ? $f : null;
	}

	private static function uidOf(string $actorRef): string
	{
		return str_starts_with($actorRef, 'uid:') ? substr($actorRef, 4) : '';
	}

	/**
	 * Company options for the link picker — search-driven on purpose (the
	 * whole CRM book must not be enumerable through a care surface).
	 * @return array{available:bool, companies:list<array{id:int,label:string}>}
	 */
	public function companies(string $actorRef, ?string $q = null): array
	{
		$f = $this->facade();
		$q = trim((string)$q);
		if ($f === null || $q === '') {
			return ['available' => $f !== null, 'companies' => []];
		}
		try {
			$rows = $f->searchForPicker(self::uidOf($actorRef), $q, 15);
		} catch (\Throwable) {
			return ['available' => false, 'companies' => []];
		}
		$out = [];
		foreach ($rows as $r) {
			$out[] = [
				'id' => (int)($r['crmCompanyId'] ?? 0),
				'label' => (string)($r['name'] ?? ''),
				'deepLink' => (string)($r['deepLink'] ?? ''),
			];
		}
		return ['available' => true, 'companies' => $out];
	}

	/**
	 * Resolved link state for the client record. A stored id that no longer
	 * resolves (company archived/deleted, CUC offline, actor lost access)
	 * keeps `name:null` so the UI renders a muted "no longer available" —
	 * the relation is never silently dropped.
	 *
	 * @return array{available:bool,id:?int,name:?string,deepLink:?string}
	 */
	public function linkInfo(string $actorRef, ?int $companyId): array
	{
		$f = $this->facade();
		$info = ['available' => $f !== null, 'id' => $companyId, 'name' => null, 'deepLink' => null];
		if ($f === null || $companyId === null) {
			return $info;
		}
		try {
			$r = $f->findByCompanyId(self::uidOf($actorRef), $companyId);
		} catch (\Throwable) {
			$r = null;
		}
		if (is_array($r)) {
			$info['name'] = (string)($r['name'] ?? '');
			$info['deepLink'] = (string)($r['deepLink'] ?? '') ?: null;
		}
		return $info;
	}

	/**
	 * Set or clear the soft link. Uniform 404 for unknown client or
	 * unresolvable company — never leak which side failed. Column-only
	 * write (soft ref): last-writer-wins is the intended semantics, the
	 * encrypted payload and its revision CAS are untouched.
	 */
	public function setLink(string $actorRef, int $clientId, ?int $companyId): void
	{
		$this->acl->assertStaff($actorRef);
		$c = $this->clients->find($clientId);
		if ($c === null || (int)$c->getActive() === 0) {
			throw ApiException::notFound();
		}
		if ($companyId !== null) {
			$f = $this->facade();
			$r = null;
			if ($f !== null) {
				try {
					$r = $f->findByCompanyId(self::uidOf($actorRef), $companyId);
				} catch (\Throwable) {
					$r = null;
				}
			}
			if (!is_array($r) || (int)($r['crmCompanyId'] ?? 0) !== $companyId) {
				throw ApiException::notFound();
			}
		}
		$c->setCrmCompanyId($companyId);
		$this->clients->update($c);
		$this->acl->audit($actorRef, 'crm_link', $clientId, 'company:' . ($companyId ?? 'none'));
	}
}
