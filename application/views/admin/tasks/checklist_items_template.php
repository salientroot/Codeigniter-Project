<?php
    $total_checklist_items = total_rows('tbltaskchecklists',array('taskid'=>$task_id));

    // Show progress bar only if more then 2 checklist items
    if($total_checklist_items > 2){
        $total_finished = total_rows('tbltaskchecklists',array('taskid'=>$task_id,'finished'=>1));
        $percent_finished = number_format(($total_finished * 100) / $total_checklist_items,2);
        ?>
<div class="progress no-margin">
    <div class="progress-bar not-dynamic progress-bar-default task-progress-bar" role="progressbar" aria-valuenow="40" aria-valuemin="0" aria-valuemax="100" style="width:<?php echo $percent_finished; ?>%">
        <?php echo $percent_finished; ?>%
    </div>
</div>
<hr />
<?php } ?>
<div class="clearfix"></div>
<?php if(count($checklists) > 0){ ?>
<h4 class="bold chk-heading"><?php echo _l('task_checklist_items'); ?></h4>
<?php } ?>
<?php foreach($checklists as $list){ ?>
<div class="checklist" data-checklist-id="<?php echo $list['id']; ?>">
    <div class="checkbox checkbox-success checklist-checkbox" data-toggle="tooltip" title="">
        <input type="checkbox" name="checklist-box" <?php if($list['finished'] == 1){echo 'checked';}; ?>>
        <label for=""><span class="hide"><?php echo $list['description']; ?></span></label>
        <textarea name="checklist-description" rows="1"><?php echo clear_textarea_breaks($list['description']); ?></textarea>
        <?php if(has_permission('tasks','','delete') || $list['addedfrom'] == get_staff_user_id()){ ?>
        <a href="#" class="pull-right text-muted remove-checklist" onclick="delete_checklist_item(<?php echo $list['id']; ?>,this); return false;"><i class="fa fa-remove"></i></a>
        <?php } ?>
    </div>
</div>
<?php } ?>
<script>
    $("textarea[name='checklist-description']").keypress(function(event) {
      if(event.which == '13') {
        $(this).focusout();
        add_task_checklist_item();
        return false;
      }
    });

    $("#checklist-items").sortable({
        helper: 'clone',
        items: 'div.checklist',
        update: function(event, ui) {
            update_checklist_order();
        }
    });

</script>
