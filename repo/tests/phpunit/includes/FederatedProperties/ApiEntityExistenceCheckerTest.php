<?php

declare( strict_types=1 );

namespace Wikibase\Repo\Tests\FederatedProperties;

use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\Repo\FederatedProperties\ApiEntityExistenceChecker;
use Wikibase\Repo\FederatedProperties\ApiEntityLookup;

/**
 * @covers \Wikibase\Repo\FederatedProperties\ApiEntityExistenceChecker
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class ApiEntityExistenceCheckerTest extends TestCase {

	public function testGivenApiResultContainsMissingKey_existsReturnsFalse() {
		$id = new PropertyId( 'P321' );

		$apiEntityLookup = $this->createMock( ApiEntityLookup::class );
		$apiEntityLookup->expects( $this->once() )
			->method( 'getResultPartForId' )
			->with( $id )
			->willReturn( [ 'id' => 'P321', 'missing' => '' ] );

		$existenceChecker = new ApiEntityExistenceChecker( $apiEntityLookup );

		$this->assertFalse( $existenceChecker->exists( $id ) );
	}

	public function testGivenApiResultDoesNotContainMissingKey_existsReturnsTrue() {
		$id = new PropertyId( 'P123' );

		$apiEntityLookup = $this->createMock( ApiEntityLookup::class );
		$apiEntityLookup->expects( $this->once() )
			->method( 'getResultPartForId' )
			->with( $id )
			->willReturn( [
				'id' => 'P123',
				'datatype' => 'string',
				// ...
			] );

		$existenceChecker = new ApiEntityExistenceChecker( $apiEntityLookup );

		$this->assertTrue( $existenceChecker->exists( $id ) );
	}

	public function testExistsBatch() {
		$ids = [ new PropertyId( 'P123' ), new PropertyId( 'P321' ) ];

		$apiEntityLookup = $this->createMock( ApiEntityLookup::class );
		$apiEntityLookup->expects( $this->once() )
			->method( 'fetchEntities' )
			->with( $ids );
		$apiEntityLookup->expects( $this->exactly( 2 ) )
			->method( 'getResultPartForId' )
			->withConsecutive(
				[ $ids[0] ],
				[ $ids[1] ]
			)
			->willReturnOnConsecutiveCalls(
				[ 'id' => 'P123', 'datatype' => 'string' ],
				[ 'id' => 'P321', 'missing' => '' ]
			);

		$existenceChecker = new ApiEntityExistenceChecker( $apiEntityLookup );
		$result = $existenceChecker->existsBatch( $ids );

		$expected = [ 'P123' => true, 'P321' => false ];
		$this->assertSame( $expected, $result );
	}

}
