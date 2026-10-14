<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

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