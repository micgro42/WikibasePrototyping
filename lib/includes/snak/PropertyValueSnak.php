<?php

namespace Wikibase;
use DataValues\DataValue;

/**
 * Class representing a property value snak.
 * See https://meta.wikimedia.org/wiki/Wikidata/Data_model#PropertyValueSnak
 *
 * @since 0.1
 *
 * @file
 * @ingroup Wikibase
 *
 * @licence GNU GPL v2+
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 */
class PropertyValueSnak extends PropertySnakObject {

	/**
	 * @since 0.1
	 *
	 * @var DataValue
	 */
	protected $dataValue;

	/**
	 * Constructor.
	 *
	 * @since 0.1
	 *
	 * @param integer $propertyId
	 * @param DataValue $dataValue
	 */
	public function __construct( $propertyId, DataValue $dataValue ) {
		parent::__construct( $propertyId );
		$this->dataValue = $dataValue;
	}

	/**
	 * Returns the value of the property value snak.
	 *
	 * @since 0.1
	 *
	 * @return DataValue
	 */
	public function getDataValue() {
		return $this->dataValue;
	}

	/**
	 * @see Serializable::serialize
	 *
	 * @since 0.1
	 *
	 * @return string
	 */
	public function serialize() {
		return serialize( array( $this->propertyId, $this->dataValue ) );
	}

	/**
	 * @see Serializable::unserialize
	 *
	 * @since 0.1
	 *
	 * @param string $serialized
	 *
	 * @return PropertyValueSnak
	 */
	public function unserialize( $serialized ) {
		list( $this->propertyId, $this->dataValue ) = unserialize( $serialized );
	}

	/**
	 * @see Snak::getType
	 *
	 * @since 0.2
	 *
	 * @return string
	 */
	public function getType() {
		return 'value';
	}

}