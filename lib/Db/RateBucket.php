<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getBucket()
 * @method void setBucket(string|null $bucket)
 * @method int|null getCnt()
 * @method void setCnt(int|null $cnt)
 * @method \DateTime getWinStart()
 * @method void setWinStart(\DateTime|null $winStart)
 */
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
