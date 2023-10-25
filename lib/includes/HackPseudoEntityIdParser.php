<?php

namespace Wikibase\Lib;

use EntitySchema\Domain\Model\EntitySchemaId;
use Wikibase\DataModel\Entity\EntityIdParser;
use Wikibase\DataModel\Entity\EntityIdParsingException;

/**
 * Class that can parse real entity IDs (like {@link EntityIdParser})
 * and pseudo entity IDs (EntitySchema).
 *
 * @license GPL-2.0-or-later
 */
class HackPseudoEntityIdParser {

	private EntityIdParser $parser;

	public function __construct( EntityIdParser $parser ) {
		$this->parser = $parser;
	}

	/**
	 * @see EntityIdParser::parse()
	 * @throws EntityIdParsingException
	 */
	public function parse( $idSerialization ) {
		if ( preg_match( '/^E[1-9]\d{0,9}\z/', $idSerialization ) ) {
			return new EntitySchemaId( $idSerialization );
		}
		return $this->parser->parse( $idSerialization );
	}

}
