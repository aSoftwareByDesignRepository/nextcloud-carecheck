<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int|null getClientId()
 * @method void setClientId(int|null $clientId)
 * @method int|null getSeq()
 * @method void setSeq(int|null $seq)
 * @method string|null getPrevHash()
 * @method void setPrevHash(string|null $prevHash)
 * @method string|null getPayloadHash()
 * @method void setPayloadHash(string|null $payloadHash)
 * @method string|null getType()
 * @method void setType(string|null $type)
 * @method string|null getActorRef()
 * @method void setActorRef(string|null $actorRef)
 * @method string|null getPayloadEnc()
 * @method void setPayloadEnc(string|null $payloadEnc)
 * @method \DateTime getTs()
 * @method void setTs(\DateTime|null $ts)
 */
class JournalEntry extends Entity
{
	protected $clientId;
	protected $seq;
	protected $prevHash;
	protected $payloadHash;
	protected $type;
	protected $actorRef;
	protected $payloadEnc;
	protected $ts;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('clientId', 'integer');
		$this->addType('seq', 'integer');
		$this->addType('prevHash', 'string');
		$this->addType('payloadHash', 'string');
		$this->addType('type', 'string');
		$this->addType('actorRef', 'string');
		$this->addType('payloadEnc', 'string');
		$this->addType('ts', 'datetime');
	}
}