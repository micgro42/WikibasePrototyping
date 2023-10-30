<?php

namespace Wikibase\DataModel\Entity;

/**
 * Something that looks on the outside like a Wikibase Entity but is not implemented as one.
 * Sometimes it behaves like a Wikibase Entity, but it for example cannot be looked up like one.
 *
 * @license GPL-2.0-or-later
 */
interface PseudoEntityId extends IndeterminateEntityId {
}
