<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

class Pair extends Entity
{
	protected $memberId;
	protected $codeHash;
	protected $codeExpires;
	protected $codeUsed;
	protected $tokenHash;
	protected $deviceLabel;
	protected $redeemedAt;
	protected $revoked;
	protected $createdAt;
	protected $uid;
	protected $kind;
	protected $lastSeen;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('memberId', 'integer');
		$this->addType('codeHash', 'string');
		$this->addType('codeExpires', 'datetime');
		$this->addType('codeUsed', 'integer');
		$this->addType('tokenHash', 'string');
		$this->addType('deviceLabel', 'string');
		$this->addType('redeemedAt', 'datetime');
		$this->addType('revoked', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('uid', 'string');
		$this->addType('kind', 'string');
		$this->addType('lastSeen', 'datetime');
	}
}