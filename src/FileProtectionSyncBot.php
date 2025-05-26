<?php
namespace Krinkle\FileProtectionSync;

use JsonException;
use RuntimeException;
use SensitiveParameter;
use function yaml_parse;

class FileProtectionSyncBot {
	/* Never protect these files. */
	private const UNPROTECTED_FILES = [
		'War in Ukraine (2022) en.png',
		// https://commons.wikimedia.org/w/index.php?title=User_talk:Krinkle&diff=632356246&oldid=628973063
		'War in Ukraine 2022 - fr.svg',
	];

	private string $cookiejar;
	/** Username without any BotPasswords suffix, used by `assertuser` and `User-Agent`. */
	private string $userName;
	private string $userAgent;
	private bool $loggedIn = false;

	public static function newFromCli( array $argv ): static {
		$verbose = false;
		$authFile = __DIR__ . '/../auth.json';
		array_shift( $argv );
		while ( $argv ) {
			match ( array_shift( $argv ) ) {
				'--auth' => ( $authFile = array_shift( $argv ) ),
				'--verbose' => ( $verbose = true ),
			};
		}

		$auth = self::parseJSON( file_get_contents( $authFile ) );
		$config = self::parseJSON( file_get_contents( __DIR__ . '/../config.json' ) );

		return new static(
			apipath: $auth['apipath'],
			loginname: $auth['loginname'],
			password: $auth['password'],
			config: $config,
			quiet: false,
			verbose: $verbose,
		);
	}

	public function __construct(
		private string $apipath,
		private string $loginname,
		#[SensitiveParameter]
		private string $password,
		private array $config,
		private bool $quiet = true,
		private bool $verbose = false,
	) {
		$this->cookiejar = tempnam( sys_get_temp_dir(), 'fileprotsync_cookies' );
		$this->debug( "Cookiejar created {$this->cookiejar}" );

		// https://www.mediawiki.org/wiki/API:Etiquette
		$this->userName = preg_replace( '/@.*$/', '', $this->loginname );
		$this->userAgent = "FileProtectionSyncBot/2.0 [[User:{$this->userName}]] https://gerrit.wikimedia.org/g/labs/tools/fileprotectionsync";
		ini_set( 'user-agent', $this->userAgent );
	}

	public function execute(): void {
		$this->logInfo( 'Starting' );
		$languages = $this->apiRequest( $this->apipath, [ 'action' => 'query', 'meta' => 'siteinfo', 'siprop' => 'languages' ] )['query']['languages'];

		foreach ( $this->config['wikis'] as $wiki ) {
			$apipath = sprintf( 'https://%s/w/api.php', $wiki['sourcewiki'] );
			$data = $this->apiRequest( $apipath, [
				'action' => 'query',
				'prop' => 'images',
				'titles' => implode( '|', $wiki['sourcepages'] ),
				'imlimit' => 500,
				'redirects' => '1',
			] );
			$images = [];
			foreach ( $data['query']['pages'] as $page ) {
				foreach ( $page['images'] as $image ) {
					if ( $image['ns'] === 6 ) {
						// Extract file name (remove localised "File" namespace prefix)
						// "Datei:The_Example_(2006).jpg"
						$images[] = explode( ':', $image['title'], 2 )[1];
					}
				}
			}

			$preamble = $this->buildWikitextPreamble( $wiki );
			$text = $this->buildGalleryPage( $preamble, $images, $languages );
			try {
				$this->edit( $wiki['targetpage'], $text, $this->config['editsummary'] );
			} catch ( RuntimeException $e ) {
				// Several times a day, commons.wikimedia.org/w/api.php refuses to save an edit
				// because of "API Error: readonly: while the replica database servers catch up".
				// When this happens, sleep for a second, then try the next one. This way,
				// rather than 1 readony eror skipping everything until the next scheduled run,
				// we increase chances of at least a few happening in this round still.
				self::logError( "Skipping [[{$wiki['targetpage']}]] due to $e" );
				sleep( 1 );
			}
		}

		if ( !function_exists( 'yaml_parse' ) ) {
			// T361457
			return;
		}
		// Wiki logos (T273490)
		$logos = $this->getLogos();
		$preamble = "Files found in [https://noc.wikimedia.org/conf/highlight.php?file=logos/config.yaml logos/config.yaml] are automatically protected here.\n";
		$text = $this->buildGalleryPage( $preamble, $logos );
		$this->edit( 'Project:Auto-protected files/misc/logos', $text, $this->config['editsummary'] );
	}

