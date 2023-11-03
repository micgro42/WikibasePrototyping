<?php

declare( strict_types = 1 );

namespace Wikibase\Lib\Store;

use MediaWiki\MediaWikiServices;
use Wikibase\DataModel\Entity\IndeterminateEntityId;
use Wikibase\DataModel\Entity\PseudoEntityId;
use Wikibase\DataModel\Services\Lookup\LabelDescriptionLookupException;
use Wikibase\DataModel\Services\Lookup\PseudoTermLookup;
use Wikibase\DataModel\Term\TermFallback;
use Wikibase\Lib\TermLanguageFallbackChain;

/**
 * @license GPL-2.0-or-later
 */
class PseudoFallbackLabelDescriptionLookup implements FallbackLabelDescriptionLookup {

	/**
	 * @var array PseudoTermLookup[] keyed by pseudo-entity-type
	 */
	private array $pseudoTermLookups = [];
	private TermLanguageFallbackChain $termLanguageFallbackChain;

	public function __construct( TermLanguageFallbackChain $termLanguageFallbackChain ) {
		$this->termLanguageFallbackChain = $termLanguageFallbackChain;
		MediaWikiServices::getInstance()->getHookContainer()->run(
			'WikibasePseudoEntities_GetPseudoTermLookup',
			[ &$this->pseudoTermLookups ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function getLabel( IndeterminateEntityId $entityId ): ?TermFallback {
		$fetchLanguages = $this->termLanguageFallbackChain->getFetchLanguageCodes();

		if ( !( $entityId instanceof PseudoEntityId ) ) {
			throw new LabelDescriptionLookupException(
				$entityId,
				'Expected implementation of PseudoEntityId, but got: ' . get_class( $entityId )
			);

		}

		$labels = $this->getPseudoTermLookup( $entityId )
			->getLabels( $entityId, $fetchLanguages );

		return $this->getTermFallback( $labels, $fetchLanguages );
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription( IndeterminateEntityId $entityId ): ?TermFallback {
		$fetchLanguages = $this->termLanguageFallbackChain->getFetchLanguageCodes();

		if ( !( $entityId instanceof PseudoEntityId ) ) {
			throw new LabelDescriptionLookupException(
				$entityId,
				'Expected implementation of PseudoEntityId, but got: ' . get_class( $entityId )
			);
		}

		$descriptions = $this->getPseudoTermLookup( $entityId )
			->getDescriptions( $entityId, $fetchLanguages );

		return $this->getTermFallback( $descriptions, $fetchLanguages );
	}

	private function getPseudoTermLookup( PseudoEntityId $entityId ): PseudoTermLookup {
		$entityType = $entityId->getEntityType();

		if ( !isset( $this->pseudoTermLookups[$entityType] ) ) {
			throw new LabelDescriptionLookupException( $entityId, "No pseudo term lookup for entity type $entityType" );
		}

		return $this->pseudoTermLookups[$entityType];
	}

	/**
	 * copied from \Wikibase\Lib\Store\LanguageFallbackLabelDescriptionLookup::getTermFallback
	 *
	 * @param string[] $terms
	 * @param string[] $fetchLanguages
	 *
	 * @return TermFallback|null
	 */
	private function getTermFallback( array $terms, array $fetchLanguages ): ?TermFallback {
		$extractedData = $this->termLanguageFallbackChain->extractPreferredValue( $terms );

		if ( $extractedData === null ) {
			return null;
		}

		// $fetchLanguages are in order of preference
		$requestLanguage = reset( $fetchLanguages );

		// see extractPreferredValue for array keys
		return new TermFallback(
			$requestLanguage,
			$extractedData['value'],
			$extractedData['language'],
			$extractedData['source']
		);
	}
}
