<?php

declare( strict_types = 1 );

namespace Wikibase\DataModel\Entity;

use InvalidArgumentException;

/**
 * @license GPL-2.0-or-later
 */
class DispatchingPseudoEntityIdParser implements PseudoEntityIdParser {

	/**
	 * @var callable[]
	 */
	private array $pseudoIdBuilders;
	private EntityIdParser $vanillaEntityIdParser;

	/**
	 * FIXME
	 *
	 * Takes an array in which each key is a preg_match pattern.
	 * The first pattern the id matches against will be picked.
	 * The value this key points to has to be a builder function
	 * that takes as only required argument the id serialization
	 * (string) and returns an EntityId instance.
	 *
	 * @param EntityIdParser $vanillaEntityIdParser
	 * @param array $pseudoIdBuilders
	 */
	public function __construct( EntityIdParser $vanillaEntityIdParser, array $pseudoIdBuilders ) {
		$this->vanillaEntityIdParser = $vanillaEntityIdParser;
		$this->pseudoIdBuilders = $pseudoIdBuilders;
	}

	public function getVanillaEntityIdParser(): EntityIdParser {
		return $this->vanillaEntityIdParser;
	}

	/**
	 * @param string $idSerialization
	 *
	 * @throws EntityIdParsingException
	 */
	public function parse( $idSerialization ): IndeterminateEntityId {
		$this->assertIdIsString( $idSerialization );

		foreach ( $this->pseudoIdBuilders as $idPattern => $idBuilder ) {
			if ( preg_match( $idPattern, $idSerialization ) ) {
				return $this->buildId( $idBuilder, $idSerialization );
			}
		}

		return $this->vanillaEntityIdParser->parse( $idSerialization );
	}

	/**
	 * @param string $idSerialization
	 *
	 * @throws EntityIdParsingException
	 */
	private function assertIdIsString( $idSerialization ) {
		if ( !is_string( $idSerialization ) ) {
			throw new EntityIdParsingException(
				'$idSerialization must be a string, got ' . ( is_object( $idSerialization )
					? get_class( $idSerialization )
					: getType( $idSerialization ) )
			);
		}
	}

	/**
	 * @throws EntityIdParsingException
	 */
	private function buildId( callable $idBuilder, string $idSerialization ): PseudoEntityId {
		try {
			return $idBuilder( $idSerialization );
		} catch ( InvalidArgumentException $ex ) {
			// Should not happen, but if it does, re-throw the original message
			throw new EntityIdParsingException( $ex->getMessage(), 0, $ex );
		}
	}

}
