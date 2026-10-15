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
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime|null $createdAt)
 */
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
