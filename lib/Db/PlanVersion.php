<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int|null getClientId()
 * @method void setClientId(int|null $clientId)
 * @method string|null getSnapshotEnc()
 * @method void setSnapshotEnc(string|null $snapshotEnc)
 * @method string|null getStatus()
 * @method void setStatus(string|null $status)
 * @method string|null getPublishedBy()
 * @method void setPublishedBy(string|null $publishedBy)
 * @method \DateTime|null getPublishedAt()
 * @method void setPublishedAt(\DateTime|null $publishedAt)
 * @method int|null getRevision()
 * @method void setRevision(int|null $revision)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime|null $createdAt)
 */
class PlanVersion extends Entity
{
	protected $clientId;
	protected $snapshotEnc;
	protected $status;
	protected $publishedBy;
	protected $publishedAt;
	protected $revision;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('clientId', 'integer');
		$this->addType('snapshotEnc', 'string');
		$this->addType('status', 'string');
		$this->addType('publishedBy', 'string');
		$this->addType('publishedAt', 'datetime');
		$this->addType('revision', 'integer');
		$this->addType('createdAt', 'datetime');
	}
}