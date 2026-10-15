<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getCode()
 * @method void setCode(string|null $code)
 * @method string|null getLabel()
 * @method void setLabel(string|null $label)
 * @method string|null getIcon()
 * @method void setIcon(string|null $icon)
 * @method string|null getKind()
 * @method void setKind(string|null $kind)
 * @method string|null getRequiresQual()
 * @method void setRequiresQual(string|null $requiresQual)
 * @method int|null getActive()
 * @method void setActive(int|null $active)
 */
class TaskCatalog extends Entity
{
	protected $code;
	protected $label;
	protected $icon;
	protected $kind;
	protected $requiresQual;
	protected $active;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('code', 'string');
		$this->addType('label', 'string');
		$this->addType('icon', 'string');
		$this->addType('kind', 'string');
		$this->addType('requiresQual', 'string');
		$this->addType('active', 'integer');
	}
}