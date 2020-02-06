<?php

namespace Wikibase\Repo\Tests;

use Wikibase\DataAccess\EntitySource;
use Wikibase\DataAccess\EntitySourceDefinitions;
use Wikibase\DataModel\Entity\Int32EntityId;
use Wikibase\Lib\DataTypeFactory;
use DataValues\DataValue;
use DataValues\DataValueFactory;
use DataValues\Geo\Values\GlobeCoordinateValue;
use DataValues\Geo\Values\LatLongValue;
use DataValues\MonolingualTextValue;
use DataValues\QuantityValue;
use DataValues\StringValue;
use DataValues\TimeValue;
use DataValues\UnboundedQuantityValue;
use DataValues\UnknownValue;
use Deserializers\Deserializer;
use MediaWikiTestCase;
use RequestContext;
use Serializers\Serializer;
use User;
use ReflectionClass;
use ReflectionMethod;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\Lib\Store\PropertyInfoLookup;
use Wikibase\Lib\Store\PropertyInfoStore;
use Wikibase\Lib\Interactors\TermSearchInteractor;
use Wikibase\Repo\ChangeOp\ChangeOpFactoryProvider;
use Wikibase\DataModel\DeserializerFactory;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdParser;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\SerializerFactory;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\DataModel\Services\Lookup\PropertyDataTypeLookup;
use Wikibase\DataModel\Services\Lookup\TermLookup;
use Wikibase\DataModel\Services\Statement\StatementGuidParser;
use Wikibase\DataModel\Services\Statement\StatementGuidValidator;
use Wikibase\DataModel\Services\Term\TermBuffer;
use Wikibase\Repo\EditEntity\MediawikiEditEntityFactory;
use Wikibase\EntityFactory;
use Wikibase\LanguageFallbackChainFactory;
use Wikibase\Lib\Changes\EntityChangeFactory;
use Wikibase\Lib\ContentLanguages;
use Wikibase\Lib\DataTypeDefinitions;
use Wikibase\Lib\EntityTypeDefinitions;
use Wikibase\Lib\Formatters\OutputFormatSnakFormatterFactory;
use Wikibase\Lib\Formatters\OutputFormatValueFormatterFactory;
use Wikibase\Lib\RepositoryDefinitions;
use Wikibase\Lib\Store\EntityContentDataCodec;
use Wikibase\Lib\Store\EntityNamespaceLookup;
use Wikibase\Lib\Store\EntityRevisionLookup;
use Wikibase\Lib\Store\EntityStore;
use Wikibase\Lib\Store\EntityStoreWatcher;
use Wikibase\Lib\Store\EntityTitleLookup;
use Wikibase\Lib\Store\LanguageFallbackLabelDescriptionLookupFactory;
use Wikibase\Lib\Formatters\WikibaseSnakFormatterBuilders;
use Wikibase\Lib\Formatters\WikibaseValueFormatterBuilders;
use Wikibase\PropertyInfoBuilder;
use Wikibase\Rdf\EntityRdfBuilderFactory;
use Wikibase\Rdf\RdfVocabulary;
use Wikibase\Rdf\ValueSnakRdfBuilderFactory;
use Wikibase\Repo\Api\ApiHelperFactory;
use Wikibase\Repo\BuilderBasedDataTypeValidatorFactory;
use Wikibase\Repo\ChangeOp\Deserialization\ChangeOpDeserializerFactory;
use Wikibase\Repo\ChangeOp\EntityChangeOpProvider;
use Wikibase\Repo\Content\EntityContentFactory;
use Wikibase\Repo\Content\EntityHandler;
use Wikibase\Repo\EntityIdHtmlLinkFormatterFactory;
use Wikibase\Repo\Interactors\ItemMergeInteractor;
use Wikibase\Repo\Interactors\ItemRedirectCreationInteractor;
use Wikibase\Repo\Localizer\ExceptionLocalizer;
use Wikibase\Repo\Notifications\ChangeNotifier;
use Wikibase\Repo\ParserOutput\EntityParserOutputGeneratorFactory;
use Wikibase\Repo\SnakFactory;
use Wikibase\Repo\ValidatorBuilders;
use Wikibase\Repo\Validators\CompositeValidator;
use Wikibase\Repo\Validators\EntityExistsValidator;
use Wikibase\Repo\Validators\TermValidatorFactory;
use Wikibase\Repo\ValueParserFactory;
use Wikibase\Repo\WikibaseRepo;
use Wikibase\SettingsArray;
use Wikibase\Store\EntityIdLookup;
use Wikibase\Store;
use Wikibase\StringNormalizer;
use Wikibase\SummaryFormatter;
use Wikibase\WikibaseSettings;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\LBFactory;

/**
 * @covers \Wikibase\Repo\WikibaseRepo
 *
 * @group Wikibase
 * @group Database
 *
 * @license GPL-2.0-or-later
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 * @author Daniel Kinzler
 */
class WikibaseRepoTest extends MediaWikiTestCase {

	protected function setUp(): void {
		parent::setUp();

		// WikibaseRepo service getters should never access the database or do http requests
		// https://phabricator.wikimedia.org/T243729
		$this->disallowDBAccess();
		$this->disallowHttpAccess();
	}

	private function disallowDBAccess() {
		$this->setService(
			'DBLoadBalancerFactory',
			function() {
				$lb = $this->createMock( ILoadBalancer::class );
				$lb->expects( $this->never() )
					->method( 'getConnection' );
				$lb->expects( $this->never() )
					->method( 'getConnectionRef' );
				$lb->expects( $this->never() )
					->method( 'getMaintenanceConnectionRef' );
				$lb->expects( $this->any() )
					->method( 'getLocalDomainID' )
					->willReturn( 'banana' );

				$lbFactory = $this->createMock( LBFactory::class );
				$lbFactory->expects( $this->any() )
					->method( 'getMainLB' )
					->willReturn( $lb );

				return $lbFactory;
			}
		);
	}

	private function disallowHttpAccess() {
		$this->setService(
			'HttpRequestFactory',
			function() {
				$this->fail( 'Service getters must not access HttpRequestFactory.' );
			}
		);
	}

	public function testGetDefaultValidatorBuilders() {
		$first = WikibaseRepo::getDefaultValidatorBuilders();
		$this->assertInstanceOf( ValidatorBuilders::class, $first );

		$second = WikibaseRepo::getDefaultValidatorBuilders();
		$this->assertSame( $first, $second );
	}

