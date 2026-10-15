<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int|null getClientId()
 * @method void setClientId(int|null $clientId)
 * @method int|null getVisitId()
 * @method void setVisitId(int|null $visitId)
 * @method string|null getActorRef()
 * @method void setActorRef(string|null $actorRef)
 * @method string|null getMime()
 * @method void setMime(string|null $mime)
 * @method int|null getByteSize()
 * @method void setByteSize(int|null $byteSize)
 * @method string|null getSha256()
 * @method void setSha256(string|null $sha256)
 * @method string|null getBlobKey()
 * @method void setBlobKey(string|null $blobKey)
 * @method string|null getStatus()
 * @method void setStatus(string|null $status)
 * @method int|null getTaskId()
 * @method void setTaskId(int|null $taskId)
 * @method string|null getTranscriptEnc()
 * @method void setTranscriptEnc(string|null $transcriptEnc)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime|null $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime|null $updatedAt)
 */
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
