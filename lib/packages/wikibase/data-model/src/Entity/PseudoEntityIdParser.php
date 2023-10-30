<?php

declare( strict_types = 1 );

namespace Wikibase\DataModel\Entity;

/**
 * Interface for objects that can parse strings into IndeterminateEntityIds
 *
 * @license GPL-2.0-or-later
 */
interface PseudoEntityIdParser {

	/**
	 * @throws EntityIdParsingException
	 */
	public function parse( string $idSerialization ): IndeterminateEntityId;

	public function getVanillaEntityIdParser(): EntityIdParser;

}
