<div class="col-md-12 page-pdf-html-logo">
    <?php get_company_logo(); ?>
    <?php if(is_staff_logged_in() && has_permission('invoices')){ ?>
    <a href="<?php echo admin_url(); ?>invoices/list_invoices/<?php echo $invoice->id; ?>" class="btn btn-info pull-right"><?php echo _l('goto_admin_area'); ?></a>
    <?php } else if(is_client_logged_in() && has_contact_permission('invoices')){ ?>
      <a href="<?php echo site_url('clients/invoices/'); ?>" class="btn btn-info pull-right"><?php echo _l('client_go_to_dashboard'); ?></a>
      <?php } ?>
</div>
<div class="clearfix"></div>
<div class="panel_s mtop20">
    <div class="panel-body">
        <div class="col-md-10 col-md-offset-1">
            <div class="row">
                <div class="col-md-6">
                    <div class="mtop10 display-block">
                        <?php echo format_invoice_status($invoice->status,'',true); ?>
                    </div>
                </div>
                <div class="col-md-6 text-right _buttons">
                    <div class="visible-xs">
                        <div class="mtop10"></div>
                    </div>
                    <?php echo form_open($this->uri->uri_string()); ?>
                    <input type="submit" name="invoicepdf" class="btn btn-info" value="<?php echo _l('clients_invoice_html_btn_download'); ?>">
                    <?php echo form_close(); ?>
                </div>
            </div>
            <div class="row mtop40">
                <div class="col-md-6">
                    <h4 class="bold"><?php echo format_invoice_number($invoice->id); ?></h4>
                    <address>
                        <span class="bold"><?php echo get_option('invoice_company_name'); ?></span><br>
                        <?php echo get_option('invoice_company_address'); ?><br>
                        <?php echo get_option('invoice_company_city'); ?>, <?php echo get_option('invoice_company_country_code'); ?> <?php echo get_option('invoice_company_postal_code'); ?><br>
                        <?php if(get_option('invoice_company_phonenumber') != ''){ ?>
                        <abbr title="Phone">P:</abbr> <?php echo get_option('invoice_company_phonenumber'); ?><br />
                        <?php } ?>
                        <?php
                            // check for company custom fields
                        $custom_company_fields = get_company_custom_fields();
                        foreach($custom_company_fields as $field){
                         echo $field['label'] . ': ' . $field['value'] . '<br />';
                     }
                     ?>
                    </address>
                </div>
                <div class="col-sm-6 text-right">
                    <span class="bold"><?php echo _l('invoice_bill_to'); ?>:</span>
                    <address>
                        <span class="bold"><?php echo $invoice->client->company; ?></span><br>
                        <?php echo $invoice->billing_street; ?><br>
                        <?php
                        if(!empty($invoice->billing_city)){
                            echo $invoice->billing_city;
                        }
                        if(!empty($invoice->billing_state)){
                            echo ', '.$invoice->billing_state;
                        }
                        $billing_country = get_country_short_name($invoice->billing_country);
                        if(!empty($billing_country)){
                            echo '<br />'.$billing_country;
                        }
                        if(!empty($invoice->billing_zip)){
                            echo ', '.$invoice->billing_zip;
                        }
                        if(!empty($invoice->client->vat)){
                            echo '<br /><b>'._l('invoice_vat') .'</b>: '. $invoice->client->vat;
                        }
                        // check for customer custom fields which is checked show on pdf
                        $pdf_custom_fields = get_custom_fields('customers',array('show_on_pdf'=>1));
                        if(count($pdf_custom_fields) > 0){
                            echo '<br />';
                            foreach($pdf_custom_fields as $field){
                                $value = get_custom_field_value($invoice->clientid,$field['id'],'customers');
                                if($value == ''){continue;}
                                echo '<b>'.$field['name'] . '</b>: ' . $value . '<br />';
                            }
                        }
                        ?>
                    </address>
                    <!-- shipping details -->
                    <?php if($invoice->include_shipping == 1 && $invoice->show_shipping_on_invoice == 1){ ?>
                    <span class="bold"><?php echo _l('ship_to'); ?>:</span>
                    <address>
                        <?php echo $invoice->shipping_street; ?><br>
                        <?php echo $invoice->shipping_city; ?>, <?php echo $invoice->shipping_state; ?><br/><?php echo get_country_short_name($invoice->shipping_country); ?>, <?php echo $invoice->shipping_zip; ?>
                    </address>
                    <?php } ?>
                    <p>
                        <span><span class="bold"><?php echo _l('invoice_data_date'); ?></span> <?php echo _d($invoice->date); ?></span><br>
                        <?php if(!empty($invoice->duedate)){ ?>
                        <span class="mtop20"><span class="bold"><?php echo _l('invoice_data_duedate'); ?></span> <?php echo _d($invoice->duedate); ?></span>
                        <?php } ?>
                        <?php if($invoice->sale_agent != 0){
                            if(get_option('show_sale_agent_on_invoices') == 1){ ?>
                        <br /><span class="mtop20">
                        <span class="bold"><?php echo _l('sale_agent_string'); ?>:</span>
                        <?php echo get_staff_full_name($invoice->sale_agent); ?>
                        </span>
                        <?php }
                            }
                            ?>
                        <?php
                            // check for invoice custom fields which is checked show on pdf
                        $pdf_custom_fields = get_custom_fields('invoice',array('show_on_pdf'=>1));
                        foreach($pdf_custom_fields as $field){
                         $value = get_custom_field_value($invoice->id,$field['id'],'invoice');
                         if($value == ''){continue;} ?>
                        <br /><span class="mtop20">
                        <span class="bold"><?php echo $field['name']; ?>: </span>
                        <?php echo $value; ?>
                        </span>
                        <?php
                            }
                            ?>
                    </p>
                </div>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="table-responsive">
                        <table class="table items">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th class="description" width="50%"><?php echo _l('invoice_table_item_heading'); ?></th>
                                    <?php
                                        $qty_heading = _l('invoice_table_quantity_heading');
                                        if($invoice->show_quantity_as == 2){
                                        	$qty_heading = _l('invoice_table_hours_heading');
                                        } else if($invoice->show_quantity_as == 3){
                                        	$qty_heading = _l('invoice_table_quantity_heading') .'/'._l('invoice_table_hours_heading');
                                        }
                                        ?>
                                    <th><?php echo $qty_heading; ?></th>
                                    <th><?php echo _l('invoice_table_rate_heading'); ?></th>
                                    <?php if(get_option('show_tax_per_item') == 1){ ?>
                                    <th><?php echo _l('invoice_table_tax_heading'); ?></th>
                                    <?php } ?>
                                    <th><?php echo _l('invoice_table_amount_heading'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                    $_tax_tr = '';
                                    $taxes = array();
                                    $_calculated_taxes = array();
                                    $i = 1;
                                    foreach($invoice->items as $item){
                                    	$_item = '';
                                    	$_item .= '<tr>';
                                    	$_item .= '<td>' .$i. '</td>';
                                    	$_item .= '<td class="description"><span class="bold">'.$item['description'].'</span><br /><span class="text-muted">'.$item['long_description'].'</span></td>';
                                    	$_item .= '<td>'.floatVal($item['qty']).'</td>';
                                    	$_item .= '<td>'._format_number($item['rate']).'</td>';
                                    	if(get_option('show_tax_per_item') == 1){
                                    		$_item .= '<td>';
                                    	}
                                    	$item_taxes = get_invoice_item_taxes($item['id']);
                                    	if(count($item_taxes) > 0){

                                    		foreach($item_taxes as $tax){
                                    			$calc_tax = 0;
                                    			$tax_not_calc = false;
                                    			if(!in_array($tax['taxname'],$_calculated_taxes)) {
                                    				array_push($_calculated_taxes,$tax['taxname']);
                                    				$tax_not_calc = true;
                                    			}
                                    			if($tax_not_calc == true){
                                    				$taxes[$tax['taxname']] =array();
                                    				$taxes[$tax['taxname']]['total'] = array();
                                    				array_push($taxes[$tax['taxname']]['total'],(($item['qty'] * $item['rate']) / 100 * $tax['taxrate']));
                                    				$taxes[$tax['taxname']]['tax_name'] = $tax['taxname'];
                                    				$taxes[$tax['taxname']]['taxrate'] = $tax['taxrate'];
                                    			} else {
                                    				array_push($taxes[$tax['taxname']]['total'],(($item['qty'] * $item['rate']) / 100 * $tax['taxrate']));
                                    			}
                                                if(get_option('show_tax_per_item') == 1){
                                                  if((count($item_taxes) > 1 && get_option('remove_tax_name_from_item_table') == false) || get_option('remove_tax_name_from_item_table') == false || mutiple_taxes_found_for_item($item_taxes)){
                                                    $_item .= str_replace('|',' ',$tax['taxname']) .'%<br />';
                                                } else {
                                                    $_item .= $tax['taxrate'] .'%';
                                                }
                                            }
                                    		}
                                    	} else {
                                    		if(get_option('show_tax_per_item') == 1){
                                    			$_item .= '0%';
                                    		}
                                    	}
                                    	if(get_option('show_tax_per_item') == 1){
                                    		$_item .= '</td>';
                                    	}
                                    	$_item .= '<td class="amount">'._format_number(($item['qty'] * $item['rate'])).'</td>';
                                    	$_item .= '</tr>';
                                    	echo $_item;

                                    	$i++;
                                    } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="col-md-6 col-md-offset-6">
                    <table class="table text-right">
                        <tbody>
                            <tr id="subtotal">
                                <td><span class="bold"><?php echo _l('invoice_subtotal'); ?></span>
                                </td>
                                <td class="subtotal">
                                    <?php echo format_money($invoice->subtotal,$invoice->symbol); ?>
                                </td>
                            </tr>
                            <?php if($invoice->discount_percent != 0){ ?>
                            <tr>
                                <td>
                                    <span class="bold"><?php echo _l('invoice_discount'); ?> (<?php echo _format_number($invoice->discount_percent,true); ?>%)</span>
                                </td>
                                <td class="discount">
                                    <?php echo '-' . format_money($invoice->discount_total,$invoice->symbol); ?>
                                </td>
                            </tr>
                            <?php } ?>
                            <?php
                                	foreach($taxes as $tax){
                                		$total = array_sum($tax['total']);
                                		if($invoice->discount_percent != 0 && $invoice->discount_type == 'before_tax'){
                                			$total_tax_calculated = ($total * $invoice->discount_percent) / 100;
                                			$total = ($total - $total_tax_calculated);
                                		}
                                		$_tax_name = explode('|',$tax['tax_name']);
                                		echo '<tr class="tax-area"><td>'.$_tax_name[0].'('._format_number($tax['taxrate']).'%)</td><td>'.format_money($total,$invoice->symbol).'</td></tr>';
                                	}

                                ?>
                            <?php if($invoice->adjustment != '0.00'){ ?>
                            <tr>
                                <td>
                                    <span class="bold"><?php echo _l('invoice_adjustment'); ?></span>
                                </td>
                                <td class="adjustment">
                                    <?php echo format_money($invoice->adjustment,$invoice->symbol); ?>
                                </td>
                            </tr>
                            <?php } ?>
                            <tr>
                                <td><span class="bold"><?php echo _l('invoice_total'); ?></span>
                                </td>
                                <td class="total">
                                    <?php echo format_money($invoice->total,$invoice->symbol); ?>
                                </td>
                            </tr>
                            <?php if($invoice->status == 3){ ?>
                            <tr>
                                <td><span><?php echo _l('invoice_total_paid'); ?></span></td>
                                <td>
                                    <?php echo format_money(sum_from_table('tblinvoicepaymentrecords',array('field'=>'amount','where'=>array('invoiceid'=>$invoice->id))),$invoice->symbol); ?>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="text-danger bold"><?php echo _l('invoice_amount_due'); ?></span></td>
                                <td>
                                    <?php echo format_money(get_invoice_total_left_to_pay($invoice->id,$invoice->total),$invoice->symbol); ?>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php if(get_option('total_to_words_enabled') == 1){ ?>
                <div class="col-md-12 text-center">
                    <p class="bold"><?php echo  _l('num_word').': '.$this->numberword->convert($invoice->total,$invoice->currency_name); ?></p>
                </div>
                <?php } ?>
                <?php if(!empty($invoice->clientnote)){ ?>
                <div class="col-md-12">
                    <b><?php echo _l('invoice_note'); ?></b><br /><br /><?php echo $invoice->clientnote; ?>
                </div>
                <?php } ?>
                <?php if(!empty($invoice->terms)){ ?>
                <div class="col-md-12">
                    <hr />
                    <b><?php echo _l('terms_and_conditions'); ?></b><br /><br /><?php echo $invoice->terms; ?>
                </div>
                <?php } ?>

                <div class="col-md-12 mtop25">
                    <hr />
                    <?php
                        $total_payments = count($invoice->payments);
                        if($total_payments > 0){ ?>
                    <h4 class="bold"><?php echo _l('invoice_received_payments'); ?></h4>
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th><?php echo _l('invoice_payments_table_number_heading'); ?></th>
                                <th><?php echo _l('invoice_payments_table_mode_heading'); ?></th>
                                <th><?php echo _l('invoice_payments_table_date_heading'); ?></th>
                                <th><?php echo _l('invoice_payments_table_amount_heading'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($invoice->payments as $payment){ ?>
                            <tr>
                                <td>
                                    <span class="pull-left"><?php echo $payment['paymentid']; ?></span>
                                    <?php echo form_open($this->uri->uri_string()); ?>
                                    <button type="submit" value="<?php echo $payment['paymentid']; ?>" class="btn btn-icon btn-default pull-right" name="paymentpdf"><i class="fa fa-file-pdf-o"></i></button>
                                    <?php echo form_close(); ?>
                                </td>
                                <td><?php echo $payment['name']; ?></td>
                                <td><?php echo _d($payment['date']); ?></td>
                                <td><?php echo format_money($payment['amount'],$invoice->symbol); ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                    <?php } else { ?>
                    <h5 class="bold mtop15 pull-left"><?php echo _l('invoice_no_payments_found'); ?></h5>
                    <?php } ?>
                </div>
                <?php
                // No payments for paid and cancelled
                if (($invoice->status != 2 && $invoice->status != 5 && $invoice->total > 0)){ ?>
                <div class="col-md-12">
                    <div class="row">
                        <?php
                        $found_online_mode = false;
                        if(found_invoice_mode($payment_modes,$invoice->id,false)) {
                            $found_online_mode = true;
                         ?>
                        <hr />
                        <div class="col-md-6 text-left">
                            <h4 class="bold"><?php echo _l('invoice_html_online_payment'); ?></h4>
                            <?php echo form_open($this->uri->uri_string(),array('id'=>'online_payment_form')); ?>
                            <?php foreach($payment_modes as $mode){
                                if(!is_numeric($mode['id']) && !empty($mode['id'])) {
                                	if(!is_payment_mode_allowed_for_invoice($mode['id'],$invoice->id)){
                                		continue;
                                	}
                                	?>
                            <div class="radio radio-primary">
                                <input type="radio" value="<?php echo $mode['id']; ?>" id="pm_<?php echo $mode['id']; ?>" name="paymentmode">
                                <label for="pm_<?php echo $mode['id']; ?>"><?php echo $mode['name']; ?></label>
                            </div>
                            <?php if(!empty($mode['description'])){ ?>
                            <div class="mbot15">
                                <?php echo $mode['description']; ?>
                            </div>
                            <?php }
                                }
                                } ?>
                            <div class="form-group">
                                <?php if(get_option('allow_payment_amount_to_be_modified') == 1){ ?>
                                <label for="amount" class="control-label"><?php echo _l('invoice_html_amount'); ?></label>
                                <input type="number" data-total="<?php echo get_invoice_total_left_to_pay($invoice->id,$invoice->total); ?>" name="amount" class="form-control" value="<?php echo get_invoice_total_left_to_pay($invoice->id,$invoice->total); ?>">
                                <?php } else {
                                    echo '<hr />';
                                    echo '<span class="bold">' . _l('invoice_html_total_pay',format_money(get_invoice_total_left_to_pay($invoice->id,$invoice->total),$invoice->symbol)) . '</span>';
                                    } ?>
                            </div>
                            <input type="submit" name="make_payment" class="btn btn-success" value="<?php echo _l('invoice_html_online_payment_button_text'); ?>">
                            <input type="hidden" name="hash" value="<?php echo $hash; ?>">
                            <?php echo form_close(); ?>
                        </div>
                        <?php } ?>
                        <?php if(found_invoice_mode($payment_modes,$invoice->id)) {?>
                        <div class="<?php if($found_online_mode == true){echo 'col-md-6 text-right';}else{echo 'col-md-12';};?>">
                            <h3 class="bold"><?php echo _l('invoice_html_offline_payment'); ?></h3>
                            <?php foreach($payment_modes as $mode){
                                if(is_numeric($mode['id'])) {
                                	if(!is_payment_mode_allowed_for_invoice($mode['id'],$invoice->id)){
                                		continue;
                                	}
                                	?>
                            <p class="bold"><?php echo $mode['name']; ?></p>
                            <?php if(!empty($mode['description'])){ ?>
                            <div class="mbot15">
                                <?php echo $mode['description']; ?>
                            </div>
                            <?php }
                                }
                                } ?>
                        </div>
                        <?php } ?>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
    </div>
</div>
