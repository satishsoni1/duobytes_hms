<?php
/* Repeating input rows: $name (form array name), $rows, $cols (key => label), $min (blank rows to show). */
$rows = $rows ?: array();
while (count($rows) < $min) {
    $rows[] = array();
}
$tid = 'cs-rows-' . $name;
?>
<table class="table table-sm cs-rows mb-1" id="<?php echo $tid; ?>" data-name="<?php echo $name; ?>">
    <thead><tr><?php foreach ($cols as $l) { ?><th><?php echo html_escape($l); ?></th><?php } ?><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r) { ?>
        <tr>
            <?php foreach ($cols as $k => $l) { ?>
                <td><input class="form-control form-control-sm" name="<?php echo $name . '[' . $i . '][' . $k . ']'; ?>" value="<?php echo html_escape(isset($r[$k]) ? $r[$k] : ''); ?>"></td>
            <?php } ?>
            <td><button type="button" class="btn btn-sm btn-link text-danger cs-row-del">&times;</button></td>
        </tr>
    <?php } ?>
    </tbody>
</table>
<button type="button" class="btn btn-sm btn-outline-primary cs-row-add mb-2" data-target="#<?php echo $tid; ?>">+ Add row</button>
