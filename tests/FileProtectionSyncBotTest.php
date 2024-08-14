<?php

use Krinkle\FileProtectionSync\FileProtectionSyncBot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( FileProtectionSyncBot::class )]
class FileProtectionSyncBotTest extends TestCase {

	public function testBuild() {
		$wiki = [
			'sourcewiki' => 'en.test',
			'sourcepages' => [ 'My Tomato', 'Wikipedia:Your/potato' ],
		];
		$images = [ 'Example.jpg', 'Videoonwikipedia.ogv' ];
		$languages = [ [ 'code' => 'en' ], [ 'code' => 'nl' ] ];

		$bot = new FileProtectionSyncBot( '', '', '', [], false );
		$preamble = $bot->buildWikitextPreamble( $wiki );
		$text = $bot->buildGalleryPage( $preamble, $images, $languages );
		$this->assertEquals( <<<TEXT
			{{Auto-protected files gallery}}
			Files found on the following pages are automatically protected here:
			* [https://en.test/wiki/My_Tomato My Tomato]
			* [https://en.test/wiki/Wikipedia:Your/potato Wikipedia:Your/potato]

			<gallery widths="80" heights="80">
			File:Example.jpg
			File:Videoonwikipedia.ogv
			</gallery>

			<div class="mw-collapsible mw-collapsed">
			<div style="font-weight:bold;line-height:1.6;">Subtitles</div>
			<div class="mw-collapsible-content">
			{{TimedText:Videoonwikipedia.ogv.en.srt}}
			{{TimedText:Videoonwikipedia.ogv.nl.srt}}
			</div></div>

			TEXT,
			$text
		);
	}

	public function testExecute() {
		// Input
		$config = [
			'editsummary' => 'Hello world',
			'wikis' => [
				[
					'sourcewiki' => 'en.test',
					'sourcepages' => [ 'My Tomato', 'Wikipedia:Your/potato' ],
					'targetpage' => 'Project:Fileprotectionsync/en',
				],
				[
					'sourcewiki' => 'de.test',
					'sourcepages' => [ 'Meine Tomate' ],
					'targetpage' => 'Project:Fileprotectionsync/de',
				],
			],
		];
		$mockApi = static function ( $apipath, $params ) {
			$route = "action={$params['action']}"
				. ( @$params['meta'] ? "&meta={$params['meta']}" : '' )
				. ( @$params['prop'] ? "&prop={$params['prop']}" : '' );
			return match ( $apipath ) {
				'https://commons.test/api.php' => match ( $route ) {
					'action=query&meta=siteinfo' => [
						'query' => [
							'languages' => [
								[ 'code' => 'en' ],
								[ 'code' => 'nl' ],
							]
						]
					],
				},
				'https://en.test/w/api.php' => match ( $route ) {
					'action=query&prop=images' => [
						'query' => [
							'pages' => [
								[
									'images' => [
										[ 'ns' => 6, 'title' => 'File:Example.jpg' ],
										[ 'ns' => 6, 'title' => 'File:Foo.png' ],
									],
								],
								[
									'images' => [
										[ 'ns' => 6, 'title' => 'File:Bar.png' ],
										[ 'ns' => 6, 'title' => 'File:Example.jpg' ],
									],
								],
							],
						],
					],
				},
				'https://de.test/w/api.php' => match ( $route ) {
					'action=query&prop=images' => [
						'query' => [
							'pages' => [
								[
									'images' => [
										[ 'ns' => 6, 'title' => 'File:Foo.png' ],
										[ 'ns' => 6, 'title' => 'File:Bar.png' ],
									],
								],
								[
									'images' => [
										[ 'ns' => 6, 'title' => 'File:Foo.png' ],
										[ 'ns' => 6, 'title' => 'File:Quux.png' ],
									],
								],
							],
						]
					],
				},
			};
		};
		$mockNocLogos = [ 'Aa a.svg', 'Bb b.svg' ];

		$expectedEdits = [
			[
				'Project:Fileprotectionsync/en',
				<<<TEXT
				{{Auto-protected files gallery}}
				Files found on the following pages are automatically protected here:
				* [https://en.test/wiki/My_Tomato My Tomato]
				* [https://en.test/wiki/Wikipedia:Your/potato Wikipedia:Your/potato]

				<gallery widths="80" heights="80">
				File:Bar.png
				File:Example.jpg
				File:Foo.png
				</gallery>

				TEXT,
				'Hello world'
			],
			[
				'Project:Fileprotectionsync/de',
				<<<TEXT
				{{Auto-protected files gallery}}
				Files found on the following pages are automatically protected here:
				* [https://de.test/wiki/Meine_Tomate Meine Tomate]

				<gallery widths="80" heights="80">
				File:Bar.png
				File:Foo.png
				File:Quux.png
				</gallery>

				TEXT,
				'Hello world'
			],
			[
				'Project:Auto-protected files/misc/logos',
				<<<TEXT
				{{Auto-protected files gallery}}
				Files found in [https://noc.wikimedia.org/conf/highlight.php?file=logos/config.yaml logos/config.yaml] are automatically protected here.

				<gallery widths="80" heights="80">
				File:Aa a.svg
				File:Bb b.svg
				</gallery>

				TEXT,
				'Hello world'
			],
		];

		$bot = $this->getMockBuilder( FileProtectionSyncBot::class )
			->setConstructorArgs(
				[ 'https://commons.test/api.php', '', '', $config ]
			)
			->onlyMethods( [ 'edit', 'apiRequest', 'getLogos' ] )
			->getMock();
		$bot->method( 'apiRequest' )->willReturnCallback( $mockApi );
		$bot->method( 'getLogos' )->willReturn( $mockNocLogos );
		$edits = [];
		$bot->method( 'edit' )
			->willReturnCallback( static function ( $title, $text, $summary ) use ( &$edits ) {
				// withConsecutive() was removed in PHPUnit 10
				$edits[] = [ $title, $text, $summary ];
			} );

		$bot->execute();
		$this->assertSame( $expectedEdits, $edits, 'edits' );
	}
}
