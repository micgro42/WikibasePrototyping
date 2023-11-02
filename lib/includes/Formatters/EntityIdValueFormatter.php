<?php

namespace Wikibase\Lib\Formatters;

use InvalidArgumentException;
use ValueFormatters\ValueFormatter;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Services\EntityId\EntityIdFormatter;

/**
 * A simple wrapper that forwards formatting of an EntityIdValue object to an EntityIdFormatter.
 *
 * @license GPL-2.0-or-later
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 * @author Thiemo Kreuz
 */
class EntityIdValueFormatter implements ValueFormatter {

	/**
	 * @var EntityIdFormatter
	 */
	private $entityIdFormatter;

	public function __construct( EntityIdFormatter $entityIdFormatter ) {
		$this->entityIdFormatter = $entityIdFormatter;
	}

	/**
	 * @see ValueFormatter::format
	 *
	 * Format an EntityIdValue
	 *
	 * @param EntityIdValue $value
	 *
	 * @throws InvalidArgumentException
	 * @return string Either plain text, wikitext or HTML, depending on the EntityIdFormatter
	 *  provided.
	 */
	public function format( $value ) {
		if ( !( $value instanceof EntityIdValue ) ) {
			throw new InvalidArgumentException( 'Data value type mismatch. Expected an EntityIdValue.' );
		}

		$entityId = $value->getEntityId();
		if ( !( $entityId instanceof EntityId ) ) {
			/**
			 * TODO: Now that EntitySchema have a wikibase-entityid datavalue-type should they
			 *       maybe return this EntityIdValueFormatter in the `formatter-factory-callback`
			 *       callback as well?
			 */
			throw new InvalidArgumentException( 'FIXME' );
		}

		return $this->entityIdFormatter->formatEntityId( $entityId );
	}

}
