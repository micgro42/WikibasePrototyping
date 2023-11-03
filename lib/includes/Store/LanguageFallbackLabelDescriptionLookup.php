<?php

namespace Wikibase\Lib\Store;

use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\IndeterminateEntityId;
use Wikibase\DataModel\Services\Lookup\LabelDescriptionLookupException;
use Wikibase\DataModel\Services\Lookup\TermLookup;
use Wikibase\DataModel\Services\Lookup\TermLookupException;
use Wikibase\DataModel\Term\TermFallback;
use Wikibase\Lib\TermLanguageFallbackChain;

/**
 * A lookup for labels and descriptions in a given language.
 * Applies language fallbacks; does not resolve redirects.
 * Use {@link FallbackLabelDescriptionLookupFactory} instead of using this class directly.
 *
 * @license GPL-2.0-or-later
 * @author Katie Filbert < aude.wiki@gmail.com >
 * @author Marius Hoch < hoo@online.de >
 */
class LanguageFallbackLabelDescriptionLookup implements FallbackLabelDescriptionLookup {

	/**
	 * @var TermLookup
	 */
	private $termLookup;

	/**
	 * @var TermLanguageFallbackChain
	 */
	private $termLanguageFallbackChain;

	/**
	 * @param TermLookup $termLookup
	 * @param TermLanguageFallbackChain $termLanguageFallbackChain
	 */
	public function __construct(
		TermLookup $termLookup,
		TermLanguageFallbackChain $termLanguageFallbackChain
	) {
		$this->termLookup = $termLookup;
		$this->termLanguageFallbackChain = $termLanguageFallbackChain;
	}

	/**
	 * @param IndeterminateEntityId $entityId
	 *
	 * @throws LabelDescriptionLookupException
	 * @return TermFallback|null
	 */
	public function getLabel( IndeterminateEntityId $entityId ) {
		$fetchLanguages = $this->termLanguageFallbackChain->getFetchLanguageCodes();

		try {
			if ( $entityId instanceof EntityId ) {
				$labels = $this->termLookup->getLabels( $entityId, $fetchLanguages );
			} else {
				throw new LabelDescriptionLookupException(
					$entityId,
					'Expected $entityId to be an implementation of EntityId, but got: ' . get_class( $entityId ) . ' instead.'
				);
			}
		} catch ( TermLookupException $ex ) {
			throw new LabelDescriptionLookupException( $entityId, $ex->getMessage(), $ex );
		}

		return $this->getTermFallback( $labels, $fetchLanguages );
	}

	/**
	 * @param IndeterminateEntityId $entityId
	 *
	 * @throws LabelDescriptionLookupException
	 * @return TermFallback|null
	 */
	public function getDescription( IndeterminateEntityId $entityId ) {
		$fetchLanguages = $this->termLanguageFallbackChain->getFetchLanguageCodes();

		try {
			if ( $entityId instanceof EntityId ) {
				$descriptions = $this->termLookup->getDescriptions( $entityId, $fetchLanguages );
			} else {
				throw new LabelDescriptionLookupException(
					$entityId,
					'Expected $entityId to be an implementation of EntityId, but got: ' . get_class( $entityId ) . ' instead.'
				);
			}
		} catch ( TermLookupException $ex ) {
			throw new LabelDescriptionLookupException( $entityId, $ex->getMessage(), $ex );
		}

		return $this->getTermFallback( $descriptions, $fetchLanguages );
	}

	/**
	 * @param string[] $terms
	 * @param string[] $fetchLanguages
	 *
	 * @return TermFallback|null
	 */
	private function getTermFallback( array $terms, array $fetchLanguages ) {
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
