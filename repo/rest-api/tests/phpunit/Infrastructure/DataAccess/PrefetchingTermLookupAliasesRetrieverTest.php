<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\RestApi\Infrastructure\DataAccess;

use PHPUnit\Framework\TestCase;
use Wikibase\DataAccess\NullPrefetchingTermLookup;
use Wikibase\DataAccess\PrefetchingTermLookup;
use Wikibase\DataAccess\Tests\FakePrefetchingTermLookup;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Lib\StaticContentLanguages;
use Wikibase\Repo\RestApi\Domain\ReadModel\Aliases;
use Wikibase\Repo\RestApi\Domain\ReadModel\AliasesInLanguage;
use Wikibase\Repo\RestApi\Infrastructure\DataAccess\PrefetchingTermLookupAliasesRetriever;

/**
 * @covers \Wikibase\Repo\RestApi\Infrastructure\DataAccess\PrefetchingTermLookupAliasesRetriever
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class PrefetchingTermLookupAliasesRetrieverTest extends TestCase {

	private const ALL_TERM_LANGUAGES = [ 'de', 'en', 'ar' ];
	private const ITEM_ID = 'Q123';

	public function testGetAliases(): void {
		$itemId = new ItemId( self::ITEM_ID );

		$aliasesRetriever = new PrefetchingTermLookupAliasesRetriever(
			new FakePrefetchingTermLookup(),
			new StaticContentLanguages( self::ALL_TERM_LANGUAGES )
		);

		$aliases = $aliasesRetriever->getAliases( $itemId );

		$aliasesReadModel = new Aliases(
			new AliasesInLanguage( 'de', [ 'Q123 de alias 1', 'Q123 de alias 2' ] ),
			new AliasesInLanguage( 'en', [ 'Q123 en alias 1', 'Q123 en alias 2' ] ),
			new AliasesInLanguage( 'ar', [ 'Q123 ar alias 1', 'Q123 ar alias 2' ] ),
		);

		$this->assertEquals( $aliasesReadModel, $aliases );
	}

	public function testGetAliasesForSpecificLanguages(): void {
		$itemId = new ItemId( self::ITEM_ID );
		$languages = [ 'en', 'de' ];

		$prefetchingTermLookup = $this->createStub( PrefetchingTermLookup::class );
		$prefetchingTermLookup->method( 'getPrefetchedAliases' )
			->willReturnMap( [
				[ $itemId, 'en', [ 'Q123 en alias 1', 'Q123 en alias 2' ] ],
				[ $itemId, 'de', false ],
			] );

		$aliasesRetriever = new PrefetchingTermLookupAliasesRetriever(
			$prefetchingTermLookup,
			new StaticContentLanguages( $languages )
		);

		$aliases = $aliasesRetriever->getAliases( $itemId );

		$this->assertCount( 1, $aliases );
		$this->assertArrayHasKey( 'en', $aliases );
		$this->assertArrayNotHasKey( 'de', $aliases );
	}

	public function testGivenLanguageCodeWithNoAliasesFor_getAliasesInLanguageReturnsNull(): void {
		$itemId = new ItemId( self::ITEM_ID );
		$languageCode = 'de';

		$aliasesRetriever = new PrefetchingTermLookupAliasesRetriever(
			new NullPrefetchingTermLookup(),
			new StaticContentLanguages( [ $languageCode ] )
		);

		$aliasesInLanguage = $aliasesRetriever->getAliasesInLanguage( $itemId, $languageCode );
		$this->assertNull( $aliasesInLanguage );
	}

	public function testGetAliasesInLanguage(): void {
		$itemId = new ItemId( self::ITEM_ID );
		$languageCode = 'en';

		$aliasesRetriever = new PrefetchingTermLookupAliasesRetriever(
			new FakePrefetchingTermLookup(),
			new StaticContentLanguages( [ $languageCode ] )
		);

		$aliasesInLanguage = $aliasesRetriever->getAliasesInLanguage( $itemId, $languageCode );

		$this->assertEquals(
			new AliasesInLanguage( 'en', [ 'Q123 en alias 1', 'Q123 en alias 2' ] ),
			$aliasesInLanguage
		);
	}

}