	public function testNewValidatorBuilders() {
		$kittenId = $this->getMockBuilder( EntityId::class )
			->disableOriginalConstructor()
			->getMock();
		$kittenId->expects( $this->any() )
			->method( 'getEntityType' )
			->will( $this->returnValue( 'kitten' ) );
		$kittenId->expects( $this->any() )
			->method( 'getSerialization' )
			->will( $this->returnValue( 'other:K9' ) );
		$kittenId->expects( $this->any() )
			->method( 'getLocalPart' )
			->will( $this->returnValue( 'K9' ) );
		$kittenId->expects( $this->any() )
			->method( 'getRepositoryName' )
			->will( $this->returnValue( 'other' ) );

		$valueToValidate = new EntityIdValue( $kittenId );

		$repo = $this->getWikibaseRepoWithCustomRepositoryDefinitions( array_merge(
			$this->getRepositoryDefinition( '', [ 'entity-namespaces' => [ 'item' => 200, 'property' => 300 ] ] ),
			$this->getRepositoryDefinition( 'other', [ 'entity-namespaces' => [ 'kitten' => 666 ] ] )
		) );

		$builders = $repo->newValidatorBuilders();
		$this->assertInstanceOf( ValidatorBuilders::class, $builders );

		// We get the resulting ValueValidators and run them against our fake remote-repo
		// custom-type EntityIdValue. We skip the existence check though, since we don't
		// have a mock lookup in place.
		$entityValidators = $builders->buildEntityValidators();
		foreach ( $entityValidators as $validator ) {
			if ( $validator instanceof EntityExistsValidator ) {
				continue;
			}

			$result = $validator->validate( $valueToValidate );
			$this->assertTrue( $result->isValid(), get_class( $validator ) );
		}
	}

	/**
	 * @dataProvider urlSchemesProvider
	 */
	public function testDefaultUrlValidators( $input, $expected ) {
		$validatorBuilders = WikibaseRepo::getDefaultValidatorBuilders();
		$urlValidator = new CompositeValidator( $validatorBuilders->buildUrlValidators() );
		$result = $urlValidator->validate( new StringValue( $input ) );
		$this->assertSame( $expected, $result->isValid() );
	}

	public function urlSchemesProvider() {
		return [
			[ 'bzr://x', true ],
			[ 'cvs://x', true ],
			[ 'ftp://x', true ],
			[ 'git://x', true ],
			[ 'http://x', true ],
			[ 'https://x', true ],
			[ 'irc://x', true ],
			[ 'mailto:x@x', true ],
			[ 'ssh://x', true ],
			[ 'svn://x', true ],

			// Supported by UrlSchemeValidators, but not enabled by default.
			[ 'ftps://x', false ],
			[ 'gopher://x', false ],
			[ 'ircs://x', false ],
			[ 'mms://x', false ],
			[ 'nntp://x', false ],
			[ 'redis://x', false ],
			[ 'sftp://x', false ],
			[ 'telnet://x', false ],
			[ 'worldwind://x', false ],
		];
	}

	public function testGetDefaultValueFormatterBuilders() {
		$first = $this->getWikibaseRepo()->getDefaultValueFormatterBuilders();
		$this->assertInstanceOf( WikibaseValueFormatterBuilders::class, $first );

		$second = $this->getWikibaseRepo()->getDefaultValueFormatterBuilders();
		$this->assertSame( $first, $second );
	}

	public function testGetDefaultSnakFormatterBuilders() {
		$first = $this->getWikibaseRepo()->getDefaultSnakFormatterBuilders();
		$this->assertInstanceOf( WikibaseSnakFormatterBuilders::class, $first );

		$second = $this->getWikibaseRepo()->getDefaultSnakFormatterBuilders();
		$this->assertSame( $first, $second );
	}

	public function testGetDataTypeFactoryReturnType() {
		$returnValue = $this->getWikibaseRepo()->getDataTypeFactory();
		$this->assertInstanceOf( DataTypeFactory::class, $returnValue );
	}

	public function testGetValueParserFactoryReturnType() {
		$returnValue = $this->getWikibaseRepo()->getValueParserFactory();
		$this->assertInstanceOf( ValueParserFactory::class, $returnValue );
	}

	public function testGetDataValueFactoryReturnType() {
		$returnValue = $this->getWikibaseRepo()->getDataValueFactory();
		$this->assertInstanceOf( DataValueFactory::class, $returnValue );
	}

	public function testGetEntityContentFactoryReturnType() {
		$returnValue = $this->getWikibaseRepo()->getEntityContentFactory();
		$this->assertInstanceOf( EntityContentFactory::class, $returnValue );
	}

	public function testGetEntityStoreWatcherReturnType() {
		$returnValue = $this->getWikibaseRepo()->getEntityStoreWatcher();
		$this->assertInstanceOf( EntityStoreWatcher::class, $returnValue );
	}

	public function testGetEntityTitleLookupReturnType() {
		$returnValue = $this->getWikibaseRepo()->getEntityTitleLookup();
		$this->assertInstanceOf( EntityTitleLookup::class, $returnValue );
	}

	public function testGetEntityIdLookupReturnType() {
		$returnValue = $this->getWikibaseRepo()->getEntityIdLookup();
		$this->assertInstanceOf( EntityIdLookup::class, $returnValue );
	}

	public function testGetEntityRevisionLookupReturnType() {
		$returnValue = $this->getWikibaseRepo()->getEntityRevisionLookup();
		$this->assertInstanceOf( EntityRevisionLookup::class, $returnValue );
	}

	public function testNewRedirectCreationInteractorReturnType() {
		$user = $this->getMockBuilder( User::class )
			->disableOriginalConstructor()
			->getMock();
		$context = new RequestContext();
		$returnValue = $this->getWikibaseRepo()->newItemRedirectCreationInteractor( $user, $context );
		$this->assertInstanceOf( ItemRedirectCreationInteractor::class, $returnValue );
	}

	public function testNewTermSearchInteractorReturnType() {
		$returnValue = $this->getWikibaseRepo()->newTermSearchInteractor( '' );
		$this->assertInstanceOf( TermSearchInteractor::class, $returnValue );
	}

