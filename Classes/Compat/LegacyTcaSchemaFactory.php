<?php

declare(strict_types=1);

namespace Hn\McpServer\Compat;

use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Minimal stand-in for the TCA Schema API (TYPO3 >= 13) on TYPO3 12.
 *
 * Mirrors only the subset TableAccessService relies on: has()/get() on the
 * factory, and getFields(), getSubSchemata(), hasSubSchema(), getSubSchema(),
 * supportsSubSchema() and getSubSchemaTypeInformation()->getFieldName() on a
 * schema. Field lists and configurations are derived exactly like core's
 * TcaSchemaFactory::build(): sub-schema fields come from the type's showitem
 * (palettes unpacked, columnsOverrides and label overrides applied) and each
 * configuration is flattened (the "config" sub-array merged to top level).
 */
final class LegacyTcaSchemaFactory
{
    /** @var array<string, LegacyTcaSchema> */
    private array $schemata = [];

    /**
     * Returns the core TcaSchemaFactory where it exists (TYPO3 >= 13), this
     * stand-in otherwise. Both expose the same duck-typed surface.
     */
    public static function create(): object
    {
        if (class_exists(TcaSchemaFactory::class)) {
            return GeneralUtility::makeInstance(TcaSchemaFactory::class);
        }
        return new self();
    }

    public function has(string $table): bool
    {
        return isset($GLOBALS['TCA'][$table]);
    }

    public function get(string $table): LegacyTcaSchema
    {
        if (!$this->has($table)) {
            throw new \InvalidArgumentException('No TCA schema exists for "' . $table . '".', 1758456001);
        }
        return $this->schemata[$table] ??= $this->build($table, $GLOBALS['TCA'][$table]);
    }

    private function build(string $table, array $tca): LegacyTcaSchema
    {
        $fields = [];
        foreach ($tca['columns'] ?? [] as $fieldName => $fieldConfiguration) {
            $fields[$fieldName] = new LegacyTcaField((string)$fieldName, self::flatten($fieldConfiguration));
        }

        $subSchemata = [];
        $typeField = $tca['ctrl']['type'] ?? null;
        if ($typeField !== null) {
            foreach ($tca['types'] ?? [] as $typeName => $typeConfiguration) {
                $subFields = [];
                foreach (self::findFieldsForType($tca, $typeConfiguration) as $fieldName => $fieldConfiguration) {
                    $subFields[$fieldName] = new LegacyTcaField($fieldName, self::flatten($fieldConfiguration));
                }
                $subSchemata[(string)$typeName] = new LegacyTcaSchema($subFields, null, []);
            }
        }

        return new LegacyTcaSchema($fields, is_string($typeField) ? $typeField : null, $subSchemata);
    }

    /**
     * @see \TYPO3\CMS\Core\Schema\TcaSchemaFactory::findRelevantFieldsForSubSchema()
     */
    private static function findFieldsForType(array $tca, array $typeConfiguration): array
    {
        $fields = [];
        foreach (GeneralUtility::trimExplode(',', (string)($typeConfiguration['showitem'] ?? ''), true) as $item) {
            [$fieldName, $fieldLabel, $paletteName] = GeneralUtility::trimExplode(';', $item . ';;;');
            if ($fieldName === '--div--') {
                continue;
            }
            if ($fieldName === '--palette--' && $paletteName !== '') {
                foreach (GeneralUtility::trimExplode(',', (string)($tca['palettes'][$paletteName]['showitem'] ?? ''), true) as $paletteItem) {
                    [$paletteField, $paletteLabel] = GeneralUtility::trimExplode(';', $paletteItem . ';;');
                    if (isset($tca['columns'][$paletteField])) {
                        $fields[$paletteField] = self::finalConfiguration($tca, $typeConfiguration, $paletteField, $paletteLabel);
                    }
                }
            } elseif (isset($tca['columns'][$fieldName])) {
                $fields[$fieldName] = self::finalConfiguration($tca, $typeConfiguration, $fieldName, $fieldLabel);
            }
        }
        return $fields;
    }

    private static function finalConfiguration(array $tca, array $typeConfiguration, string $fieldName, string $label): array
    {
        $configuration = $tca['columns'][$fieldName] ?? [];
        if (isset($typeConfiguration['columnsOverrides'][$fieldName])) {
            $configuration = array_replace_recursive($configuration, $typeConfiguration['columnsOverrides'][$fieldName]);
        }
        if ($label !== '') {
            $configuration['label'] = $label;
        }
        return $configuration;
    }

    /**
     * @see \TYPO3\CMS\Core\Schema\FieldTypeFactory::streamlineFieldConfiguration()
     */
    private static function flatten(array $configuration): array
    {
        $config = $configuration['config'] ?? null;
        unset($configuration['config']);
        return array_replace_recursive(is_array($config) ? $config : [], $configuration);
    }
}
