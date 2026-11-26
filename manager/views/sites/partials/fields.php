<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : fields.php
 *  Date    : 26/11/2026
 *  Author  : Semansys Development Team
 *
 *  Copyright (c) 2026
 *  Tel: +91 97863 37914
 *  Email: dev.digiboost.muthu@gmail.com
 *  All rights reserved, see the LICENSE file for more information.
 *
 ******************************************************************************/

/* View: sites/partials/fields | Shared form rows for the project folder, PHP version and group
   (used by "Add New Site" and the site details form).
   Data: $roots (var => folder), $phpList (installed PHP), $groups, $selRootVar, $selRootPath, $selPhp, $selGroup */
?>
<tr>
    <th><label for="root_path">Project folder</label></th>
    <td>
        <?php if (!$roots): ?>
            <div class="alert alert-warn">Add your projects folder on the <a href="/settings">Settings</a> page first.</div>
        <?php else: ?>
            <div class="input-prefix" style="max-width: 620px">
                <select name="root_var" aria-label="Projects folder">
                    <?php foreach ($roots as $var => $folder): ?>
                        <option value="<?= e($var) ?>" <?= $selRootVar === $var ? 'selected' : '' ?>><?= e($folder) ?>/</option>
                    <?php endforeach; ?>
                </select>
                <input type="text" id="root_path" name="root_path" value="<?= e($selRootPath) ?>" placeholder="myapp/public" required>
            </div>
            <?php // Original values: the server only re-validates the folder when it was changed. ?>
            <input type="hidden" name="root_var_orig" value="<?= e($selRootVar) ?>">
            <input type="hidden" name="root_path_orig" value="<?= e($selRootPath) ?>">
        <?php endif; ?>
    </td>
</tr>
<tr>
    <th><label for="php">PHP version</label></th>
    <td>
        <select id="php" name="php" style="max-width: 320px" <?= ($keepPhpOption ?? false) ? '' : 'required' ?>>
            <?php if ($keepPhpOption ?? false): ?>
                <option value="" selected>Keep current (not one of the installed PHP versions)</option>
            <?php endif; ?>
            <?php foreach ($phpList as $v): ?>
                <option value="<?= e($v['id']) ?>" <?= $selPhp === $v['id'] ? 'selected' : '' ?>>
                    PHP <?= e($v['id']) ?> (<?= e($v['version']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <a class="small" href="/php#add-php" style="margin-left: 8px">Need another version?</a>
    </td>
</tr>
<tr>
    <th><label for="group">Group</label></th>
    <td class="row">
        <select id="group" name="group" style="max-width: 240px">
            <option value="">— No group —</option>
            <?php foreach ($groups as $g): ?>
                <option value="<?= e($g['id']) ?>" <?= $selGroup === $g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <span class="muted">or new:</span>
        <input type="text" name="group_new" placeholder="New group name" maxlength="40" style="max-width: 220px">
    </td>
</tr>