	public function testGetEntityStoreReturnType() {
		$returnValue = $this->getWikibaseRepo()->getEntityStore();
		$this->assertInstanceOf( EntityStore::class, $returnValue );
	}

	public function testGetPropertyDataTypeLookupReturnType() {
		$returnValue = $this->getWikibaseRepo()->getPropertyDataTypeLookup();
		$this->assertInstanceOf( PropertyDataTypeLookup::class, $returnValue );
	}

	public function testGetStringNormalizerReturnType() {
		$returnValue = $this->getWikibaseRepo()->getStringNormalizer();
		$this->assertInstanceOf( StringNormalizer::class, $returnValue );
	}

	public function testGetEntityLookupReturnType() {
		$returnValue = $this->getWikibaseRepo()->getEntityLookup();
		$this->assertInstanceOf( EntityLookup::class, $returnValue );
	}

	public function testGetSnakFactoryReturnType() {
		$returnValue = $this->getWikibaseRepo()->getSnakFactory();
		$this->assertInstanceOf( SnakFactory::class, $returnValue );
	}

	public function testGetEntityIdParserReturnType() {
		$returnValue = $this->getWikibaseRepo()->getEntityIdParser();
		$this->assertInstanceOf( EntityIdParser::class, $returnValue );
	}

	public function testGetStatementGuidParser() {
		$returnValue = $this->getWikibaseRepo()->getStatementGuidParser();
		$this->assertInstanceOf( StatementGuidParser::class, $returnValue );
	}

	public function testGetEntityChangeOpProvider() {
		$provider = $this->getWikibaseRepo()->getEntityChangeOpProvider();
		$this->assertInstanceOf( EntityChangeOpProvider::class, $provider );
	}

	public function testGetChangeOpDeserializerFactory() {
		$factory = $this->getWikibaseRepo()->getChangeOpDeserializerFactory();
		$this->assertInstanceOf( ChangeOpDeserializerFactory::class, $factory );
	}

	public function testGetLanguageFallbackChainFactory() {
		$returnValue = $this->getWikibaseRepo()->getLanguageFallbackChainFactory();
		$this->assertInstanceOf( LanguageFallbackChainFactory::class, $returnValue );
	}

	public function testGetLanguageFallbackLabelDescriptionLookupFactory() {
		$returnValue = $this->getWikibaseRepo()->getLanguageFallbackLabelDescriptionLookupFactory();
		$this->assertInstanceOf( LanguageFallbackLabelDescriptionLookupFactory::class, $returnValue );
	}

	public function testGetStatementGuidValidator() {
		$returnValue = $this->getWikibaseRepo()->getStatementGuidValidator();
		$this->assertInstanceOf( StatementGuidValidator::class, $returnValue );
	}

	public function testGetSettingsReturnType() {
		$returnValue = $this->getWikibaseRepo()->getSettings();
		$this->assertInstanceOf( SettingsArray::class, $returnValue );
	}

	public function testGetStoreReturnType() {
		$returnValue = $this->getWikibaseRepo()->getStore();
		$this->assertInstanceOf( Store::class, $returnValue );
	}

	public function testGetSnakFormatterFactory() {
		$returnValue = $this->getWikibaseRepo()->getSnakFormatterFactory();
		$this->assertInstanceOf( OutputFormatSnakFormatterFactory::class, $returnValue );
	}

	public function testGetValueFormatterFactory() {
		$returnValue = $this->getWikibaseRepo()->getValueFormatterFactory();
		$this->assertInstanceOf( OutputFormatValueFormatterFactory::class, $returnValue );
	}

	public function testGetSummaryFormatter() {
		$returnValue = $this->getWikibaseRepo()->getSummaryFormatter();
		$this->assertInstanceOf( SummaryFormatter::class, $returnValue );
	}

	public function testGetTermValidatorFactory() {
		$factory = $this->getWikibaseRepo()->getTermValidatorFactory();
		$this->assertInstanceOf( TermValidatorFactory::class, $factory );
	}

	public function testGetChangeOpFactory() {
		$returnValue = $this->getWikibaseRepo()->getChangeOpFactoryProvider();
		$this->assertInstanceOf( ChangeOpFactoryProvider::class, $returnValue );
	}

	public function testGetChangeNotifier() {
		$factory = $this->getWikibaseRepo()->getChangeNotifier();
		$this->assertInstanceOf( ChangeNotifier::class, $factory );
	}

	public function testGetContentModelMappings() {
		$array = $this->getWikibaseRepo()->getContentModelMappings();
		$this->assertIsArray( $array );
		$this->assertContainsOnly( 'string', $array );
	}

	public function testGetEntityFactory() {
		$entityFactory = $this->getWikibaseRepo()->getEntityFactory();
		$this->assertInstanceOf( EntityFactory::class, $entityFactory );
	}

	public function testGetLocalEntityTypes() {
		$settings = new SettingsArray( WikibaseRepo::getDefaultInstance()->getSettings()->getArrayCopy() );
		$settings->setSetting(
			'entityNamespaces',
			[
				'foo' => 100,
				'bar' => 102,
				'lexeme' => 104,
			]
		);
		$settings->setSetting(
			'repositories',
			[ '' => [
				'database' => null,
				'base-uri' => null,
				'prefix-mapping' => [ '' => '' ],
				'entity-namespaces' => $settings->getSetting( 'entityNamespaces' ),
			] ]
		);
		$settings->setSetting( 'useEntitySourceBasedFederation', false );

		$entityTypeDefinitions = $this->getEntityTypeDefinitions();
		$wikibaseRepo = new WikibaseRepo(
			$settings,
			new DataTypeDefinitions( [] ),
			$entityTypeDefinitions,
			new RepositoryDefinitions(
				$settings->getSetting( 'repositories' ),
				$entityTypeDefinitions
			),
			new EntitySourceDefinitions( [], $entityTypeDefinitions )
		);

		$localEntityTypes = $wikibaseRepo->getLocalEntityTypes();
		$this->assertContains( 'foo', $localEntityTypes );
		$this->assertContains( 'bar', $localEntityTypes );
		$this->assertContains( 'lexeme', $localEntityTypes );
		// Sub entities should appear in the list
		$this->assertContains( 'form', $localEntityTypes );
	}

