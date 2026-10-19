<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Db\ClientMapper;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * F13 producer push (spec §4.4.1): sends one client's allow-listed
 * billing draft to InvoiceCheck's `CareBillingDraftWriteFacade`, where it
 * lands as a draft invoice a human still has to price and finalize.
 *
 * Identity chain, all explicit — nothing is guessed:
 *   cck_client.crm_company_id (F14 link)
 *     → CustomerCheck facade → pc_customer_id
 *     → InvoiceCheck createDraft(actorUid, pc_customer_id, ...)
 *
 * Dedupe rides on the deterministic source_ref
 * (`cck:{clientId}:{period}:{kind}`) — InvoiceCheck dedupes on
 * (src_app, src_ref), so a repeat click returns the same draft instead
 * of minting a second invoice.
 *
 * Fail-soft: every failure path returns a coded error array; nothing
 * throws into the caller and nothing rolls back CareCheck state (the
 * signed evidence is already committed upstream of this push).
 */
final class InvoiceDraftPushService
{
	private const FACADE = \OCA\InvoiceCheck\Facade\CareBillingDraftWriteFacade::class;

	public function __construct(
		private IAppManager $appManager,
		private ClientMapper $clients,
		private CrmLinkService $crmLinks,
		private BillingDraftService $billingDrafts,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{ok:bool, code?:string, invoiceId?:int, created?:bool}
	 *   codes: sibling_unavailable | not_linked | no_pc_customer
	 *        | no_visits | sibling_error | bad_request | not_found
	 */
	public function push(string $actorRef, int $clientId, string $period, string $kind): array
	{
		// Only real session users push (member/station refs cannot own an
		// InvoiceCheck draft — the facade needs a uid to authorize against).
		$actorUid = str_starts_with($actorRef, 'uid:') ? substr($actorRef, 4) : '';
		if ($actorUid === '' || !isset(BillingDraftService::LINE_TEXTS[$kind])
			|| preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
			return ['ok' => false, 'code' => 'bad_request'];
		}
		$client = $this->clients->find($clientId);
		if ($client === null || (int)$client->getActive() === 0) {
			// Canonical miss — collapses with the controller's authz 404 so
			// a staff caller cannot enumerate client ids by error class.
			return ['ok' => false, 'code' => 'not_found'];
		}
		// Link checks before the availability gate: "link a company first"
		// is more actionable than "InvoiceCheck not enabled" when both hold.
		$companyId = $client->getCrmCompanyId();
		if ($companyId === null || (int)$companyId <= 0) {
			return ['ok' => false, 'code' => 'not_linked'];
		}
		$pcCustomerId = $this->crmLinks->pcCustomerIdFor($actorRef, (int)$companyId);
		if ($pcCustomerId === null) {
			return ['ok' => false, 'code' => 'no_pc_customer'];
		}
		if (!$this->available()) {
			return ['ok' => false, 'code' => 'sibling_unavailable'];
		}

		$from = $period . '-01';
		$to = (new \DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d');
		$drafts = $this->billingDrafts->drafts($from, $to, $kind);
		$row = null;
		foreach ($drafts as $d) {
			if ((int)$d['client_id'] === $clientId) {
				$row = $d;
				break;
			}
		}
		if ($row === null) {
			return ['ok' => false, 'code' => 'no_visits'];
		}

		$sourceRef = sprintf('cck:%d:%s:%s', $clientId, $period, $kind);
		try {
			$result = \OCP\Server::get(self::FACADE)->createDraft(
				$actorUid,
				$pcCustomerId,
				$from,
				$to,
				$row['lines'],
				$sourceRef,
			);
		} catch (\Throwable $e) {
			$this->logger->warning('invoicecheck draft push threw', ['exception' => $e->getMessage()]);
			return ['ok' => false, 'code' => 'sibling_error'];
		}
		if (!is_array($result)) {
			return ['ok' => false, 'code' => 'sibling_error'];
		}
		return [
			'ok' => true,
			'invoiceId' => (int)($result['invoiceId'] ?? 0),
			'created' => (bool)($result['created'] ?? false),
		];
	}

	public function available(): bool
	{
		try {
			if (!$this->appManager->isEnabledForUser('invoicecheck')
				|| !class_exists(self::FACADE)
				|| !method_exists(self::FACADE, 'createDraft')) {
				return false;
			}
			// defined()+constant(): a facade without the const throws Error,
			// which ?? cannot catch — fail closed as "unavailable".
			return defined(self::FACADE . '::FACADE_VERSION')
				&& (int)constant(self::FACADE . '::FACADE_VERSION') >= 1;
		} catch (\Throwable) {
			return false;
		}
	}
}
