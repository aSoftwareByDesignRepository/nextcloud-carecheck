<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Db\ClientMapper;
use OCA\CareCheck\Db\Share;
use OCA\CareCheck\Db\ShareMapper;
use OCA\CareCheck\Exception\ApiException;

/**
 * NH-05 — Angehörigen read-only share link per client. Bearer token is
 * 32 random bytes; only the SHA-256 lands in the DB — a leaked dump
 * never mints a working link. Expiring + revocable; last_access tracked.
 *
 * The public view (`view()`) is a deliberately minimal projection:
 * upcoming tour windows + whether today's visit happened — enough for a
 * worried relative ("war jemand da?"), never task/medical detail.
 */
class ShareService
{
	public function __construct(
		private ShareMapper $shares,
		private ClientMapper $clients,
		private ClientService $clientService,
		private AclService $acl,
		private TourService $tours,
		private \OCP\IDBConnection $db,
	) {
	}

	public function create(string $actorRef, int $clientId, ?string $label, ?string $expiresAt): array
	{
		$this->acl->assertStaff($actorRef);
		$this->acl->assertClientScope($actorRef, $clientId);
		$exp = null;
		if ($expiresAt !== null && $expiresAt !== '') {
			$exp = \DateTime::createFromFormat('Y-m-d', $expiresAt);
			if ($exp === false || $exp->format('Y-m-d') !== $expiresAt) {
				throw ApiException::validation('Validation failed.', ['expires_at' => 'invalid']);
			}
			$exp->setTime(23, 59, 59);
		}
		$token = bin2hex(random_bytes(24));
		$s = new Share();
		$s->setClientId($clientId);
		$s->setTokenHash(hash('sha256', $token));
		$s->setLabel($label !== null && trim($label) !== '' ? mb_substr(trim($label), 0, 190) : null);
		$s->setExpiresAt($exp);
		$s->setRevoked(0);
		$s->setCreatedBy($actorRef);
		$s->setCreatedAt(new \DateTime());
		$s = $this->shares->insert($s);
		return [
			'id' => (int)$s->getId(),
			'token' => $token,
			'url' => '/apps/carecheck/share/' . $token,
		];
	}

	/** @return list<array{id:int,label:?string,expiresAt:?string,createdAt:string,lastAccessAt:?string}> */
	public function list(string $actorRef, int $clientId): array
	{
		$this->acl->assertStaff($actorRef);
		$this->acl->assertClientScope($actorRef, $clientId);
		$out = [];
		foreach ($this->shares->findByClient($clientId) as $s) {
			if ((int)$s->getRevoked() === 1) {
				continue;
			}
			$out[] = [
				'id' => (int)$s->getId(),
				'label' => $s->getLabel(),
				'expiresAt' => $s->getExpiresAt()?->format(\DateTimeInterface::ATOM),
				'createdAt' => $s->getCreatedAt()->format(\DateTimeInterface::ATOM),
				'lastAccessAt' => $s->getLastAccessAt()?->format(\DateTimeInterface::ATOM),
			];
		}
		return $out;
	}

	public function revoke(string $actorRef, int $clientId, int $shareId): void
	{
		$this->acl->assertStaff($actorRef);
		$this->acl->assertClientScope($actorRef, $clientId);
		$s = $this->shares->find($shareId);
		if ($s === null || (int)$s->getClientId() !== $clientId) {
			throw ApiException::notFound();
		}
		$s->setRevoked(1);
		$this->shares->update($s);
	}

	/**
	 * Public read — minimal projection ONLY. No health data, no task
	 * detail, no journal. A relative sees: "someone was/will be there".
	 * Uniform failure for unknown/revoked/expired tokens.
	 * @return array{label:?string, client:string, nextVisit:?string, lastVisit:?string}
	 */
	public function view(string $token): array
	{
		$s = $this->shares->findByToken($token);
		if ($s === null || (int)$s->getRevoked() === 1) {
			throw ApiException::notFound();
		}
		if ($s->getExpiresAt() !== null && $s->getExpiresAt() < new \DateTime()) {
			throw ApiException::notFound();
		}
		$clientId = (int)$s->getClientId();
		$c = $this->clients->find($clientId);
		if ($c === null || (int)$c->getActive() !== 1) {
			throw ApiException::notFound();
		}
		$s->setLastAccessAt(new \DateTime());
		$this->shares->update($s);

		$h = $this->clientService->hydrated($c);
		$tz = new \DateTimeZone($this->tours->providerTz());
		$today = (new \DateTime('today', $tz))->format('Y-m-d');

		// next pending stop for this client + last completed visit
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.date', 's.win_start', 's.win_end')
			->from('cck_tstop', 's')
			->innerJoin('s', 'cck_tour', 't', 's.tour_id = t.id')
			->where($qb->expr()->eq('s.client_id', $qb->createNamedParameter($clientId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('s.status', $qb->createNamedParameter('pending')))
			->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($today)))
			->orderBy('t.date', 'ASC')->addOrderBy('s.win_start', 'ASC')
			->setMaxResults(1);
		$next = $qb->executeQuery()->fetch();

		$qb = $this->db->getQueryBuilder();
		$qb->select('checkin_ts')->from('cck_visit')
			->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['done', 'done_corrected'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('checkin_ts', 'DESC')->setMaxResults(1);
		$last = $qb->executeQuery()->fetch();

		return [
			'label' => $s->getLabel(),
			// first name only — enough context for a relative, minimal exposure
			'client' => explode(' ', (string)($h['payload']['name'] ?? ''))[0],
			'nextVisit' => $next !== false
				? sprintf('%s %02d:%02d', $next['date'], intdiv((int)$next['win_start'], 60), (int)$next['win_start'] % 60)
				: null,
			'lastVisit' => $last !== false ? (string)$last['checkin_ts'] : null,
		];
	}
}
