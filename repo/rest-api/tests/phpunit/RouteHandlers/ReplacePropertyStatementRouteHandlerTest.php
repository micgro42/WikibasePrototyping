<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\RestApi\RouteHandlers;

use Generator;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\Reporter\ErrorReporter;
use MediaWiki\Rest\RequestData;
use MediaWiki\Rest\Response;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;
use RuntimeException;
use Throwable;
use Wikibase\Repo\RestApi\Application\UseCases\ReplaceStatement\ReplaceStatement;
use Wikibase\Repo\RestApi\Application\UseCases\ReplaceStatement\ReplaceStatementFactory;
use Wikibase\Repo\RestApi\Application\UseCases\ReplaceStatement\ReplaceStatementResponse;
use Wikibase\Repo\RestApi\Application\UseCases\UseCaseError;
use Wikibase\Repo\RestApi\Domain\ReadModel\Statement;
use Wikibase\Repo\RestApi\RouteHandlers\ReplacePropertyStatementRouteHandler;

/**
 * @covers \Wikibase\Repo\RestApi\RouteHandlers\ReplacePropertyStatementRouteHandler
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 *
 */
class ReplacePropertyStatementRouteHandlerTest extends MediaWikiIntegrationTestCase {

	use HandlerTestTrait;
	use RestHandlerTestUtilsTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setMockChangeTagsStore();
		$this->setMockPreconditionMiddlewareFactory();
	}

	public function testValidHttpResponse(): void {
		$useCaseResponse = new ReplaceStatementResponse(
			$this->createStub( Statement::class ), '20230731042031', 42
		);
		$useCase = $this->createStub( ReplaceStatement::class );
		$useCase->method( 'execute' )->willReturn( $useCaseResponse );
		$useCaseFactory = $this->createStub( ReplaceStatementFactory::class );
		$useCaseFactory->method( 'newReplaceStatement' )->willReturn( $useCase );

		$this->setService( 'WbRestApi.ReplaceStatementFactory', $useCaseFactory );

		/** @var Response $response */
		$response = $this->newHandlerWithValidRequest()->execute();
		$responseBody = json_decode( $response->getBody()->getContents(), true );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertSame( [ 'application/json' ], $response->getHeader( 'Content-Type' ) );
		$this->assertSame( [ '"42"' ], $response->getHeader( 'ETag' ) );
		$this->assertSame( [ 'Mon, 31 Jul 2023 04:20:31 GMT' ], $response->getHeader( 'Last-Modified' ) );
		$this->assertArrayEquals( [ 'id', 'rank', 'property', 'value', 'qualifiers', 'references' ], array_keys( $responseBody ) );
	}

	/**
	 * @dataProvider provideExceptionAndExpectedErrorCode
	 */
	public function testHandlesErrors( Throwable $exception, string $expectedErrorCode ): void {
		$useCase = $this->createStub( ReplaceStatement::class );
		$useCase->method( 'execute' )->willThrowException( $exception );
		$useCaseFactory = $this->createStub( ReplaceStatementFactory::class );
		$useCaseFactory->method( 'newReplaceStatement' )->willReturn( $useCase );

		$this->setService( 'WbRestApi.ReplaceStatementFactory', $useCaseFactory );
		$this->setService( 'WbRestApi.ErrorReporter', $this->createStub( ErrorReporter::class ) );

		/** @var Response $response */
		$response = $this->newHandlerWithValidRequest()->execute();
		$responseBody = json_decode( $response->getBody()->getContents() );

		$this->assertSame( [ 'en' ], $response->getHeader( 'Content-Language' ) );
		$this->assertSame( $expectedErrorCode, $responseBody->code );
	}

	public function provideExceptionAndExpectedErrorCode(): Generator {
		yield 'Error handled by ResponseFactory' => [
			new UseCaseError( UseCaseError::INVALID_STATEMENT_ID, '' ),
			UseCaseError::INVALID_STATEMENT_ID,
		];

		yield 'Invalid Statement Subject Id' => [
			new UseCaseError( UseCaseError::INVALID_STATEMENT_SUBJECT_ID, '', [ 'subject-id' => 'P123' ] ),
			UseCaseError::INVALID_PROPERTY_ID,
		];

		yield 'Statement Subject Not Found' => [
			new UseCaseError( UseCaseError::STATEMENT_SUBJECT_NOT_FOUND, '', [ 'subject-id' => 'P123' ] ),
			UseCaseError::PROPERTY_NOT_FOUND,
		];

		yield 'Unexpected Error' => [ new RuntimeException(), UseCaseError::UNEXPECTED_ERROR ];
	}

	public function testReadWriteAccess(): void {
		$routeHandler = $this->newHandlerWithValidRequest();

		$this->assertTrue( $routeHandler->needsReadAccess() );
		$this->assertTrue( $routeHandler->needsWriteAccess() );
	}

	private function newHandlerWithValidRequest(): Handler {
		$routeHandler = ReplacePropertyStatementRouteHandler::factory();
		$this->initHandler(
			$routeHandler,
			new RequestData( [
				'method' => 'PUT',
				'headers' => [
					'User-Agent' => 'PHPUnit Test',
					'Content-Type' => 'application/json',
				],
				'pathParams' => [
					ReplacePropertyStatementRouteHandler::PROPERTY_ID_PATH_PARAM => 'P1',
					ReplacePropertyStatementRouteHandler::STATEMENT_ID_PATH_PARAM => 'P1$1e63e3d9-4bd4-7671-706c-b745db23c3f1',
				],
				'bodyContents' => json_encode( [
					'statement' => [
						'property' => [
							'id' => 'P2',
						],
						'value' => [
							'type' => 'novalue',
						],
					],
				] ),
			] )
		);
		$this->validateHandler( $routeHandler );

		return $routeHandler;
	}
}
