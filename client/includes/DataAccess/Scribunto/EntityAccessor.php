<?php

namespace Wikibase\Client\DataAccess\Scribunto;

use InvalidArgumentException;
use Language;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Serializers\Serializer;
use Wikibase\Client\Serializer\ClientEntitySerializer;
use Wikibase\Client\Serializer\ClientStatementListSerializer;
use Wikibase\Client\Usage\UsageAccumulator;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Entity\PseudoEntityIdParser;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\DataModel\Services\Lookup\PropertyDataTypeLookup;
use Wikibase\DataModel\Services\Lookup\UnresolvedEntityRedirectException;
use Wikibase\DataModel\Statement\StatementListProvider;
use Wikibase\Lib\ContentLanguages;
use Wikibase\Lib\TermLanguageFallbackChain;

/**
 * Functionality needed to expose Entities to Lua.
 *
 * @license GPL-2.0-or-later
 */
class EntityAccessor {

	/**
	 * @var PseudoEntityIdParser
	 */
	private $entityIdParser;

	/**
	 * @var EntityLookup
	 */
	private $entityLookup;

	/**
	 * @var UsageAccumulator
	 */
	private $usageAccumulator;

	/**
	 * @var Serializer
	 */
	private $entitySerializer;

	/**
	 * @var Serializer
	 */
	private $statementSerializer;

	/**
	 * @var PropertyDataTypeLookup
	 */
	private $dataTypeLookup;

	/**
	 * @var TermLanguageFallbackChain
	 */
	private $termFallbackChain;

	/**
	 * @var Language
	 */
	private $language;

	/**
	 * @var ContentLanguages
	 */
	private $termsLanguages;

	/**
	 * @var LoggerInterface
	 */
	private $logger;
	private HookContainer $hookContainer;

	/**
	 * @param PseudoEntityIdParser $entityIdParser
	 * @param EntityLookup $entityLookup
	 * @param UsageAccumulator $usageAccumulator
	 * @param Serializer $entitySerializer
	 * @param Serializer $statementSerializer
	 * @param PropertyDataTypeLookup $dataTypeLookup
	 * @param TermLanguageFallbackChain $termFallbackChain
	 * @param Language $language
	 * @param ContentLanguages $termsLanguages
	 */
	public function __construct(
		PseudoEntityIdParser $entityIdParser,
		EntityLookup $entityLookup,
		UsageAccumulator $usageAccumulator,
		Serializer $entitySerializer,
		Serializer $statementSerializer,
		PropertyDataTypeLookup $dataTypeLookup,
		TermLanguageFallbackChain $termFallbackChain,
		Language $language,
		ContentLanguages $termsLanguages,
		LoggerInterface $logger = null
	) {
		$this->entityIdParser = $entityIdParser;
		$this->entityLookup = $entityLookup;
		$this->usageAccumulator = $usageAccumulator;
		$this->entitySerializer = $entitySerializer;
		$this->statementSerializer = $statementSerializer;
		$this->dataTypeLookup = $dataTypeLookup;
		$this->termFallbackChain = $termFallbackChain;
		$this->language = $language;
		$this->termsLanguages = $termsLanguages;
		$this->logger = $logger ?: new NullLogger();
		// TODO: inject
		$this->hookContainer = MediaWikiServices::getInstance()->getHookContainer();
	}

	/**
	 * Recursively renumber a serialized array in place, so it is indexed at 1, not 0.
	 * Just like Lua wants it.
	 *
	 * @param array &$entityArr
	 */
	private function renumber( array &$entityArr ) {
		foreach ( $entityArr as &$value ) {
			if ( !is_array( $value ) ) {
				continue;
			}
			if ( isset( $value[0] ) ) {
				array_unshift( $value, null );
				unset( $value[0] );
			}
			$this->renumber( $value );
		}
	}

