<?php

namespace Wikibase\Lib\Store\Sql\Terms;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Term\AliasGroup;
use Wikibase\DataModel\Term\AliasGroupList;
use Wikibase\DataModel\Term\Fingerprint;
use Wikibase\DataModel\Term\Term;
use Wikibase\DataModel\Term\TermList;
use Wikibase\StringNormalizer;
use Wikibase\TermStore\ItemTermStore;
use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * @license GPL-2.0-or-later
 */
class DatabaseItemTermStore implements ItemTermStore {

	/** @var ILoadBalancer */
	private $loadBalancer;

	/** @var TermIdsAcquirer */
	private $acquirer;

	/** @var TermIdsResolver */
	private $resolver;

	/** @var TermIdsCleaner */
	private $cleaner;

	/** @var StringNormalizer */
	private $stringNormalizer;

	/** @var LoggerInterface */
	private $logger;

	/** @var IDatabase|null */
	private $dbr = null;

	/** @var IDatabase|null */
	private $dbw = null;

	public function __construct(
		ILoadBalancer $loadBalancer,
		TermIdsAcquirer $acquirer,
		TermIdsResolver $resolver,
		TermIdsCleaner $cleaner,
		StringNormalizer $stringNormalizer,
		LoggerInterface $logger = null
	) {
		$this->loadBalancer = $loadBalancer;
		$this->acquirer = $acquirer;
		$this->resolver = $resolver;
		$this->cleaner = $cleaner;
		$this->stringNormalizer = $stringNormalizer;
		$this->logger = $logger ?: new NullLogger();
	}

	private function getDbr(): IDatabase {
		if ( $this->dbr === null ) {
			$this->dbr = $this->loadBalancer->getConnection( ILoadBalancer::DB_REPLICA );
		}
		return $this->dbr;
	}

	private function getDbw(): IDatabase {
		if ( $this->dbw === null ) {
			$this->dbw = $this->loadBalancer->getConnection( ILoadBalancer::DB_MASTER );
		}
		return $this->dbw;
	}

	private function disallowForeignItemIds( ItemId $itemId ) {
		if ( $itemId->isForeign() ) {
			throw new InvalidArgumentException(
				'This implementation cannot be used with foreign item IDs!'
			);
		}
	}

	public function storeTerms( ItemId $itemId, Fingerprint $terms ) {
		$this->disallowForeignItemIds( $itemId );

		$termsArray = [];
		foreach ( $terms->getLabels()->toTextArray() as $language => $label ) {
			$label = $this->stringNormalizer->cleanupToNFC( $label );
			$termsArray['label'][$language] = $label;
		}
		foreach ( $terms->getDescriptions()->toTextArray() as $language => $description ) {
			$description = $this->stringNormalizer->cleanupToNFC( $description );
			$termsArray['description'][$language] = $description;
		}
		foreach ( $terms->getAliasGroups()->toTextArray() as $language => $aliases ) {
			$aliases = array_map( [ $this->stringNormalizer, 'cleanupToNFC' ], $aliases );
			$termsArray['alias'][$language] = $aliases;
		}

		try {
			$termIdsToClean = $this->acquireAndInsertTerms( $itemId, $termsArray );
		} catch ( DBError $exception ) {
			$this->logger->error(
				'{method}: DBError while storing terms for {itemId}: {exception}',
				[
					'method' => __METHOD__,
					'itemId' => $itemId->getSerialization(),
					'terms' => $termsArray,
					'exception' => $exception,
				]
			);
			throw $exception;
		}

		if ( $termIdsToClean !== [] ) {
			$this->cleanTermsIfUnused( $termIdsToClean );
		}
	}

	/**
	 * Acquire term IDs for the given terms array,
	 * store them in wbt_item_terms for the given item ID,
	 * and return term IDs that are no longer referenced
	 * and might now need to be cleaned up.
	 *
	 * (The returned term IDs might still be used in wbt_item_terms rows
	 * for other item IDs or elsewhere, and this should be checked just before cleanup.
	 * However, that may happen in a different transaction than this call.)
	 *
	 * @param ItemId $itemId
	 * @param array $termsArray
	 * @return int[]
	 */
	private function acquireAndInsertTerms( ItemId $itemId, array $termsArray ): array {
		$oldTermIds = $this->getDbw()->selectFieldValues(
			'wbt_item_terms',
			'wbit_term_in_lang_id',
			[ 'wbit_item_id' => $itemId->getNumericId() ],
			__METHOD__,
			[ 'FOR UPDATE' ]
		);

		$termIdsToClean = [];

		$this->acquirer->acquireTermIds(
			$termsArray,
			function ( array $newTermIds ) use ( $itemId, $oldTermIds, &$termIdsToClean ) {
				$termIdsToInsert = array_diff( $newTermIds, $oldTermIds );
				$termIdsToClean = array_diff( $oldTermIds, $newTermIds );
				$rowsToInsert = [];
				foreach ( $termIdsToInsert as $termIdToInsert ) {
					$rowsToInsert[] = [
						'wbit_item_id' => $itemId->getNumericId(),
						'wbit_term_in_lang_id' => $termIdToInsert,
					];
				}

				$this->getDbw()->insert(
					'wbt_item_terms',
					$rowsToInsert,
					__METHOD__
				);
			}
		);

		if ( $termIdsToClean !== [] ) {
			$this->getDbw()->delete(
				'wbt_item_terms',
				[
					'wbit_item_id' => $itemId->getNumericId(),
					'wbit_term_in_lang_id' => $termIdsToClean,
				],
				__METHOD__
			);
		}

		return $termIdsToClean;
	}

