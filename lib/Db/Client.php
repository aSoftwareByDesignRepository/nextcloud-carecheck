<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

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