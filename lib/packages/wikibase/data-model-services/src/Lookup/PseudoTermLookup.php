<?php

declare( strict_types=1 );

namespace Wikibase\DataModel\Services\Lookup;

use Wikibase\DataModel\Entity\PseudoEntityId;

/**
 * A service interface for looking up terms of pseudo-entities.
 *
 * This service only looks up terms in the specified language(s)
 * and does not apply language fallbacks.
 *
 * @note: A TermLookup cannot be used to determine whether an entity exists or not.
 *
 *
 * @license GPL-2.0-or-later
 */
interface PseudoTermLookup {

	/**
	 * Gets the label of an Entity with the specified EntityId and language code.
	 *
	 * @throws TermLookupException for entity not found
	 */
	public function getLabel( PseudoEntityId $entityId, string $languageCode ): ?string;

	/**
	 * Gets all labels of an Entity with the specified EntityId.
	 *
	 * The result will contain the entries for the requested languages, if they exist.
	 *
	 * @param PseudoEntityId $entityId
	 * @param string[] $languageCodes The list of languages to fetch
	 *
	 * @throws TermLookupException if the entity was not found (not guaranteed).
	 * @return string[] labels, keyed by language.
	 */
	public function getLabels( PseudoEntityId $entityId, array $languageCodes ): array;

	/**
	 * Gets the description of an Entity with the specified EntityId and language code.
	 *
	 * @throws TermLookupException for entity not found
	 */
	public function getDescription( PseudoEntityId $entityId, string $languageCode ): ?string;

	/**
	 * Gets all descriptions of an Entity with the specified EntityId.
	 *
	 * If $languages is given, the result will contain the entries for the
	 * requested languages, if they exist.
	 *
	 * @param PseudoEntityId $entityId
	 * @param string[] $languageCodes The list of languages to fetch
	 *
	 * @throws TermLookupException if the entity was not found (not guaranteed).
	 * @return string[] descriptions, keyed by language.
	 */
	public function getDescriptions( PseudoEntityId $entityId, array $languageCodes ): array;

}