	public function buildWikitextPreamble( array $wiki ): string {
		$text = "Files found on the following pages are automatically protected here:\n";
		foreach ( $wiki['sourcepages'] as $page ) {
			$text .= sprintf( "* [https://%s/wiki/%s %s]\n",
				$wiki['sourcewiki'],
				strtr( $page, [ ' ' => '_' ] ),
				$page
			);
		}
		return $text;
	}

	public function buildGalleryPage( string $preamble, array $images, array $languages = [] ): string {
		$text = "{{Auto-protected files gallery}}\n"
			. $preamble . "\n"
			. "<gallery widths=\"80\" heights=\"80\">\n";

		// Sort and remove duplicates
		$images = array_values( array_unique( $images ) );
		sort( $images );

		$timedMedia = [];
		foreach ( $images as $image ) {
			if ( in_array( $image, self::UNPROTECTED_FILES ) ) {
				continue;
			}
			$text .= "File:$image\n";
			$ext = pathinfo( $image, PATHINFO_EXTENSION );
			// https://commons.wikimedia.org/wiki/Commons:Project_scope/Allowable_file_types
			if ( in_array( $ext, [
				'ogv', 'webm', 'mpg', 'mpeg', 'ogg', 'oga',
				'mid', 'flac', 'mp3', 'opus', 'wav',
			] ) ) {
				$timedMedia[] = $image;
			}
		}
		$text .= "</gallery>\n";
		if ( $timedMedia ) {
			$text .= '
<div class="mw-collapsible mw-collapsed">
<div style="font-weight:bold;line-height:1.6;">Subtitles</div>
<div class="mw-collapsible-content">
';
			foreach ( $timedMedia as $name ) {
				// This blindly transcludes all 500+ possible translations.
				// If you encounter "API Error: contenttoobig: exceeds 20 kibibytes" locally,
				// set `$wgMaxArticleSize = 2048;` to override MediaWiki DevelopmentSettings.php
				foreach ( $languages as $language ) {
					$text .= sprintf( "{{TimedText:%s.%s.srt}}\n",
						$name,
						$language['code']
					);
				}
			}
			$text .= "</div></div>\n";
		}
		return $text;
	}

	protected function getLogos(): array {
		$url = 'https://noc.wikimedia.org/conf/logos-config.yaml';
		$this->logInfo( "Request $url" );
		$yaml = file_get_contents( $url );
		$data = yaml_parse( $yaml );
		$logos = [];
		$keys = [ 'commons', 'commons_wordmark', 'commons_tagline', 'commons_icon' ];
		foreach ( $data as $group => $sites ) {
			foreach ( $sites as $site => $info ) {
				if ( !$info ) {
					continue;
				}
				foreach ( $keys as $key ) {
					$logo = $info[$key] ?? null;
					if ( $logo !== null ) {
						// Strip "File:" prefix
						$logos[] = explode( ':', $logo, 2 )[1];
					}
				}
				foreach ( $info['variants'] ?? [] as $variant ) {
					foreach ( $keys as $key ) {
						$logo = $variant[$key] ?? null;
						if ( $logo !== null ) {
							$logos[] = explode( ':', $logo, 2 )[1];
						}
					}
				}
			}
		}
		return $logos;
	}

	protected function edit( string $page, string $text, string $summary = '' ): void {
		// https://www.mediawiki.org/wiki/API:Edit
		$this->login();

		$csrftoken = $this->apiAuthRequest( [ 'action' => 'query', 'meta' => 'tokens' ] )['query']['tokens']['csrftoken'];

		$this->apiAuthRequest( [
			'action' => 'edit',
			'token' => $csrftoken,
			'title' => $page,
			'text' => $text,
			'summary' => $summary,
			'minor' => '1',
		] );
	}

