<?php if(isset($client)){ ?>
    <?php if(has_permission('expenses','','create')){ ?>
        <a href="<?php echo admin_url('expenses/expense?customer_id='.$client->userid); ?>" class="btn btn-info"><?php echo _l('new_expense'); ?></a>
        <?php } ?>
        <?php if(has_permission('expenses','','view')){ ?>
            <?php
            $table_data = array( _l( 'expense_dt_table_heading_category'), _l( 'expense_dt_table_heading_amount'), _l( 'expense_dt_table_heading_date'), _l('project'), _l( 'expense_dt_table_heading_customer'), _l( 'expense_dt_table_heading_reference_no'), _l( 'expense_dt_table_heading_payment_mode'));

            $custom_fields = get_custom_fields('expenses',array('show_on_table'=>1));
            foreach($custom_fields as $field){
                array_push($table_data,$field['name']);
            }
            render_datatable($table_data, 'expenses-single-client');
            ?>
            <?php } ?>
            <?php } ?>