	public function deleteTerms( ItemId $itemId ) {
		$this->disallowForeignItemIds( $itemId );

		try {
			$termIdsToClean = $this->deleteTermsWithoutClean( $itemId );
		} catch ( DBError $exception ) {
			$this->logger->error(
				'{method}: DBError while deleting terms for {itemId}: {exception}',
				[
					'method' => __METHOD__,
					'itemId' => $itemId->getSerialization(),
					'exception' => $exception,
				]
			);
			throw $exception;
		}

		if ( $termIdsToClean !== [] ) {
			$this->cleanTermsIfUnused( $termIdsToClean );
		}
	}

	/**
	 * Delete wbt_item_terms rows for the given item ID,
	 * and return term IDs that are no longer referenced
	 * and might now need to be cleaned up.
	 *
	 * (The returned term IDs might still be used in wbt_item_terms rows
	 * for other item IDs or elsewhere, and this should be checked just before cleanup.
	 * However, that may happen in a different transaction than this call.)
	 *
	 * @param ItemId $itemId
	 * @return int[]
	 */
	private function deleteTermsWithoutClean( ItemId $itemId ): array {
		$res = $this->getDbw()->select(
			'wbt_item_terms',
			[ 'wbit_id', 'wbit_term_in_lang_id' ],
			[ 'wbit_item_id' => $itemId->getNumericId() ],
			__METHOD__,
			[ 'FOR UPDATE' ]
		);

		$rowIdsToDelete = [];
		$termIdsToCleanUp = [];
		foreach ( $res as $row ) {
			$rowIdsToDelete[] = $row->wbit_id;
			$termIdsToCleanUp[] = $row->wbit_term_in_lang_id;
		}

		if ( $rowIdsToDelete !== [] ) {
			$this->getDbw()->delete(
				'wbt_item_terms',
				[ 'wbit_id' => $rowIdsToDelete ],
				__METHOD__
			);
		}

		return array_values( array_unique( $termIdsToCleanUp ) );
	}

	/**
	 * Of the given term IDs, delete those that aren’t used by any other items or properties.
	 *
	 * Currently, this does not account for term IDs that may be used anywhere else,
	 * e. g. by other entity types; anyone who uses term IDs elsewhere runs the risk
	 * of those terms being deleted at any time. This may be improved in the future.
	 *
	 * @param int[] $termIds
	 */
	private function cleanTermsIfUnused( array $termIds ) {
		try {
			$termIdsUsedInProperties = $this->getDbw()->selectFieldValues(
				'wbt_property_terms',
				'wbpt_term_in_lang_id',
				[ 'wbpt_term_in_lang_id' => $termIds ],
				__METHOD__,
				[
					'FOR UPDATE', // see comment in DatabaseTermIdsCleaner::cleanTermInLangIds()
					// 'DISTINCT', // not supported in combination with FOR UPDATE on some DB types
				]
			);
			$termIdsUsedInItems = $this->getDbw()->selectFieldValues(
				'wbt_item_terms',
				'wbit_term_in_lang_id',
				[ 'wbit_term_in_lang_id' => $termIds ],
				__METHOD__,
				[
					'FOR UPDATE', // see comment in DatabaseTermIdsCleaner::cleanTermInLangIds()
					// 'DISTINCT', // not supported in combination with FOR UPDATE on some DB types
				]
			);

			$this->cleaner->cleanTermIds(
				array_diff(
					$termIds,
					$termIdsUsedInProperties,
					$termIdsUsedInItems
				)
			);
		} catch ( DBError $exception ) {
			$this->logger->error(
				'{method}: DBError while cleaning up to {termIdsCount} terms: {exception}',
				[
					'method' => __METHOD__,
					'termIds' => $termIds,
					'termIdsCount' => count( $termIds ),
					'exception' => $exception,
				]
			);
			throw $exception;
		}
	}

	public function getTerms( ItemId $itemId ): Fingerprint {
		$this->disallowForeignItemIds( $itemId );

		$termIds = $this->getDbr()->selectFieldValues(
			'wbt_item_terms',
			'wbit_term_in_lang_id',
			[ 'wbit_item_id' => $itemId->getNumericId() ],
			__METHOD__
		);

		$terms = $this->resolver->resolveTermIds( $termIds );
		$labels = $terms['label'] ?? [];
		$descriptions = $terms['description'] ?? [];
		$aliases = $terms['alias'] ?? [];

		return new Fingerprint(
			new TermList( array_map(
				function ( $language, $labels ) {
					return new Term( $language, $labels[0] );
				},
				array_keys( $labels ), $labels
			) ),
			new TermList( array_map(
				function ( $language, $descriptions ) {
					return new Term( $language, $descriptions[0] );
				},
				array_keys( $descriptions ), $descriptions
			) ),
			new AliasGroupList( array_map(
				function ( $language, $aliases ) {
					return new AliasGroup( $language, $aliases );
				},
				array_keys( $aliases ), $aliases
			) )
		);
	}

}
