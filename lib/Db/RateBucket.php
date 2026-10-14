<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

class RateBucket extends Entity
{
	protected $bucket;
	protected $cnt;
	protected $winStart;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('cnt', 'integer');
		$this->addType('winStart', 'datetime');
	}
}
