<?php
/**
 * Rebuild languages/igbz-suite.pot from the plugin source.
 *
 * wp-cli is not installable in this sandbox (no Composer, no network to wordpress.org), so the
 * template is extracted here with the tokenizer instead. The output format matches the file that
 * shipped before, byte for byte, so a rebuild produces a readable diff rather than a rewrite:
 *
 *   - only `src/` is scanned (tests and the bootstrap carry no translatable strings);
 *   - entries are sorted by msgid, references by path, one `#: path:line` per line;
 *   - nothing is wrapped, no msgctxt, no translator comments;
 *   - only literal strings are collected — a call built from a variable or a concatenation is
 *     skipped exactly as wp-cli would skip it.
 *
 * Usage:  bash _devenv/makepot.sh            (writes igbz-suite/languages/igbz-suite.pot)
 *         bash _devenv/makepot.sh --check    (writes nothing; exits 1 when the file is stale)
 *         node .work/node_modules/@php-wasm/cli/main.js _devenv/makepot.php <plugin-dir> [out] [--check]
 */

$plugin = rtrim( (string) ( $argv[1] ?? '' ), '/' );
$out    = (string) ( $argv[2] ?? '' );
$check  = in_array( '--check', $argv, true );

if ( $plugin === '' || ! is_dir( $plugin . '/src' ) ) {
	fwrite( STDERR, "usage: makepot.php <plugin-dir> [out.pot] [--check]\n" );
	exit( 2 );
}
if ( $out === '' || $out === '--check' ) {
	$out = $plugin . '/languages/igbz-suite.pot';
}

const DOMAIN = 'igbz-suite';

/** Gettext functions used in this plugin: name => [text arg index, plural arg index|null, domain arg index]. */
const FUNCTIONS = array(
	'__'          => array( 0, null, 1 ),
	'_e'          => array( 0, null, 1 ),
	'esc_html__'  => array( 0, null, 1 ),
	'esc_html_e'  => array( 0, null, 1 ),
	'esc_attr__'  => array( 0, null, 1 ),
	'esc_attr_e'  => array( 0, null, 1 ),
	'_n'          => array( 0, 1, 3 ),
);

/**
 * Decode a PHP literal string token into its runtime value.
 */
function decode_literal( string $token ): string {
	$quote = $token[0];
	$body  = substr( $token, 1, -1 );

	if ( $quote === "'" ) {
		return strtr( $body, array( "\\'" => "'", '\\\\' => '\\' ) );
	}

	// Double-quoted: the tokenizer guarantees there is no interpolation in this token type.
	return preg_replace_callback(
		'/\\\\(n|t|r|v|e|f|\\\\|\\$|"|[0-7]{1,3}|x[0-9A-Fa-f]{1,2}|u\{[0-9A-Fa-f]+\})/',
		static function ( array $m ): string {
			switch ( $m[1] ) {
				case 'n':  return "\n";
				case 't':  return "\t";
				case 'r':  return "\r";
				case 'v':  return "\v";
				case 'e':  return "\033";
				case 'f':  return "\f";
				case '\\': return '\\';
				case '$':  return '$';
				case '"':  return '"';
			}
			if ( $m[1][0] === 'x' ) {
				return chr( hexdec( substr( $m[1], 1 ) ) );
			}
			if ( $m[1][0] === 'u' ) {
				return mb_chr( (int) hexdec( trim( substr( $m[1], 1 ), '{}' ) ), 'UTF-8' );
			}
			return chr( octdec( $m[1] ) );
		},
		$body
	);
}

/**
 * Escape a runtime string for a .po/.pot msgid.
 */
function pot_escape( string $value ): string {
	return strtr(
		$value,
		array(
			'\\'   => '\\\\',
			'"'    => '\\"',
			"\n"   => '\\n',
			"\t"   => '\\t',
			"\r"   => '\\r',
		)
	);
}

/**
 * Collect every gettext call in one file.
 *
 * @return array<int, array{name:string, args:array<int, string|null>, line:int}>
 */
function calls_in( string $source ): array {
	$tokens = token_get_all( $source );
	$found  = array();
	$count  = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		if ( ! is_array( $token ) || $token[0] !== T_STRING || ! isset( FUNCTIONS[ $token[1] ] ) ) {
			continue;
		}

		// A method call or a declaration is not the global gettext function.
		$before = null;
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$before = $tokens[ $j ];
			break;
		}
		if ( is_array( $before ) && in_array( $before[0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ), true ) ) {
			continue;
		}

		// Next meaningful token must open the argument list.
		$k = $i + 1;
		while ( $k < $count && is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			$k++;
		}
		if ( $k >= $count || $tokens[ $k ] !== '(' ) {
			continue;
		}

		// Walk the argument list at depth 1. An argument that is not a single literal string is
		// recorded as null, which disqualifies the call when it lands on the text or the domain.
		$args    = array();
		$current = array();
		$depth   = 0;

		for ( $k = $k; $k < $count; $k++ ) {
			$arg = $tokens[ $k ];

			if ( $arg === '(' ) {
				$depth++;
				if ( $depth === 1 ) {
					continue;
				}
			} elseif ( $arg === ')' ) {
				$depth--;
				if ( $depth === 0 ) {
					$args[] = $current;
					break;
				}
			} elseif ( $arg === ',' && $depth === 1 ) {
				$args[]  = $current;
				$current = array();
				continue;
			}

			if ( is_array( $arg ) && in_array( $arg[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$current[] = $arg;
		}

		$values = array();
		foreach ( $args as $arg ) {
			$values[] = ( count( $arg ) === 1 && is_array( $arg[0] ) && $arg[0][0] === T_CONSTANT_ENCAPSED_STRING )
				? decode_literal( $arg[0][1] )
				: null;
		}

		$found[] = array(
			'name' => $token[1],
			'args' => $values,
			'line' => $token[2],
		);
	}

	return $found;
}

$files = array();
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	if ( $file->getExtension() === 'php' ) {
		$files[] = $file->getPathname();
	}
}
sort( $files, SORT_STRING );

