<?php
/**
 * Code hygience test.
 *
 * @file
 * @author Niklas Laxström
 * @copyright Copyright © 2012-2013, Niklas Laxström
 * @license GPL-2.0+
 */

class ResourcesTest extends MediaWikiTestCase {
	public function testAlphabeticalOrder() {
		require __DIR__ . '/../../Resources.php';

		$sorted = $wgResourceModules;
		ksort( $sorted );

		$this->assertEquals(
			array_keys( $sorted ),
			array_keys( $wgResourceModules ),
			"Modules are defined in alphabetical order."
		);
	}
}
