<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

class Photo extends Entity
{
	protected $clientId;
	protected $visitId;
	protected $actorRef;
	protected $mime;
	protected $byteSize;
	protected $sha256;
	protected $blobKey;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('clientId', 'integer');
		$this->addType('visitId', 'integer');
		$this->addType('actorRef', 'string');
		$this->addType('mime', 'string');
		$this->addType('byteSize', 'integer');
		$this->addType('sha256', 'string');
		$this->addType('blobKey', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
