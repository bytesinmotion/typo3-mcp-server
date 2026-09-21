<?php

declare(strict_types=1);

namespace Hn\McpServer\Compat;

/**
 * TYPO3 12 stand-in for \TYPO3\CMS\Core\Schema\TcaSchema.
 *
 * @see LegacyTcaSchemaFactory
 */
final class LegacyTcaSchema
{
    /**
     * @param array<string, LegacyTcaField> $fields
     * @param array<string, LegacyTcaSchema> $subSchemata
     */
    public function __construct(
        private readonly array $fields,
        private readonly ?string $typeField,
        private readonly array $subSchemata,
    ) {}

    /** @return array<string, LegacyTcaField> */
    public function getFields(): array
    {
        return $this->fields;
    }

    /** @return array<string, LegacyTcaSchema> */
    public function getSubSchemata(): array
    {
        return $this->subSchemata;
    }

    public function hasSubSchema(string $subSchema): bool
    {
        return isset($this->subSchemata[$subSchema]);
    }

    public function getSubSchema(string $subSchema): LegacyTcaSchema
    {
        if (!$this->hasSubSchema($subSchema)) {
            throw new \InvalidArgumentException('The sub schema "' . $subSchema . '" is not defined.', 1758456002);
        }
        return $this->subSchemata[$subSchema];
    }

    public function supportsSubSchema(): bool
    {
        return $this->typeField !== null;
    }

    /**
     * Only getFieldName() is used by callers; for foreign type notation
     * ("uid_local:type") core returns the local field as well.
     */
    public function getSubSchemaTypeInformation(): object
    {
        if ($this->typeField === null) {
            throw new \InvalidArgumentException('The schema has no type information.', 1758456003);
        }
        $fieldName = explode(':', $this->typeField, 2)[0];
        return new class ($fieldName) {
            public function __construct(private readonly string $fieldName) {}

            public function getFieldName(): string
            {
                return $this->fieldName;
            }
        };
    }
}
