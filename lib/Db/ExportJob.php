<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getKind()
 * @method void setKind(string|null $kind)
 * @method string|null getScopeJson()
 * @method void setScopeJson(string|null $scopeJson)
 * @method string|null getActorRef()
 * @method void setActorRef(string|null $actorRef)
 * @method string|null getHash()
 * @method void setHash(string|null $hash)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime|null $createdAt)
 */
class ExportJob extends Entity
{
	protected $kind;
	protected $scopeJson;
	protected $actorRef;
	protected $hash;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('kind', 'string');
		$this->addType('scopeJson', 'string');
		$this->addType('actorRef', 'string');
		$this->addType('hash', 'string');
		$this->addType('createdAt', 'datetime');
	}
}