<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Constants;
use OCA\CareCheck\Db\Client;
use OCA\CareCheck\Db\ClientMapper;
use OCA\CareCheck\Exception\ApiException;
use OCP\IDBConnection;

class ClientService
{
	public function __construct(
		private IDBConnection $db,
		private ClientMapper $clients,
		private CryptoService $crypto,
		private AclService $acl,
	) {
	}

/**
	 * Payload: {name, street, zip, city, contacts[], risks[], accessNote} — all encrypted.
	 * @param array<string, mixed> $payload
	 */
	public function create(string $actorRef, array $payload, ?string $requiresQual = null): Client
	{
		$this->acl->assertStaff($actorRef);
		$name = trim((string)($payload['name'] ?? ''));
		if ($name === '') {
			throw ApiException::validation('Validation failed.', ['name' => 'required']);
		}
		if ($requiresQual !== null && !in_array($requiresQual, Constants::QUALS, true)) {
			throw ApiException::validation('Validation failed.', ['requires_qual' => 'invalid']);
		}
		// framework params must never be persisted into the encrypted payload
		foreach (array_keys($payload) as $k) {
			if (str_starts_with((string)$k, '_')) {
				unset($payload[$k]);
			}
		}
		$c = new Client();
		$c->setNameHmac($this->crypto->nameHmac($this->crypto->normalizeName($name)));
		$c->setPayloadEnc($this->crypto->encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
		$c->setRequiresQual($requiresQual);
		$c->setPrimaryRef($this->refOrNull($payload['primary_ref'] ?? null));
		$c->setSubRef($this->refOrNull($payload['sub_ref'] ?? null));
		$c->setIsDemo(0); // server-owned — never settable from client payloads
		$c->setActive(1);
		$c->setRevision(1);
		$now = new \DateTime();
		$c->setCreatedAt($now);
		$c->setUpdatedAt($now);
		return $this->clients->insert($c);
	}

	public function archive(string $actorRef, int $clientId): void
	{
		$this->acl->assertStaff($actorRef);
		$c = $this->clients->find($clientId);
		// already-archived collapses to the same uniform 404 as missing — a
		// second DELETE must not succeed (repeat-delete contract).
		if ($c === null || (int)$c->getActive() === 0) {
			throw ApiException::notFound();
		}
		$c->setActive(0);
		$c->setRevision((int)$c->getRevision() + 1);
		$c->setUpdatedAt(new \DateTime());
		$this->clients->update($c);
	}

	public function getForActor(string $actorRef, int $clientId): Client
	{
		$this->acl->assertClientScope($actorRef, $clientId);
		$c = $this->clients->find($clientId);
		if ($c === null || (int)$c->getActive() === 0) {
			throw ApiException::notFound();
		}
		return $c;
	}

/**
	 * Update client master data — CAS on revision, refs validated.
	 * @param array<string, mixed> $payload
	 */
	public function update(string $actorRef, int $clientId, array $payload, ?int $baseRevision): Client
	{
		$this->acl->assertStaff($actorRef);
		// Row lock before the revision check — CAS without it is a lost-update
		// race (two writers read the same revision, both merge against it, and
		// the second silently clobbers the first). Same lock order as
		// JournalService: cck_client row first.
		$this->db->beginTransaction();
		try {
			$lock = $this->db->prepare('SELECT id FROM `*PREFIX*cck_client` WHERE `id` = :id FOR UPDATE');
			$lock->execute(['id' => $clientId]);
			$c = $this->clients->find($clientId);
			if ($c === null || (int)$c->getActive() === 0) {
				throw ApiException::notFound();
			}
			if ($baseRevision === null || (int)$c->getRevision() !== $baseRevision) {
				throw ApiException::conflict('Client changed — reload.');
			}
			if (isset($payload['name']) && trim((string)$payload['name']) === '') {
				throw ApiException::validation('Validation failed.', ['name' => 'required']);
			}
			$existing = json_decode($this->crypto->decrypt((string)$c->getPayloadEnc()), true) ?? [];
			foreach (array_keys($payload) as $k) {
				if (str_starts_with((string)$k, '_')) {
					unset($payload[$k]);
				}
			}
			$merged = array_merge($existing, $payload);
			if (isset($payload['name'])) {
				$c->setNameHmac($this->crypto->nameHmac($this->crypto->normalizeName((string)$payload['name'])));
			}
			$c->setPayloadEnc($this->crypto->encrypt(json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
			if (array_key_exists('primary_ref', $payload)) {
				$c->setPrimaryRef($this->refOrNull($payload['primary_ref']));
			}
			if (array_key_exists('sub_ref', $payload)) {
				$c->setSubRef($this->refOrNull($payload['sub_ref']));
			}
			if (isset($payload['requires_qual'])) {
				$rq = $payload['requires_qual'];
				if ($rq !== null && $rq !== '' && !in_array($rq, Constants::QUALS, true)) {
					throw ApiException::validation('Validation failed.', ['requires_qual' => 'invalid']);
				}
				$c->setRequiresQual($rq !== '' ? $rq : null);
			}
			$c->setRevision((int)$c->getRevision() + 1);
			$c->setUpdatedAt(new \DateTime());
			$c = $this->clients->update($c);
			$this->db->commit();
			return $c;
		} catch (\Throwable $th) {
			$this->db->rollBack();
			throw $th;
		}
	}

	/** Typed staff refs only — uid:{uid} or member:{id}, never a bare id. */
	private function refOrNull(mixed $ref): ?string
	{
		if (!is_string($ref) || $ref === '') {
			return null;
		}
		if (!(str_starts_with($ref, 'member:') && preg_match('/^\d+$/', substr($ref, 7)) === 1)
			&& !(str_starts_with($ref, 'uid:') && substr($ref, 4) !== '')) {
			throw ApiException::validation('Validation failed.', ['caregiver_ref' => 'untyped']);
		}
		// existence leg — deleted users / deactivated members must never land as refs
		if (!$this->acl->validRef($ref)) {
			throw ApiException::validation('Validation failed.', ['caregiver_ref' => 'invalid']);
		}
		return $ref;
	}

	/** @return array{id:int,requiresQual:?string,payload:array} */
	/**
	 * @return array{id:int,requiresQual:?string,primaryRef:?string,subRef:?string,isDemo:bool,teamId:?int,revision:int,payload:array<string,mixed>}
	 */
	public function hydrated(Client $c): array
	{
		return [
			'id' => (int)$c->getId(),
			'requiresQual' => $c->getRequiresQual(),
			'primaryRef' => $c->getPrimaryRef(),
			'subRef' => $c->getSubRef(),
			'isDemo' => (int)$c->getIsDemo() === 1,
			'teamId' => $c->getTeamId() !== null ? (int)$c->getTeamId() : null,
			'revision' => (int)$c->getRevision(),
			'payload' => json_decode($this->crypto->decrypt((string)$c->getPayloadEnc()), true) ?? [],
		];
	}

	/**
	 * Break-glass search — minimal projection (id + name + street only),
	 * audited, rate-limited. HMAC exact match first, then bounded decrypt-scan.
	 * @return list<array{id:int,name:string,street:string}>
	 */
	public function searchMinimal(string $actorRef, string $query, int $ratePerMin): array
	{
		$query = $this->crypto->normalizeName($query);
		if (mb_strlen($query) < 2) {
			throw ApiException::validation('Validation failed.', ['q' => 'too_short']);
		}
		// rate limit: count this actor's searches in the last minute
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('cck_audit')
			->where($qb->expr()->eq('actor_ref', $qb->createNamedParameter($actorRef)))
			->andWhere($qb->expr()->eq('action', $qb->createNamedParameter('search')))
			->andWhere($qb->expr()->gte('ts', $qb->createNamedParameter((new \DateTime())->modify('-60 seconds'), 'datetime')));
		if ((int)$qb->executeQuery()->fetchOne() >= $ratePerMin) {
			throw new ApiException(Constants::ERR_RATE_LIMITED, 'Too many requests.', [], 429);
		}
		$this->acl->audit($actorRef, 'search', null, 'q_len:' . mb_strlen($query));

		$out = [];
		$hmac = $this->crypto->nameHmac($query);
		foreach ($this->clients->findAll() as $c) {
			if ((int)$c->getActive() === 0) {
				continue;
			}
			$h = $this->hydrated($c);
			$name = (string)($h['payload']['name'] ?? '');
			$match = $c->getNameHmac() === $hmac
				|| str_contains($this->crypto->normalizeName($name), $query);
			if ($match) {
				$out[] = ['id' => (int)$c->getId(), 'name' => $name, 'street' => (string)($h['payload']['street'] ?? '')];
			}
			if (count($out) >= 20) {
				break;
			}
		}
		return $out;
	}
}
