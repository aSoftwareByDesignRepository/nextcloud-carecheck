<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getActorRef()
 * @method void setActorRef(string|null $actorRef)
 * @method string|null getIdemKey()
 * @method void setIdemKey(string|null $idemKey)
 * @method int|null getClientId()
 * @method void setClientId(int|null $clientId)
 * @method int|null getTstopId()
 * @method void setTstopId(int|null $tstopId)
 * @method \DateTime|null getCheckinTs()
 * @method void setCheckinTs(\DateTime|null $checkinTs)
 * @method \DateTime|null getCheckoutTs()
 * @method void setCheckoutTs(\DateTime|null $checkoutTs)
 * @method int|null getDurationSec()
 * @method void setDurationSec(int|null $durationSec)
 * @method int|null getPlanVId()
 * @method void setPlanVId(int|null $planVId)
 * @method string|null getSigState()
 * @method void setSigState(string|null $sigState)
 * @method string|null getSigEnc()
 * @method void setSigEnc(string|null $sigEnc)
 * @method string|null getSignerName()
 * @method void setSignerName(string|null $signerName)
 * @method int|null getJointVisitRef()
 * @method void setJointVisitRef(int|null $jointVisitRef)
 * @method float|null getGeoLat()
 * @method void setGeoLat(float|null $geoLat)
 * @method float|null getGeoLon()
 * @method void setGeoLon(float|null $geoLon)
 * @method string|null getStatus()
 * @method void setStatus(string|null $status)
 * @method string|null getRejectReason()
 * @method void setRejectReason(string|null $rejectReason)
 * @method int|null getQualMismatch()
 * @method void setQualMismatch(int|null $qualMismatch)
 * @method int|null getIsDemo()
 * @method void setIsDemo(int|null $isDemo)
 * @method int|null getPresenceOk()
 * @method void setPresenceOk(int|null $presenceOk)
 * @method string|null getNoteEnc()
 * @method void setNoteEnc(string|null $noteEnc)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime|null $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime|null $updatedAt)
 */
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