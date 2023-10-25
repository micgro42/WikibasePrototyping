<?php

namespace Wikibase\Lib;

use EntitySchema\Domain\Model\EntitySchemaId;
use Wikibase\DataModel\Entity\EntityIdParser;

/**
 * EntityIdParser that also parses pseudo entity IDs.
 *
 * @license GPL-2.0-or-later
 */
class HackPseudoEntityIdParser implements EntityIdParser {

	private EntityIdParser $parser;

	public function __construct( EntityIdParser $parser ) {
		$this->parser = $parser;
	}

	public function parse( $idSerialization ) {
		if ( preg_match( '/^E[1-9]\d{0,9}\z/', $idSerialization ) ) {
			return new EntitySchemaId( $idSerialization );
		}
		return $this->parser->parse( $idSerialization );
	}

}
