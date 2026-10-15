<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method \DateTime getDate()
 * @method void setDate(\DateTime|null $date)
 * @method string|null getHeadsDigest()
 * @method void setHeadsDigest(string|null $headsDigest)
 * @method string|null getAnchorToken()
 * @method void setAnchorToken(string|null $anchorToken)
 * @method string|null getStatus()
 * @method void setStatus(string|null $status)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime|null $createdAt)
 */
class Anchor extends Entity
{
	protected $date;
	protected $headsDigest;
	protected $anchorToken;
	protected $status;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('date', 'datetime');
		$this->addType('headsDigest', 'string');
		$this->addType('anchorToken', 'string');
		$this->addType('status', 'string');
		$this->addType('createdAt', 'datetime');
	}
}