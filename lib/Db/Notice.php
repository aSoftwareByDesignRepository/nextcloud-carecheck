<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int|null getClientId()
 * @method void setClientId(int|null $clientId)
 * @method string|null getActorRef()
 * @method void setActorRef(string|null $actorRef)
 * @method string|null getKind()
 * @method void setKind(string|null $kind)
 * @method string|null getNoteEnc()
 * @method void setNoteEnc(string|null $noteEnc)
 * @method string|null getIdemKey()
 * @method void setIdemKey(string|null $idemKey)
 * @method string|null getPriority()
 * @method void setPriority(string|null $priority)
 * @method string|null getState()
 * @method void setState(string|null $state)
 * @method string|null getAckedBy()
 * @method void setAckedBy(string|null $ackedBy)
 * @method \DateTime|null getAckedAt()
 * @method void setAckedAt(\DateTime|null $ackedAt)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime|null $createdAt)
 * @method int|null getEscalated()
 * @method void setEscalated(int|null $escalated)
 * @method string|null getResolution()
 * @method void setResolution(string|null $resolution)
 */
class Notice extends Entity
{
	protected $clientId;
	protected $actorRef;
	protected $kind;
	protected $noteEnc;
	protected $idemKey;
	protected $priority;
	protected $state;
	protected $ackedBy;
	protected $ackedAt;
	protected $createdAt;
	protected $escalated;
	protected $resolution;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('clientId', 'integer');
		$this->addType('actorRef', 'string');
		$this->addType('kind', 'string');
		$this->addType('noteEnc', 'string');
		$this->addType('idemKey', 'string');
		$this->addType('priority', 'string');
		$this->addType('state', 'string');
		$this->addType('ackedBy', 'string');
		$this->addType('ackedAt', 'datetime');
		$this->addType('createdAt', 'datetime');
		$this->addType('escalated', 'integer');
		$this->addType('resolution', 'string');
	}
}