	public function testGetLocalEntityTypes_entitySourceBasedFederation() {
		$settings = new SettingsArray( WikibaseRepo::getDefaultInstance()->getSettings()->getArrayCopy() );
		$settings->setSetting( 'useEntitySourceBasedFederation', true );
		$settings->setSetting( 'localEntitySourceName', 'local' );

		$entityTypeDefinitions = $this->getEntityTypeDefinitions();
		$irrelevantRepositoryDefinition = [ '' => [
			'database' => null,
			'base-uri' => null,
			'prefix-mapping' => [ '' => '' ],
			'entity-namespaces' => [],
		] ];

		$wikibaseRepo = new WikibaseRepo(
			$settings,
			new DataTypeDefinitions( [] ),
			$entityTypeDefinitions,
			new RepositoryDefinitions(
				$irrelevantRepositoryDefinition,
				$entityTypeDefinitions
			),
			new EntitySourceDefinitions( [
				new EntitySource(
					'local',
					false,
					[
						'foo' => [ 'namespaceId' => 100, 'slot' => 'main' ],
						'bar' => [ 'namespaceId' => 102, 'slot' => 'main' ],
						'lexeme' => [ 'namespaceId' => 104, 'slot' => 'main' ],
					],
					'',
					'wd',
					'',
					''
				)
			], $entityTypeDefinitions )
		);

		$localEntityTypes = $wikibaseRepo->getLocalEntityTypes();

		$this->assertContains( 'foo', $localEntityTypes );
		$this->assertContains( 'bar', $localEntityTypes );
		$this->assertContains( 'lexeme', $localEntityTypes );
		// Sub entities should appear in the list
		$this->assertContains( 'form', $localEntityTypes );
	}

	/**
	 * @param string $repositoryName
	 * @param array $customSettings
	 *
	 * @return array[]
	 */
	private function getRepositoryDefinition( $repositoryName, array $customSettings = [] ) {
		return [
			$repositoryName => array_merge(
				[
					'database' => '',
					'base-uri' => 'http://acme.test/concept/',
					'entity-namespaces' => [ 'item' => 200, 'property' => 300 ],
					'prefix-mapping' => [],
				],
				$customSettings
			)
		];
	}

	/**
	 * @param array $repoDefinitions
	 *
	 * @return WikibaseRepo
	 */
	private function getWikibaseRepoWithCustomRepositoryDefinitions( array $repoDefinitions ) {
		$settings = new SettingsArray( WikibaseRepo::getDefaultInstance()->getSettings()->getArrayCopy() );
		$settings->setSetting( 'useEntitySourceBasedFederation', false );

		$entityTypeDefinitions = new EntityTypeDefinitions( [] );
		return new WikibaseRepo(
			$settings,
			new DataTypeDefinitions( [] ),
			$entityTypeDefinitions,
			new RepositoryDefinitions( $repoDefinitions, $entityTypeDefinitions ),
			new EntitySourceDefinitions( [], $entityTypeDefinitions )
		);
	}

	private function getEntityTypeDefinitions() {
		return new EntityTypeDefinitions(
			[
				'lexeme' => [
					'sub-entity-types' => [
						'form',
					],
				],
			]
		);
	}

	public function testGetEnabledEntityTypes() {
		if ( !WikibaseSettings::isClientEnabled() ) {
			$this->markTestSkipped( 'WikibaseClient must be enabled to run this test' );
		}

		$settings = new SettingsArray( WikibaseRepo::getDefaultInstance()->getSettings()->getArrayCopy() );
		$settings->setSetting( 'useEntitySourceBasedFederation', false );

		$entityTypeDefinitions = $this->getEntityTypeDefinitions();
		$wikibaseRepo = new WikibaseRepo(
			$settings,
			new DataTypeDefinitions( [] ),
			$entityTypeDefinitions,
			new RepositoryDefinitions(
				array_merge(
					$this->getRepositoryDefinition( '', [ 'entity-namespaces' => [ 'foo' => 200, 'bar' => 220 ] ] ),
					$this->getRepositoryDefinition( 'repo1', [ 'entity-namespaces' => [ 'baz' => 250 ] ] ),
					$this->getRepositoryDefinition( 'repo2', [ 'entity-namespaces' => [ 'lexeme' => 280 ] ] )
				),
				$entityTypeDefinitions
			),
			new EntitySourceDefinitions( [], $entityTypeDefinitions )
		);

		$enabled = $wikibaseRepo->getEnabledEntityTypes();
		$this->assertContains( 'foo', $enabled );
		$this->assertContains( 'bar', $enabled );
		$this->assertContains( 'baz', $enabled );
		$this->assertContains( 'lexeme', $enabled );
		$this->assertContains( 'form', $enabled );
	}

	public function testGetEnabledEntityTypes_entitySourceBasedFederation() {
		if ( !WikibaseSettings::isClientEnabled() ) {
			$this->markTestSkipped( 'WikibaseClient must be enabled to run this test' );
		}

		$settings = new SettingsArray( WikibaseRepo::getDefaultInstance()->getSettings()->getArrayCopy() );
		$settings->setSetting( 'useEntitySourceBasedFederation', true );

		$entityTypeDefinitions = $this->getEntityTypeDefinitions();
		$irrelevantRepositoryDefinition = [ '' => [
			'database' => null,
			'base-uri' => null,
			'prefix-mapping' => [ '' => '' ],
			'entity-namespaces' => [],
		] ];

		$wikibaseRepo = new WikibaseRepo(
			$settings,
			new DataTypeDefinitions( [] ),
			$entityTypeDefinitions,
			new RepositoryDefinitions(
				$irrelevantRepositoryDefinition,
				$entityTypeDefinitions
			),
			new EntitySourceDefinitions( [
				new EntitySource(
					'local',
					false,
					[
						'foo' => [ 'namespaceId' => 200, 'slot' => 'main' ],
						'bar' => [ 'namespaceId' => 220, 'slot' => 'main' ],
					],
					'',
					'',
					'',
					''
				),
				new EntitySource(
					'bazwiki',
					'bazdb',
					[
						'baz' => [ 'namespaceId' => 250, 'slot' => 'main' ],
					],
					'',
					'baz',
					'baz',
					'bazwiki'
				),
				new EntitySource(
					'lexemewiki',
					'bazdb',
					[
						'lexeme' => [ 'namespaceId' => 280, 'slot' => 'main' ],
					],
					'',
					'lex',
					'lex',
					'lexwiki'
				)
			], $entityTypeDefinitions )
		);

		$enabled = $wikibaseRepo->getEnabledEntityTypes();
		$this->assertContains( 'foo', $enabled );
		$this->assertContains( 'bar', $enabled );
		$this->assertContains( 'baz', $enabled );
		$this->assertContains( 'lexeme', $enabled );
		$this->assertContains( 'form', $enabled );
	}

