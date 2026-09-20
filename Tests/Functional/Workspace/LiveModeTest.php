<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Workspace;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tests for the "live mode" extension setting (liveWorkspaceMode).
 *
 * With live mode enabled, MCP operations run in the live workspace: no MCP
 * workspace is created, nothing is staged as a draft, and tables without
 * workspace support become writable. With it disabled - the default - the
 * regular workspace behaviour must be untouched.
 */
class LiveModeTest extends AbstractFunctionalTest
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
    }

    /**
     * Set the live mode extension setting.
     *
     * Services cache the flag, so drop the singletons afterwards to make sure
     * the next makeInstance() call sees the new setting.
     */
    protected function setLiveMode(bool $enabled): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['liveWorkspaceMode'] = $enabled ? '1' : '0';
        GeneralUtility::purgeInstances();
    }

    public function testLiveModeStaysInLiveWorkspaceAndCreatesNoWorkspace(): void
    {
        $this->setLiveMode(true);

        $workspaceId = GeneralUtility::makeInstance(WorkspaceContextService::class)
            ->switchToOptimalWorkspace($GLOBALS['BE_USER']);

        $this->assertSame(0, $workspaceId, 'Live mode must stay in the live workspace');
        $this->assertSame(0, (int)($GLOBALS['BE_USER']->workspace ?? -1), 'Backend user must be in workspace 0');

        $workspaceCount = (int)$this->connectionPool
            ->getConnectionForTable('sys_workspace')
            ->count('uid', 'sys_workspace', []);
        $this->assertSame(0, $workspaceCount, 'Live mode must not create an MCP workspace');
    }

    public function testLiveModeOverridesAnExistingWorkspaceContext(): void
    {
        $workspaceId = $this->createAndSwitchToWorkspace('Existing workspace');
        $this->assertGreaterThan(0, $workspaceId);

        $this->setLiveMode(true);

        $resultingWorkspace = GeneralUtility::makeInstance(WorkspaceContextService::class)
            ->switchToOptimalWorkspace($GLOBALS['BE_USER']);

        $this->assertSame(0, $resultingWorkspace, 'Live mode must win over an existing workspace context');
        $this->assertSame(0, (int)($GLOBALS['BE_USER']->workspace ?? -1));
    }

    public function testWriteTableWritesDirectlyToLiveInLiveMode(): void
    {
        $this->setLiveMode(true);

        $result = GeneralUtility::makeInstance(WriteTableTool::class)->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'header' => 'Created in live mode',
                'CType' => 'text',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $record = $this->connectionPool
            ->getConnectionForTable('tt_content')
            ->select(['uid', 't3ver_wsid', 't3ver_oid'], 'tt_content', ['header' => 'Created in live mode'])
            ->fetchAssociative();

        $this->assertIsArray($record, 'Record must exist in the live table');
        $this->assertSame(0, (int)$record['t3ver_wsid'], 'Record must not belong to a workspace');
        $this->assertSame(0, (int)$record['t3ver_oid'], 'Record must not be a workspace version of a live record');
    }

    public function testWorkspaceModeStillStagesChanges(): void
    {
        $this->setLiveMode(false);

        $result = GeneralUtility::makeInstance(WriteTableTool::class)->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'header' => 'Created in workspace mode',
                'CType' => 'text',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $record = $this->connectionPool
            ->getConnectionForTable('tt_content')
            ->select(['uid', 't3ver_wsid'], 'tt_content', ['header' => 'Created in workspace mode'])
            ->fetchAssociative();

        $this->assertIsArray($record);
        $this->assertGreaterThan(0, (int)$record['t3ver_wsid'], 'Workspace mode must still stage changes in a workspace');
    }

    public function testNonWorkspaceCapableTableBecomesWritableInLiveMode(): void
    {
        $this->setLiveMode(false);
        $info = GeneralUtility::makeInstance(TableAccessService::class)->getTableAccessInfo('fe_groups');
        $this->assertFalse($info['accessible'], 'fe_groups is not workspace-capable and must be hidden in workspace mode');

        $this->setLiveMode(true);
        $info = GeneralUtility::makeInstance(TableAccessService::class)->getTableAccessInfo('fe_groups');
        $this->assertTrue($info['accessible'], 'Live mode must expose non-workspace-capable tables');
        $this->assertFalse($info['read_only'], 'Live mode must expose them as writable');
        $this->assertTrue($info['permissions']['write']);
    }

    public function testCredentialTablesStayRestrictedInLiveMode(): void
    {
        $this->setLiveMode(true);

        $info = GeneralUtility::makeInstance(TableAccessService::class)->getTableAccessInfo('fe_users');

        $this->assertFalse($info['accessible'], 'Tables with a password field must never be exposed');
    }

    public function testConfiguredReadOnlyTablesStayReadOnlyInLiveMode(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['additionalReadOnlyTables'] = 'sys_file';
        $this->setLiveMode(true);

        $info = GeneralUtility::makeInstance(TableAccessService::class)->getTableAccessInfo('sys_file');

        $this->assertTrue($info['accessible'], 'Configured read-only tables stay readable');
        $this->assertTrue($info['read_only'], 'Configured read-only tables must stay read-only in live mode');
        $this->assertFalse($info['permissions']['write']);
    }
}
