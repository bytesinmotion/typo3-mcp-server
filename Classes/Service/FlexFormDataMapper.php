<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Hn\McpServer\Compat\LegacyTcaSchemaFactory;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Converts the JSON shape MCP clients send for a FlexForm field
 * (e.g. {"settings": {"orderBy": "title"}}) into the DataHandler datamap
 * format data[sheet][lDEF][field][vDEF].
 *
 * Handing DataHandler an array instead of finished XML matters twice:
 * - DataHandler merges an array with the stored FlexForm, so an update only
 *   touches the fields that were sent. Passing XML replaced the whole value
 *   and silently dropped every field the client did not repeat.
 * - Each field lands in the sheet its data structure defines. FormEngine
 *   reads values per sheet, so a value written to the wrong sheet is
 *   invisible to editors (and duplicated on the next backend save).
 */
class FlexFormDataMapper
{
    /**
     * @param array $flexFormValues Client value, nested or with dotted keys
     * @param array $row Record the FlexForm belongs to (at least the type
     *                   field, e.g. CType, and pid) to resolve the data structure
     * @return array{data: array<string, array{lDEF: array<string, array{vDEF: mixed}>}>}
     */
    public function toDataMapValue(string $table, string $fieldName, array $flexFormValues, array $row): array
    {
        $values = [];
        $sheetOfField = $this->getSheetOfField($table, $fieldName, $row);
        $this->flatten($flexFormValues, '', $sheetOfField, $values);

        $defaultSheet = array_values($sheetOfField)[0] ?? 'sDEF';
        $data = [];
        foreach ($values as $name => $value) {
            $data[$sheetOfField[$name] ?? $defaultSheet]['lDEF'][$name]['vDEF'] = $value;
        }

        return ['data' => $data];
    }

    /**
     * Flattens nested client input to FlexForm field names ("settings.orderBy"),
     * the inverse of FlexFormService::convertFlexFormContentToArray(). An array
     * whose key is itself a field of the data structure (e.g. a multi-select)
     * is kept as the field's value.
     */
    private function flatten(array $input, string $prefix, array $knownFields, array &$values): void
    {
        foreach ($input as $key => $value) {
            $name = $prefix . $key;
            if (is_array($value) && !isset($knownFields[$name])) {
                $this->flatten($value, $name . '.', $knownFields, $values);
                continue;
            }
            $values[$name] = $value;
        }
    }

    /**
     * @return array<string, string> FlexForm field name => sheet name, in data structure order
     */
    private function getSheetOfField(string $table, string $fieldName, array $row): array
    {
        $fieldTca = $GLOBALS['TCA'][$table]['columns'][$fieldName] ?? null;
        if (!is_array($fieldTca)) {
            return [];
        }

        // TYPO3 12/13 look the data structure up via ds_pointerField (e.g.
        // "list_type,CType") and throw when a pointer column is missing from the
        // row - as it is for a new record that only sets CType. Missing pointer
        // columns count as empty, like on a new record in FormEngine.
        $pointerFields = GeneralUtility::trimExplode(',', (string)($fieldTca['config']['ds_pointerField'] ?? ''), true);
        foreach ($pointerFields as $pointerField) {
            $row[$pointerField] ??= '';
        }

        try {
            $flexFormTools = GeneralUtility::makeInstance(FlexFormTools::class);
            // TYPO3 14 resolves the per-type data structure through the schema;
            // TYPO3 12/13 take no such argument (the extra one is ignored).
            $schema = class_exists(TcaSchemaFactory::class) ? LegacyTcaSchemaFactory::create()->get($table) : null;
            $identifier = $flexFormTools->getDataStructureIdentifier($fieldTca, $table, $fieldName, $row, $schema);
            $dataStructure = $flexFormTools->parseDataStructureByIdentifier($identifier, $schema);
        } catch (\Throwable) {
            // No resolvable data structure (e.g. no type set yet): everything goes to sDEF
            return [];
        }

        $sheetOfField = [];
        foreach ($dataStructure['sheets'] ?? [] as $sheetName => $sheet) {
            foreach (array_keys($sheet['ROOT']['el'] ?? []) as $elementName) {
                $sheetOfField[(string)$elementName] ??= (string)$sheetName;
            }
        }

        return $sheetOfField;
    }
}
