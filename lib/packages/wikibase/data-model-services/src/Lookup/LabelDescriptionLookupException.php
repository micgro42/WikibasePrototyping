<?php

namespace Wikibase\DataModel\Services\Lookup;

use Exception;
use RuntimeException;
use Wikibase\DataModel\Entity\IndeterminateEntityId;

/**
 * @since 2.0
 *
 * @license GPL-2.0-or-later
 * @author Addshore
 */
class LabelDescriptionLookupException extends RuntimeException {

	/**
	 * @var IndeterminateEntityId
	 */
	private $entityId;

	/**
	 * @param IndeterminateEntityId $entityId
	 * @param string|null $message
	 * @param Exception|null $previous
	 */
	public function __construct( IndeterminateEntityId $entityId, $message = null, Exception $previous = null ) {
		$this->entityId = $entityId;

		parent::__construct(
			$message ?: 'Label and description lookup failed for: ' . $entityId,
			0,
			$previous
		);
	}

	/**
	 * @return IndeterminateEntityId
	 */
	public function getEntityId() {
		return $this->entityId;
	}

}
