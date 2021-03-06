<div class="row">
<?php if(isset($currencies)){
   $col = 'col-md-5ths col-xs-12 ';
   ?>
<div class="<?php echo $col; ?> stats-total-currency">
   <div class="panel_s">
      <div class="panel-body">
         <select class="selectpicker" name="estimate_total_currency" onchange="init_invoices_total();" data-width="100%" data-none-selected-text="<?php echo _l('dropdown_non_selected_tex'); ?>">
            <?php foreach($currencies as $currency){
               $selected = '';
               if(!$this->input->post('currency')){
                 if($currency['isdefault'] == 1 || isset($_currency) && $_currency == $currency['id']){
                   $selected = 'selected';
                 }
               } else {
                 if($this->input->post('currency') == $currency['id']){
                  $selected = 'selected';
                }
               }
               ?>
            <option value="<?php echo $currency['id']; ?>" <?php echo $selected; ?> data-subtext="<?php echo $currency['name']; ?>"><?php echo $currency['symbol']; ?></option>
            <?php } ?>
         </select>
      </div>
   </div>
</div>
<?php
   } else {
    $col = 'col-md-3 col-xs-12 ';
   }

   ?>
<?php foreach($totals as $status => $data){
   if($status == 0){
     $_status_lang = 'invoice_status_unpaid';
     $desc_class = 'text-danger';
   } else if ($status == 1){
     $_status_lang = 'invoice_status_paid';
     $desc_class = 'text-success';
   } else if ($status == 2){
     $_status_lang = 'invoice_status_not_paid_completely';
     $desc_class = 'text-warning';
   } else if ($status == 3){
     $_status_lang = 'invoice_status_overdue';
     $desc_class = 'text-danger';
   }
   ?>
<div class="<?php echo $col; ?>total-column">
   <div class="panel_s">
      <div class="panel-body">
         <h3 class="text-muted _total">
            <?php echo format_money($data['total'],$data['symbol']); ?>
         </h3>
         <span class="<?php echo $desc_class; ?>"><?php echo _l($_status_lang); ?></span>
      </div>
   </div>
</div>
<?php } ?>
</div>
<div class="clearfix"></div>
<script>
   init_selectpicker();
</script>