$entries = array();   // key => [ 'msgid' => .., 'plural' => ..|null, 'refs' => [..] ]
$skipped = 0;

foreach ( $files as $file ) {
	$relative = ltrim( substr( $file, strlen( $plugin ) ), '/' );
	foreach ( calls_in( (string) file_get_contents( $file ) ) as $call ) {
		list( $text_at, $plural_at, $domain_at ) = FUNCTIONS[ $call['name'] ];

		$text   = $call['args'][ $text_at ] ?? null;
		$domain = $call['args'][ $domain_at ] ?? null;
		$plural = $plural_at === null ? null : ( $call['args'][ $plural_at ] ?? null );

		if ( $domain !== DOMAIN ) {
			continue;
		}
		if ( $text === null || ( $plural_at !== null && $plural === null ) ) {
			$skipped++;
			fwrite( STDERR, sprintf( "  skipped non-literal %s() at %s:%d\n", $call['name'], $relative, $call['line'] ) );
			continue;
		}

		$key = $text . "\0" . (string) $plural;
		if ( ! isset( $entries[ $key ] ) ) {
			$entries[ $key ] = array(
				'msgid'  => $text,
				'plural' => $plural,
				'refs'   => array(),
			);
		}
		$entries[ $key ]['refs'][] = $relative . ':' . $call['line'];
	}
}

uasort(
	$entries,
	static function ( array $a, array $b ): int {
		return strcmp( $a['msgid'], $b['msgid'] ) ?: strcmp( (string) $a['plural'], (string) $b['plural'] );
	}
);

$stamp = gmdate( 'Y-m-d H:iO' );
$pot   = "# Copyright (C) IGBZ\n"
	. "# This file is distributed under the GPL-2.0-or-later license.\n"
	. "msgid \"\"\n"
	. "msgstr \"\"\n"
	. "\"Project-Id-Version: IGBZ Suite 1.0.0\\n\"\n"
	. "\"Report-Msgid-Bugs-To: https://github.com/paymanshafayan/IGBZ-WP/issues\\n\"\n"
	. "\"POT-Creation-Date: $stamp\\n\"\n"
	. "\"MIME-Version: 1.0\\n\"\n"
	. "\"Content-Type: text/plain; charset=UTF-8\\n\"\n"
	. "\"Content-Transfer-Encoding: 8bit\\n\"\n"
	. "\"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n\"\n"
	. "\"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n\"\n"
	. "\"Language-Team: LANGUAGE <LL@li.org>\\n\"\n"
	. "\"Plural-Forms: nplurals=2; plural=(n != 1);\\n\"\n"
	. "\"X-Domain: igbz-suite\\n\"\n";

foreach ( $entries as $entry ) {
	$refs = array_values( array_unique( $entry['refs'] ) );
	sort( $refs, SORT_STRING );

	$pot .= "\n";
	foreach ( $refs as $ref ) {
		$pot .= "#: $ref\n";
	}
	$pot .= 'msgid "' . pot_escape( $entry['msgid'] ) . "\"\n";
	if ( $entry['plural'] !== null ) {
		$pot .= 'msgid_plural "' . pot_escape( $entry['plural'] ) . "\"\n";
		$pot .= "msgstr[0] \"\"\n";
		$pot .= "msgstr[1] \"\"\n";
	} else {
		$pot .= "msgstr \"\"\n";
	}
}

// The creation stamp is the one line that changes on every run; ignore it when comparing.
$strip_stamp = static function ( string $text ): string {
	return preg_replace( '/^"POT-Creation-Date: .*\\\\n"$/m', '"POT-Creation-Date: \\n"', $text );
};

$existing = is_file( $out ) ? (string) file_get_contents( $out ) : '';
$changed  = $strip_stamp( $existing ) !== $strip_stamp( $pot );

printf(
	"%d strings (%d plural) from %d files, %d references%s\n",
	count( $entries ),
	count( array_filter( $entries, static fn( array $e ): bool => $e['plural'] !== null ) ),
	count( $files ),
	array_sum( array_map( static fn( array $e ): int => count( array_unique( $e['refs'] ) ), $entries ) ),
	$skipped > 0 ? sprintf( ', %d non-literal call(s) skipped', $skipped ) : ''
);

if ( $check ) {
	echo $changed ? "pot is stale — run: bash _devenv/makepot.sh\n" : "pot is up to date\n";
	exit( $changed ? 1 : 0 );
}

if ( ! $changed ) {
	echo "pot is already up to date, left untouched\n";
	exit( 0 );
}

if ( ! is_dir( dirname( $out ) ) ) {
	mkdir( dirname( $out ), 0777, true );
}
file_put_contents( $out, $pot );
printf( "wrote %s\n", $out );
