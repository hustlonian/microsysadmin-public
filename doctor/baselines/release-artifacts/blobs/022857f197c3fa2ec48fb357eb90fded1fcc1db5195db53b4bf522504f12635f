<?php
$selectedDatabase = $selectedDatabase ?? '';
$selectedTable = $selectedTable ?? '';
$columns = $columns ?? [];
$rows = $rows ?? [];
$databaseSummary = $databaseSummary ?? [];
$primaryKey = $primaryKey ?? [];
$rowCount = (int) ($rowCount ?? 0);
$primaryLookup = array_fill_keys($primaryKey, true);
$canWriteMysql = \SysAdmin\Auth::can('database.modify');
$canDeleteMysql = \SysAdmin\Auth::can('database.delete');
$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
$jsonAttr = static function (array $value) use ($jsonFlags): string {
    $json = json_encode($value, $jsonFlags);
    return e($json === false ? '{}' : $json);
};
$previewValue = static function (mixed $value): string {
    if ($value === null) {
        return 'NULL';
    }
    $text = is_scalar($value) ? (string) $value : json_encode($value);
    $text = str_replace("\0", '\\0', (string) $text);
    return strlen($text) > 180 ? substr($text, 0, 180) . '...' : $text;
};
$safeId = static fn (string $prefix, string $name): string => $prefix . '-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $name);
$isTextarea = static fn (array $column): bool => in_array(strtolower((string) $column['data_type']), ['tinytext', 'text', 'mediumtext', 'longtext', 'json'], true);
$humanLabel = static function (string $name): string {
    $label = ucwords(str_replace('_', ' ', $name));
    return preg_replace_callback('/\b(id|ip|url|uri|uuid|json|sql|api)\b/i', static fn (array $match): string => strtoupper($match[1]), $label) ?? $label;
};
$fieldKind = static function (array $column): string {
    $dataType = strtolower((string) $column['data_type']);
    $columnType = strtolower((string) $column['type']);
    $name = strtolower((string) $column['name']);

    if ($dataType === 'tinyint' && str_starts_with($columnType, 'tinyint(1)')) {
        return 'boolean';
    }
    if ($dataType === 'json' || in_array($name, ['context', 'metadata'], true)) {
        return 'json';
    }
    if (in_array($dataType, ['date', 'time', 'datetime', 'timestamp', 'year'], true)) {
        return 'datetime';
    }
    if (in_array($dataType, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double', 'real'], true)) {
        return 'number';
    }
    if (in_array($dataType, ['tinytext', 'text', 'mediumtext', 'longtext'], true)) {
        return 'long-text';
    }

    return 'text';
};
$kindLabel = static fn (string $kind): string => [
    'boolean' => 'Yes / No',
    'datetime' => 'Date / time',
    'json' => 'JSON',
    'long-text' => 'Long text',
    'number' => 'Number',
    'text' => 'Text',
][$kind] ?? 'Value';
$fieldPlaceholder = static fn (string $kind, bool $auto): string => $auto ? 'Generated automatically when left blank' : ([
    'boolean' => '',
    'datetime' => 'YYYY-MM-DD HH:MM:SS',
    'json' => '{ }',
    'long-text' => 'Enter text',
    'number' => 'Enter a number',
    'text' => 'Enter text',
][$kind] ?? '');
?>
<div class="content-header"><div class="container-fluid"><h1 class="m-0"><?= e($title) ?></h1></div></div>
<section class="content">
    <div class="container-fluid" data-mysql-crud data-database="<?= e($selectedDatabase) ?>" data-table="<?= e($selectedTable) ?>">
        <div class="mysql-workbench">
            <div class="mysql-toolbar">
                <form class="mysql-selector" method="get" action="<?= e(url($active)) ?>" data-mysql-selector>
                    <label class="mysql-select-field">
                        <span>Database</span>
                        <select class="form-select form-select-sm" name="database" data-mysql-database-select>
                            <?php foreach ($databases as $db): ?>
                                <?php $databaseName = (string) $db['name']; ?>
                                <option value="<?= e($databaseName) ?>" <?= $databaseName === $selectedDatabase ? 'selected' : '' ?>><?= e($databaseName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="mysql-select-field">
                        <span>Table</span>
                        <select class="form-select form-select-sm" name="table" data-mysql-table-select>
                            <?php foreach ($tables as $table): ?>
                                <?php $tableName = (string) $table['name']; ?>
                                <option value="<?= e($tableName) ?>" <?= $tableName === $selectedTable ? 'selected' : '' ?>><?= e($tableName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="btn btn-sm btn-outline-primary" type="submit"><i class="fas fa-folder-open"></i> Open</button>
                </form>

                <div class="mysql-toolbar-actions">
                    <?php if ($selectedTable !== '' && $canWriteMysql): ?>
                        <button class="btn btn-sm btn-primary js-mysql-create" type="button" data-bs-toggle="modal" data-bs-target="#mysqlCreateModal">
                            <i class="fas fa-plus"></i> New record
                        </button>
                    <?php endif; ?>
                    <details class="mysql-transfer">
                        <summary><i class="fas fa-right-left"></i> Import / export</summary>
                        <div class="mysql-transfer-menu">
                            <form class="js-api-form" data-endpoint="<?= e(url('api/backups.php')) ?>" data-output="#mysqlOutput">
                                <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                                <input type="hidden" name="action" value="database">
                                <input type="hidden" name="database" value="<?= e($selectedDatabase) ?>">
                                <button class="btn btn-sm btn-primary w-100" type="submit"><i class="fas fa-file-export"></i> Export SQL</button>
                            </form>
                            <form class="js-api-form mt-2" data-endpoint="<?= e(url('api/mysql.php')) ?>" data-output="#mysqlOutput" enctype="multipart/form-data">
                                <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                                <input type="hidden" name="action" value="import">
                                <input type="hidden" name="database" value="<?= e($selectedDatabase) ?>">
                                <input class="form-control form-control-sm mb-2" type="file" name="sql" accept=".sql" required>
                                <button class="btn btn-sm btn-outline-warning w-100" type="submit"><i class="fas fa-file-import"></i> Import SQL</button>
                            </form>
                        </div>
                    </details>
                </div>
            </div>

            <div class="mysql-database-overview" aria-label="Selected database information">
                <div class="mysql-database-overview-heading">
                    <div>
                        <span>Selected database</span>
                        <strong><?= e($selectedDatabase !== '' ? $selectedDatabase : 'None') ?></strong>
                    </div>
                    <?php if ($selectedDatabase !== ''): ?>
                        <span class="mysql-database-status"><i class="fas fa-circle-check"></i> Connected</span>
                    <?php endif; ?>
                </div>
                <div class="mysql-database-metrics">
                    <div><strong><?= e((int) ($databaseSummary['table_count'] ?? 0)) ?></strong><span>Tables</span></div>
                    <div><strong><?= e(number_format((int) ($databaseSummary['estimated_rows'] ?? 0))) ?></strong><span>Estimated rows</span></div>
                    <div><strong><?= e((string) ($databaseSummary['size_human'] ?? '0 B')) ?></strong><span>Table storage</span></div>
                </div>
            </div>

            <div class="mysql-context">
                <?php if ($selectedTable !== ''): ?>
                    <strong><?= e($selectedDatabase) ?>.<?= e($selectedTable) ?></strong>
                    <span><?= e(count($rows)) ?> shown</span>
                    <span><?= e($rowCount) ?> total</span>
                    <span>Primary key: <?= $primaryKey ? e(implode(', ', $primaryKey)) : 'none' ?></span>
                <?php else: ?>
                    <span>Select a table to browse rows.</span>
                <?php endif; ?>
            </div>

            <?php if ($selectedTable === ''): ?>
                <div class="mysql-empty-state">No table selected.</div>
            <?php else: ?>
                <?php if (!$primaryKey && ($canWriteMysql || $canDeleteMysql)): ?>
                    <div class="alert alert-warning py-2 mb-3">This table has no primary key. Updates and deletes match the original row values and affect one matching record.</div>
                <?php endif; ?>
                <?php if ($rows === []): ?>
                    <div class="mysql-empty-state mysql-empty-state-inline">
                        <strong>This table contains no records.</strong>
                        <span>The database is connected. Choose another table to browse its data.</span>
                    </div>
                <?php endif; ?>
                <div class="mysql-grid-shell">
                    <table class="table table-sm table-hover data-table mysql-crud-table <?= ($canWriteMysql || $canDeleteMysql) ? 'mysql-crud-table-has-actions' : '' ?>" data-actions="<?= ($canWriteMysql || $canDeleteMysql) ? '1' : '0' ?>">
                        <thead>
                            <tr>
                                <?php if ($canWriteMysql || $canDeleteMysql): ?><th>Actions</th><?php endif; ?>
                                <?php foreach ($columns as $column): ?><th><?= e($column['name']) ?></th><?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $key = $primaryKey ? [] : $row;
                                foreach ($primaryKey as $keyColumn) {
                                    $key[$keyColumn] = $row[$keyColumn] ?? null;
                                }
                                ?>
                                <tr>
                                    <?php if ($canWriteMysql || $canDeleteMysql): ?>
                                        <td class="mysql-row-actions">
                                            <?php if ($canWriteMysql): ?>
                                                <button class="btn btn-xs btn-outline-primary js-mysql-edit" type="button" data-row="<?= $jsonAttr($row) ?>" data-key="<?= $jsonAttr($key) ?>" title="Edit record" aria-label="Edit record">
                                                    <i class="fas fa-pen-to-square"></i><span>Edit</span>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($canDeleteMysql): ?>
                                                <button class="btn btn-xs btn-outline-danger js-mysql-delete" type="button" data-key="<?= $jsonAttr($key) ?>" title="Delete record" aria-label="Delete record">
                                                    <i class="fas fa-trash"></i><span>Delete</span>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <?php foreach ($columns as $column): ?>
                                        <?php $value = $row[(string) $column['name']] ?? null; ?>
                                        <td>
                                            <?php if ($value === null): ?>
                                                <span class="badge text-bg-light">NULL</span>
                                            <?php else: ?>
                                                <?= e($previewValue($value)) ?>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <pre class="sys-output compact mysql-output-panel" id="mysqlOutput" hidden></pre>
        </div>

        <?php if ($selectedTable !== '' && $canWriteMysql): ?>
            <div class="modal fade" id="mysqlCreateModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                        <form class="js-mysql-row-form" data-output="#mysqlOutput">
                            <div class="modal-header">
                                <div>
                                    <h2 class="modal-title h5">Create record</h2>
                                    <div class="mysql-modal-context"><?= e($selectedDatabase) ?>.<?= e($selectedTable) ?></div>
                                </div>
                                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="action" value="create-row">
                                <input type="hidden" name="database" value="<?= e($selectedDatabase) ?>">
                                <input type="hidden" name="table" value="<?= e($selectedTable) ?>">
                                <div class="mysql-field-grid">
                                    <?php foreach ($columns as $column): ?>
                                        <?php
                                        $name = (string) $column['name'];
                                        $inputId = $safeId('mysql-create', $name);
                                        $nullable = (string) $column['is_nullable'] === 'YES';
                                        $auto = str_contains(strtolower((string) $column['extra']), 'auto_increment');
                                        $kind = $fieldKind($column);
                                        $placeholder = $fieldPlaceholder($kind, $auto);
                                        ?>
                                        <div class="mysql-field mysql-field-<?= e($kind) ?>">
                                            <div class="mysql-field-header">
                                                <div>
                                                    <label class="mysql-field-label" for="<?= e($inputId) ?>"><?= e($humanLabel($name)) ?></label>
                                                    <div class="mysql-field-column"><?= e($name) ?> / <?= e($column['type']) ?></div>
                                                </div>
                                                <div class="mysql-field-badges">
                                                    <span class="mysql-field-chip"><?= e($kindLabel($kind)) ?></span>
                                                    <?php if ($auto): ?><span class="mysql-field-chip">Auto</span><?php endif; ?>
                                                    <span class="mysql-field-chip"><?= $nullable ? 'Optional' : 'Required' ?></span>
                                                </div>
                                            </div>
                                            <?php if ($kind === 'boolean'): ?>
                                                <select class="form-select" id="<?= e($inputId) ?>" name="values[<?= e($name) ?>]" data-mysql-kind="<?= e($kind) ?>">
                                                    <option value="">Choose...</option>
                                                    <option value="1">Yes</option>
                                                    <option value="0">No</option>
                                                </select>
                                            <?php elseif ($kind === 'json' || $isTextarea($column)): ?>
                                                <textarea class="form-control mysql-field-textarea" id="<?= e($inputId) ?>" name="values[<?= e($name) ?>]" rows="<?= $kind === 'json' ? '5' : '3' ?>" placeholder="<?= e($placeholder) ?>" data-mysql-kind="<?= e($kind) ?>"></textarea>
                                            <?php else: ?>
                                                <input class="form-control" id="<?= e($inputId) ?>" name="values[<?= e($name) ?>]" placeholder="<?= e($placeholder) ?>" data-mysql-kind="<?= e($kind) ?>" <?= $kind === 'number' ? 'inputmode="decimal"' : '' ?>>
                                            <?php endif; ?>
                                            <?php if ($nullable): ?>
                                                <div class="form-check form-switch mysql-null-switch">
                                                    <input class="form-check-input js-mysql-null-toggle" id="<?= e($inputId) ?>-null" type="checkbox" name="nulls[<?= e($name) ?>]" value="1" data-target-id="<?= e($inputId) ?>" checked>
                                                    <label class="form-check-label" for="<?= e($inputId) ?>-null">Set as NULL</label>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                                <button class="btn btn-primary" type="submit"><i class="fas fa-plus"></i> Create record</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="mysqlEditModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                        <form class="js-mysql-row-form" data-output="#mysqlOutput">
                            <div class="modal-header">
                                <div>
                                    <h2 class="modal-title h5">Edit record</h2>
                                    <div class="mysql-modal-context"><?= e($selectedDatabase) ?>.<?= e($selectedTable) ?></div>
                                </div>
                                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="action" value="update-row">
                                <input type="hidden" name="database" value="<?= e($selectedDatabase) ?>">
                                <input type="hidden" name="table" value="<?= e($selectedTable) ?>">
                                <input type="hidden" name="key" value="">
                                <div class="mysql-field-grid">
                                    <?php foreach ($columns as $column): ?>
                                        <?php
                                        $name = (string) $column['name'];
                                        $inputId = $safeId('mysql-edit', $name);
                                        $nullable = (string) $column['is_nullable'] === 'YES';
                                        $isPrimary = isset($primaryLookup[$name]);
                                        $kind = $fieldKind($column);
                                        $placeholder = $fieldPlaceholder($kind, false);
                                        ?>
                                        <div class="mysql-field mysql-field-<?= e($kind) ?> <?= $isPrimary ? 'mysql-field-readonly' : '' ?>">
                                            <div class="mysql-field-header">
                                                <div>
                                                    <label class="mysql-field-label" for="<?= e($inputId) ?>"><?= e($humanLabel($name)) ?></label>
                                                    <div class="mysql-field-column"><?= e($name) ?> / <?= e($column['type']) ?></div>
                                                </div>
                                                <div class="mysql-field-badges">
                                                    <span class="mysql-field-chip"><?= e($kindLabel($kind)) ?></span>
                                                    <?php if ($isPrimary): ?><span class="mysql-field-chip mysql-field-chip-lock">Read-only key</span><?php endif; ?>
                                                    <?php if (!$isPrimary): ?><span class="mysql-field-chip"><?= $nullable ? 'Optional' : 'Required' ?></span><?php endif; ?>
                                                </div>
                                            </div>
                                            <?php if ($kind === 'boolean'): ?>
                                                <select class="form-select" id="<?= e($inputId) ?>" <?= $isPrimary ? '' : 'name="values[' . e($name) . ']"' ?> data-column-input="<?= e($name) ?>" data-mysql-kind="<?= e($kind) ?>" <?= $isPrimary ? 'disabled data-readonly="1"' : '' ?>>
                                                    <option value="">Choose...</option>
                                                    <option value="1">Yes</option>
                                                    <option value="0">No</option>
                                                </select>
                                            <?php elseif ($kind === 'json' || $isTextarea($column)): ?>
                                                <textarea class="form-control mysql-field-textarea" id="<?= e($inputId) ?>" <?= $isPrimary ? '' : 'name="values[' . e($name) . ']"' ?> rows="<?= $kind === 'json' ? '5' : '3' ?>" placeholder="<?= e($placeholder) ?>" data-column-input="<?= e($name) ?>" data-mysql-kind="<?= e($kind) ?>" <?= $isPrimary ? 'readonly data-readonly="1"' : '' ?>></textarea>
                                            <?php else: ?>
                                                <input class="form-control" id="<?= e($inputId) ?>" <?= $isPrimary ? '' : 'name="values[' . e($name) . ']"' ?> placeholder="<?= e($placeholder) ?>" data-column-input="<?= e($name) ?>" data-mysql-kind="<?= e($kind) ?>" <?= $kind === 'number' ? 'inputmode="decimal"' : '' ?> <?= $isPrimary ? 'readonly data-readonly="1"' : '' ?>>
                                            <?php endif; ?>
                                            <?php if ($nullable && !$isPrimary): ?>
                                                <div class="form-check form-switch mysql-null-switch">
                                                    <input class="form-check-input js-mysql-null-toggle" id="<?= e($inputId) ?>-null" type="checkbox" name="nulls[<?= e($name) ?>]" value="1" data-target-id="<?= e($inputId) ?>" data-column-null="<?= e($name) ?>">
                                                    <label class="form-check-label" for="<?= e($inputId) ?>-null">Set as NULL</label>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                                <button class="btn btn-primary" type="submit"><i class="fas fa-floppy-disk"></i> Save changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
