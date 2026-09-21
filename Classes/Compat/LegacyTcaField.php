<?php

declare(strict_types=1);

namespace Hn\McpServer\Compat;

/**
 * TYPO3 12 stand-in for a \TYPO3\CMS\Core\Schema\Field\FieldTypeInterface.
 *
 * @see LegacyTcaSchemaFactory
 */
final class LegacyTcaField
{
    public function __construct(
        private readonly string $name,
        private readonly array $configuration,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getConfiguration(): array
    {
        return $this->configuration;
    }
}
