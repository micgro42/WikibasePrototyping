<?php

namespace Wikibase\Lib\Store;

use Wikibase\DataModel\Entity\IndeterminateEntityId;
use Wikibase\DataModel\Services\Lookup\LabelDescriptionLookup;
use Wikibase\DataModel\Services\Lookup\LabelDescriptionLookupException;
use Wikibase\DataModel\Term\TermFallback;

/**
 * A {@link LabelDescriptionLookup} that is guaranteed to return
 * {@link TermFallback}s, not merely {@link Term}s.
 *
 * Whether redirects are resolved is currently implementation-dependent.
 * Use {@link FallbackLabelDescriptionLookupFactory} to create a lookup
 * that applies language fallbacks and resolves redirects.
 *
 * @license GPL-2.0-or-later
 */
interface FallbackLabelDescriptionLookup extends LabelDescriptionLookup {

	/**
	 * @param IndeterminateEntityId $entityId
	 *
	 * @throws LabelDescriptionLookupException
	 * @return TermFallback|null
	 */
	public function getLabel( IndeterminateEntityId $entityId );

	/**
	 * @param IndeterminateEntityId $entityId
	 *
	 * @throws LabelDescriptionLookupException
	 * @return TermFallback|null
	 */
	public function getDescription( IndeterminateEntityId $entityId );

}
