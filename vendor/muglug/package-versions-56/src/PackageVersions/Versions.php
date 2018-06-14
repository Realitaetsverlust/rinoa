<?php

namespace Muglug\PackageVersions;

/**
 * This class is generated by muglug/package-versions-56, specifically by
 * @see \Muglug\PackageVersions\Installer
 *
 * This file is overwritten at every run of `composer install` or `composer update`.
 */
final class Versions
{
    public static $VERSIONS = array (
  'composer/xdebug-handler' => '1.1.0@c919dc6c62e221fc6406f861ea13433c0aa24f08',
  'muglug/package-versions-56' => '1.2.4@a67bed26deaaf9269a348e53063bc8d4dcc60ffd',
  'nikic/php-parser' => 'v4.0.2@35b8caf75e791ba1b2d24fec1552168d72692b12',
  'openlss/lib-array2xml' => '0.5.1@c8b5998a342d7861f2e921403f44e0a2f3ef2be0',
  'php-cs-fixer/diff' => 'v1.3.0@78bb099e9c16361126c86ce82ec4405ebab8e756',
  'psr/log' => '1.0.2@4ebe3a8bf773a19edfe0a84b6585ba3d401b724d',
  'vimeo/psalm' => '2.0.3@ea4dc9f2b80ae71cebb7613a2005149a38e84731',
  '__root__' => '1.0.0.0@',
);

    private function __construct()
    {
    }

    /**
     * @throws \OutOfBoundsException if a version cannot be located
     */
    public static function getVersion($packageName)
    {
        $selfVersion = self::$VERSIONS;

        if (! isset($selfVersion[$packageName])) {
            throw new \OutOfBoundsException(
                'Required package "' . $packageName . '" is not installed: cannot detect its version'
            );
        }

        return $selfVersion[$packageName];
    }

    /**
     * @throws \OutOfBoundsException if a version cannot be located
     */
    public static function getShortVersion($packageName)
    {
        return explode('@', static::getVersion($packageName))[0];
    }

    /**
     * @throws \OutOfBoundsException if a version cannot be located
     */
    public static function getMajorVersion($packageName)
    {
        return explode('.', static::getShortVersion($packageName))[0];
    }
}
