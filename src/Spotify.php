<?php
namespace SpotifyLyricsApi;

require_once __DIR__ . '/../vendor/autoload.php';

use Exception;
use OTPHP\TOTP;
use ParagonIE\ConstantTime\Encoding;

/**
* Class Spotify
*
* This class is responsible for interacting with the Spotify API.
*/

class Spotify
 {
    private $token_url = 'https://open.spotify.com/get_access_token';
    private $lyrics_url = 'https://spclient.wg.spotify.com/color-lyrics/v2/track/';
    private $server_time_url = 'https://open.spotify.com/server-time';
    private $sp_dc;
    private $cache_file;

    /**
    * Spotify constructor.
    *
    * @param string $sp_dc The Spotify Data Controller ( sp_dc ) cookie value.
    */
    function __construct( $sp_dc )
    {
        $this->cache_file = sys_get_temp_dir() . '/spotify_token.json';
        $this->sp_dc = $sp_dc;
    }

    /**
    * Cleans a hexadecimal string by removing invalid characters and ensuring even length.
    *
    * @param string $hex_str The hexadecimal string to be cleaned.
    * @return string The cleaned hexadecimal string.
    */
    function clean_hex( $hex_str ) {
        $valid_chars = '0123456789abcdefABCDEF';
        $cleaned = preg_replace( "/[^$valid_chars]/", '', $hex_str );
        if ( strlen( $cleaned ) % 2 != 0 ) {
            $cleaned = substr( $cleaned, 0, -1 );
        }
        return $cleaned;
    }

    /**
    * Generates a Time-based One-Time Password ( TOTP ) using the server time.
    *
    * @param int $server_time_seconds The server time in seconds.
    * @return string The generated TOTP code.
    */
    function generate_totp( $server_time_seconds ) {
        $secret_cipher = array( 12, 56, 76, 33, 88, 44, 88, 33, 78, 78, 11, 66, 22, 22, 55, 69, 54 );
        $processed = array();
        foreach ( $secret_cipher as $i => $byte ) {
            $processed[] = $byte ^ ( $i % 33 + 9 );
        }
        $processed_str = implode( '', $processed );
        $utf8_bytes = mb_convert_encoding( $processed_str, 'UTF-8', 'ASCII' );
        $hex_str = bin2hex( $utf8_bytes );
        $cleaned_hex = $this -> clean_hex( $hex_str );
        $secret_bytes = hex2bin( $cleaned_hex );
        $secret_base32 = str_replace( '=', '', Encoding::base32EncodeUpper( $secret_bytes ) );
        $totp = TOTP::create(
            $secret_base32,
            30,
            'sha1',
            6
        );
        return $totp->at( intval( $server_time_seconds ) );
    }

    /**
    * Retrieves the server time and returns the parameters needed for the token request.
    *
    * @return array The parameters for the token request.
    * @throws SpotifyException If there is an error fetching the server time.
    */
    function getServerTimeParams(): array {
        try {
            $ch = curl_init();
            curl_setopt( $ch, CURLOPT_URL, $this->server_time_url );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
            $server_time_result = curl_exec( $ch );
            if ( $server_time_result === false ) {
                throw new SpotifyException( 'Failed to fetch server time: ' . curl_error( $ch ) );
            }
            $server_time_data = json_decode( $server_time_result, true );
            if ( !$server_time_data || !isset( $server_time_data[ 'serverTime' ] ) ) {
                throw new SpotifyException( 'Invalid server time response' );
            }
            $server_time_seconds = $server_time_data[ 'serverTime' ];

            $totp = $this->generate_totp( $server_time_seconds );

            $timestamp = time();
            $params = [
                'reason' => 'transport',
                'productType' => 'web-player',
                'totp' => $totp,
                'totpVer' => '5',
                'ts' => strval( $timestamp ),
            ];

            return $params;
        } catch ( Exception $e ) {
            throw new SpotifyException( $e->getMessage() );
        }
        finally {
            curl_close( $ch );
        }
    }

    /**
    * Retrieves an access token from Spotify and stores it in a file.
    * The file is stored in the temporary directory.
    *
    * @throws SpotifyException If there is an error during the token request.
    */
    function getToken(): void {
        if ( !$this->sp_dc ) {
            throw new SpotifyException( 'AQDbBA__VLhPJ08PFpiJpybYCPkMTUvk70dMEzMZx_eQZaCljidk-Tm5iIkzgIHqskCHvYvg71aP2cAi9Wyhos5k7pH98cYrpRmSDFj-bvv6ZUWpRxoz89zTJygE75IKaI9f7XJ58EXG3DEDiny-h9aGLjIWRC6F1HnZCROp_OqX_0nY850J1pt0RwRSa0ya3NAByjOJO__lqG_ewA' );
        }
        try {
            $params = $this->getServerTimeParams();
            $headers = [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Cookie: sp_dc=' . $this->sp_dc
            ];
            $ch = curl_init();
            curl_setopt( $ch, CURLOPT_URL, $this->token_url . '?' . http_build_query( $params ) );
            curl_setopt( $ch, CURLOPT_TIMEOUT, 600 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
            curl_setopt( $ch, CURLOPT_VERBOSE, 0 );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
            curl_setopt( $ch, CURLOPT_HEADER, 0 );
            curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
            curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'GET' );
            curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );

            $result = curl_exec( $ch );
            if ( $result === false ) {
                throw new SpotifyException( 'Token request failed: ' . curl_error( $ch ) );
            }
            $token_json = json_decode( $result, true );
            if ( !$token_json || ( isset( $token_json[ 'isAnonymous' ] ) && $token_json[ 'isAnonymous' ] ) ) {
                throw new SpotifyException( 'The SP_DC set seems to be invalid, please correct it!' );
            }
            $token_file = fopen( $this->cache_file, 'w' ) or die( 'Unable to open file!' );
            fwrite( $token_file, $result );
            fclose( $token_file );
        } catch ( Exception $e ) {
            throw new SpotifyException( $e->getMessage() );
        }
        finally {
            curl_close( $ch );
        }
    }

    /**
    * Checks if the access token is expired and retrieves a new one if it is.
    * The function invokes getToken if the token is expired or the cache file is not found.
    */
    function checkTokenExpire(): void
    {
        $check = file_exists( $this->cache_file );
        if ( $check ) {
            $json = file_get_contents( $this->cache_file );
            $timeleft = json_decode( $json, true )[ 'accessTokenExpirationTimestampMs' ];
            $timenow = round( microtime( true ) * 1000 );
        }
        if ( !$check || $timeleft < $timenow ) {
            $this->getToken();
        }
    }

    /**
    * Retrieves the lyrics of a track from the Spotify.
    * @param string $track_id The Spotify track id.
    * @return string The lyrics of the track in JSON format.
    */
    function getLyrics( $track_id ): string 
    {
        $json = file_get_contents( $this->cache_file );
        $token = json_decode( $json, true )[ 'accessToken' ];
        $formated_url = $this->lyrics_url . $track_id . '?format=json&market=from_token';
        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'GET' );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
            'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/101.0.0.0 Safari/537.36',
            'App-platform: WebPlayer',
            "authorization: Bearer $token"
        ) );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
        curl_setopt( $ch, CURLOPT_URL, $formated_url );
        $result = curl_exec( $ch );
        return $result;
    }

    /**
    * Retrieves the lyrics in LRC format.
    *
    * @param array $lyrics The lyrics data.
    * @return array The lyrics in LRC format.
    */

    function getLrcLyrics( $lyrics ): array 
    {
        $lrc = array();
        foreach ( $lyrics as $lines )
        {
            $lrctime = $this->formatMS( $lines[ 'startTimeMs' ] );
            array_push( $lrc, [ 'timeTag' => $lrctime, 'words' => $lines[ 'words' ] ] );
        }
        return $lrc;
    }

    /**
    * Retrieves the lyrics in SRT format.
    *
    * @param array $lyrics The lyrics data.
    * @return array The lyrics in SRT format.
    */

    function getSrtLyrics( $lyrics ): array 
    {
        $srt = array();
        for ( $i = 1; $i < count( $lyrics ); $i++ ) 
        {
            $srttime = $this->formatSRT( $lyrics[ $i-1 ][ 'startTimeMs' ] );
            $srtendtime = $this->formatSRT( $lyrics[ $i ][ 'startTimeMs' ] );
            array_push( $srt, [ 'index' => $i, 'startTime' => $srttime, 'endTime' => $srtendtime, 'words' => $lyrics[ $i-1 ][ 'words' ] ] );
        }
        return $srt;
    }

    /**
    * Helper fucntion for getLrcLyrics to change miliseconds to [ mm:ss.xx ]
    * @param int $milliseconds The time in miliseconds.
    * @return string The time in [ mm:ss.xx ] format.
    */

    function formatMS( $milliseconds ): string
    {
        $th_secs = intdiv( $milliseconds, 1000 );
        $lrc_timetag = sprintf( '%02d:%02d.%02d', intdiv( $th_secs, 60 ), $th_secs % 60, intdiv( ( $milliseconds % 1000 ), 10 ) );
        return $lrc_timetag;
    }

    /**
    * Helper function to format milliseconds to SRT time format ( hh:mm:ss, ms ).
    * @param int $milliseconds The time in milliseconds.
    * @return string The time in SRT format.
    */
    function formatSRT( $milliseconds ): string
    {
        $hours = intdiv( $milliseconds, 3600000 );
        $minutes = intdiv( $milliseconds % 3600000, 60000 );
        $seconds = intdiv( $milliseconds % 60000, 1000 );
        $milliseconds = $milliseconds % 1000;
        return sprintf( '%02d:%02d:%02d,%03d', $hours, $minutes, $seconds, $milliseconds );
    }
}