	protected function login(): void {
		if ( !$this->loggedIn ) {
			// Fetch login token
			$logintoken = $this->apiRequest( $this->apipath, [
				'action' => 'query',
				'meta' => 'tokens',
				'type' => 'login',
				'format' => 'json'
			] )['query']['tokens']['logintoken'];

			// Perform log in.
			$this->apiRequest( $this->apipath, [
				'action' => 'login',
				'lgname' => $this->loginname,
				'lgpassword' => $this->password,
				'lgtoken' => $logintoken
			] );
			$this->loggedIn = true;
		}
	}

	protected function apiAuthRequest( array $params ): array {
		return $this->apiRequest(
			$this->apipath,
			// https://www.mediawiki.org/wiki/API:Assert
			$params + [ 'assertuser' => $this->userName ]
		);
	}

	protected function apiRequest(
		string $apipath,
		#[SensitiveParameter]
		array $params
	): array {
		$params = [
			'format' => 'json',
			'formatversion' => '2',
			'errorformat' => 'plaintext',
		] + $params;
		$this->logInfo( "Request $apipath?.."
			. ( @$params['action'] ? "&action={$params['action']}" : '' )
			. ( @$params['list'] ? "&list={$params['list']}" : '' )
			. ( @$params['meta'] ? "&meta={$params['meta']}" : '' )
			. ( @$params['prop'] ? "&prop={$params['prop']}" : '' )
			. ( @$params['query'] ? "&query={$params['query']}" : '' )
			. ( @$params['action'] === 'edit' ? " [[{$params['title']}]]" : '' )
		);

		$ch = curl_init( $apipath );
		curl_setopt_array( $ch, [
			CURLOPT_USERAGENT => $this->userAgent,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => http_build_query( $params ),
			CURLOPT_COOKIEJAR => $this->cookiejar,
			CURLOPT_COOKIEFILE => $this->cookiejar,
		] );
		try {
			$resp = curl_exec( $ch );
			if ( $resp === false ) {
				throw new RuntimeException( curl_error( $ch ) );
			}
			$httpStatus = curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
			$this->debug( 'HTTP response status='
				. (string)$httpStatus
				. ' body=' . substr( $resp, 0, 50 )
			);
			if ( $httpStatus !== 200 ) {
				throw new RuntimeException( "Unexpected HTTP $httpStatus response" );
			}
			$data = self::parseJSON( $resp );
			foreach ( $data['warnings'] ?? [] as $entry ) {
				self::logError( "API Warning {$entry['code']}: {$entry['text']}" );
			}
			foreach ( $data['errors'] ?? [] as $entry ) {
				self::logError( "API Error {$entry['code']}: {$entry['text']}" );
			}
			$error = $data['errors'][0] ?? null;
			if ( $error !== null ) {
				throw new RuntimeException( "API Error: {$error['code']}: {$error['text']}" );
			}
			return $data;
		} finally {
			curl_close( $ch );
		}
	}

	protected static function parseJSON( string $str ): array {
		try {
			// @phan-suppress-next-line PhanTypeMismatchArgumentInternalProbablyReal - https://github.com/phan/phan/issues/4745
			return json_decode( $str, null, 512, JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			self::logError( 'Invalid JSON `' . substr( $str, 0, 100 ) . '`' );
			throw $e;
		}
	}

	protected static function logError( string $str ): void {
		fwrite( STDERR, sprintf( "[%s] ERROR: %s\n",
			date( 'c' ),
			$str
		) );
	}

	protected function logInfo( string $str ): void {
		if ( !$this->quiet ) {
			print sprintf( "[%s] %s\n",
				date( 'c' ),
				$str
			);
		}
	}

	protected function debug( string $str ): void {
		if ( $this->verbose ) {
			print sprintf( "[%s] DEBUG: %s\n",
				date( 'c' ),
				$str
			);
		}
	}

	public function __destruct() {
		$this->debug( "Cookiejar deleted {$this->cookiejar}" );
		unlink( $this->cookiejar );
	}
}
