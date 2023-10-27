<?php
declare( strict_types = 1 );

namespace Wikibase\Lib;

use MediaWiki\HookContainer\HookContainer;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdParser;
use Wikibase\DataModel\Entity\EntityIdParsingException;

/**
 * @license GPL-2.0-or-later
 */
class PseudoEntityIdParser {

	private HookContainer $runner;
	private EntityIdParser $parser;

	public function __construct(
		HookContainer $runner,
		EntityIdParser $parser
	) {
		$this->runner = $runner;
		$this->parser = $parser;
	}

	/**
	 * @see EntityIdParser::parse()
	 * @throws EntityIdParsingException
	 */
	public function parse( string $idSerialization ): EntityId {
		if ( !$this->runner->run( // TODO maybe use a hook interface
			'WikibasePseudoEntities_PseudoEntityIdParser_parse',
			[ $idSerialization, &$out ]
		) ) {
			// TODO is the hook handler allowed to throw EntityIdParsingException directly?
			// can $out be either EntityId or EntityIdParsingException? 🤔
			return $out;
		}

		return $this->parser->parse( $idSerialization );
	}

}