	public function testGetExceptionLocalizer() {
		$localizer = $this->getWikibaseRepo()->getExceptionLocalizer();
		$this->assertInstanceOf( ExceptionLocalizer::class, $localizer );
	}

	public function testGetEntityContentDataCodec() {
		$codec = $this->getWikibaseRepo()->getEntityContentDataCodec();
		$this->assertInstanceOf( EntityContentDataCodec::class, $codec );
	}

	public function testGetExternalFormatDeserializerFactory() {
		$deserializerFactory = $this->getWikibaseRepo()->getBaseDataModelDeserializerFactory();
		$this->assertInstanceOf( DeserializerFactory::class, $deserializerFactory );
	}

	public function testGetSerializerFactory() {
		$serializerFactory = $this->getWikibaseRepo()->getBaseDataModelSerializerFactory();
		$this->assertInstanceOf( SerializerFactory::class, $serializerFactory );
	}

	public function testGetCompactSerializerFactory() {
		$serializerFactory = $this->getWikibaseRepo()->getCompactBaseDataModelSerializerFactory();
		$this->assertInstanceOf( SerializerFactory::class, $serializerFactory );
	}

	public function testGetInternalFormatEntityDeserializer() {
		$deserializer = $this->getWikibaseRepo()->getInternalFormatEntityDeserializer();
		$this->assertInstanceOf( Deserializer::class, $deserializer );
	}

	public function testGetEntitySerializer() {
		$serializer = $this->getWikibaseRepo()->getAllTypesEntitySerializer();
		$this->assertInstanceOf( Serializer::class, $serializer );
	}

	public function testGetCompactEntitySerializer() {
		$serializer = $this->getWikibaseRepo()->getCompactEntitySerializer();
		$this->assertInstanceOf( Serializer::class, $serializer );
	}

	public function testGetStorageEntitySerializer() {
		$serializer = $this->getWikibaseRepo()->getStorageEntitySerializer();
		$this->assertInstanceOf( Serializer::class, $serializer );
	}

	public function testGetExternalFormatStatementDeserializer() {
		$deserializer = $this->getWikibaseRepo()->getExternalFormatStatementDeserializer();
		$this->assertInstanceOf( Deserializer::class, $deserializer );
	}

	public function testGetInternalFormatStatementDeserializer() {
		$deserializer = $this->getWikibaseRepo()->getInternalFormatStatementDeserializer();
		$this->assertInstanceOf( Deserializer::class, $deserializer );
	}

	public function testGetStatementSerializer() {
		$serializer = $this->getWikibaseRepo()->getStatementSerializer();
		$this->assertInstanceOf( Serializer::class, $serializer );
	}

	public function testGetEntityChangeFactory() {
		$factory = $this->getWikibaseRepo()->getEntityChangeFactory();
		$this->assertInstanceOf( EntityChangeFactory::class, $factory );
	}

	public function testNewItemHandler() {
		$handler = $this->getWikibaseRepo()->newItemHandler();
		$this->assertInstanceOf( EntityHandler::class, $handler );
	}

	public function testNewPropertyHandler() {
		$handler = $this->getWikibaseRepo()->newPropertyHandler();
		$this->assertInstanceOf( EntityHandler::class, $handler );
	}

	public function testNewItemHandler_noTransform() {
		$wikibaseRepo = $this->getWikibaseRepo();
		$wikibaseRepo->getSettings()->setSetting( 'transformLegacyFormatOnExport', false );

		$handler = $wikibaseRepo->newItemHandler();
		$this->assertNull( $handler->getLegacyExportFormatDetector() );
	}

	public function testNewPropertyHandler_noTransform() {
		$wikibaseRepo = $this->getWikibaseRepo();
		$wikibaseRepo->getSettings()->setSetting( 'transformLegacyFormatOnExport', false );

		$handler = $wikibaseRepo->newPropertyHandler();
		$this->assertNull( $handler->getLegacyExportFormatDetector() );
	}

	public function testNewItemHandler_withTransform() {
		$wikibaseRepo = $this->getWikibaseRepo();
		$wikibaseRepo->getSettings()->setSetting( 'transformLegacyFormatOnExport', true );

		$handler = $wikibaseRepo->newItemHandler();
		$this->assertNotNull( $handler->getLegacyExportFormatDetector() );
	}

	public function testNewPropertyHandler_withTransform() {
		$wikibaseRepo = $this->getWikibaseRepo();
		$wikibaseRepo->getSettings()->setSetting( 'transformLegacyFormatOnExport', true );

		$handler = $wikibaseRepo->newPropertyHandler();
		$this->assertNotNull( $handler->getLegacyExportFormatDetector() );
	}

	/**
	 * @param array[] $entityTypeDefinitions
	 *
	 * @return WikibaseRepo
	 */
	private function getWikibaseRepo( $entityTypeDefinitions = [] ) {
		$settings = new SettingsArray( WikibaseRepo::getDefaultInstance()->getSettings()->getArrayCopy() );
		$settings->setSetting( 'localEntitySourceName', 'test' );
		return new WikibaseRepo(
			$settings,
			new DataTypeDefinitions( [] ),
			new EntityTypeDefinitions( $entityTypeDefinitions ),
			$this->getRepositoryDefinitions(),
			$this->getEntitySourceDefinitions()
		);
	}

	/**
	 * @return RepositoryDefinitions
	 */
	private function getRepositoryDefinitions() {
		return new RepositoryDefinitions(
			[ '' => [ 'database' => '', 'base-uri' => '', 'entity-namespaces' => [], 'prefix-mapping' => [] ] ],
			new EntityTypeDefinitions( [] )
		);
	}

	private function getEntitySourceDefinitions( string $sourceName = 'test' ) {
		return new EntitySourceDefinitions(
			[ new EntitySource(
				$sourceName,
				false,
				[
					'item' => [ 'namespaceId' => 100, 'slot' => 'main' ],
					'property' => [ 'namespaceId' => 200, 'slot' => 'main' ],
				],
				'',
				'',
				'',
				''
			) ],
			new EntityTypeDefinitions( [] )
		);
	}

