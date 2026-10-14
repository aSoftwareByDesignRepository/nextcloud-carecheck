<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

class Visit extends Entity
{
	protected $actorRef;
	protected $idemKey;
	protected $clientId;
	protected $tstopId;
	protected $checkinTs;
	protected $checkoutTs;
	protected $durationSec;
	protected $planVId;
	protected $sigState;
	protected $sigEnc;
	protected $signerName;
	protected $jointVisitRef;
	protected $geoLat;
	protected $geoLon;
	protected $status;
	protected $rejectReason;
	protected $qualMismatch;
	protected $isDemo;
	protected $presenceOk;
	protected $noteEnc;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('actorRef', 'string');
		$this->addType('idemKey', 'string');
		$this->addType('clientId', 'integer');
		$this->addType('tstopId', 'integer');
		$this->addType('checkinTs', 'datetime');
		$this->addType('checkoutTs', 'datetime');
		$this->addType('durationSec', 'integer');
		$this->addType('planVId', 'integer');
		$this->addType('sigState', 'string');
		$this->addType('sigEnc', 'string');
		$this->addType('signerName', 'string');
		$this->addType('jointVisitRef', 'integer');
		$this->addType('geoLat', 'float');
		$this->addType('geoLon', 'float');
		$this->addType('status', 'string');
		$this->addType('rejectReason', 'string');
		$this->addType('qualMismatch', 'integer');
		$this->addType('isDemo', 'integer');
		$this->addType('presenceOk', 'integer');
		$this->addType('noteEnc', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}