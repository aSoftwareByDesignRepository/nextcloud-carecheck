<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Constants;
use OCA\CareCheck\Db\Absence;
use OCA\CareCheck\Db\AbsenceMapper;
use OCA\CareCheck\Exception\ApiException;
use OCP\IDBConnection;

/**
 * Manual absence marking (standalone baseline — works with zero other apps).
 * Presence/absence flag only: `kind` is a coarse category, never a reason or
 * diagnosis, and is never surfaced on the coverage board (secondary-use rule).
 */
class AbsenceService
{
	public function __construct(
		private IDBConnection $db,
		private AbsenceMapper $absences,
		private AclService $acl,
	) {
	}

	/**
	 * Mark a staff/member absent for a date range; their pending today/future
	 * stops move to the uncovered queue immediately (never silently dropped).
	 * @return array{id:int}
	 */
	public function mark(string $actorRef, string $staffRef, string $from, string $until, string $kind): array
	{
		$this->acl->assertStaff($actorRef);
		$fields = [];
		if (!$this->validRef($staffRef)) {
			$fields['staff_ref'] = 'invalid';
		}
		if (!in_array($kind, Constants::ABSENCE_KINDS, true)) {
			$fields['kind'] = 'invalid';
		}
		$df = \DateTime::createFromFormat('Y-m-d', $from);
		$du = \DateTime::createFromFormat('Y-m-d', $until);
		if ($df === false || $df->format('Y-m-d') !== $from) {
			$fields['from'] = 'invalid';
		}
		if ($du === false || $du->format('Y-m-d') !== $until) {
			$fields['until'] = 'invalid';
		}
		if ($fields === [] && $du !== false && $df !== false && $du < $df) {
			$fields['until'] = 'invalid';
		}
		if ($fields !== [] || $df === false || $du === false) {
			throw ApiException::validation('Validation failed.', $fields);
		}
		$a = new Absence();
		$a->setActorRef($staffRef);
		$a->setFromDate($df);
		$a->setUntilDate($du);
		$a->setKind($kind);
		$a->setSource('manual');
		$a->setMarkedBy($actorRef);
		$a->setCreatedAt(new \DateTime());
		$a = $this->absences->insert($a);
		$this->uncoverStops($staffRef, $df, $du);
		return ['id' => (int)$a->getId()];
	}

	/** Cancel an absence (undo): restores nothing — reassign is a human decision. */
	public function cancel(string $actorRef, int $id): void
	{
		$this->acl->assertStaff($actorRef);
		$a = $this->absences->find($id);
		if ($a === null) {
			throw ApiException::notFound();
		}
		$this->absences->delete($a);
	}

	/** @return list<array{id:int,staffRef:?string,from:string,until:string,kind:?string}> */
	public function list(string $actorRef): array
	{
		$this->acl->assertStaff($actorRef);
		$out = [];
		foreach ($this->absences->findAll() as $a) {
			$out[] = [
				'id' => (int)$a->getId(),
				'staffRef' => $a->getActorRef(),
				'from' => $a->getFromDate()->format('Y-m-d'),
				'until' => $a->getUntilDate()->format('Y-m-d'),
				'kind' => $a->getKind(),
			];
		}
		return $out;
	}

	/** Is this staff ref absent on the given date? Used by tour generation. */
	public function isAbsentOn(string $staffRef, \DateTimeInterface $date): bool
	{
		$d = $date->format('Y-m-d');
		foreach ($this->absences->findAll() as $a) {
			if ($a->getActorRef() === $staffRef
				&& $a->getFromDate()->format('Y-m-d') <= $d
				&& $a->getUntilDate()->format('Y-m-d') >= $d) {
				return true;
			}
		}
		return false;
	}

	/** Move pending stops of staffRef inside [from,until] to the uncovered queue. */
	private function uncoverStops(string $staffRef, \DateTime $from, \DateTime $until): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('cck_tstop')
			->set('status', $qb->createNamedParameter('uncovered'))
			->set('executor_ref', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('executor_ref', $qb->createNamedParameter($staffRef)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['pending'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere('tour_id IN (SELECT id FROM `*PREFIX*cck_tour` WHERE `date` >= :df AND `date` <= :du)')
			->setParameter('df', $from->format('Y-m-d'))
			->setParameter('du', $until->format('Y-m-d'));
		$qb->executeStatement();
	}

	private function validRef(string $ref): bool
	{
		return $this->acl->validRef($ref);
	}
}