	public function testGetApiHelperFactory() {
		$factory = $this->getWikibaseRepo()->getApiHelperFactory( new RequestContext() );
		$this->assertInstanceOf( ApiHelperFactory::class, $factory );
	}

	public function testNewEditEntityFactory() {
		$factory = $this->getWikibaseRepo()->newEditEntityFactory( new RequestContext() );
		$this->assertInstanceOf( MediawikiEditEntityFactory::class, $factory );
	}

	public function testNewEditEntityFactory_withoutContextParam() {
		$factory = $this->getWikibaseRepo()->newEditEntityFactory();
		$this->assertInstanceOf( MediawikiEditEntityFactory::class, $factory );
	}

	public function testNewItemMergeInteractor() {
		$interactor = $this->getWikibaseRepo()->newItemMergeInteractor( new RequestContext() );
		$this->assertInstanceOf( ItemMergeInteractor::class, $interactor );
	}

	public function testGetTermLookup() {
		$service = $this->getWikibaseRepo()->getTermLookup();
		$this->assertInstanceOf( TermLookup::class, $service );
	}

	public function testGetTermBuffer() {
		$service = $this->getWikibaseRepo()->getTermBuffer();
		$this->assertInstanceOf( TermBuffer::class, $service );
	}

	public function testGetTermBuffer_instance() {
		$repo = $this->getWikibaseRepo();
		$service = $repo->getTermBuffer();
		$this->assertSame( $service, $repo->getTermBuffer(), 'Second call should return same instance' );
		$this->assertSame( $service, $repo->getTermLookup(), 'TermBuffer and TermLookup should be the same object' );
	}

	public function testGetTermsLanguages() {
		$service = $this->getWikibaseRepo()->getTermsLanguages();
		$this->assertInstanceOf( ContentLanguages::class, $service );
	}

	public function testNewPropertyInfoBuilder() {
		$wikibaseRepo = $this->getWikibaseRepo();
		$wikibaseRepo->getSettings()->setSetting(
			'formatterUrlProperty',
			'P123'
		);

		$wikibaseRepo->getSettings()->setSetting(
			'canonicalUriProperty',
			'P321'
		);

		$builder = $wikibaseRepo->newPropertyInfoBuilder();

		$this->assertInstanceOf( PropertyInfoBuilder::class, $builder );
		$expected = [
			PropertyInfoLookup::KEY_FORMATTER_URL => new PropertyId( 'P123' ),
			PropertyInfoStore::KEY_CANONICAL_URI => new PropertyId( 'P321' )
		];
		$this->assertEquals( $expected,  $builder->getPropertyIdMap() );
	}

	public function testGetEntityNamespaceLookup() {
		$service = $this->getWikibaseRepo()->getEntityNamespaceLookup();
		$this->assertInstanceOf( EntityNamespaceLookup::class, $service );
	}

	public function testGetEntityIdHtmlLinkFormatterFactory() {
		$service = $this->getWikibaseRepo()->getEntityIdHtmlLinkFormatterFactory();
		$this->assertInstanceOf( EntityIdHtmlLinkFormatterFactory::class, $service );
	}

	public function testGetEntityParserOutputGeneratorFactory() {
		$service = $this->getWikibaseRepo()->getEntityParserOutputGeneratorFactory();
		$this->assertInstanceOf( EntityParserOutputGeneratorFactory::class, $service );
	}

	public function testGetDataTypeValidatorFactory() {
		$service = $this->getWikibaseRepo()->getDataTypeValidatorFactory();
		$this->assertInstanceOf( BuilderBasedDataTypeValidatorFactory::class, $service );
	}

	public function testGetDataTypeDefinitions() {
		$dataTypeDefinitions = $this->getWikibaseRepo()->getDataTypeDefinitions();
		$this->assertInstanceOf( DataTypeDefinitions::class, $dataTypeDefinitions );
	}

	public function testGetValueSnakRdfBuilderFactory() {
		$factory = $this->getWikibaseRepo()->getValueSnakRdfBuilderFactory();
		$this->assertInstanceOf( ValueSnakRdfBuilderFactory::class, $factory );
	}

	public function testGetRdfVocabulary() {
		$factory = $this->getWikibaseRepo()->getRdfVocabulary();
		$this->assertInstanceOf( RdfVocabulary::class, $factory );
	}

	public function testGetEntityRdfBuilderFactory() {
		$provider = $this->getWikibaseRepo()->getEntityRdfBuilderFactory();
		$this->assertInstanceOf( EntityRdfBuilderFactory::class, $provider );
	}

	/**
	 * @return DataValueFactory
	 */
	private function getDataValueFactory() {
		return $this->getWikibaseRepo( [
			'item' => [
				'entity-id-pattern' => ItemId::PATTERN,
				'entity-id-builder' => function ( $serialization ) {
					return new ItemId( $serialization );
				},
			],
		] )->getDataValueFactory();
	}

	public function dataValueProvider() {
		return [
			'string' => [ new StringValue( 'Test' ) ],
			'unknown' => [ new UnknownValue( [ 'foo' => 'bar' ] ) ],
			'globecoordinate' => [ new GlobeCoordinateValue( new LatLongValue( 2, 3 ), 1 ) ],
			'monolingualtext' => [ new MonolingualTextValue( 'als', 'Test' ) ],
			'unbounded quantity' => [ UnboundedQuantityValue::newFromNumber( 2 ) ],
			'quantity' => [ QuantityValue::newFromNumber( 2 ) ],
			'time' => [ new TimeValue(
				'+1980-10-07T17:33:22Z',
				0,
				0,
				1,
				TimeValue::PRECISION_DAY,
				TimeValue::CALENDAR_GREGORIAN
			) ],
			'wikibase-entityid' => [ new EntityIdValue( new ItemId( 'Q13' ) ) ],
		];
	}

	/**
	 * @dataProvider dataValueProvider
	 */
	public function testDataValueSerializationDeserializationRoundtrip( DataValue $expected ) {
		$service = $this->getDataValueFactory();
		$deserialized = $service->newFromArray( $expected->toArray() );

		$this->assertEquals( $expected, $deserialized );
	}

