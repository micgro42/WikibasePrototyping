<?php

namespace Wikibase\DataModel\Services\Lookup;

use Wikibase\DataModel\Entity\Property;
use Wikibase\DataModel\Entity\PropertyId;

/**
 * @since 1.0
 *
 * @licence GNU GPL v2+
 * @author Thomas Pellissier Tanon
 */
interface PropertyLookup {

	/**
	 * Returns the Property of which the id is given.
	 *
	 * @param PropertyId $propertyId
	 *
	 * @return Property|null
	 * @throws EntityLookupException
	 */
	public function getPropertyForId( PropertyId $propertyId );

}
