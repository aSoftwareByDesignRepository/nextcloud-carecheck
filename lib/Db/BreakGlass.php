<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getActorRef()
 * @method void setActorRef(string|null $actorRef)
 * @method int|null getClientId()
 * @method void setClientId(int|null $clientId)
 * @method string|null getReasonCode()
 * @method void setReasonCode(string|null $reasonCode)
 * @method \DateTime getGrantedAt()
 * @method void setGrantedAt(\DateTime|null $grantedAt)
 * @method \DateTime getExpiresAt()
 * @method void setExpiresAt(\DateTime|null $expiresAt)
 * @method string|null getState()
 * @method void setState(string|null $state)
 * @method int|null getQualMismatch()
 * @method void setQualMismatch(int|null $qualMismatch)
 */
class BreakGlass extends Entity
{
	protected $actorRef;
	protected $clientId;
	protected $reasonCode;
	protected $grantedAt;
	protected $expiresAt;
	protected $state;
	protected $qualMismatch;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('actorRef', 'string');
		$this->addType('clientId', 'integer');
		$this->addType('reasonCode', 'string');
		$this->addType('grantedAt', 'datetime');
		$this->addType('expiresAt', 'datetime');
		$this->addType('state', 'string');
		$this->addType('qualMismatch', 'integer');
	}
}