	public function entityIdValueSerializationProvider() {
		return [
			'legacy' => [ [
				'entity-type' => 'item',
				'numeric-id' => 13,
			] ],
			'intermediate' => [ [
				'entity-type' => 'item',
				'numeric-id' => 13,
				'id' => 'Q13',
			] ],
			'new' => [ [
				'id' => 'Q13',
			] ],
		];
	}

	/**
	 * @dataProvider entityIdValueSerializationProvider
	 */
	public function testEntityIdValueDeserialization( array $serialization ) {
		$service = $this->getDataValueFactory();
		$deserialized = $service->newFromArray( [
			'type' => 'wikibase-entityid',
			'value' => $serialization,
		] );

		$expected = new EntityIdValue( new ItemId( 'Q13' ) );
		$this->assertEquals( $expected, $deserialized );
	}

	public function testGetEntityTypeToRepositoryMapping() {
		$wikibaseRepo = $this->getWikibaseRepoWithCustomRepositoryDefinitions( array_merge(
			$this->getRepositoryDefinition( '', [ 'entity-namespaces' => [ 'foo' => 100, 'bar' => 200 ] ] ),
			$this->getRepositoryDefinition( 'repo1', [ 'entity-namespaces' => [ 'baz' => 300 ] ] ),
			$this->getRepositoryDefinition( 'repo2', [ 'entity-namespaces' => [ 'foobar' => '400/foo' ] ] )
		) );

		$this->assertEquals(
			[
				'foo' => [ [ '', 100, 'main' ] ],
				'bar' => [ [ '', 200, 'main' ] ],
				'baz' => [ [ 'repo1', 300, 'main' ] ],
				'foobar' => [ [ 'repo2', 400, 'foo' ] ],
			],
			$wikibaseRepo->getEntityTypeToRepositoryMapping()
		);
	}

	public function testGetEntityTypeToRepositoryMapping_entitySourceBasedFederation() {
		$settings = new SettingsArray( WikibaseRepo::getDefaultInstance()->getSettings()->getArrayCopy() );
		$settings->setSetting( 'useEntitySourceBasedFederation', true );

		$entityTypeDefinitions = $this->getEntityTypeDefinitions();
		$irrelevantRepositoryDefinition = [ '' => [
			'database' => null,
			'base-uri' => null,
			'prefix-mapping' => [ '' => '' ],
			'entity-namespaces' => [],
		] ];

		$wikibaseRepo = new WikibaseRepo(
			$settings,
			new DataTypeDefinitions( [] ),
			$entityTypeDefinitions,
			new RepositoryDefinitions(
				$irrelevantRepositoryDefinition,
				$entityTypeDefinitions
			),
			new EntitySourceDefinitions( [
				new EntitySource(
					'local',
					false,
					[
						'foo' => [ 'namespaceId' => 200, 'slot' => 'main' ],
						'bar' => [ 'namespaceId' => 220, 'slot' => 'main' ],
					],
					'',
					'',
					'',
					''
				),
				new EntitySource(
					'lexemewiki',
					'bazdb',
					[
						'lexeme' => [ 'namespaceId' => 280, 'slot' => 'main' ],
					],
					'',
					'lex',
					'lex',
					'lexwiki'
				)
			], $entityTypeDefinitions )
		);

		$this->assertEquals(
			[
				'foo' => [ '' ],
				'bar' => [ '' ],
				'lexeme' => [ '' ],
				'form' => [ '' ],
			],
			$wikibaseRepo->getEntityTypeToRepositoryMapping()
		);
	}

	public function testGetConceptBaseUris() {
		$wikibaseRepo = $this->getWikibaseRepoWithCustomRepositoryDefinitions( array_merge(
			$this->getRepositoryDefinition( '', [ 'base-uri' => 'http://acme.test/concept/' ] ),
			$this->getRepositoryDefinition( 'other', [ 'base-uri' => 'http://other.wiki/concept/', 'entity-namespaces' => [ 'foo' => 123 ] ] )
		) );

		$this->assertEquals(
			[
				'' => 'http://acme.test/concept/',
				'other' => 'http://other.wiki/concept/',
			],
			$wikibaseRepo->getConceptBaseUris()
		);
	}

	public function testGetConceptBaseUris_entitySourceBasedFederation() {
		$settings = new SettingsArray( WikibaseRepo::getDefaultInstance()->getSettings()->getArrayCopy() );
		$settings->setSetting( 'useEntitySourceBasedFederation', true );

		$entityTypeDefinitions = $this->getEntityTypeDefinitions();
		$irrelevantRepositoryDefinition = [ '' => [
			'database' => null,
			'base-uri' => null,
			'prefix-mapping' => [ '' => '' ],
			'entity-namespaces' => [],
		] ];

		$wikibaseRepo = new WikibaseRepo(
			$settings,
			new DataTypeDefinitions( [] ),
			$entityTypeDefinitions,
			new RepositoryDefinitions(
				$irrelevantRepositoryDefinition,
				$entityTypeDefinitions
			),
			new EntitySourceDefinitions( [
				new EntitySource(
					'local',
					false,
					[
						'foo' => [ 'namespaceId' => 200, 'slot' => 'main' ],
						'bar' => [ 'namespaceId' => 220, 'slot' => 'main' ],
					],
					'http://local.wiki/entity/',
					'',
					'',
					''
				),
				new EntitySource(
					'bazwiki',
					'bazdb',
					[
						'baz' => [ 'namespaceId' => 250, 'slot' => 'main' ],
					],
					'http://baz.wiki/entity/',
					'baz',
					'baz',
					'bazwiki'
				)
			], $entityTypeDefinitions )
		);

		$this->assertEquals(
			[ 'local' => 'http://local.wiki/entity/', 'bazwiki' => 'http://baz.wiki/entity/' ],
			$wikibaseRepo->getConceptBaseUris()
		);
	}

	public function testParameterLessFunctionCalls() {
		// Make sure (as good as we can) that all functions can be called without
		// exceptions/ fatals and nothing accesses the database or does http requests.
		$wbRepo = $this->getWikibaseRepo();

		$reflectionClass = new ReflectionClass( $wbRepo );
		$publicMethods = $reflectionClass->getMethods( ReflectionMethod::IS_PUBLIC );

		foreach ( $publicMethods as $publicMethod ) {
			if ( $publicMethod->getNumberOfRequiredParameters() === 0 ) {
				$publicMethod->invoke( $wbRepo );
			}
		}
	}

