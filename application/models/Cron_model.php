<?php
defined('BASEPATH') OR exit('No direct script access allowed');
ini_set('memory_limit', '-1');
define('CRON', true);
class Cron_model extends CRM_Model
{
    function __construct()
    {
        parent::__construct();
        $this->load->model('emails_model');
    }
    public function run($manualy = false)
    {
        $last_recurring_invoices_cron = get_option('last_recurring_invoices_cron');
        $last_recurring_expenses_cron = get_option('last_recurring_expenses_cron');
        if ($manualy == true) {
            logActivity('Cron Invoked Manually');
        }
        $this->reminders();
        $this->make_backup_db();
        $this->tasks_reminders();
        $this->proposals();
        $this->goals_notification();
        $this->surveys();
        $this->invoice_overdue_status();
        $this->estimate_expiry_check();
        $this->contracts_expiration_check();
        $this->projects();
        $this->autoclose_tickets();
        $this->events();
        if ((time() > ($last_recurring_invoices_cron + 86400) || $last_recurring_invoices_cron == '') || $manualy == true) {
            $this->recurring_invoices();
            update_option('last_recurring_invoices_cron', time());
        }
        if ((time() > ($last_recurring_expenses_cron + 86400) || $last_recurring_expenses_cron == '') || $manualy == true) {
            $this->recurring_expenses();
            update_option('last_recurring_expenses_cron', time());
        }
        $this->auto_import_imap_tickets();
        $this->check_leads_email_integration();
        update_option('last_cron_run', time());
    }
    private function events()
    {
        $now                 = date('Y-m-d H:i:s');
        $all_notified_events = array();
        // User events
        $this->db->where('start <=', $now);
        $this->db->where('isstartnotified', 0);
        $events         = $this->db->get('tblevents')->result_array();
        $notified_users = array();

        foreach ($events as $event) {
            add_notification(array(
                'description' => 'not_event',
                'touserid' => $event['userid'],
                'fromcompany' => true,
                'additional_data' => serialize(array(
                    $event['title']
                ))
            ));
            $this->db->select('email');
            $this->db->where('staffid',$event['userid']);
            $email = $this->db->get('tblstaff')->row()->email;
            load_admin_language($event['userid']);
            $this->emails_model->send_simple_email($email,_l('not_event',$event['title'] .' - ' . _d($event['start'])),$event['description'].'<br /><br />' . get_option('email_signature'));
            array_push($all_notified_events, $event['eventid']);
            array_push($notified_users, $event['userid']);
        }
        load_admin_language();
        // Show public events

        $this->db->where('start <=', $now);
        $this->db->where('public', 1);
        if (count($notified_users) > 0) {
            $this->db->where('userid NOT IN (' . implode(',', $notified_users) . ')');
        }
        $this->db->where('isstartnotified', 0);
        $events = $this->db->get('tblevents')->result_array();

        $this->load->model('staff_model');
        $staff  = $this->staff_model->get('', 1);

        foreach ($staff as $member) {
            if (is_staff_member($member['staffid'])) {
                foreach ($events as $event) {
                    add_notification(array(
                        'description' => 'not_event_public',
                        'touserid' => $member['staffid'],
                        'fromcompany' => true,
                        'additional_data' => serialize(array(
                            $event['title']
                        ))
                    ));
                load_admin_language($member['staffid']);
                $this->emails_model->send_simple_email($member['email'],_l('not_event',$event['title'] .' - ' . _d($event['start'])),$event['description'].'<br /><br />' . get_option('email_signature'));
                    array_push($all_notified_events, $event['eventid']);
                }
            }
        }
        load_admin_language();
        foreach ($all_notified_events as $id) {
            $this->db->where('eventid', $id);
            $this->db->update('tblevents', array(
                'isstartnotified' => 1
            ));
        }
    }
    private function autoclose_tickets()
    {
        $auto_close_after = get_option('autoclose_tickets_after');
        $this->db->select('ticketid,lastreply,date,userid,contactid');
        $this->db->where('status !=', 5);
        $this->db->where('status !=', 4);
        $this->db->where('status !=', 2);
        $tickets = $this->db->get('tbltickets')->result_array();
        foreach ($tickets as $ticket) {
            $close_ticket = false;
            if (!is_null($ticket['lastreply'])) {
                $last_reply = strtotime($ticket['lastreply']);
                if ($last_reply <= strtotime('-' . $auto_close_after . ' hours')) {
                    $close_ticket = true;
                }
            } else {
                $created = strtotime($ticket['date']);
                if ($created <= strtotime('-' . $auto_close_after . ' hours')) {
                    $close_ticket = true;
                }
            }
            if ($close_ticket == true) {
                $this->db->where('ticketid', $ticket['ticketid']);
                $this->db->update('tbltickets', array(
                    'status' => 5
                ));
                if ($this->db->affected_rows() > 0) {
                    if ($ticket['userid'] != 0 && $ticket['contactid'] != 0) {
                        $email = $this->clients_model->get_contact($ticket['contactid'])->email;
                    } else {
                        $email = $ticket['ticket_email'];
                    }
                    $merge_fields = array();
                    $merge_fields = array_merge($merge_fields, get_ticket_merge_fields('auto-close-ticket', $ticket['ticketid']));
                    $merge_fields = array_merge($merge_fields, get_client_contact_merge_fields($ticket['userid'], $ticket['contactid']));
                    $this->emails_model->send_email_template('auto-close-ticket', $email, $merge_fields, $ticket['ticketid']);
                }
            }
        }
    }
    public function contracts_expiration_check()
    {
        // Contracts
        $this->load->model('staff_model');
        $staff = $this->staff_model->get('', 1);
        $this->db->where('isexpirynotified', 0);
        $this->db->where('dateend is NOT NULL');
        $this->db->where('trash', 0);
        $this->db->where('not_visible_to_client', 0);
        $contracts = $this->db->get('tblcontracts')->result_array();
        $now       = new DateTime(date('Y-m-d'));
        foreach ($contracts as $contract) {
            if ($contract['dateend'] > date('Y-m-d')) {
                $dateend = new DateTime($contract['dateend']);
                $diff    = $dateend->diff($now)->format("%a");
                if ($diff <= get_option('contract_expiration_before')) {
                    $merge_fields = array();
                    $merge_fields = array_merge($merge_fields, get_client_contact_merge_fields($contract['client']));
                    $merge_fields = array_merge($merge_fields, get_contract_merge_fields($contract['id']));
                    foreach ($staff as $member) {
                        if (has_permission('contracts', $member['staffid'], 'create')) {
                            add_notification(array(
                                'description' => 'not_contract_expiry_reminder',
                                'touserid' => $member['staffid'],
                                'fromcompany' => 1,
                                'fromuserid' => NULL,
                                'link' => 'contracts/contract/' . $contract['id'],
                                'additional_data' => serialize(array(
                                    $contract['description']
                                ))
                            ));
                            $this->emails_model->send_email_template('contract-expiration', $member['email'], $merge_fields);
                        }
                    }
                    if ($contract['not_visible_to_client'] == 0) {
                        $contacts = $this->clients_model->get_contacts($contract['client']);
                        foreach ($contacts as $contact) {
                            if (has_contact_permission('contracts', $contact['id'])) {
                                $merge_fields = array();
                                $merge_fields = array_merge($merge_fields, get_client_contact_merge_fields($contract['client'], $contact['id']));
                                $merge_fields = array_merge($merge_fields, get_contract_merge_fields($contract['id']));
                                $this->emails_model->send_email_template('contract-expiration', $contact['email'], $merge_fields);
                            }
                        }
                    }
                    $this->db->where('id', $contract['id']);
                    $this->db->update('tblcontracts', array(
                        'isexpirynotified' => 1
                    ));
                }
            }
        }
    }
    private function recurring_expenses()
    {
        $recurring_expenses = total_rows('tblexpenses', array(
            'recurring' => 1
        ));
        if ($recurring_expenses == 0) {
            return;
        }
        $this->db->where('recurring', 1);
        $recurring_expenses = $this->db->get('tblexpenses')->result_array();
        // Load the necessary models
        $this->load->model('invoices_model');
        $this->load->model('expenses_model');
        $this->load->model('staff_model');
        $_renewals_ids_data = array();
        $total_renewed      = 0;
        foreach ($recurring_expenses as $expense) {
            $type                     = $expense['recurring_type'];
            $repeat_every             = $expense['repeat_every'];
            $last_recurring_date      = $expense['last_recurring_date'];
            $create_invoice_billable  = $expense['create_invoice_billable'];
            $send_invoice_to_customer = $expense['send_invoice_to_customer'];
            $expense_date             = $expense['date'];
            // Current date
            $date                     = new DateTime(date('Y-m-d'));
            // Check if is first recurring
            if (!$last_recurring_date) {
                $last_recurring_date = date('Y-m-d', strtotime($expense_date));
            } else {
                $last_recurring_date = date('Y-m-d', strtotime($last_recurring_date));
            }
            $calculated_date_difference = date('Y-m-d', strtotime('+' . $repeat_every . ' ' . strtoupper($type), strtotime($last_recurring_date)));
            if (date('Y-m-d') > $calculated_date_difference) {
                // Ok we can repeat the expense now
                $new_expense_data['category']       = $expense['category'];
                $new_expense_data['amount']         = $expense['amount'];
                $new_expense_data['tax']            = $expense['tax'];
                $new_expense_data['reference_no']   = $expense['reference_no'];
                $new_expense_data['note']           = $expense['note'];
                $new_expense_data['clientid']       = $expense['clientid'];
                $new_expense_data['billable']       = $expense['billable'];
                $new_expense_data['paymentmode']    = $expense['paymentmode'];
                $new_expense_data['currency']       = $expense['currency'];
                $new_expense_data['date']           = date('Y-m-d');
                $new_expense_data['recurring_from'] = $expense['id'];
                $new_expense_data['dateadded']      = date('Y-m-d H:i:s');
                $new_expense_data['addedfrom']      = $expense['addedfrom'];
                $this->db->insert('tblexpenses', $new_expense_data);
                $insert_id = $this->db->insert_id();
                if ($insert_id) {
                    // Get the old expense custom field and add to the new
                    $custom_fields = get_custom_fields('expenses');
                    foreach ($custom_fields as $field) {
                        $value = get_custom_field_value($expense['id'], $field['id'], 'expenses');
                        if ($value == '') {
                            continue;
                        }
                        $this->db->insert('tblcustomfieldsvalues', array(
                            'relid' => $insert_id,
                            'fieldid' => $field['id'],
                            'fieldto' => 'expenses',
                            'value' => $value
                        ));
                    }
                    $total_renewed++;
                    $this->db->where('id', $expense['id']);
                    $this->db->update('tblexpenses', array(
                        'last_recurring_date' => date('Y-m-d')
                    ));
                    $sent               = false;
                    $created_invoice_id = '';
                    if ($expense['create_invoice_billable'] == 1) {
                        $invoiceid = $this->expenses_model->convert_to_invoice($insert_id);
                        if ($invoiceid) {
                            $created_invoice_id = $invoiceid;
                            if ($expense['send_invoice_to_customer'] == 1) {
                                $sent = $this->invoices_model->sent_invoice_to_client($invoiceid, 'invoice-send-to-client', true);
                            }
                        }
                    }
                    $_renewals_ids_data[] = array(
                        'from' => $expense['id'],
                        'renewed' => $insert_id,
                        'send_invoice_to_customer' => $expense['send_invoice_to_customer'],
                        'create_invoice_billable' => $expense['create_invoice_billable'],
                        'is_sent' => $sent,
                        'created_invoice_id' => $created_invoice_id
                    );
                }
            }
        }
        if ($total_renewed > 0) {
            $date  = _dt(date('Y-m-d H:i:s'));
            // Get all active staff members
            $staff = $this->staff_model->get('', 1);
            foreach ($staff as $member) {
                if (has_permission('expenses', $member['staffid'], 'create')) {
                    $recurring_expenses_email_data = _l('not_recurring_expense_cron_activity_heading') . ' - ' . $date . '<br /><br />';
                    foreach ($_renewals_ids_data as $data) {
                        load_admin_language($member['staffid']);
                        $recurring_expenses_email_data .= _l('not_recurring_expenses_action_taken_from') . ': <a href="' . admin_url('expenses/list_expenses/' . $data['from']) . '">' . $data['from'] . '</a><br />';
                        $recurring_expenses_email_data .= _l('not_expense_renewed') . ' <a href="' . admin_url('expenses/list_expenses/' . $data['renewed']) . '">' . _l('id') . ': ' . $data['renewed'] . '</a>';
                        if ($expense['create_invoice_billable'] == 1) {
                            $recurring_expenses_email_data .= '<br />' . _l('not_invoice_created') . ' ';
                            if (is_numeric($data['created_invoice_id'])) {
                                $recurring_expenses_email_data .= _l('not_invoice_sent_yes');
                                if ($data['send_invoice_to_customer'] == 1) {
                                    if ($data['is_sent']) {
                                        $invoice_sent = 'not_invoice_sent_yes';
                                    } else {
                                        $invoice_sent = 'not_invoice_sent_no';
                                    }
                                    $recurring_expenses_email_data .= '<br />' . _l('not_invoice_sent_to_customer', _l($invoice_sent));
                                }
                            } else {
                                $recurring_expenses_email_data .= _l('not_invoice_sent_no');
                            }
                        }
                        $recurring_expenses_email_data .= '<br /><br />';
                    }
                    $recurring_expenses_email_data .= _l('not_recurring_total_renewed', $total_renewed);
                    $this->emails_model->send_simple_email($member['email'], _l('not_recurring_expense_cron_activity_heading'), $recurring_expenses_email_data);
                    // Add notifications to user
                    add_notification(array(
                        'fromcompany' => 1,
                        'touserid' => $member['staffid'],
                        'description' => $recurring_expenses_email_data
                    ));
                }
            }
            load_admin_language();
        }
    }
    private function recurring_invoices()
    {
        // dont run this function if no recurring invoices exists
        $recurring_invoices = total_rows('tblinvoices', array(
            'recurring >' => 0
        ));
        if ($recurring_invoices == 0) {
            return;
        }
        $invoices_create_invoice_from_recurring_only_on_paid_invoices = get_option('invoices_create_invoice_from_recurring_only_on_paid_invoices');
        $this->load->model('invoices_model');
        $this->db->select('id,recurring,date,last_recurring_date,number,duedate');
        $this->db->from('tblinvoices');
        $this->db->where('recurring !=', 0);
        if ($invoices_create_invoice_from_recurring_only_on_paid_invoices == 1) {
            // Includes all recurring invoices with paid status if this option set to Yes
            $this->db->where('status', 2);
        }
        $invoices           = $this->db->get()->result_array();
        $_renewals_ids_data = array();
        $total_renewed      = 0;
        foreach ($invoices as $invoice) {
            // Current date
            $date = new DateTime(date('Y-m-d'));
            // Check if is first recurring
            if (!$invoice['last_recurring_date']) {
                $last_recurring_date = new DateTime($invoice['date']);
            } else {
                $last_recurring_date = new DateTime($invoice['last_recurring_date']);
            }
            $diff   = $last_recurring_date->diff($date);
            $months = (($diff->format('%y') * 12) + $diff->format('%m'));
            if ($months >= (int) $invoice['recurring']) {
                // Recurring invoice date is okey lets convert it to new invoice
                $_invoice                     = $this->invoices_model->get($invoice['id']);
                $new_invoice_data             = array();
                $new_invoice_data['clientid'] = $_invoice->clientid;
                $new_invoice_data['number']   = get_option('next_invoice_number');
                $new_invoice_data['date']     = _d(date('Y-m-d'));
                if ($_invoice->duedate) {
                    // Now we need to get duedate from the old invoice and calculate the time difference and set new duedate
                    // Ex. if the first invoice had duedate 20 days from now we will add the same duedate date but starting from now
                    $dStart                      = new DateTime($invoice['date']);
                    $dEnd                        = new DateTime($invoice['duedate']);
                    $dDiff                       = $dStart->diff($dEnd);
                    $new_invoice_data['duedate'] = _d(date('Y-m-d', strtotime(date('Y-m-d', strtotime('+' . $dDiff->days . 'DAY')))));
                } else {
                    if (get_option('invoice_due_after') != 0) {
                        $new_invoice_data['duedate'] = _d(date('Y-m-d', strtotime('+' . get_option('invoice_due_after') . ' DAY', strtotime(date('Y-m-d')))));
                    }
                }
                $new_invoice_data['show_quantity_as'] = $_invoice->show_quantity_as;
                $new_invoice_data['currency']         = $_invoice->currency;
                $new_invoice_data['subtotal']         = $_invoice->subtotal;
                $new_invoice_data['total']            = $_invoice->total;
                $new_invoice_data['adjustment']       = $_invoice->adjustment;
                $new_invoice_data['discount_percent'] = $_invoice->discount_percent;
                $new_invoice_data['discount_total']   = $_invoice->discount_total;
                $new_invoice_data['discount_type']    = $_invoice->discount_type;
                $new_invoice_data['terms']            = $_invoice->terms;
                $new_invoice_data['sale_agent']       = $_invoice->sale_agent;
                // Since version 1.0.6
                $new_invoice_data['billing_street']   = $_invoice->billing_street;
                $new_invoice_data['billing_city']     = $_invoice->billing_city;
                $new_invoice_data['billing_state']    = $_invoice->billing_state;
                $new_invoice_data['billing_zip']      = $_invoice->billing_zip;
                $new_invoice_data['billing_country']  = $_invoice->billing_country;
                $new_invoice_data['shipping_street']  = $_invoice->shipping_street;
                $new_invoice_data['shipping_city']    = $_invoice->shipping_city;
                $new_invoice_data['shipping_state']   = $_invoice->shipping_state;
                $new_invoice_data['shipping_zip']     = $_invoice->shipping_zip;
                $new_invoice_data['shipping_country'] = $_invoice->shipping_country;
                if ($_invoice->include_shipping == 1) {
                    $new_invoice_data['include_shipping'] = $_invoice->include_shipping;
                }
                $new_invoice_data['include_shipping']         = $_invoice->include_shipping;
                $new_invoice_data['show_shipping_on_invoice'] = $_invoice->show_shipping_on_invoice;
                // Set to unpaid status automatically
                $new_invoice_data['status']                   = 1;
                $new_invoice_data['clientnote']               = $_invoice->clientnote;
                $new_invoice_data['adminnote']                = 'Recuring invoice created from Cron Job from invoice ' . format_invoice_number($invoice['id']);
                $new_invoice_data['allowed_payment_modes']    = unserialize($_invoice->allowed_payment_modes);
                $new_invoice_data['is_recurring_from']        = $_invoice->id;
                $new_invoice_data['newitems']                 = array();
                $key                                          = 1;
                foreach ($_invoice->items as $item) {
                    $new_invoice_data['newitems'][$key]['description']      = $item['description'];
                    $new_invoice_data['newitems'][$key]['long_description'] = $item['long_description'];
                    $new_invoice_data['newitems'][$key]['qty']              = $item['qty'];
                    $new_invoice_data['newitems'][$key]['taxname']          = array();
                    $taxes                                                  = get_invoice_item_taxes($item['id']);
                    foreach ($taxes as $tax) {
                        // tax name is in format TAX1|10.00
                        array_push($new_invoice_data['newitems'][$key]['taxname'], $tax['taxname']);
                    }
                    $new_invoice_data['newitems'][$key]['rate']  = $item['rate'];
                    $new_invoice_data['newitems'][$key]['order'] = $item['item_order'];
                    $key++;
                }
                $id = $this->invoices_model->add($new_invoice_data);
                if ($id) {
                    $this->db->where('id', $id);
                    $this->db->update('tblinvoices', array(
                        'addedfrom' => $_invoice->addedfrom,
                        'sale_agent' => $_invoice->sale_agent
                    ));
                    // Get the old expense custom field and add to the new
                    $custom_fields = get_custom_fields('invoice');
                    foreach ($custom_fields as $field) {
                        $value = get_custom_field_value($invoice['id'], $field['id'], 'invoice');
                        if ($value == '') {
                            continue;
                        }
                        $this->db->insert('tblcustomfieldsvalues', array(
                            'relid' => $id,
                            'fieldid' => $field['id'],
                            'fieldto' => 'invoice',
                            'value' => $value
                        ));
                    }
                    // Increment total renewed invoices
                    $total_renewed++;
                    // Update last recurring date to this invoice
                    $this->db->where('id', $invoice['id']);
                    $this->db->update('tblinvoices', array(
                        'last_recurring_date' => date('Y-m-d')
                    ));
                    // Used for email to determins is sent to email or not.
                    if (get_option('send_renewed_invoice_from_recurring_to_email') == 1) {
                        $this->invoices_model->sent_invoice_to_client($id, 'invoice-send-to-client', true);
                    }
                    $_renewals_ids_data[] = array(
                        'from' => $invoice['id'],
                        'renewed' => $id
                    );
                }
            }
        }
        if ($total_renewed > 0) {
            $date = _dt(date('Y-m-d H:i:s'));
            $this->load->model('staff_model');
            // Get all active staff members
            $staff = $this->staff_model->get('', 1);
            foreach ($staff as $member) {
                if (has_permission('invoices', $member['staffid'], 'create')) {
                    load_admin_language($member['staffid']);
                    $recurring_invoices_email_data = _l('not_recurring_invoices_cron_activity_heading') . ' - ' . $date . '<br /><br />';
                    foreach ($_renewals_ids_data as $renewed_invoice_data) {
                        $recurring_invoices_email_data .= _l('not_action_taken_from_recurring_invoice') . ' <a href="' . admin_url('invoices/list_invoices/' . $renewed_invoice_data['from']) . '">' . format_invoice_number($renewed_invoice_data['from']) . '</a><br />';
                        $recurring_invoices_email_data .= _l('not_invoice_renewed') . ' <a href="' . admin_url('invoices/list_invoices/' . $renewed_invoice_data['renewed']) . '">' . format_invoice_number($renewed_invoice_data['renewed']) . '</a><br /><br />';
                    }
                    $recurring_invoices_email_data .= _l('not_recurring_total_renewed', $total_renewed);
                    $this->emails_model->send_simple_email($member['email'], _l('not_recurring_invoices_cron_activity_heading'), $recurring_invoices_email_data);
                    // Add notifications to user
                    add_notification(array(
                        'fromcompany' => 1,
                        'touserid' => $member['staffid'],
                        'description' => $recurring_invoices_email_data
                    ));
                }
            }
            load_admin_language();
        }
    }
    private function tasks_reminders()
    {
        $reminder_before = get_option('tasks_reminder_notification_before');
        $this->load->model('tasks_model');
        $this->db->where('finished', 0);
        $this->db->where('duedate IS NOT NULL');
        $this->db->where('deadline_notified', 0);
        $tasks = $this->db->get('tblstafftasks')->result_array();
        $now   = new DateTime(date('Y-m-d'));
        foreach ($tasks as $task) {
            if (date('Y-m-d', strtotime($task['duedate'])) > date('Y-m-d')) {
                $duedate                 = new DateTime($task['duedate']);
                $diff                    = $duedate->diff($now)->format("%a");
                // Check if difference between start date and duedate is the same like the reminder before
                // In this case reminder wont be sent becuase the task it too short
                $start_date              = strtotime($task['startdate']);
                $duedate                 = strtotime($task['duedate']);
                $start_and_due_date_diff = $duedate - $start_date;
                $start_and_due_date_diff = floor($start_and_due_date_diff / (60 * 60 * 24));
                if ($diff <= $reminder_before && $start_and_due_date_diff > $reminder_before) {
                    $assignees = $this->tasks_model->get_task_assignees($task['id']);
                    foreach ($assignees as $member) {
                        $this->db->select('email');
                        $this->db->where('staffid', $member['assigneeid']);
                        $row = $this->db->get('tblstaff')->row();
                        if ($row) {
                            add_notification(array(
                                'description' => 'not_task_deadline_reminder',
                                'touserid' => $member['assigneeid'],
                                'fromcompany' => 1,
                                'fromuserid' => NULL,
                                'link' => 'tasks/list_tasks/' . $task['id'],
                                'additional_data' => serialize(array(
                                    $task['name']
                                ))
                            ));
                            $merge_fields = array();
                            $merge_fields = array_merge($merge_fields, get_staff_merge_fields($member['assigneeid']));
                            $merge_fields = array_merge($merge_fields, get_task_merge_fields($task['id']));
                            $this->emails_model->send_email_template('task-deadline-notification', $row->email, $merge_fields);
                            $this->db->where('id', $task['id']);
                            $this->db->update('tblstafftasks', array(
                                'deadline_notified' => 1
                            ));
                        }
                    }
                }
            }
        }
    }
    private function surveys()
    {
        $last_survey_cron = get_option('last_survey_send_cron');
        if (time() > ($last_survey_cron + 3600)) {
            $found_emails = $this->db->count_all_results('tblsurveysemailsendcron');
            if ($found_emails > 0) {
                $total_emails_per_cron = get_option('survey_send_emails_per_cron_run');
                // Initialize mail library
                $this->email->initialize();
                $this->load->library('email');
                // Load survey model
                $this->load->model('surveys_model');
                // Get all surveys send log where sending emails is not finished
                $this->db->where('iscronfinished', 0);
                $unfinished_surveys_send_log = $this->db->get('tblsurveysendlog')->result_array();
                foreach ($unfinished_surveys_send_log as $_survey) {
                    $surveyid = $_survey['surveyid'];
                    // Get survey emails that has been not sent yet.
                    $this->db->where('surveyid', $surveyid);
                    $this->db->limit($total_emails_per_cron);
                    $emails = $this->db->get('tblsurveysemailsendcron')->result_array();
                    $survey = $this->surveys_model->get($surveyid);
                    if ($survey->fromname == '' || $survey->fromname == NULL) {
                        $survey->fromname = get_option('companyname');
                    }
                    if (stripos($survey->description, '{survey_link}') !== false) {
                        $survey->description = str_ireplace('{survey_link}', '<a href="' . site_url('survey/' . $survey->surveyid . '/' . $survey->hash) . '" target="_blank">' . $survey->subject . '</a>', $survey->description);
                    }
                    $total = 0;
                    foreach ($emails as $data) {
                        if (isset($data['emailid']) && isset($data['listid'])) {
                            $customfields = $this->surveys_model->get_list_custom_fields($data['listid']);
                            foreach ($customfields as $custom_field) {
                                $value                     = $this->surveys_model->get_email_custom_field_value($data['emailid'], $data['listid'], $custom_field['customfieldid']);
                                $custom_field['fieldslug'] = '{' . $custom_field['fieldslug'] . '}';
                                if ($value != '') {
                                    if (stripos($survey->description, $custom_field['fieldslug']) !== false) {
                                        $survey->description = str_ireplace($custom_field['fieldslug'], $value, $survey->description);
                                    }
                                }
                            }
                        }
                        $this->email->clear();
                        $this->email->from(get_option('smtp_email'), $survey->fromname);
                        $this->email->to($data['email']);
                        $this->email->subject($survey->subject);
                        $this->email->message($survey->description);
                        if ($this->email->send()) {
                            $total++;
                            $this->db->where('id', $data['id']);
                            $this->db->delete('tblsurveysemailsendcron');
                        }
                    }
                    // Update survey send log
                    $this->db->where('id', $_survey['id']);
                    $this->db->update('tblsurveysendlog', array(
                        'total' => $total
                    ));
                    // Check if all emails send
                    $this->db->where('surveyid', $surveyid);
                    $found_emails = $this->db->count_all_results('tblsurveysemailsendcron');
                    if ($found_emails == 0) {
                        // Update that survey send is finished
                        $this->db->where('id', $_survey['id']);
                        $this->db->update('tblsurveysendlog', array(
                            'iscronfinished' => 1
                        ));
                    }
                }
            }
            update_option('last_survey_send_cron', time());
        }
    }

