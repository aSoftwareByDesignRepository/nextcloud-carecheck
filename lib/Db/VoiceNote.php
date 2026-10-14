<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

class VoiceNote extends Entity
{
	protected $clientId;
	protected $visitId;
	protected $actorRef;
	protected $mime;
	protected $byteSize;
	protected $sha256;
	protected $blobKey;
	protected $status;
	protected $taskId;
	protected $transcriptEnc;
	protected $createdAt;
	protected $updatedAt;

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
		$this->addType('status', 'string');
		$this->addType('taskId', 'integer');
		$this->addType('transcriptEnc', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
