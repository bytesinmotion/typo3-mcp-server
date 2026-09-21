<?php

declare(strict_types=1);

namespace Hn\McpServer\Compat;

use TYPO3\CMS\Core\Configuration\Tca\TcaFactory;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Loads the base TCA into $GLOBALS['TCA'] on every supported TYPO3 version.
 */
final class BaseTca
{
    public static function load(): void
    {
        if (class_exists(TcaFactory::class)) {
            // TYPO3 >= 13
            $GLOBALS['TCA'] = GeneralUtility::getContainer()->get(TcaFactory::class)->get();
            return;
        }
        // TYPO3 12
        Bootstrap::loadBaseTca();
    }
}
