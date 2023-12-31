<?php

declare( strict_types = 1 );
namespace Wikibase\Client\Hooks;

use MediaWiki\Hook\LoginFormValidErrorMessagesHook;

/**
 * @license GPL-2.0-or-later
 */
class LoginFormValidErrorMessagesHandler implements LoginFormValidErrorMessagesHook {

	public function onLoginFormValidErrorMessages( array &$messages ): void {
		$messages[] = 'wikibase-client-data-bridge-login-warning';
	}

}