	/**
	 * Get entity from prefixed ID (e.g. "Q23") and return it as serialized array.
	 *
	 * @param string $prefixedEntityId
	 *
	 * @return array|null
	 */
	public function getEntity( $prefixedEntityId ) {
		$prefixedEntityId = trim( $prefixedEntityId );

		$entityId = $this->entityIdParser->parse( $prefixedEntityId );

		if ( $entityId instanceof EntityId ) {

			try {
				$entityObject = $this->entityLookup->getEntity( $entityId );
			} catch ( UnresolvedEntityRedirectException $ex ) {
				$this->logPossibleDoubleRedirect( $prefixedEntityId );

				return null;
			}

			if ( $entityObject === null ) {
				return null;
			}

			$entityArr = $this->newClientEntitySerializer()->serialize( $entityObject );
		} else {
			$entityArr = [];
			$this->hookContainer->run(
				'WikibasePseudoEntities_LoadPseudoEntityArray',
				[ &$entityArr, $entityId ]
			);
			if ( $entityArr === [] ) {
				return null;
			}
		}

		// Renumber the entity as Lua uses 1-based array indexing
		$this->renumber( $entityArr );
		$entityArr['schemaVersion'] = 2;

		// FIXME: assert array serialization structure to prevent Wikibase and EntitySchema from diverging
		return $entityArr;
	}

	/**
	 * Find out whether an entity exists.
	 *
	 * @param string $prefixedEntityId
	 *
	 * @return bool
	 */
	public function entityExists( $prefixedEntityId ) {
		$prefixedEntityId = trim( $prefixedEntityId );

		$entityId = $this->entityIdParser->parse( $prefixedEntityId );

		// This doesn't really depend on any aspect of the entity specifically.
		$this->usageAccumulator->addOtherUsage( $entityId );
		if ( !( $entityId instanceof EntityId ) ) {
			$entityExists = false;
			$this->hookContainer->run(
				'WikibasePseudoEntities_EntityExists',
				[ &$entityExists, $entityId ]
			);
			return $entityExists;
		}
		try {
			return $this->entityLookup->hasEntity( $entityId );
		} catch ( UnresolvedEntityRedirectException $ex ) {
			$this->logPossibleDoubleRedirect( $prefixedEntityId );

			return false;
		}
	}

	/**
	 * Get statement list from prefixed ID (e.g. "Q23") and property (e.g "P123") and return it as serialized array.
	 *
	 * @param string $prefixedEntityId
	 * @param string $propertyIdSerialization
	 * @param string $rank Which statements to include. Either "best" or "all".
	 *
	 * @return array|null
	 */
	public function getEntityStatements( $prefixedEntityId, $propertyIdSerialization, $rank ) {
		$prefixedEntityId = trim( $prefixedEntityId );
		$entityId = $this->entityIdParser->parse( $prefixedEntityId );

		if ( !( $entityId instanceof EntityId ) ) {
			// TODO: PseudoEntities do not have statements *yet*, see T345745
			return null;
		}

		$propertyId = new NumericPropertyId( $propertyIdSerialization );
		$this->usageAccumulator->addStatementUsage( $entityId, $propertyId );

		try {
			$entity = $this->entityLookup->getEntity( $entityId );
		} catch ( UnresolvedEntityRedirectException $ex ) {
			$this->logPossibleDoubleRedirect( $prefixedEntityId );

			return null;
		}

		if ( !( $entity instanceof StatementListProvider ) ) {
			return null;
		}

		$statements = $entity->getStatements()->getByPropertyId( $propertyId );

		if ( $rank === 'best' ) {
			$statements = $statements->getBestStatements();
		} elseif ( $rank !== 'all' ) {
			throw new InvalidArgumentException( '$rank must be "best" or "all", "' . $rank . '" given' );
		}

		$serialization = $this->newClientStatementListSerializer()->serialize( $statements );
		$this->renumber( $serialization );
		return $serialization;
	}

	private function newClientEntitySerializer() {
		return new ClientEntitySerializer(
			$this->entitySerializer,
			$this->dataTypeLookup,
			$this->entityIdParser->getVanillaEntityIdParser(),
			array_unique( array_merge(
				$this->termsLanguages->getLanguages(),
				$this->termFallbackChain->getFetchLanguageCodes(),
				[ $this->language->getCode() ]
			) ),
			[ $this->language->getCode() => $this->termFallbackChain ]
		);
	}

	private function newClientStatementListSerializer() {
		return new ClientStatementListSerializer(
			$this->statementSerializer,
			$this->dataTypeLookup,
			$this->entityIdParser->getVanillaEntityIdParser()
		);
	}

	/**
	 * @see UnresolvedEntityRedirectException
	 * @param string $prefixedEntityId
	 */
	private function logPossibleDoubleRedirect( $prefixedEntityId ) {
		$this->logger->info( 'Unresolved redirect encountered loading {prefixedEntityId}. This is typically cleaned up asynchronously.',
			[
				'prefixedEntityId' => $prefixedEntityId,
			]
		);
	}

}