    private function reminders()
    {
        // Customer reminders
        $this->db->where('isnotified', 0);
        $reminders = $this->db->get('tblreminders')->result_array();
        if (count($reminders) > 0) {
            $this->load->model('staff_model');
            $this->load->model('leads_model');
            $this->load->model('proposals_model');
        }
        $description = '';
        foreach ($reminders as $reminder) {
            if (date('Y-m-d H:i:s') >= $reminder['date']) {
                $staff                = $this->staff_model->get($reminder['staff']);
                load_admin_language($reminder['staff']);
                $_rel_type_translated = '';
                if ($reminder['rel_type'] == 'customer') {
                    $_rel_type_translated = _l('client_lowercase');
                    $client               = $this->clients_model->get($reminder['rel_id']);
                    $not_link             = 'clients/client/' . $client->userid;
                } else if ($reminder['rel_type'] == 'lead') {
                    $_rel_type_translated = _l('lead_lowercase');
                    $lead                 = $this->leads_model->get($reminder['rel_id']);
                    $not_link             = 'leads/index/' . $lead->id;
                } else if ($reminder['rel_type'] == 'estimate') {
                    $_rel_type_translated = _l('estimate_lowercase');
                    $not_link             = 'estimates/list_estimates/' . $reminder['rel_id'];
                } else if ($reminder['rel_type'] == 'invoice') {
                    $not_link             = 'invoices/list_invoices/' . $reminder['rel_id'];
                    $_rel_type_translated = _l('invoice_lowercase');
                } else if ($reminder['rel_type'] == 'proposal') {
                    $not_link             = 'proposals/list_proposals/' . $reminder['rel_id'];
                    $proposal             = $this->proposals_model->get($reminder['rel_id']);
                    $_rel_type_translated = _l('proposal_lowercase');
                }
                $description .= '<br /><br />' . $reminder['description'];
                add_notification(array(
                    'fromcompany' => true,
                    'touserid' => $reminder['staff'],
                    'description' => 'not_new_reminder_for',
                    'link' => $not_link,
                    'additional_data' => serialize(array(
                        $_rel_type_translated . ' - ' . $reminder['description']
                    ))
                ));
                if ($reminder['notify_by_email'] == 1) {
                    $this->emails_model->send_simple_email($staff->email, _l('not_new_reminder_for', $_rel_type_translated), $description . '<br /><br />' . get_option('email_signature'));
                }
                $this->db->where('id', $reminder['id']);
                $this->db->update('tblreminders', array(
                    'isnotified' => 1
                ));
            }
        }
        load_admin_language();
    }
    private function invoice_overdue_status()
    {
        $this->load->model('invoices_model');
        $send_invoice_overdue_reminder = get_option('cron_send_invoice_overdue_reminder');
        $this->db->select('id,date,status,last_overdue_reminder');
        $this->db->from('tblinvoices');
        $this->db->where('status !=', 2); // We dont need paid status
        $this->db->where('status !=', 5); // We dont need cancelled status
        $invoices = $this->db->get()->result_array();
        foreach ($invoices as $invoice) {
            if ($invoice['status'] == '4') {
                if ($send_invoice_overdue_reminder == '1') {
                    $now = time();
                    // Check if already sent invoice reminder
                    if ($invoice['last_overdue_reminder']) {
                        // We already have sent reminder, check for resending
                        $resend_days = get_option('automatically_resend_invoice_overdue_reminder_after');
                        // If resend_days from options is 0 means that the admin dont want to resend the mails.
                        if ($resend_days != 0) {
                            $datediff  = $now - strtotime($invoice['last_overdue_reminder']);
                            $days_diff = floor($datediff / (60 * 60 * 24));
                            if ($days_diff >= $resend_days) {
                                $this->invoices_model->send_invoice_overdue_notice($invoice['id']);
                            }
                        }
                    } else {
                        $datediff  = $now - strtotime($invoice['date']);
                        $days_diff = floor($datediff / (60 * 60 * 24));
                        if ($days_diff >= get_option('automatically_send_invoice_overdue_reminder_after')) {
                            $this->invoices_model->send_invoice_overdue_notice($invoice['id']);
                        }
                    }
                }
            } else {
                $statusid = update_invoice_status($invoice['id']);
                if ($statusid == '4') {
                    if ($send_invoice_overdue_reminder == '1') {
                        $this->invoices_model->send_invoice_overdue_notice($invoice['id']);
                    }
                }
            }
        }
    }
    private function projects()
    {
        $this->load->model('projects_model');
        $this->db->where('status', 2);
        $projects = $this->db->get('tblprojects')->result_array();
        foreach ($projects as $project) {
            $this->db->where('project_id', $project['id']);
            $this->db->order_by('id', 'desc');
            $this->db->limit(1);
            $activity = $this->db->get('tblprojectactivity')->row();
            // Close project if not activity for 1 week and all tasks is finished
            if (strtotime($activity->dateadded) < strtotime('-1 WEEK')) {
                if (total_rows('tblstafftasks', array(
                    'rel_id' => $project['id'],
                    'rel_type' => 'project'
                )) > 0) {
                    $percent = $this->projects_model->calc_progress($project['id']);
                    if ($percent >= 100) {
                        $this->db->where('id', $project['id']);
                        $this->db->update('tblprojects', array(
                            'status' => 4
                        ));
                        if ($this->db->affected_rows() > 0) {
                            $this->projects_model->log_activity($project['id'], 'project_activity_status_updated', '<lang>project_status_4</lang>', 0);
                        }
                    }
                }
            }
        }
    }
    public function proposals(){
            // Open
            $this->db->where('status',1);
            // Sent
            $this->db->or_where('status',4);
            $proposals = $this->db->get('tblproposals')->result_array();
            $now   = new DateTime(date('Y-m-d'));
            $this->load->model('proposals_model');
            foreach($proposals as $proposal){
               if ($proposal['open_till'] != NULL) {
                if (date('Y-m-d') < $proposal['open_till']) {
                         if(get_option('proposal_expiry_reminder_enabled') == 1){
                            $reminder_before = get_option('send_proposal_expiry_reminder_before');
                            $open_till              = new DateTime($proposal['open_till']);
                            $diff                    = $open_till->diff($now)->format("%a");
                            $date                    = strtotime($proposal['date']);
                            $open_till              = strtotime($proposal['open_till']);
                            $date_and_due_date_diff = $open_till - $date;
                            $date_and_due_date_diff = floor($date_and_due_date_diff / (60 * 60 * 24));
                            if ($diff <= $reminder_before && $date_and_due_date_diff > $reminder_before) {
                                if($proposal['is_expiry_notified'] == 0){
                                 if(!$this->proposals_model->send_expiry_reminder($proposal['id'])){
                                    logActivity('[CRON] Failed to send proposal expiry reminder: '.$proposal['subject'],null);
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    private function estimate_expiry_check()
    {
        $this->db->select('id,expirydate,status,is_expiry_notified,date');
        $this->db->from('tblestimates');
        // Only get sent estimates
        $this->db->where('status', 2);
        $estimates = $this->db->get()->result_array();
        $this->load->model('estimates_model');
        $now   = new DateTime(date('Y-m-d'));
        foreach ($estimates as $estimate) {
            if ($estimate['expirydate'] != NULL) {
                if (date('Y-m-d') > $estimate['expirydate']) {
                    $this->db->where('id', $estimate['id']);
                    $this->db->update('tblestimates', array(
                        'status' => 5
                        ));
                    if ($this->db->affected_rows() > 0) {
                        $additional_activity = serialize(array(
                            '<original_status>' . $estimate['status'] . '</original_status>',
                            '<new_status>5</new_status>'
                            ));
                        $this->estimates_model->log_estimate_activity($estimate['id'], 'not_estimate_status_updated', false, $additional_activity);
                    }
                } else {
                    if(get_option('estimate_expiry_reminder_enabled') == 1){
                        $reminder_before = get_option('send_estimate_expiry_reminder_before');
                        $expirydate              = new DateTime($estimate['expirydate']);
                        $diff                    = $expirydate->diff($now)->format("%a");
                        $date                    = strtotime($estimate['date']);
                        $expirydate              = strtotime($estimate['expirydate']);
                        $date_and_due_date_diff = $expirydate - $date;
                        $date_and_due_date_diff = floor($date_and_due_date_diff / (60 * 60 * 24));
                        if ($diff <= $reminder_before && $date_and_due_date_diff > $reminder_before) {
                            if($estimate['is_expiry_notified'] == 0){
                             if(!$this->estimates_model->send_expiry_reminder($estimate['id'])){
                               logActivity('[CRON] Failed to send estimate expiry reminder: '.format_estimate_number($estimate['id']),null);
                            }
                        }
                    }
                }
            }
        }
    }
}
    public function goals_notification()
    {
        $this->load->model('goals_model');
        $goals = $this->goals_model->get('', true);
        foreach ($goals as $goal) {
            $achievement = $this->goals_model->calculate_goal_achievement($goal['id']);
            if ($achievement['percent'] >= 100) {
                if ($goal['notify_when_achieve'] == 1) {
                    if ((date('Y-m-d') == $goal['end_date']) || date('Y-m-d') >= $goal['end_date']) {
                        $this->goals_model->notify_staff_members($goal['id'], 'success', $achievement);
                    }
                }
            } else {
                // not yet achieved, check for end date
                if ($goal['notify_when_fail'] == 1) {
                    if (date('Y-m-d') > $goal['end_date']) {
                        $this->goals_model->notify_staff_members($goal['id'], 'failed', $achievement);
                    }
                }
            }
        }
    }
    public function check_leads_email_integration()
    {
        $this->load->model('leads_model');
        $mail = $this->leads_model->get_email_integration();
        if ($mail->active == 0) {
            return false;
        }
        require_once(APPPATH . 'third_party/php-imap/Imap.php');
        if (empty($mail->last_run) || (time() > $mail->last_run + ($mail->check_every * 60))) {
            $this->db->where('id', 1);
            $this->db->update('tblleadsemailintegration', array(
                'last_run' => time()
            ));
            $ps = $this->encryption->decrypt($mail->password);
            if (!$ps) {
                logActivity('[CRON] Failed to decrypt email integration password', null);
                return false;
            }
            $mailbox    = $mail->imap_server;
            $username   = $mail->email;
            $password   = $ps;
            $encryption = $mail->encryption;
            // open connection
            $imap       = new Imap($mailbox, $username, $password, $encryption);
            if ($imap->isConnected() === false) {
                logActivity('[CRON] Failed to connect to IMAP lead email integration - Set option to false if you dont use it.', null);
                return false;
            }
            if ($mail->folder == '') {
                $mail->folder = 'INBOX';
            }
            $imap->selectFolder($mail->folder);
            if ($mail->only_loop_on_unseen_emails == 1) {
                $emails = $imap->getUnreadMessages();
            } else {
                $emails = $imap->getMessages();
            }
            foreach ($emails as $email) {
                $from      = $email['from'];
                $fromname  = preg_replace("/(.*)<(.*)>/", "\\1", $from);
                $fromname  = trim(str_replace("\"", "", $fromname));
                $fromemail = trim(preg_replace("/(.*)<(.*)>/", "\\2", $from));
                // Okey everything good now let make some statements
                // Check if this email exists in customers table first
                if (total_rows('tblcontacts', array(
                    'email' => $fromemail
                )) > 0) {
                    // Set message to seen to in the next time we dont need to loop over this message
                    $imap->setUnseenMessage($email['uid']);
                    // Exists no need to do anything
                    continue;
                } else {
                    // Not exists its okey.
                    // Now we need to check the leads table
                    $this->db->where('email', $fromemail);
                    $lead = $this->db->get('tblleads')->row();
                    if ($lead) {
                        // Check if the lead uid is the same with the email uid
                        if ($lead->email_integration_uid == $email['uid']) {
                            // Set message to seen to in the next time we dont need to loop over this message
                            $imap->setUnseenMessage($email['uid']);
                            continue;
                        }
                        // Check if this uid exists in the emails data log table
                        $this->db->where('emailid', $email['uid']);
                        $exists_in_emails = $this->db->count_all_results('tblleadsemailintegrationemails');
                        if ($exists_in_emails > 0) {
                            // Set message to seen to in the next time we dont need to loop over this message
                            $imap->setUnseenMessage($email['uid']);
                            continue;
                        }
                        // We dont need the junk leads
                        if ($lead->junk == 1) {
                            // Set message to seen to in the next time we dont need to loop over this message
                            $imap->setUnseenMessage($email['uid']);
                            continue;
                        }
                        // More the one time email from this lead, insert into the lead emails log table
                        $this->db->insert('tblleadsemailintegrationemails', array(
                            'leadid' => $lead->id,
                            'subject' => trim($email['subject']),
                            'body' => nl2br(_strip_tags(trim($email['body']))),
                            'dateadded' => date('Y-m-d H:i:s'),
                            'emailid' => $email['uid']
                        ));
                        // Set message to seen to in the next time we dont need to loop over this message
                        $imap->setUnseenMessage($email['uid']);
                        $this->_notification_lead_email_integration('not_received_one_or_more_messages_lead', $mail, $lead->id);
                        $this->_check_lead_email_integration_attachments($email, $lead->id, $imap);
                        // Exists not need to do anything except to add the email
                        continue;
                    }
                    // Lets insert into the leads table
                    $lead_data = array(
                        'name' => $fromname,
                        'assigned' => $mail->responsible,
                        'dateadded' => date('Y-m-d H:i:s'),
                        'status' => $mail->lead_status,
                        'source' => $mail->lead_source,
                        'addedfrom' => 0,
                        'email' => $fromemail,
                        'is_imported_from_email_integration' => 1,
                        'email_integration_uid' => $email['uid'],
                        'lastcontact' => NULL
                    );
                    $this->db->insert('tblleads', $lead_data);
                    $insert_id = $this->db->insert_id();
                    if ($insert_id) {
                        $this->db->insert('tblleadsemailintegrationemails', array(
                            'leadid' => $insert_id,
                            'subject' => trim($email['subject']),
                            'body' => _strip_tags(trim($email['body'])),
                            'dateadded' => date('Y-m-d H:i:s'),
                            'emailid' => $email['uid']
                        ));
                        if ($mail->delete_after_import == 1) {
                            $imap->deleteMessage($email['uid']);
                        } else {
                            $imap->setUnseenMessage($email['uid']);
                        }
                        // Set message to seen to in the next time we dont need to loop over this message
                        $this->_notification_lead_email_integration('not_received_lead_imported_email_integration', $mail, $insert_id);
                        $this->leads_model->log_lead_activity($insert_id, 'not_received_lead_imported_email_integration', true);
                        $this->_check_lead_email_integration_attachments($email, $insert_id, $imap);
                    }
                }
            }
        }
    }
    public function auto_import_imap_tickets()
    {
        $this->db->select('host,encryption,password,email,delete_after_import')->from('tbldepartments')->where('host !=', '')->where('password !=', '')->where('email !=', '');
        $dep_emails = $this->db->get()->result_array();
        foreach ($dep_emails as $e) {
            $password = $this->encryption->decrypt($e['password']);
            if (!$password) {
                logActivity('[CRON] Failed to decrypt department password', null);
                continue;
            }
            require_once(APPPATH . 'third_party/php-imap/Imap.php');
            $mailbox    = $e['host'];
            $username   = $e['email'];
            $password   = $password;
            $encryption = $e['encryption'];
            // open connection
            $imap       = new Imap($mailbox, $username, $password, $encryption);
            if ($imap->isConnected() === false) {
                logActivity('[CRON] Failed to connect to IMAP auto importing tickets from departments.', null);
                return false;
            }
            $imap->selectFolder('INBOX');
            $emails = $imap->getUnreadMessages();
            $this->load->model('tickets_model');
            foreach ($emails as $email) {
                // Check if empty body
                if (isset($email['body']) && $email['body'] == '' || !isset($email['body'])) {
                    $email['body'] = 'No message found';
                }
                // Trim message
                $email['body'] = trim($email['body']);
                $email['body'] = str_replace("&nbsp;", " ",$email['body']);
                // Remove html tags - strips inline styles also
                $email['body'] = trim(strip_html_tags($email['body'],"<br/>,<br>"));
                // Remove duplicate new lines
                $email['body'] = preg_replace("/[\r\n]+/", "\n", $email['body']);
                // new lines with <br />
                $email['body'] = preg_replace('/\n(\s*\n)+/', '<br />', $email['body']);
                $email['body'] = preg_replace('/\n/', '<br>', $email['body']);
                if (isset($email['attachments'])) {
                    foreach ($email['attachments'] as $key => $at) {
                        $_at_name = $email['attachments'][$key]['name'];
                        // Rename the name to filename the model expects filename not name
                        unset($email['attachments'][$key]['name']);
                        $email['attachments'][$key]['filename'] = $_at_name;
                        $_attachment                            = $imap->getAttachment($email['uid'], $key);
                        $email['attachments'][$key]['data']     = $_attachment['content'];
                    }
                    // Add the attchments to data
                    $data['attachments'] = $email['attachments'];
                } else {
                    // No attachments
                    $data['attachments'] = array();
                }

                $data['subject']  = $email['subject'];
                $data['body']     = $email['body'];
                $data['email']    = preg_replace("/(.*)<(.*)>/", "\\2", $email['from']);
                $data['fromname'] = preg_replace("/(.*)<(.*)>/", "\\1", $email['from']);
                $data['fromname'] = str_replace("\"", "", $data['fromname']);
                // To is the department name
                $data['to']       = $e['email'];
                $status           = $this->tickets_model->insert_piped_ticket($data);
                if ($status == 'Ticket Imported Successfully' || $status == 'Ticket Reply Imported Successfully') {
                    if ($e['delete_after_import'] == 0) {
                        $imap->setUnseenMessage($email['uid']);
                    } else {
                        $imap->deleteMessage($email['uid']);
                    }
                } else {
                    // Set unseen message in all cases to prevent looping throught the message again
                    $imap->setUnseenMessage($email['uid']);
                }
            }
        }
    }
    public function make_backup_db($manual = false)
    {
        if ((get_option('auto_backup_enabled') == "1" && time() > (get_option('last_auto_backup') + get_option('auto_backup_every') * 24 * 60 * 60)) || $manual == true) {
            $this->load->dbutil();
            $prefs       = array(
                'format' => 'zip',
                'filename' => date("Y-m-d-H-i-s") . '_backup.sql'
            );
            $backup      = $this->dbutil->backup($prefs);
            $backup_name = 'database_backup_' . date("Y-m-d-H-i-s") . '.zip';
            $backup_name = unique_filename(BACKUPS_FOLDER, $backup_name);
            $save        = BACKUPS_FOLDER . $backup_name;
            $this->load->helper('file');
            if (write_file($save, $backup)) {
                if ($manual == false) {
                    logActivity('[CRON] Database Backup [' . $backup_name . ']', null);
                    update_option('last_auto_backup', time());
                } else {
                    logActivity('Database Backup [' . $backup_name . ']');
                }
                return true;
            }
        }
        return false;
    }
    private function _notification_lead_email_integration($description, $mail, $leadid)
    {
        if (!empty($mail->notify_type)) {
            $ids = unserialize($mail->notify_ids);
            if (!is_array($ids) || count($ids) == 0) {
                return;
            }
            if ($mail->notify_type == 'specific_staff') {
                $field = 'staffid';
            } else if ($mail->notify_type == 'roles') {
                $field = 'role';
            } else {
                return;
            }
            $this->db->where('active', 1);
            $this->db->where_in($field, $ids);
            $staff = $this->db->get('tblstaff')->result_array();
            foreach ($staff as $member) {
                add_notification(array(
                    'description' => $description,
                    'touserid' => $member['staffid'],
                    'fromcompany' => 1,
                    'fromuserid' => NULL,
                    'link' => 'leads/index/' . $leadid
                ));
            }
        }
    }
    private function _check_lead_email_integration_attachments($email, $leadid, &$imap)
    {
        // Check for any attachments
        if (isset($email['attachments'])) {
            foreach ($email['attachments'] as $key => $attachment) {
                $_attachment = $imap->getAttachment($email['uid'], $key);
                $path        = LEAD_ATTACHMENTS_FOLDER . $leadid . '/';
                $file_name   = unique_filename($path, $attachment['name']);
                if (!file_exists($path)) {
                    mkdir($path);
                    fopen($path . 'index.html', 'w');
                }
                $path = $path . $file_name;
                $fp   = fopen($path, "w+");
                if (fwrite($fp, $_attachment['content'])) {
                    $this->db->insert('tblleadattachments', array(
                        'leadid' => $leadid,
                        'file_name' => $file_name,
                        'addedfrom' => 0,
                        'dateadded' => date('Y-m-d H:i:s')
                    ));
                    $attachment_id = $this->db->insert_id();
                    if ($attachment_id) {
                        $this->leads_model->log_lead_activity($leadid, 'not_lead_imported_attachment', true);
                        $file_type = get_mime_by_extension($attachment['name']);
                        // update the file type
                        $this->db->where('id', $attachment_id);
                        $this->db->update('tblleadattachments', array(
                            'filetype' => $file_type
                        ));
                    }
                }
                fclose($fp);
            }
        }
    }
}
