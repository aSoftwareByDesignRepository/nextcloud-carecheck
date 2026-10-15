<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getNameHmac()
 * @method void setNameHmac(string|null $nameHmac)
 * @method string|null getPayloadEnc()
 * @method void setPayloadEnc(string|null $payloadEnc)
 * @method string|null getRequiresQual()
 * @method void setRequiresQual(string|null $requiresQual)
 * @method string|null getRetentionClass()
 * @method void setRetentionClass(string|null $retentionClass)
 * @method int|null getActive()
 * @method void setActive(int|null $active)
 * @method int|null getRevision()
 * @method void setRevision(int|null $revision)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime|null $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime|null $updatedAt)
 * @method string|null getPrimaryRef()
 * @method void setPrimaryRef(string|null $primaryRef)
 * @method string|null getSubRef()
 * @method void setSubRef(string|null $subRef)
 * @method int|null getIsDemo()
 * @method void setIsDemo(int|null $isDemo)
 * @method string|null getPresenceHash()
 * @method void setPresenceHash(string|null $presenceHash)
 * @method int|null getTeamId()
 * @method void setTeamId(int|null $teamId)
 */
class Client extends Entity
{
	protected $nameHmac;
	protected $payloadEnc;
	protected $requiresQual;
	protected $retentionClass;
	protected $active;
	protected $revision;
	protected $createdAt;
	protected $updatedAt;
	protected $primaryRef;
	protected $subRef;
	protected $isDemo;
	protected $presenceHash;
	protected $teamId;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('nameHmac', 'string');
		$this->addType('payloadEnc', 'string');
		$this->addType('requiresQual', 'string');
		$this->addType('retentionClass', 'string');
		$this->addType('active', 'integer');
		$this->addType('revision', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
		$this->addType('primaryRef', 'string');
		$this->addType('subRef', 'string');
		$this->addType('isDemo', 'integer');
		$this->addType('presenceHash', 'string');
		$this->addType('teamId', 'integer');
	}
}