<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int|null getMemberId()
 * @method void setMemberId(int|null $memberId)
 * @method string|null getCodeHash()
 * @method void setCodeHash(string|null $codeHash)
 * @method \DateTime|null getCodeExpires()
 * @method void setCodeExpires(\DateTime|null $codeExpires)
 * @method int|null getCodeUsed()
 * @method void setCodeUsed(int|null $codeUsed)
 * @method string|null getTokenHash()
 * @method void setTokenHash(string|null $tokenHash)
 * @method string|null getDeviceLabel()
 * @method void setDeviceLabel(string|null $deviceLabel)
 * @method \DateTime|null getRedeemedAt()
 * @method void setRedeemedAt(\DateTime|null $redeemedAt)
 * @method int|null getRevoked()
 * @method void setRevoked(int|null $revoked)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime|null $createdAt)
 * @method string|null getUid()
 * @method void setUid(string|null $uid)
 * @method string|null getKind()
 * @method void setKind(string|null $kind)
 * @method \DateTime|null getLastSeen()
 * @method void setLastSeen(\DateTime|null $lastSeen)
 */
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