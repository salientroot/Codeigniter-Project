<?php
defined('BASEPATH') OR exit('No direct script access allowed');
ini_set('memory_limit', '-1');
// set max execution time 2 hours / mostly used for exporting PDF
ini_set('max_execution_time', 3600);
class Utilities extends Admin_controller
{
    public $pdf_zip;
    function __construct()
    {
        parent::__construct();
        $this->load->model('utilities_model');
    }
    /* All perfex activity log */
    public function activity_log()
    {
        // Only full admin have permission to activity log
        if (!is_admin()) {
            access_denied('activityLog');
        }
        if ($this->input->is_ajax_request()) {
            $this->perfex_base->get_table_data('activity_log');
        }
        $data['title'] = _l('utility_activity_log');
        $this->load->view('admin/utilities/activity_log', $data);
    }
    /* All perfex activity log */
    public function pipe_log()
    {
        // Only full admin have permission to activity log
        if (!is_admin()) {
            access_denied('Ticket Pipe Log');
        }
        if ($this->input->is_ajax_request()) {
            $this->perfex_base->get_table_data('ticket_pipe_log');
        }
        $data['title'] = _l('ticket_pipe_log');
        $this->load->view('admin/utilities/ticket_pipe_log', $data);
    }
    public function clear_activity_log(){
         if (!is_admin()) {
            access_denied('Clear activity log');
        }
        $this->db->empty_table('tblactivitylog');
        redirect(admin_url('utilities/activity_log'));
    }
    public function clear_pipe_log(){
        if (!is_admin()) {
            access_denied('Clear ticket pipe activity log');
        }
        $this->db->empty_table('tblticketpipelog');
        redirect(admin_url('utilities/pipe_log'));
    }
    /* Calendar functions */
    public function calendar()
    {
        if ($this->input->post() && $this->input->is_ajax_request()) {
            $data    = $this->input->post();
            $success = $this->utilities_model->event($data);
            $message = '';
            if ($success) {
                if (isset($data['eventid'])) {
                    $message = _l('event_updated');
                } else {
                    $message = _l('utility_calendar_event_added_successfuly');
                }
            }
            echo json_encode(array(
                'success' => $success,
                'message' => $message
            ));
            die();
        }
        $data['google_ids_calendars'] = $this->misc_model->get_google_calendar_ids();
        $data['google_calendar_api']  = get_option('google_calendar_api_key');
        $data['title']                = _l('calendar');
        // To load js files
        $data['calendar_assets']      = true;
        $this->load->view('admin/utilities/calendar', $data);
    }
    public function view_event($id)
    {
        $data['event'] = $this->utilities_model->get_event($id);
        $this->load->view('admin/utilities/event', $data);
    }
    public function get_calendar_data()
    {
        if ($this->input->is_ajax_request()) {
            echo json_encode($this->utilities_model->get_calendar_data());
            die();
        }
    }
    public function delete_event($id)
    {
        if ($this->input->is_ajax_request()) {
            $event = $this->utilities_model->get_event_by_id($id);
            if ($event->userid != get_staff_user_id() && !is_admin()) {
                echo json_encode(array(
                    'success' => false
                ));
                die;
            }
            $success = $this->utilities_model->delete_event($id);
            $message = '';
            if ($success) {
                $message = _l('utility_calendar_event_deleted_successfuly');
            }
            echo json_encode(array(
                'success' => $success,
                'message' => $message
            ));
            die();
        }
    }
    // Moves here from version 1.0.5
    public function media()
    {
        $data['media_assets'] = true;
        $data['title']        = _l('media_files');
        $this->load->view('admin/utilities/media', $data);
    }
    public function elfinder_init()
    {

        $this->load->helper('path');
        $_allowed_files = explode(',', get_option('allowed_files'));
        $_allowed_files = do_action('after_setup_media_allowed_files_extensions',$_allowed_files);
        $allowed_files  = array();
        if (is_array($_allowed_files)) {
            foreach ($_allowed_files as $extension) {
                $_mime = get_mime_by_extension($extension);
                if($_mime == 'application/x-zip'){
                    array_push($allowed_files,'application/zip');
                }
                array_push($allowed_files, $_mime);
            }
        }
        $root_options = array(
            'driver' => 'LocalFileSystem',
            'path' => set_realpath('media'),
            'URL' => site_url('media') . '/',
            'uploadMaxSize' => get_option('media_max_file_size_upload') . 'M',
            'accessControl' => 'access',
            'uploadAllow' => $allowed_files,
            'uploadOrder' => array(
                'allow',
                'deny'
            ),
            'attributes' => array(
                array(
                    'pattern' => '/.tmb/',
                    'hidden' => true
                ),
                array(
                    'pattern' => '/.quarantine/',
                    'hidden' => true
                )
            )
        );
        if (!is_admin()) {
            $this->db->select('media_path_slug,staffid,firstname,lastname');
            $this->db->from('tblstaff');
            $this->db->where('staffid', get_staff_user_id());
            $user = $this->db->get()->row();
            $path = set_realpath('media/' . $user->media_path_slug);
            if (empty($user->media_path_slug)) {
                $this->db->where('staffid', $user->staffid);
                $slug = slug_it($user->firstname . ' ' . $user->lastname);
                $this->db->update('tblstaff', array(
                    'media_path_slug' => $slug
                ));
                $user->media_path_slug = $slug;
                $path                  = set_realpath('media/' . $user->media_path_slug);
            }
            if (!is_dir($path)) {
                mkdir($path);
            }
            if (!file_exists($path . '/index.html')) {
                fopen($path . '/index.html', 'w');
            }
            array_push($root_options['attributes'], array(
                'pattern' => '/.(' . $user->media_path_slug . '+)/', // Prevent deleting/renaming folder
                'read' => true,
                'write' => true,
                'locked' => true
            ));
            $root_options['path'] = $path;
            $root_options['URL']  = site_url('media/' . $user->media_path_slug) . '/';
        }
        $opts = array(
            'roots' => array(
                $root_options
            )
        );

        $opts = do_action('after_before_init_media',$opts);
        $this->load->library('elfinder_lib', $opts);
    }
    public function bulk_pdf_exporter()
    {
        if (!has_permission('bulk_pdf_exporter', '', 'view')) {
            access_denied('bulk_pdf_exporter');
        }
        if ($this->input->post()) {
            if (!is_really_writable(TEMP_FOLDER)) {
                show_error('/temp folder is not writable. You need to change the permissions to 777');
            }
            $type = $this->input->post('export_type');
            if ($type == 'invoices') {
                $status = $this->input->post('invoice_export_status');
                $this->db->select('id');
                $this->db->from('tblinvoices');
                if ($status != 'all') {
                    $this->db->where('status', $status);
                }
                $this->db->order_by('date', 'desc');
            } else if ($type == 'estimates') {
                $status = $this->input->post('estimate_export_status');
                $this->db->select('id');
                $this->db->from('tblestimates');
                if ($status != 'all') {
                    $this->db->where('status', $status);
                }
                $this->db->order_by('date', 'desc');
            } else if ($type == 'payments') {
                $this->db->select('tblinvoicepaymentrecords.id as paymentid');
                $this->db->from('tblinvoicepaymentrecords');
                $this->db->join('tblinvoices', 'tblinvoices.id = tblinvoicepaymentrecords.invoiceid', 'left');
                $this->db->join('tblclients', 'tblclients.userid = tblinvoices.clientid', 'left');
                if ($this->input->post('paymentmode')) {
                    $this->db->where('paymentmode', $this->input->post('paymentmode'));
                }
            } else if ($type == 'proposals') {
                $this->db->select('id');
                $this->db->from('tblproposals');
                $status = $this->input->post('proposal_export_status');
                if ($status != 'all') {
                    $this->db->where('status', $status);
                }
                $this->db->order_by('date', 'desc');
            } else {
                // This may not happend but in all cases :)
                die('No Export Type Selected');
            }
            if ($this->input->post('date-to') && $this->input->post('date-from')) {
                $from_date  = to_sql_date($this->input->post('date-from'));
                $to_date    = to_sql_date($this->input->post('date-to'));
                $date_field = 'date';
                // Column date is ambiguous in payments
                if ($type == 'payments') {
                    $date_field = 'tblinvoicepaymentrecords.date';
                }
                if ($from_date == $to_date) {
                    $this->db->where($date_field, $from_date);
                } else {
                    $this->db->where($date_field . ' BETWEEN "' . $from_date . '" AND "' . $to_date . '"');
                }
            }
            $data = $this->db->get()->result_array();
            if (count($data) == 0) {
                set_alert('warning', _l('no_data_found_bulk_pdf_export'));
                redirect(admin_url('utilities/bulk_pdf_exporter'));
            }
            $dir = TEMP_FOLDER . $type;
            if (is_dir($dir)) {
                delete_dir($dir);
            }
            mkdir($dir, 0777);
            if ($type == 'invoices') {
                $this->load->model('invoices_model');
                foreach ($data as $invoice) {
                    $invoice_data    = $this->invoices_model->get($invoice['id']);
                    $this->pdf_zip   = invoice_pdf($invoice_data, $this->input->post('tag'));
                    $_temp_file_name = slug_it(format_invoice_number($invoice_data->id));
                    $file_name       = $dir . '/' . strtoupper($_temp_file_name);
                    $this->pdf_zip->Output($file_name . '.pdf', 'F');
                }
            } else if ($type == 'estimates') {
                foreach ($data as $estimate) {
                    $this->load->model('estimates_model');
                    $estimate_data   = $this->estimates_model->get($estimate['id']);
                    $this->pdf_zip   = estimate_pdf($estimate_data);
                    $_temp_file_name = slug_it(format_estimate_number($estimate_data->id));
                    $file_name       = $dir . '/' . strtoupper($_temp_file_name);
                    $this->pdf_zip->Output($file_name . '.pdf', 'F');
                }
            } else if ($type == 'payments') {
                $this->load->model('payments_model');
                $this->load->model('invoices_model');
                foreach ($data as $payment) {
                    $payment_data               = $this->payments_model->get($payment['paymentid']);
                    $payment_data->invoice_data = $this->invoices_model->get($payment_data->invoiceid);
                    $this->pdf_zip              = payment_pdf($payment_data);
                    $file_name                  = $dir;
                    $file_name .= '/' . strtoupper(_l('payment'));
                    $file_name .= '-' . strtoupper($payment_data->paymentid) . '.pdf';
                    $this->pdf_zip->Output($file_name, 'F');
                }
            } else {
                $this->load->model('proposals_model');
                foreach ($data as $proposal) {
                    $proposal        = $this->proposals_model->get($proposal['id']);
                    $this->pdf_zip   = proposal_pdf($proposal);
                    $_temp_file_name = slug_it($proposal->subject);
                    $file_name       = $dir . '/' . strtoupper($_temp_file_name);
                    $this->pdf_zip->Output($file_name . '.pdf', 'F');
                }
            }
            $this->load->library('zip');
            $this->zip->read_dir($dir, false);
            // Delete the temp directory for the export type
            delete_dir($dir);
            $this->zip->download(slug_it(get_option('companyname')) . '-' . $type . '.zip');
            $this->zip->clear_data();
        }
        $this->load->model('payment_modes_model');
        $data['payment_modes'] = $this->payment_modes_model->get();
        $data['title']         = _l('bulk_pdf_exporter');
        $this->load->view('admin/utilities/bulk_pdf_exporter', $data);
    }
    /* Database back up functions */
    public function backup()
    {
        if (!is_admin()) {
            access_denied('databaseBackup');
        }
        $data['title'] = _l('utility_backup');
        $this->load->view('admin/utilities/backup', $data);
    }
    public function make_backup_db()
    {
        do_action('before_make_backup');
        // Only full admin can make database backup
        if (!is_admin()) {
            access_denied('databaseBackup');
        }
        if (!is_really_writable(BACKUPS_FOLDER)) {
            show_error('/backups folder is not writable. You need to change the permissions to 755');
        }
        $this->load->model('cron_model');
        $success = $this->cron_model->make_backup_db(true);
        if ($success) {
            set_alert('success', _l('backup_success'));
            logActivity('Database Backup [' . $backup_name . ']');
        }
        redirect(admin_url('utilities/backup'));
    }
    public function update_auto_backup_options()
    {
        do_action('before_update_backup_options');
        if (!is_admin()) {
            access_denied('databaseBackup');
        }
        if ($this->input->post()) {
            $_post     = $this->input->post();
            $updated_1 = update_option('auto_backup_enabled', $_post['settings']['auto_backup_enabled']);
            $updated_2 = update_option('auto_backup_every', $this->input->post('auto_backup_every'));
            if ($updated_2 || $updated_1) {
                set_alert('success', _l('auto_backup_options_updated'));
            }
        }
        redirect(admin_url('utilities/backup'));
    }
    public function delete_backup($backup)
    {
        if (!is_admin()) {
            access_denied('databaseBackup');
        }
        if (unlink(BACKUPS_FOLDER . $backup)) {
            set_alert('success', _l('backup_delete'));
        }
        redirect(admin_url('utilities/backup'));
    }
    public function theme_style(){
        $data['title'] = _l('theme_style');
        $this->load->view('admin/utilities/theme_style',$data);
    }
    public function save_theme_style(){
        do_action('before_save_theme_style');
        $data = $this->input->post();
        if ($data == null) {
            $data = array();
        } else {
            $data = $data['data'];
        }

        update_option('theme_style',$data);
    }
    public function main_menu()
    {
        if (!is_admin()) {
            access_denied('Edit Main Menu');
        }
        $data['permissions']   = $this->roles_model->get_permissions();
        $data['permissions'][] = array(
            'shortname' => 'is_admin',
            'name' => 'Admin'
        );
        $data['permissions'][] = array(
            'shortname' => 'is_not_staff',
            'name' => _l('is_not_staff_member')
        );
        $data['title']         = _l('main_menu');
        $this->load->view('admin/utilities/main_menu', $data);
    }
    public function update_aside_menu()
    {
        do_action('before_update_aside_menu');
        if (!is_admin()) {
            access_denied('Edit Main Menu');
        }
        $data_inactive = $this->input->post('inactive');
        if ($data_inactive == null) {
            $data_inactive = array();
        }
        $data_active = $this->input->post('active');
        if ($data_active == null) {
            $data_active = array();
        }
        update_option('aside_menu_active', json_encode(array(
            'aside_menu_active' => $data_active
        )));
        update_option('aside_menu_inactive', json_encode(array(
            'aside_menu_inactive' => $data_inactive
        )));
    }
    public function setup_menu()
    {
        if (!is_admin()) {
            access_denied('Edit Setup Menu');
        }
        $data['permissions']   = $this->roles_model->get_permissions();
        $data['permissions'][] = array(
            'shortname' => 'is_admin',
            'name' => 'Admin'
        );
        $data['permissions'][] = array(
            'shortname' => 'is_not_staff',
            'name' => _l('is_not_staff_member')
        );
        $data['title']         = _l('setup_menu');
        $this->load->view('admin/utilities/setup_menu', $data);
    }
    public function update_setup_menu()
    {
        do_action('before_update_setup_menu');
        if (!is_admin()) {
            access_denied('Edit Setup Menu');
        }
        $data_inactive = $this->input->post('inactive');
        if ($data_inactive == null) {
            $data_inactive = array();
        }
        $data_active = $this->input->post('active');
        if ($data_active == null) {
            $data_active = array();
        }
        update_option('setup_menu_active', json_encode(array(
            'setup_menu_active' => $data_active
        )));
        update_option('setup_menu_inactive', json_encode(array(
            'setup_menu_inactive' => $data_inactive
        )));
    }
}