	private function getWikibaseRepoWithCustomSettings( SettingsArray $settings ) {
		return new WikibaseRepo(
			$settings,
			new DataTypeDefinitions( [] ),
			new EntityTypeDefinitions( [] ),
			$this->getRepositoryDefinitions(),
			$this->getEntitySourceDefinitions( 'local' )
		);
	}

	private function getSettingsCopyWithSettingSet( $settingName, $settingValue ) {
		$settings = new SettingsArray( WikibaseRepo::getDefaultInstance()->getSettings()->getArrayCopy() );
		$settings->setSetting( $settingName, $settingValue );
		return $settings;
	}

	public function provideTestGetPropertyTermStoreWriters() {
		yield [ $this->getSettingsCopyWithSettingSet( 'tmpPropertyTermsMigrationStage', MIGRATION_OLD ), 1, 0 ];
		yield [ $this->getSettingsCopyWithSettingSet( 'tmpPropertyTermsMigrationStage', MIGRATION_WRITE_BOTH ), 1, 1 ];
		yield [ $this->getSettingsCopyWithSettingSet( 'tmpPropertyTermsMigrationStage', MIGRATION_WRITE_NEW ), 1, 1 ];
		yield [ $this->getSettingsCopyWithSettingSet( 'tmpPropertyTermsMigrationStage', MIGRATION_NEW ), 0, 1 ];
	}

	/**
	 * @dataProvider provideTestGetPropertyTermStoreWriters
	 */
	public function testGetPropertyTermStoreWriters( $settings, $oldCount, $newCount ) {
		$repo = $this->getWikibaseRepoWithCustomSettings( $settings );

		$writers = $repo->getPropertyTermStoreWriters();

		$this->assertCount( $oldCount + $newCount, $writers );
		$this->assertEquals( $oldCount, array_key_exists( 'old', $writers ) );
		$this->assertEquals( $newCount, array_key_exists( 'new', $writers ) );
	}

	public function provideTestGetItemTermStoreWriters() {
		yield 'Everything Old' => [ $this->getSettingsCopyWithSettingSet( 'tmpItemTermsMigrationStages', [ 'max' => MIGRATION_OLD ] ), 1, 0 ];
		yield 'Everything New' => [ $this->getSettingsCopyWithSettingSet( 'tmpItemTermsMigrationStages', [ 'max' => MIGRATION_NEW ] ), 0, 1 ];
		yield 'New and Old 1' => [
			$this->getSettingsCopyWithSettingSet( 'tmpItemTermsMigrationStages', [
				'100' => MIGRATION_NEW,
				'max' => MIGRATION_OLD,
			] ),
			1,
			1,
		];
		yield 'New and Old 2' => [
			$this->getSettingsCopyWithSettingSet( 'tmpItemTermsMigrationStages', [
				'100' => MIGRATION_NEW,
				'max' => MIGRATION_WRITE_BOTH,
			] ),
			1,
			1,
		];
		yield 'New and Old 3' => [
			$this->getSettingsCopyWithSettingSet( 'tmpItemTermsMigrationStages', [
				'100' => MIGRATION_NEW,
				'max' => MIGRATION_WRITE_NEW,
			] ),
			1,
			1,
		];
		yield 'New and Old 4' => [
			$this->getSettingsCopyWithSettingSet( 'tmpItemTermsMigrationStages', [
				'100' => MIGRATION_WRITE_BOTH,
				'max' => MIGRATION_WRITE_NEW,
			] ),
			1,
			1,
		];
	}

	/**
	 * This test doesnt check the per item configuration and due to the wrapped services doing that would be a bit evil.
	 * This only tests that we get the correct resulting writer objects.
	 * For testing of the other logic see testGetItemTermStoreArrayForWriters
	 *
	 * @dataProvider provideTestGetItemTermStoreWriters
	 */
	public function testGetItemTermStoreWriters( $settings, $oldCount, $newCount ) {
			$repo = $this->getWikibaseRepoWithCustomSettings( $settings );

			$writers = $repo->getItemTermStoreWriters();

			$this->assertCount( $oldCount + $newCount, $writers );
			$this->assertEquals( $oldCount, array_key_exists( 'old', $writers ) );
			$this->assertEquals( $newCount, array_key_exists( 'new', $writers ) );
	}

	public function provideTestGetItemTermStoreArrayForWriters() {
		yield 'Everything Old' => [
			[ 'max' => MIGRATION_OLD ],
			[ 'old' => [ Int32EntityId::MAX => 'old' ], 'new' => [] ]
		];
		yield 'Everything New' => [
			[ 'max' => MIGRATION_NEW ],
			[ 'old' => [], 'new' => [ Int32EntityId::MAX => 'new' ] ]
		];
		yield 'Two stages' => [
			[
			'100' => MIGRATION_NEW,
			'max' => MIGRATION_OLD,
			],
			[ 'old' => [ Int32EntityId::MAX => 'old' ], 'new' => [ '100' => 'new' ] ]
		];
		yield 'Four stages' => [
			[
			'100' => MIGRATION_NEW,
			'1000' => MIGRATION_WRITE_NEW,
			'10000' => MIGRATION_WRITE_BOTH,
			'max' => MIGRATION_OLD,
			],
			[
				'old' => [ '1000' => 'old', '10000' => 'old', Int32EntityId::MAX => 'old' ],
				'new' => [ '100' => 'new', '1000' => 'new', '10000' => 'new' ]
			]
		];
	}

	/**
	 * This test doesnt check the per item configuration and due to the wrapped services doing that would be a bit evil.
	 * This only tests that we get the correct resulting writer objects.
	 * For testing of the other logic see testGetItemTermStoreArrayForWriters
	 *
	 * @group Addshore
	 *
	 * @dataProvider provideTestGetItemTermStoreArrayForWriters
	 */
	public function testGetItemTermStoreArrayForWriters( $stages, $expected ) {
		$currentSettings = new SettingsArray( WikibaseRepo::getDefaultInstance()->getSettings()->getArrayCopy() );
		$repo = $this->getWikibaseRepoWithCustomSettings( $currentSettings );

		$array = $repo->getItemTermStoreArrayForWriters( $stages, 'old', 'new' );

		$this->assertEquals( $expected, $array );
	}

}
