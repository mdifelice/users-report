#!/usr/bin/php
<?php
require_once __DIR__ . '/inc/cli.php';

function get_users( $endpoint, $username, $password ) {
	$users_per_page = 100;
	$offset         = 0;
	$users          = array();

	do {
		$request = xmlrpc_encode_request( 'wp.getUsers', array(
			1,
			$username,
			$password,
			array(
				'number' => $users_per_page,
				'offset' => $offset,
			)
		) );

		$context = stream_context_create(
			array(
				'http' => array(
					'method'     => 'POST',
					'header'     => 'Content-Type: text/xml'. "\r\n",
					'content'    => $request,
					'user_agent' => 'XML-RPC client',
				)
			)
		);

		$contents = file_get_contents( sprintf( '%s/xmlrpc.php', $endpoint ), false, $context );

		if ( false === $contents ) {
			throw new Exception( sprintf( 'Cannot connect to %s', $endpoint ) );
		}

		$page_users = xmlrpc_decode( $contents );

		if ( null === $page_users ) {
			throw new Exception( sprintf( 'Cannot decode response from %s', $endpoint ) );
	}

		if ( xmlrpc_is_fault( $page_users ) ) {
			throw new Exception( 'Invalid response' );
		}

		if ( count( $page_users ) === $users_per_page ) {
			$continue = true;
			$offset   += $users_per_page;
		} else {
			$continue = false;
		}

		$users += $page_users;
	} while ( $continue );

	return $users;
}

$arguments = parse_arguments( array(
	'input-file' => array(
	),
	'input-format' => array(
		'default' => 'csv',
	),
	'credentials-file' => array(
	),
	'credentials-format' => array(
		'default' => 'csv',
	),
	'output-file' => array(
		'required' => true,
	),
	'output-format' => array(
		'default' => 'csv',
	),
) );

try {
	set_time_limit( 0 );

	$input_file       = $arguments['input-file'];
	$credentials_file = $arguments['credentials-file'];
	$output_file      = $arguments['output-file'];
	$users            = array();
	$sites            = array();
	$credentials      = array();

	if ( ! empty( $input_file ) ) {
		switch ( $arguments['input-format'] ) {
			case 'csv':
				$fp = fopen( $input_file, 'r' );

				if ( false === $fp ) {
					throw new Exception( sprintf( 'Cannot open input file %s', $input_file ) );
				}

				while ( false !== ( $row = fgetcsv( $fp ) ) ) {
					$url      = $row[0] ?: null;
					$platform = $row[1] ?: null;
					$staging  = $row[2] ?: null;

					if ( $url ) {
						$sites[] = array(
							'url'      => $url,
							'platform' => $platform,
							'staging'  => $staging,
						);
					}
				}

				fclose( $fp );
				break;
		}
	} else {
		do {
			$url = prompt( 'Enter the website URL: ' );
		} while ( empty( $url ) );

		$sites[] = array(
			'url' => $url,
		);
	}

	if ( ! empty( $credentials_file ) ) {
		switch ( $arguments['credentials-format'] ) {
			case 'csv':
				$fp = fopen( $credentials_file, 'r' );

				if ( false === $fp ) {
					throw new Exception( sprintf( 'Cannot open credentials file %s', $credentials_file ) );
				}

				while ( false !== ( $row = fgetcsv( $fp ) ) ) {
					$key      = $row[0] ?: null;
					$username = $row[1] ?: null;
					$password = $row[2] ?: null;

					if ( $key ) {
						$credentials[ $key ] = array(
							$username,
							$password,
						);
					}
				}

				fclose( $fp );
				break;
		}
	}

	foreach ( $sites as $site ) {
		$url = $site['url'];

		print_message( sprintf( 'Retrieving users for site %s', $url ) );

		$credentials_key = $site['platform'] ?: $url;

		if ( ! isset( $credentials[ $credentials_key ] ) ) {
			$username = prompt( sprintf( 'Enter username for %s: ', $credentials_key ) );
			$password = prompt( sprintf( 'Enter password for %s: ', $credentials_key ), '*' );

			$credentials[ $credentials_key ] = array(
				$username,
				$password,
			);
		} else {
			list( $username, $password ) = $credentials[ $credentials_key ];
		}

		$endpoint = $site['staging'] ?: $url;

		$site_users = get_users( $endpoint, $username, $password );

		foreach ( $site_users as $site_user ) {
			$email = $site_user['email'];

			if ( $email ) {
				$users[] = array(
					$url,
					$email,
					implode( ',', $site_user['roles'] ),
				);
			}
		}
	}

	if ( empty( $users ) ) {
		print_error( 'No sites found' );
	} else {
		print_message( sprintf( 'Generating output in file %s...', $output_file ) );

		switch ( $arguments['output-format'] ) {
			case 'csv':
				$fp = fopen( $output_file, 'w' );

				if ( false === $fp ) {
					throw new Exception( 'Cannot open file %s for output', $output_file );
				}

				foreach ( $users as $user ) {
					if ( false === fputcsv( $fp, $user ) ) {
						throw new Exception( 'Cannot write in file %s', $output_file );
					}
				}

				fclose( $fp );
				break;
		}
	}

	print_message( 'Finished' );

	$status = 0;
} catch ( Exception $e ) {
	print_error( $e->getMessage() );

	$status = 1;
}

return $status;
