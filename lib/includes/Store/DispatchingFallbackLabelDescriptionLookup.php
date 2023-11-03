<?php

declare( strict_types = 1 );

namespace Wikibase\Lib\Store;

use Wikibase\DataModel\Entity\IndeterminateEntityId;
use Wikibase\DataModel\Entity\PseudoEntityId;
use Wikibase\Lib\FederatedProperties\FederatedPropertyId;

/**
 * A {@link FallbackLabelDescriptionLookup} that dispatches between two other lookups,
 * using one for federated property IDs and one for everything else.
 *
 * This is necessary because the lookup implementation we want to use for most entity IDs,
 * {@link CachingFallbackLabelDescriptionLookup}, does not support federated properties,
 * because it requires an EntityRevisionLookup (to get the latest revision ID for the cache key),
 * which is not available for federated properties as of July 2022.
 *
 * This class should only be used by {@link FallbackLabelDescriptionLookupFactory}.
 * Do not use it directly.
 *
 * @license GPL-2.0-or-later
 */
class DispatchingFallbackLabelDescriptionLookup implements FallbackLabelDescriptionLookup {

	/** @var FallbackLabelDescriptionLookup */
	private $standardLookup;
	/** @var FallbackLabelDescriptionLookup */
	private $federatedPropertiesLookup;
	private FallbackLabelDescriptionLookup $pseudoEntityFallbackLookup;

	/**
	 * @param FallbackLabelDescriptionLookup $standardLookup
	 * The lookup used for most entity IDs.
	 * Usually a {@link CachingFallbackLabelDescriptionLookup}.
	 * @param FallbackLabelDescriptionLookup $federatedPropertiesLookup
	 * The lookup used for federated property IDs.
	 * Usually a {@link LanguageFallbackLabelDescriptionLookup}.
	 * @param FallbackLabelDescriptionLookup $pseudoEntityFallbackLookup
	 * The lookup used for pseudo-entity IDs.
	 * Usually a {@link PseudoFallbackLabelDescriptionLookup}.
	 */
	public function __construct(
		FallbackLabelDescriptionLookup $standardLookup,
		FallbackLabelDescriptionLookup $federatedPropertiesLookup,
		FallbackLabelDescriptionLookup $pseudoEntityFallbackLookup
	) {
		$this->standardLookup = $standardLookup;
		$this->federatedPropertiesLookup = $federatedPropertiesLookup;
		$this->pseudoEntityFallbackLookup = $pseudoEntityFallbackLookup;
	}

	public function getLabel( IndeterminateEntityId $entityId ) {
		return $this->getLookup( $entityId )->getLabel( $entityId );
	}

	public function getDescription( IndeterminateEntityId $entityId ) {
		return $this->getLookup( $entityId )->getDescription( $entityId );
	}

	private function getLookup( IndeterminateEntityId $entityId ): FallbackLabelDescriptionLookup {
		if ( $entityId instanceof FederatedPropertyId ) {
			return $this->federatedPropertiesLookup;
		}
		if ( $entityId instanceof PseudoEntityId ) {
			return $this->pseudoEntityFallbackLookup;
		}
		return $this->standardLookup;
	}

}
