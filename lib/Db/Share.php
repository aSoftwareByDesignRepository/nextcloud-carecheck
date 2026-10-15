<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int|null getClientId()
 * @method void setClientId(int|null $clientId)
 * @method string|null getTokenHash()
 * @method void setTokenHash(string|null $tokenHash)
 * @method string|null getLabel()
 * @method void setLabel(string|null $label)
 * @method \DateTime|null getExpiresAt()
 * @method void setExpiresAt(\DateTime|null $expiresAt)
 * @method int|null getRevoked()
 * @method void setRevoked(int|null $revoked)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(string|null $createdBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime|null $createdAt)
 * @method \DateTime|null getLastAccessAt()
 * @method void setLastAccessAt(\DateTime|null $lastAccessAt)
 */
class Share extends Entity
{
	protected $clientId;
	protected $tokenHash;
	protected $label;
	protected $expiresAt;
	protected $revoked;
	protected $createdBy;
	protected $createdAt;
	protected $lastAccessAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('clientId', 'integer');
		$this->addType('tokenHash', 'string');
		$this->addType('label', 'string');
		$this->addType('expiresAt', 'datetime');
		$this->addType('revoked', 'integer');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('lastAccessAt', 'datetime');
	}
}
