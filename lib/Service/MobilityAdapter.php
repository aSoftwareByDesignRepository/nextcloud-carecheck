<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCP\App\IAppManager;
use OCP\IDBConnection;

/**
 * NH-08 / suite flange F11 companion half — read-only pool-vehicle badge.
 *
 * If MobilityCheck is installed and the tour day has an approved booking
 * for this caregiver (uid actors only — member identities have no NC
 * account and can never be drivers), the tour header shows the vehicle.
 * Read-only: booking/changes stay in MobilityCheck. Capability detection
 * fails soft — absent app, missing tables, or a schema drift returns null,
 * never an error. Only plate+name cross the flange (minimal data).
 */
class MobilityAdapter
{
	public function __construct(
		private IDBConnection $db,
		private IAppManager $appManager,
		private TourService $tours,
	) {
	}

	public function installed(): bool
	{
		try {
			return $this->appManager->isEnabledForUser('mobilitycheck');
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Vehicle label for the actor's booking covering `date`, or null.
	 * Only uid: actors can hold bookings (mc_bookings.driver_user_id is a uid).
	 */
	public function vehicleFor(string $actorRef, string $date): ?string
	{
		if (!str_starts_with($actorRef, 'uid:') || !$this->installed()) {
			return null;
		}
		$uid = substr($actorRef, 4);
		try {
			$tz = new \DateTimeZone($this->tours->providerTz());
			$dayStart = (new \DateTime($date, $tz))->setTime(0, 0)->format('Y-m-d H:i:s');
			$dayEnd = (new \DateTime($date, $tz))->setTime(23, 59, 59)->format('Y-m-d H:i:s');
			$qb = $this->db->getQueryBuilder();
			$qb->select('v.licence_plate', 'v.internal_name')
				->from('mc_bookings', 'b')
				->innerJoin('b', 'mc_vehicles', 'v', 'b.vehicle_id = v.id')
				->where($qb->expr()->eq('b.driver_user_id', $qb->createNamedParameter($uid)))
				->andWhere($qb->expr()->eq('b.status', $qb->createNamedParameter('approved')))
				->andWhere($qb->expr()->lte('b.start_datetime', $qb->createNamedParameter($dayEnd)))
				->andWhere($qb->expr()->gte('b.end_datetime', $qb->createNamedParameter($dayStart)))
				->setMaxResults(1);
			$row = $qb->executeQuery()->fetch();
			if ($row === false) {
				return null;
			}
			$label = trim((string)$row['internal_name'] . ' ' . (string)$row['licence_plate']);
			return $label !== '' ? $label : null;
		} catch (\Throwable) {
			// Tables absent (mobilitycheck mid-upgrade / disabled) — fail soft.
			return null;
		}
	}
}
