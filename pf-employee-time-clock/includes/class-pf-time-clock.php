<?php
if (!defined('ABSPATH')) exit;

class PF_Time_Clock {
    private static $instance;
    private $punches;
    private $audit;
    const META_EMP='pf_tc_employee';
    const META_HIDDEN='pf_tc_hidden';
    const META_MANAGER='pf_tc_manager';
    const META_PIN='pf_tc_pin_hash';

    public static function instance(){ return self::$instance ?: (self::$instance=new self()); }
    public function __construct(){
        global $wpdb; $this->punches=$wpdb->prefix.'pf_tc_punches'; $this->audit=$wpdb->prefix.'pf_tc_audit';
        add_action('show_user_profile',[$this,'profile_fields']); add_action('edit_user_profile',[$this,'profile_fields']);
        add_action('personal_options_update',[$this,'save_profile']); add_action('edit_user_profile_update',[$this,'save_profile']);
        add_action('wp_enqueue_scripts',[$this,'assets']);
        add_action('admin_menu',[$this,'admin_menu']);
        add_action('admin_init',[$this,'maybe_redirect_report_menu']);
        add_action('admin_post_pf_tc_admin_add_punch',[$this,'admin_add_punch_submit']);
        add_action('admin_post_pf_tc_admin_add_shift',[$this,'admin_add_shift_submit']);
        add_action('wp_ajax_pf_tc_admin_recent_punches',[$this,'ajax_admin_recent_punches']);
        add_shortcode('pf_time_clock',[$this,'clock_shortcode']); add_shortcode('pf_time_clock_admin',[$this,'admin_shortcode']);
        add_action('wp_ajax_pf_tc_state',[$this,'ajax_state']); add_action('wp_ajax_pf_tc_punch',[$this,'ajax_punch']);
        add_action('wp_ajax_pf_tc_manual_out',[$this,'ajax_manual_out']); add_action('wp_ajax_pf_tc_employee_edit_last',[$this,'ajax_employee_edit_last']); add_action('wp_ajax_pf_tc_employee_add_punch',[$this,'ajax_employee_add_punch']); add_action('wp_ajax_pf_tc_review',[$this,'ajax_review']);
        add_action('wp_ajax_pf_tc_report',[$this,'ajax_report']); add_action('wp_ajax_pf_tc_details',[$this,'ajax_details']); add_action('wp_ajax_pf_tc_manager_add_missing_out',[$this,'ajax_manager_add_missing_out']); add_action('wp_ajax_pf_tc_manager_ignore_unmatched',[$this,'ajax_manager_ignore_unmatched']); add_action('wp_ajax_pf_tc_manager_pair_action',[$this,'ajax_manager_pair_action']);
        add_action('admin_post_pf_tc_export_csv',[$this,'export_csv']); add_action('admin_post_pf_tc_export_xlsx',[$this,'export_xlsx']);
        $this->maybe_upgrade();
    }
    public static function activate(){
        global $wpdb; $charset=$wpdb->get_charset_collate();
        $p=$wpdb->prefix.'pf_tc_punches'; $a=$wpdb->prefix.'pf_tc_audit'; require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE $p (
          id bigint unsigned NOT NULL AUTO_INCREMENT,
          user_id bigint unsigned NOT NULL,
          punch_type varchar(3) NOT NULL,
          work_time datetime NOT NULL,
          submitted_at datetime NOT NULL,
          source varchar(24) NOT NULL DEFAULT 'kiosk',
          review_status varchar(24) NOT NULL DEFAULT 'approved',
          related_punch_id bigint unsigned NULL,
          created_by bigint unsigned NULL,
          note text NULL,
          PRIMARY KEY (id), KEY user_time (user_id,work_time), KEY review_status (review_status)
        ) $charset;");
        dbDelta("CREATE TABLE $a (
          id bigint unsigned NOT NULL AUTO_INCREMENT,
          punch_id bigint unsigned NULL,
          employee_id bigint unsigned NOT NULL,
          actor_id bigint unsigned NULL,
          action varchar(40) NOT NULL,
          old_value longtext NULL,
          new_value longtext NULL,
          note text NULL,
          created_at datetime NOT NULL,
          PRIMARY KEY (id), KEY employee_id (employee_id), KEY punch_id (punch_id)
        ) $charset;");
    }
    private function yes($uid,$key){ return get_user_meta($uid,$key,true)==='1'; }
    private function current_can_clock(){ return is_user_logged_in() && $this->yes(get_current_user_id(),self::META_EMP); }
    private function current_can_manage(){ return is_user_logged_in() && ($this->yes(get_current_user_id(),self::META_MANAGER) || current_user_can('manage_options')); }
    private function now_mysql(){ return current_time('mysql',true); }
    private function utc_ts($mysql){
        if(!$mysql) return false;
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$mysql,new DateTimeZone('UTC'));
        return $dt ? $dt->getTimestamp() : false;
    }
    private function display_dt($mysql,$format='M j, Y g:i A'){
        $ts=$this->utc_ts($mysql); return $ts===false ? '' : wp_date($format,$ts,wp_timezone());
    }
    private function local_date($mysql){ return $this->display_dt($mysql,'Y-m-d'); }
    private function local_input_to_utc($date,$time){
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$date.' '.$time,wp_timezone());
        if(!$dt || $dt->format('Y-m-d H:i')!==$date.' '.$time) return false;
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    private function local_range_to_utc($start,$end){
        $tz=wp_timezone(); $utc=new DateTimeZone('UTC');
        $s=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$start.' 00:00:00',$tz);
        $e=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$end.' 23:59:59',$tz);
        return [$s->setTimezone($utc)->format('Y-m-d H:i:s'),$e->setTimezone($utc)->format('Y-m-d H:i:s')];
    }
    private function maybe_upgrade(){
        if(get_option('pf_tc_storage_version')==='utc-1') return;
        global $wpdb; $tz=wp_timezone(); $utc=new DateTimeZone('UTC');
        // v1.0.0 stored wall-clock site time. Convert existing rows once to UTC.
        $rows=$wpdb->get_results("SELECT id,work_time,submitted_at FROM {$this->punches}");
        if(is_array($rows)) foreach($rows as $r){
            $vals=[]; foreach(['work_time','submitted_at'] as $f){ $dt=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$r->$f,$tz); if($dt) $vals[$f]=$dt->setTimezone($utc)->format('Y-m-d H:i:s'); }
            if($vals) $wpdb->update($this->punches,$vals,['id'=>$r->id]);
        }
        $aud=$wpdb->get_results("SELECT id,created_at FROM {$this->audit}");
        if(is_array($aud)) foreach($aud as $r){ $dt=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$r->created_at,$tz); if($dt) $wpdb->update($this->audit,['created_at'=>$dt->setTimezone($utc)->format('Y-m-d H:i:s')],['id'=>$r->id]); }
        update_option('pf_tc_storage_version','utc-1',false);
    }


    public function admin_menu(){
        add_menu_page('PF Employee Time Clock','Time Clock','manage_options','pf-time-clock',[$this,'instructions_page'],'dashicons-clock',58);
        add_submenu_page('pf-time-clock','Time Clock Instructions','Instructions','manage_options','pf-time-clock',[$this,'instructions_page']);
        add_submenu_page('pf-time-clock','Generate Time Report','Generate Report','manage_options','pf-time-clock-report',[$this,'report_menu_fallback']);
        add_submenu_page('pf-time-clock','Add Missing Punch','Add Missing Punch','manage_options','pf-time-clock-add-punch',[$this,'admin_add_punch_page']);
        add_submenu_page('pf-time-clock','Add Missing Shift','Add Missing Shift','manage_options','pf-time-clock-add-shift',[$this,'admin_add_shift_page']);
    }
    private function report_page_url(){
        global $wpdb;
        $like='%'.$wpdb->esc_like('[pf_time_clock_admin').'%';
        $page_id=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_content LIKE %s ORDER BY ID ASC LIMIT 1",
            $like
        ));
        return $page_id ? get_permalink($page_id) : '';
    }
    public function maybe_redirect_report_menu(){
        if(!is_admin() || !current_user_can('manage_options')) return;
        if(sanitize_key($_GET['page']??'')!=='pf-time-clock-report') return;
        $url=$this->report_page_url();
        if($url){ wp_safe_redirect($url); exit; }
    }
    public function report_menu_fallback(){
        if(!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to view this page.'));
        ?>
        <div class="wrap"><h1>Generate Report</h1><div class="notice notice-warning inline"><p>The plugin could not find a published WordPress page containing <code>[pf_time_clock_admin]</code>.</p></div><p>Create or publish the Employee Time Records page with that shortcode, then the <strong>Time Clock → Generate Report</strong> menu will open it automatically.</p></div>
        <?php
    }
    public function admin_add_punch_page(){
        if(!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to view this page.'));
        $emps=$this->employees(); $msg=sanitize_text_field($_GET['pf_tc_msg']??''); $err=sanitize_text_field($_GET['pf_tc_err']??''); $selected=absint($_GET['employee_id']??0);
        ?>
        <div class="wrap pf-tc-backend-add">
          <style>
            .pf-tc-backend-add{max-width:850px}.pf-tc-backend-add .pf-head{background:linear-gradient(135deg,#173c2b,#285f43);color:#fff;border-radius:14px;padding:26px 30px;margin:20px 0}.pf-tc-backend-add .pf-head h1{color:#fff;margin:0 0 6px}.pf-tc-backend-add .pf-head p{margin:0;opacity:.9}.pf-tc-backend-add .pf-box{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:26px;box-shadow:0 1px 3px rgba(0,0,0,.05)}.pf-tc-backend-add .pf-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.pf-tc-backend-add label{display:block;font-weight:600;margin-bottom:6px}.pf-tc-backend-add select,.pf-tc-backend-add input[type=date],.pf-tc-backend-add input[type=time],.pf-tc-backend-add input[type=text]{width:100%;max-width:none;min-height:42px}.pf-tc-backend-add .pf-full{grid-column:1/-1}.pf-tc-backend-add .button-primary{margin-top:20px;min-height:42px;padding:0 20px}.pf-tc-backend-add .notice{margin:0 0 16px}.pf-tc-backend-add .pf-note{color:#646970;margin:16px 0 0}.pf-tc-backend-add .pf-recent{margin-top:18px}.pf-tc-backend-add .pf-recent h2{margin:0 0 10px}.pf-tc-backend-add .pf-recent table{width:100%;border-collapse:collapse}.pf-tc-backend-add .pf-recent th,.pf-tc-backend-add .pf-recent td{text-align:left;padding:9px 10px;border-bottom:1px solid #e2e4e7}.pf-tc-backend-add .pf-recent .pf-in{font-weight:700;color:#166534}.pf-tc-backend-add .pf-recent .pf-out{font-weight:700;color:#9a3412}@media(max-width:700px){.pf-tc-backend-add .pf-grid{grid-template-columns:1fr}}
          </style>
          <div class="pf-head"><h1>Add Missing Punch</h1><p>Add a management-entered clock IN or OUT for an employee.</p></div>
          <?php if($msg):?><div class="notice notice-success inline"><p><?php echo esc_html($msg);?></p></div><?php endif;?>
          <?php if($err):?><div class="notice notice-error inline"><p><?php echo esc_html($err);?></p></div><?php endif;?>
          <div class="pf-box"><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>">
            <input type="hidden" name="action" value="pf_tc_admin_add_punch"><?php wp_nonce_field('pf_tc_admin_add_punch'); ?>
            <div class="pf-grid">
              <div class="pf-full"><label for="pf-employee">Employee</label><select id="pf-employee" name="employee_id" required><option value="">Select employee…</option><?php foreach($emps as $e):?><option value="<?php echo esc_attr($e->ID);?>" <?php selected($selected,$e->ID);?>><?php echo esc_html($e->display_name);?></option><?php endforeach;?></select></div>
              <div><label for="pf-type">Type</label><select id="pf-type" name="punch_type" required><option value="in">Clock In</option><option value="out">Clock Out</option></select></div>
              <div><label for="pf-date">Date</label><input id="pf-date" type="date" name="date" value="<?php echo esc_attr(wp_date('Y-m-d',time(),wp_timezone()));?>" required></div>
              <div><label for="pf-time">Time</label><input id="pf-time" type="time" name="time" required></div>
              <div><label for="pf-note">Note <span style="font-weight:400;color:#646970">(optional)</span></label><input id="pf-note" type="text" name="note" maxlength="500" placeholder="Reason or reference"></div>
            </div>
            <button type="submit" class="button button-primary">Add Punch</button>
            <p class="pf-note">Management-added punches are approved immediately and recorded in the audit history with the manager, entry time, and optional note.</p>
          </form></div>
          <div id="pf-admin-recent" class="pf-box pf-recent" style="display:none"></div>
          <script>jQuery(function($){function loadRecent(){var id=$('#pf-employee').val();if(!id){$('#pf-admin-recent').hide().empty();return;}$('#pf-admin-recent').show().html('<p>Loading recent punches…</p>');$.post(ajaxurl,{action:'pf_tc_admin_recent_punches',nonce:'<?php echo esc_js(wp_create_nonce('pf_tc_admin_recent'));?>',employee_id:id}).done(function(r){if(r.success)$('#pf-admin-recent').html(r.data.html);else $('#pf-admin-recent').html('<p>Could not load recent punches.</p>');});}$('#pf-employee').on('change',loadRecent);loadRecent();});</script>
        </div><?php
    }
    public function admin_add_shift_page(){
        if(!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to view this page.'));
        $emps=$this->employees(); $msg=sanitize_text_field($_GET['pf_tc_msg']??''); $err=sanitize_text_field($_GET['pf_tc_err']??''); $selected=absint($_GET['employee_id']??0);
        ?>
        <div class="wrap pf-tc-backend-add">
          <style>
            .pf-tc-backend-add{max-width:850px}.pf-tc-backend-add .pf-head{background:linear-gradient(135deg,#173c2b,#285f43);color:#fff;border-radius:14px;padding:26px 30px;margin:20px 0}.pf-tc-backend-add .pf-head h1{color:#fff;margin:0 0 6px}.pf-tc-backend-add .pf-head p{margin:0;opacity:.9}.pf-tc-backend-add .pf-box{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:26px;box-shadow:0 1px 3px rgba(0,0,0,.05)}.pf-tc-backend-add .pf-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.pf-tc-backend-add label{display:block;font-weight:600;margin-bottom:6px}.pf-tc-backend-add select,.pf-tc-backend-add input[type=date],.pf-tc-backend-add input[type=time],.pf-tc-backend-add input[type=text]{width:100%;max-width:none;min-height:42px}.pf-tc-backend-add .pf-full{grid-column:1/-1}.pf-tc-backend-add .button-primary{margin-top:20px;min-height:42px;padding:0 20px}.pf-tc-backend-add .notice{margin:0 0 16px}.pf-tc-backend-add .pf-note{color:#646970;margin:16px 0 0}.pf-tc-backend-add .pf-recent{margin-top:18px}.pf-tc-backend-add .pf-recent h2{margin:0 0 10px}.pf-tc-backend-add .pf-recent table{width:100%;border-collapse:collapse}.pf-tc-backend-add .pf-recent th,.pf-tc-backend-add .pf-recent td{text-align:left;padding:9px 10px;border-bottom:1px solid #e2e4e7}.pf-tc-backend-add .pf-recent .pf-in{font-weight:700;color:#166534}.pf-tc-backend-add .pf-recent .pf-out{font-weight:700;color:#9a3412}@media(max-width:700px){.pf-tc-backend-add .pf-grid{grid-template-columns:1fr}}
          </style>
          <div class="pf-head"><h1>Add Missing Shift</h1><p>Add a complete management-entered IN/OUT shift when both punches are missing.</p></div>
          <?php if($msg):?><div class="notice notice-success inline"><p><?php echo esc_html($msg);?></p></div><?php endif;?>
          <?php if($err):?><div class="notice notice-error inline"><p><?php echo esc_html($err);?></p></div><?php endif;?>
          <div class="pf-box"><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>">
            <input type="hidden" name="action" value="pf_tc_admin_add_shift"><?php wp_nonce_field('pf_tc_admin_add_shift'); ?>
            <div class="pf-grid">
              <div class="pf-full"><label for="pf-shift-employee">Employee</label><select id="pf-shift-employee" name="employee_id" required><option value="">Select employee…</option><?php foreach($emps as $e):?><option value="<?php echo esc_attr($e->ID);?>" <?php selected($selected,$e->ID);?>><?php echo esc_html($e->display_name);?></option><?php endforeach;?></select></div>
              <div class="pf-full"><label for="pf-shift-date">Date</label><input id="pf-shift-date" type="date" name="date" value="<?php echo esc_attr(wp_date('Y-m-d',time(),wp_timezone()));?>" required></div>
              <div><label for="pf-shift-in">Clock In</label><input id="pf-shift-in" type="time" name="in_time" required></div>
              <div><label for="pf-shift-out">Clock Out</label><input id="pf-shift-out" type="time" name="out_time" required></div>
              <div class="pf-full"><label for="pf-shift-note">Note <span style="font-weight:400;color:#646970">(optional)</span></label><input id="pf-shift-note" type="text" name="note" maxlength="500" placeholder="Reason or reference"></div>
            </div>
            <button type="submit" class="button button-primary">Add Shift</button>
            <p class="pf-note">Both punches are added together as an approved management correction. The plugin validates the complete shift as a pair so a missing full day does not get blocked by the next day's clock-in.</p>
          </form></div>
          <div id="pf-admin-recent" class="pf-box pf-recent" style="display:none"></div>
          <script>jQuery(function($){function loadRecent(){var id=$('#pf-shift-employee').val();if(!id){$('#pf-admin-recent').hide().empty();return;}$('#pf-admin-recent').show().html('<p>Loading recent punches…</p>');$.post(ajaxurl,{action:'pf_tc_admin_recent_punches',nonce:'<?php echo esc_js(wp_create_nonce('pf_tc_admin_recent'));?>',employee_id:id}).done(function(r){if(r.success)$('#pf-admin-recent').html(r.data.html);else $('#pf-admin-recent').html('<p>Could not load recent punches.</p>');});}$('#pf-shift-employee').on('change',loadRecent);loadRecent();});</script>
        </div><?php
    }

    public function ajax_admin_recent_punches(){
        check_ajax_referer('pf_tc_admin_recent','nonce'); if(!current_user_can('manage_options')) wp_send_json_error(['message'=>'Access denied.'],403); global $wpdb;
        $uid=absint($_POST['employee_id']??0); if(!$uid||!$this->yes($uid,self::META_EMP)) wp_send_json_error(['message'=>'Choose a valid employee.']); $u=get_userdata($uid);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->punches} WHERE user_id=%d AND review_status!='ignored' AND work_time >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 DAY) ORDER BY work_time DESC,id DESC LIMIT 30",$uid));
        $html='<h2>'.esc_html($u?$u->display_name:'Employee').' — Recent Punches</h2><p class="pf-note">Individual punches from the last 10 days, newest first. Use this to see the surrounding record before adding a missing punch.</p>';
        if(!$rows){$html.='<p>No punches found in the last 10 days.</p>';} else {$html.='<table><thead><tr><th>Date &amp; Time</th><th>Type</th><th>Source</th><th>Status</th></tr></thead><tbody>'; foreach($rows as $r){$html.='<tr><td>'.esc_html($this->display_dt($r->work_time,'D, M j, Y g:i A')).'</td><td class="pf-'.esc_attr($r->punch_type).'">'.esc_html(strtoupper($r->punch_type)).'</td><td>'.esc_html(str_replace('_',' ',$r->source)).'</td><td>'.esc_html(ucfirst($r->review_status)).'</td></tr>';}$html.='</tbody></table>';} wp_send_json_success(['html'=>$html]);
    }
    public function admin_add_punch_submit(){
        if(!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to do this.')); check_admin_referer('pf_tc_admin_add_punch'); global $wpdb;
        $uid=absint($_POST['employee_id']??0); $type=sanitize_key($_POST['punch_type']??''); $date=sanitize_text_field($_POST['date']??''); $time=sanitize_text_field($_POST['time']??''); $note=sanitize_text_field($_POST['note']??'');
        $base=admin_url('admin.php?page=pf-time-clock-add-punch'); $fail=function($m)use($base,$uid){wp_safe_redirect(add_query_arg(['pf_tc_err'=>$m,'employee_id'=>$uid],$base));exit;};
        if(!$uid||!$this->yes($uid,self::META_EMP)||$this->yes($uid,self::META_HIDDEN)) $fail('Choose a valid employee.');
        if(!in_array($type,['in','out'],true)) $fail('Choose Clock In or Clock Out.'); $work=$this->local_input_to_utc($date,$time); if(!$work) $fail('Choose a valid date and time.'); if($this->utc_ts($work)>time()+60) $fail('A punch cannot be added in the future.');
        $prev=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->punches} WHERE user_id=%d AND review_status!='ignored' AND work_time<=%s ORDER BY work_time DESC,id DESC LIMIT 1",$uid,$work));
        $next=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->punches} WHERE user_id=%d AND review_status!='ignored' AND work_time>%s ORDER BY work_time ASC,id ASC LIMIT 1",$uid,$work));
        if($prev && $prev->punch_type===$type) $fail('That would create two consecutive '.strtoupper($type).' punches. The previous '.strtoupper($type).' is '.$this->display_dt($prev->work_time,'D, M j, Y g:i A').'. Review the recent punches below.');
        if($next && $next->punch_type===$type) $fail('That would create two consecutive '.strtoupper($type).' punches. The next '.strtoupper($type).' is '.$this->display_dt($next->work_time,'D, M j, Y g:i A').'. Review the recent punches below.');
        $now=$this->now_mysql(); $wpdb->insert($this->punches,['user_id'=>$uid,'punch_type'=>$type,'work_time'=>$work,'submitted_at'=>$now,'source'=>'manager_manual_add','review_status'=>'approved','created_by'=>get_current_user_id(),'note'=>$note],['%d','%s','%s','%s','%s','%s','%d','%s']);
        if(!$wpdb->insert_id) $fail('The punch could not be saved.'); $id=$wpdb->insert_id; $this->audit($id,$uid,get_current_user_id(),'manager_add_missing_punch',null,['punch_type'=>$type,'work_time'=>$work,'submitted_at'=>$now,'review_status'=>'approved'],$note);
        $u=get_userdata($uid); $msg=($u?$u->display_name:'Employee').' — '.($type==='in'?'Clock In':'Clock Out').' added for '.$this->display_dt($work,'D, M j, Y g:i A').'.'; wp_safe_redirect(add_query_arg(['pf_tc_msg'=>$msg,'employee_id'=>$uid],$base)); exit;
    }

    public function admin_add_shift_submit(){
        if(!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to do this.')); check_admin_referer('pf_tc_admin_add_shift'); global $wpdb;
        $uid=absint($_POST['employee_id']??0); $date=sanitize_text_field($_POST['date']??''); $in_time=sanitize_text_field($_POST['in_time']??''); $out_time=sanitize_text_field($_POST['out_time']??''); $note=sanitize_text_field($_POST['note']??'');
        $base=admin_url('admin.php?page=pf-time-clock-add-shift'); $fail=function($m)use($base,$uid){wp_safe_redirect(add_query_arg(['pf_tc_err'=>$m,'employee_id'=>$uid],$base));exit;};
        if(!$uid||!$this->yes($uid,self::META_EMP)||$this->yes($uid,self::META_HIDDEN)) $fail('Choose a valid employee.');
        $in=$this->local_input_to_utc($date,$in_time); $out=$this->local_input_to_utc($date,$out_time); if(!$in||!$out) $fail('Choose a valid date, clock-in time, and clock-out time.');
        if($this->utc_ts($out)<=$this->utc_ts($in)) $fail('Clock Out must be later than Clock In on the same date.');
        if($this->utc_ts($out)>time()+60) $fail('A shift cannot end in the future.');
        $inside=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->punches} WHERE user_id=%d AND review_status!='ignored' AND work_time>=%s AND work_time<=%s ORDER BY work_time ASC,id ASC LIMIT 1",$uid,$in,$out));
        if($inside) $fail('An existing '.strtoupper($inside->punch_type).' punch already falls inside this shift at '.$this->display_dt($inside->work_time,'D, M j, Y g:i A').'. Review the recent punches below.');
        $prev=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->punches} WHERE user_id=%d AND review_status!='ignored' AND work_time<%s ORDER BY work_time DESC,id DESC LIMIT 1",$uid,$in));
        $next=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->punches} WHERE user_id=%d AND review_status!='ignored' AND work_time>%s ORDER BY work_time ASC,id ASC LIMIT 1",$uid,$out));
        if($prev && $prev->punch_type==='in') $fail('This shift would follow an unmatched IN at '.$this->display_dt($prev->work_time,'D, M j, Y g:i A').'. Resolve or ignore that earlier record first.');
        if($next && $next->punch_type==='out') $fail('This shift would be followed by an OUT without a new IN. The next OUT is '.$this->display_dt($next->work_time,'D, M j, Y g:i A').'. Resolve that record first.');
        $now=$this->now_mysql(); $actor=get_current_user_id();
        $wpdb->query('START TRANSACTION');
        $ok1=$wpdb->insert($this->punches,['user_id'=>$uid,'punch_type'=>'in','work_time'=>$in,'submitted_at'=>$now,'source'=>'manager_manual_shift','review_status'=>'approved','created_by'=>$actor,'note'=>$note],['%d','%s','%s','%s','%s','%s','%d','%s']); $in_id=$wpdb->insert_id;
        $ok2=false; $out_id=0; if($ok1){$ok2=$wpdb->insert($this->punches,['user_id'=>$uid,'punch_type'=>'out','work_time'=>$out,'submitted_at'=>$now,'source'=>'manager_manual_shift','review_status'=>'approved','created_by'=>$actor,'note'=>$note],['%d','%s','%s','%s','%s','%s','%d','%s']); $out_id=$wpdb->insert_id;}
        if(!$ok1||!$ok2){$wpdb->query('ROLLBACK');$fail('The complete shift could not be saved. No punches were added.');}
        $wpdb->query('COMMIT');
        $this->audit($in_id,$uid,$actor,'manager_add_missing_shift_in',null,['punch_type'=>'in','work_time'=>$in,'submitted_at'=>$now,'review_status'=>'approved','related_punch_id'=>$out_id],$note);
        $this->audit($out_id,$uid,$actor,'manager_add_missing_shift_out',null,['punch_type'=>'out','work_time'=>$out,'submitted_at'=>$now,'review_status'=>'approved','related_punch_id'=>$in_id],$note);
        $u=get_userdata($uid); $msg=($u?$u->display_name:'Employee').' — missing shift added: '.$this->display_dt($in,'D, M j, Y g:i A').' to '.$this->display_dt($out,'g:i A').'.'; wp_safe_redirect(add_query_arg(['pf_tc_msg'=>$msg,'employee_id'=>$uid],$base)); exit;
    }

    public function instructions_page(){
        if(!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to view this page.'));
        ?>
        <div class="wrap pf-tc-help-wrap">
          <style>
            .pf-tc-help-wrap{max-width:1160px}.pf-tc-help-hero{background:linear-gradient(135deg,#173c2b,#285f43);color:#fff;border-radius:16px;padding:32px 36px;margin:20px 0;box-shadow:0 5px 20px rgba(0,0,0,.12)}
            .pf-tc-help-hero h1{color:#fff;font-size:32px;margin:0 0 8px}.pf-tc-help-hero p{font-size:16px;margin:0;opacity:.93}.pf-tc-help-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.pf-tc-help-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:23px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
            .pf-tc-help-card h2{margin:0 0 12px;font-size:20px}.pf-tc-help-card h3{margin:18px 0 7px;font-size:15px}.pf-tc-help-card p,.pf-tc-help-card li,.pf-tc-help-card td,.pf-tc-help-card th{font-size:14px;line-height:1.6}.pf-tc-help-card ol,.pf-tc-help-card ul{margin-left:20px}.pf-tc-help-code{display:block;background:#f0f0f1;border:1px solid #c3c4c7;border-radius:6px;padding:10px 12px;margin:8px 0;font:600 14px/1.4 monospace;user-select:all}.pf-tc-help-note{border-left:4px solid #dba617;background:#fff8e5;padding:10px 14px;margin-top:12px}.pf-tc-help-good{border-left:4px solid #1d6f42;background:#edf7f1;padding:10px 14px;margin-top:12px}.pf-tc-help-table{width:100%;border-collapse:collapse;margin-top:10px}.pf-tc-help-table th,.pf-tc-help-table td{text-align:left;padding:9px 10px;border-bottom:1px solid #e2e4e7;vertical-align:top}.pf-tc-help-table th{background:#f6f7f7}.pf-tc-help-wide{grid-column:1/-1}.pf-tc-help-sign{text-align:right;color:#646970;font-style:italic;font-size:14px;margin:20px 4px 30px}@media(max-width:800px){.pf-tc-help-grid{grid-template-columns:1fr}.pf-tc-help-wide{grid-column:auto}}
          </style>
          <div class="pf-tc-help-hero"><h1>Employee Time Clock</h1><p>Complete guide to setup, kiosk use, corrections, management review, reports, exports, and record safety.</p></div>
          <div class="pf-tc-help-grid">
            <section class="pf-tc-help-card"><h2>1. Employee Setup</h2><p>Create or edit employees under <strong>Users</strong>. The <strong>Employee Time Clock</strong> section on each WordPress user profile controls how that account works.</p><ul><li><strong>This user is an employee</strong> — enables time-clock access and time records.</li><li><strong>4-digit Time Clock PIN</strong> — authenticates kiosk actions. PINs are stored hashed.</li><li><strong>Hide from employee clock-in list</strong> — removes the account from the kiosk and normal payroll/reporting lists. Ideal for the shared tablet account.</li><li><strong>Access to employee time records</strong> — grants access to the management/report page.</li></ul><p>Disabling or hiding a user never deletes historical punches.</p></section>
            <section class="pf-tc-help-card"><h2>2. Required WordPress Pages</h2><p>Create a normal WordPress page for each screen and place the matching shortcode in the page content.</p><h3>Employee / kiosk page</h3><code class="pf-tc-help-code">[pf_time_clock]</code><h3>Management / reports page</h3><code class="pf-tc-help-code">[pf_time_clock_admin]</code><p><strong>Time Clock → Generate Report</strong> automatically finds the published page containing the management shortcode.</p></section>
            <section class="pf-tc-help-card"><h2>3. Using the Kiosk</h2><ol><li>Select the employee name.</li><li>Check the displayed current status.</li><li>Choose <strong>Clock In</strong> or <strong>Clock Out</strong>.</li><li>Confirm and enter the employee's 4-digit PIN.</li></ol><p>Normal punches use authoritative website/server time, not the tablet's clock. After a successful action, the confirmation displays briefly and the kiosk resets for the next employee.</p><div class="pf-tc-help-good"><strong>Kiosk reliability:</strong> the kiosk refreshes itself about once per hour when idle. If a tablet sleeps or backgrounds the page for a long period, the stale page refreshes when it becomes active again. An active employee entry is never intentionally interrupted by the maintenance refresh.</div></section>
            <section class="pf-tc-help-card"><h2>4. Employee Corrections</h2><p>Employees have two controlled correction tools on the kiosk:</p><ul><li><strong>Edit Last Punch</strong> — proposes a corrected date/time for the employee's most recent punch.</li><li><strong>Add Missing Punch</strong> — adds a forgotten IN or OUT.</li></ul><p>These require the employee's PIN and are marked for <strong>management review</strong>. The original information and actual submission time remain in the audit history.</p><h3>Missing clock-out after midnight</h3><p>If an employee remains clocked in past midnight, the prior day is flagged. The plugin does <strong>not</strong> invent a midnight OUT. The employee can enter the time they actually left and submit it for review.</p></section>
            <section class="pf-tc-help-card"><h2>5. Management Menu</h2><p>The WordPress sidebar provides the main management shortcuts:</p><ul><li><strong>Instructions</strong> — this guide.</li><li><strong>Generate Report</strong> — opens the front-end time-record/report page.</li><li><strong>Add Missing Punch</strong> — adds one management-entered IN or OUT.</li><li><strong>Add Missing Shift</strong> — adds a complete IN/OUT pair when an entire shift is absent.</li></ul><p>The correction screens show the selected employee's recent punches so management can see surrounding records before adding anything.</p></section>
            <section class="pf-tc-help-card"><h2>6. Add Missing Punch vs. Shift</h2><h3>Add Missing Punch</h3><p>Use this when only <strong>one side</strong> of a shift is missing. Select the employee, IN/OUT type, date, time, and optional note.</p><h3>Add Missing Shift</h3><p>Use this when the <strong>entire shift</strong> is missing. Enter the date plus both Clock In and Clock Out. The plugin validates the pair together so the new IN does not get blocked merely because the next existing record is another IN.</p><p>Management-entered corrections are approved immediately and recorded in the audit history with the manager and entry timestamp.</p></section>
            <section class="pf-tc-help-card pf-tc-help-wide"><h2>7. Which Tool Do I Use?</h2><table class="pf-tc-help-table"><thead><tr><th>Situation</th><th>Use</th></tr></thead><tbody><tr><td>Employee forgot one IN or OUT</td><td><strong>Add Missing Punch</strong></td></tr><tr><td>Employee's entire shift/day is absent</td><td><strong>Add Missing Shift</strong></td></tr><tr><td>Employee submitted their own correction</td><td><strong>Generate Report → Review</strong></td></tr><tr><td>Existing completed shift has the wrong time</td><td><strong>Daily Breakdown → Edit</strong></td></tr><tr><td>Accidental, duplicate, or test record should not count</td><td><strong>Ignore Record</strong></td></tr><tr><td>You need to understand what happened to a record</td><td><strong>Details</strong></td></tr><tr><td>You need one employee's time card</td><td>Select that employee in <strong>Generate Report</strong></td></tr></tbody></table></section>
            <section class="pf-tc-help-card"><h2>8. Reviews &amp; Report Locking</h2><p>Select <strong>All Employees</strong> or one employee, choose the date range, and generate the report. If the selected report scope contains unresolved exceptions, final totals and exports stay locked.</p><ul><li><strong>Accept</strong> — approve exactly what the employee submitted.</li><li><strong>Edit</strong> — change the value and approve it as management.</li><li><strong>Add Missing Clock Out</strong> — supply an OUT for an unmatched IN.</li><li><strong>Ignore Record</strong> — exclude a bad punch without deleting it.</li><li><strong>Details</strong> — inspect the record, nearby punches, and audit history.</li></ul><p>An unresolved record for another employee does not block an individual employee report.</p></section>
            <section class="pf-tc-help-card"><h2>9. Reports &amp; Exports</h2><p>Clean reports show total hours and a daily breakdown with weekday abbreviations, IN/OUT times, hours, and management actions.</p><p><strong>CSV</strong> is the straightforward data export. <strong>XLSX Time Card</strong> is the accountant-friendly formatted workbook.</p><p>When exporting <strong>All Employees</strong>, XLSX contains a Summary sheet plus a separate worksheet named for each visible employee. A single-employee export contains that employee's time card.</p><p>Hidden/system employees are excluded from normal report selectors, totals, daily breakdowns, CSV, and XLSX while hidden.</p></section>
            <section class="pf-tc-help-card"><h2>10. Audit History &amp; Safety</h2><p>The plugin preserves the history behind employee manual entries, management edits, approvals, ignored records, and management-added punches/shifts.</p><p><strong>Ignore Record does not delete the record.</strong> It removes it from calculations while preserving the underlying history.</p><p>Use <strong>Details</strong> for a read-only view of a record's source, review state, submission information, surrounding punches, and audit events.</p></section>
            <section class="pf-tc-help-card"><h2>11. Time &amp; Calculation Behavior</h2><ul><li>Normal kiosk punches use server-authoritative timestamps.</li><li>Times are stored consistently and displayed using the WordPress site's configured timezone.</li><li>Hours are calculated from approved IN/OUT pairs in the requested report period.</li><li>Employee-entered corrections require review before they can contribute to a finalized report.</li><li>Management-entered corrections are approved immediately but remain auditable.</li></ul></section>
            <section class="pf-tc-help-card"><h2>12. Scope of the Plugin</h2><p>PF Employee Time Clock replaces basic paper clock-in/out sheets and produces reviewed hour totals and time cards.</p><p>It is intentionally <strong>not</strong> a payroll, tax, scheduling, PTO, benefits, or full HR system. Payroll rules and payment processing remain outside the plugin.</p></section>
          </div>
          <div class="pf-tc-help-sign">PF Employee Time Clock v<?php echo esc_html(PF_TC_VERSION); ?> &nbsp;—&nbsp; by KiasAreCool</div>
        </div>
        <?php
    }

    public function profile_fields($user){ if(!current_user_can('edit_users')) return; ?>
      <h2>Employee Time Clock</h2><table class="form-table"><tbody>
      <tr><th>Employee</th><td><label><input type="checkbox" name="pf_tc_employee" value="1" <?php checked($this->yes($user->ID,self::META_EMP));?>> This user is an employee</label></td></tr>
      <tr><th><label for="pf_tc_pin">4-digit Time Clock PIN</label></th><td><input id="pf_tc_pin" name="pf_tc_pin" type="password" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="new-password" class="regular-text"><p class="description">Leave blank to keep the current PIN. PINs are stored hashed.</p></td></tr>
      <tr><th>Clock list</th><td><label><input type="checkbox" name="pf_tc_hidden" value="1" <?php checked($this->yes($user->ID,self::META_HIDDEN));?>> Hide this user from the employee clock-in list</label></td></tr>
      <tr><th>Time records</th><td><label><input type="checkbox" name="pf_tc_manager" value="1" <?php checked($this->yes($user->ID,self::META_MANAGER));?>> This user has access to employee time records</label></td></tr>
      </tbody></table><?php
    }
    public function save_profile($uid){
        if(!current_user_can('edit_user',$uid)) return;
        update_user_meta($uid,self::META_EMP,isset($_POST['pf_tc_employee'])?'1':'0');
        update_user_meta($uid,self::META_HIDDEN,isset($_POST['pf_tc_hidden'])?'1':'0');
        update_user_meta($uid,self::META_MANAGER,isset($_POST['pf_tc_manager'])?'1':'0');
        if(isset($_POST['pf_tc_pin']) && $_POST['pf_tc_pin']!==''){
            $pin=preg_replace('/\D/','',wp_unslash($_POST['pf_tc_pin']));
            if(strlen($pin)===4) update_user_meta($uid,self::META_PIN,wp_hash_password($pin));
        }
    }
    public function assets(){
        wp_register_style('pf-tc',PF_TC_URL.'assets/time-clock.css',[],PF_TC_VERSION); wp_register_script('pf-tc',PF_TC_URL.'assets/time-clock.js',['jquery'],PF_TC_VERSION,true);
        wp_localize_script('pf-tc','PFTC',['ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('pf_tc'),'adminPost'=>admin_url('admin-post.php')]);
    }
    private function enqueue(){ wp_enqueue_style('pf-tc'); wp_enqueue_script('pf-tc'); }
    private function employees(){
        $q=new WP_User_Query(['meta_query'=>[['key'=>self::META_EMP,'value'=>'1'],['key'=>self::META_HIDDEN,'value'=>'1','compare'=>'!=']], 'orderby'=>'display_name','order'=>'ASC']);
        // Users without the hidden meta need inclusion too.
        $q=new WP_User_Query(['meta_query'=>['relation'=>'AND',['key'=>self::META_EMP,'value'=>'1'],['relation'=>'OR',['key'=>self::META_HIDDEN,'compare'=>'NOT EXISTS'],['key'=>self::META_HIDDEN,'value'=>'1','compare'=>'!=']]],'orderby'=>'display_name','order'=>'ASC']);
        return $q->get_results();
    }
    public function clock_shortcode(){
        if(!$this->current_can_clock()) return '<div class="pf-tc-denied">You do not have access to the employee time clock.</div>';
        $this->enqueue(); $emps=$this->employees(); ob_start(); ?>
        <div class="pf-tc pf-tc-kiosk"><h2>Employee Time Clock</h2>
          <label class="pf-label">Employee</label><select id="pf-tc-employee"><option value="">Select your name…</option><?php foreach($emps as $e):?><option value="<?php echo esc_attr($e->ID);?>"><?php echo esc_html($e->display_name);?></option><?php endforeach;?></select>
          <div id="pf-tc-state" class="pf-card pf-hidden"></div><div id="pf-tc-actions" class="pf-hidden"></div><div id="pf-tc-message"></div>
        </div><?php return ob_get_clean();
    }
    private function last_punch($uid){ global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->punches} WHERE user_id=%d AND review_status!='ignored' ORDER BY work_time DESC,id DESC LIMIT 1",$uid)); }
    private function missing_out($uid){
        $last=$this->last_punch($uid); if(!$last || $last->punch_type!=='in') return false;
        $today=wp_date('Y-m-d',time(),wp_timezone()); $day=$this->local_date($last->work_time); return $day < $today ? $last : false;
    }
    private function check_pin($uid,$pin){ $h=get_user_meta($uid,self::META_PIN,true); return $h && wp_check_password((string)$pin,$h); }
    private function verify_ajax(){ check_ajax_referer('pf_tc','nonce'); if(!$this->current_can_clock()) wp_send_json_error(['message'=>'Access denied.'],403); }
    public function ajax_state(){ $this->verify_ajax(); $uid=absint($_POST['employee_id']??0); if(!$this->yes($uid,self::META_EMP)||$this->yes($uid,self::META_HIDDEN)) wp_send_json_error(['message'=>'Invalid employee.']);
        $u=get_userdata($uid); $last=$this->last_punch($uid); $missing=$this->missing_out($uid);
        if($missing) wp_send_json_success(['name'=>$u->display_name,'state'=>'missing','last_time'=>$this->display_dt($missing->work_time),'last_date'=>$this->local_date($missing->work_time),'last_clock'=>$this->display_dt($missing->work_time,'H:i'),'last_type'=>$missing->punch_type,'last_id'=>(int)$missing->id,'last_review'=>$missing->review_status,'in_id'=>$missing->id]);
        $state=($last&&$last->punch_type==='in')?'in':'out';
        wp_send_json_success(['name'=>$u->display_name,'state'=>$state,'last_time'=>$last?$this->display_dt($last->work_time):null,'last_date'=>$last?$this->local_date($last->work_time):'','last_clock'=>$last?$this->display_dt($last->work_time,'H:i'):'','last_type'=>$last?$last->punch_type:'','last_id'=>$last?(int)$last->id:0,'last_review'=>$last?$last->review_status:'']);
    }
    public function ajax_punch(){ $this->verify_ajax(); global $wpdb; $uid=absint($_POST['employee_id']??0); $type=sanitize_key($_POST['type']??''); $pin=(string)($_POST['pin']??'');
        if(!in_array($type,['in','out'],true)||!$this->yes($uid,self::META_EMP)||$this->yes($uid,self::META_HIDDEN)) wp_send_json_error(['message'=>'Invalid request.']);
        if(!$this->check_pin($uid,$pin)) wp_send_json_error(['message'=>'Incorrect PIN.']); if($this->missing_out($uid)) wp_send_json_error(['message'=>'Resolve the missing clock-out first.']);
        $last=$this->last_punch($uid); $expected=($last&&$last->punch_type==='in')?'out':'in'; if($type!==$expected) wp_send_json_error(['message'=>'That action does not match the employee’s current clock state.']);
        $now=$this->now_mysql(); $wpdb->insert($this->punches,['user_id'=>$uid,'punch_type'=>$type,'work_time'=>$now,'submitted_at'=>$now,'source'=>'kiosk','review_status'=>'approved','created_by'=>get_current_user_id()],['%d','%s','%s','%s','%s','%s','%d']);
        $this->audit($wpdb->insert_id,$uid,get_current_user_id(),'normal_'.$type,null,['work_time'=>$now]);
        wp_send_json_success(['message'=>get_userdata($uid)->display_name.' clocked '.strtoupper($type).' at '.$this->display_dt($now,'g:i A').'.']);
    }
    public function ajax_manual_out(){ $this->verify_ajax(); global $wpdb; $uid=absint($_POST['employee_id']??0); $pin=(string)($_POST['pin']??''); $time=sanitize_text_field($_POST['time']??''); $missing=$this->missing_out($uid);
        if(!$missing) wp_send_json_error(['message'=>'No missing clock-out found.']); if(!$this->check_pin($uid,$pin)) wp_send_json_error(['message'=>'Incorrect PIN.']);
        $date=$this->local_date($missing->work_time); $work=$this->local_input_to_utc($date,$time);
        if(!$work) wp_send_json_error(['message'=>'Enter a valid time.']);
        if($this->utc_ts($work)<=$this->utc_ts($missing->work_time)||$this->local_date($work)!==$date) wp_send_json_error(['message'=>'Clock-out must be after the clock-in and on the same day.']);
        $now=$this->now_mysql(); $wpdb->insert($this->punches,['user_id'=>$uid,'punch_type'=>'out','work_time'=>$work,'submitted_at'=>$now,'source'=>'employee_manual','review_status'=>'pending','related_punch_id'=>$missing->id,'created_by'=>$uid],['%d','%s','%s','%s','%s','%s','%d','%d']);
        $this->audit($wpdb->insert_id,$uid,$uid,'employee_manual_out',null,['work_time'=>$work,'submitted_at'=>$now]); wp_send_json_success(['message'=>'Missing clock-out submitted for management review. You can now clock in normally.']);
    }
    public function ajax_employee_edit_last(){
        $this->verify_ajax(); global $wpdb;
        $uid=absint($_POST['employee_id']??0); $pin=(string)($_POST['pin']??''); $date=sanitize_text_field($_POST['date']??''); $time=sanitize_text_field($_POST['time']??'');
        if(!$this->yes($uid,self::META_EMP)||$this->yes($uid,self::META_HIDDEN)) wp_send_json_error(['message'=>'Invalid employee.']);
        if(!$this->check_pin($uid,$pin)) wp_send_json_error(['message'=>'Incorrect PIN.']);
        $p=$this->last_punch($uid); if(!$p) wp_send_json_error(['message'=>'There is no previous punch to edit.']);
        if($p->review_status==='pending') wp_send_json_error(['message'=>'Your last punch is already waiting for management review.']);
        $work=$this->local_input_to_utc($date,$time); if(!$work) wp_send_json_error(['message'=>'Choose a valid date and time.']);
        if($this->utc_ts($work)>time()+60) wp_send_json_error(['message'=>'A punch cannot be moved into the future.']);
        $old=['work_time'=>$p->work_time,'source'=>$p->source,'review_status'=>$p->review_status];
        $wpdb->update($this->punches,['work_time'=>$work,'source'=>'employee_manual_edit','review_status'=>'pending'],['id'=>$p->id]);
        $this->audit($p->id,$uid,$uid,'employee_edit_last',$old,['work_time'=>$work,'source'=>'employee_manual_edit','review_status'=>'pending']);
        wp_send_json_success(['message'=>'Your edited punch was submitted for management review.']);
    }
    public function ajax_employee_add_punch(){
        $this->verify_ajax(); global $wpdb;
        $uid=absint($_POST['employee_id']??0); $pin=(string)($_POST['pin']??''); $type=sanitize_key($_POST['type']??''); $date=sanitize_text_field($_POST['date']??''); $time=sanitize_text_field($_POST['time']??'');
        if(!$this->yes($uid,self::META_EMP)||$this->yes($uid,self::META_HIDDEN)) wp_send_json_error(['message'=>'Invalid employee.']);
        if(!$this->check_pin($uid,$pin)) wp_send_json_error(['message'=>'Incorrect PIN.']);
        if(!in_array($type,['in','out'],true)) wp_send_json_error(['message'=>'Choose Clock In or Clock Out.']);
        $work=$this->local_input_to_utc($date,$time); if(!$work) wp_send_json_error(['message'=>'Choose a valid date and time.']);
        if($this->utc_ts($work)>time()+60) wp_send_json_error(['message'=>'A punch cannot be added in the future.']);
        $now=$this->now_mysql();
        $wpdb->insert($this->punches,['user_id'=>$uid,'punch_type'=>$type,'work_time'=>$work,'submitted_at'=>$now,'source'=>'employee_manual_add','review_status'=>'pending','created_by'=>$uid],['%d','%s','%s','%s','%s','%s','%d']);
        $id=$wpdb->insert_id; $this->audit($id,$uid,$uid,'employee_add_missing',null,['punch_type'=>$type,'work_time'=>$work,'submitted_at'=>$now,'review_status'=>'pending']);
        wp_send_json_success(['message'=>'Missing '.strtoupper($type).' punch submitted for management review.']);
    }

    private function audit($pid,$eid,$actor,$action,$old=null,$new=null,$note=''){ global $wpdb; $wpdb->insert($this->audit,['punch_id'=>$pid,'employee_id'=>$eid,'actor_id'=>$actor,'action'=>$action,'old_value'=>$old?wp_json_encode($old):null,'new_value'=>$new?wp_json_encode($new):null,'note'=>$note,'created_at'=>$this->now_mysql()]); }

    public function admin_shortcode(){ if(!$this->current_can_manage()) return '<div class="pf-tc-denied">You do not have access to employee time records.</div>'; $this->enqueue(); $emps=$this->employees(); ob_start(); ?>
      <div class="pf-tc pf-tc-admin"><h2>Employee Time Records</h2><?php echo $this->clocked_in_html(); ?>
      <div class="pf-report-controls"><label>Employee <select id="pf-report-employee"><option value="0">All Employees</option><?php foreach($emps as $e):?><option value="<?php echo esc_attr($e->ID);?>"><?php echo esc_html($e->display_name);?></option><?php endforeach;?></select></label><label>Start date <input type="date" id="pf-start"></label><label>End date <input type="date" id="pf-end"></label><button class="pf-btn" id="pf-run-report">Generate Report</button><a class="pf-btn pf-add-missing-admin" id="pf-admin-add-missing" href="<?php echo esc_url(admin_url('admin.php?page=pf-time-clock-add-punch'));?>">+ Add Missing Punch</a></div>
      <div id="pf-report"></div></div><?php return ob_get_clean(); }
    private function clocked_in_html(){ global $wpdb; $users=$this->employees(); $rows=[]; foreach($users as $u){ $p=$this->last_punch($u->ID); if($p&&$p->punch_type==='in') $rows[]='<li><strong>'.esc_html($u->display_name).'</strong> — since '.esc_html($this->display_dt($p->work_time,'M j, g:i A')).'</li>'; }
        return '<div class="pf-card"><h3>Currently Clocked In ('.count($rows).')</h3>'.($rows?'<ul>'.implode('',$rows).'</ul>':'<p>Nobody is currently clocked in.</p>').'</div>'; }
    private function report_data($start,$end,$employee_id=0){
        global $wpdb; [$start_sql,$end_sql]=$this->local_range_to_utc($start,$end); $employee_id=absint($employee_id);
        if($employee_id){
            // Individual reports are only valid for active, visible employees.
            $scope=($this->yes($employee_id,self::META_EMP) && !$this->yes($employee_id,self::META_HIDDEN)) ? $wpdb->prepare(' AND p.user_id=%d',$employee_id) : ' AND 1=0';
        } else {
            // All Employees must use the same visible employee scope as the kiosk/report selector.
            // Hidden/system accounts retain their history, but are excluded from payroll reports and exports.
            $visible_ids=array_map('intval',wp_list_pluck($this->employees(),'ID'));
            $scope=$visible_ids ? ' AND p.user_id IN ('.implode(',', $visible_ids).')' : ' AND 1=0';
        }
        $pending=$wpdb->get_results($wpdb->prepare("SELECT p.*,u.display_name FROM {$this->punches} p JOIN {$wpdb->users} u ON u.ID=p.user_id WHERE p.work_time BETWEEN %s AND %s AND p.review_status='pending'{$scope} ORDER BY p.work_time",$start_sql,$end_sql));
        $punches=$wpdb->get_results($wpdb->prepare("SELECT p.*,u.display_name FROM {$this->punches} p JOIN {$wpdb->users} u ON u.ID=p.user_id WHERE p.work_time BETWEEN %s AND %s AND p.review_status!='ignored'{$scope} ORDER BY p.user_id,p.work_time,p.id",$start_sql,$end_sql));
        $open=[];$pairs=[];$missing=[]; foreach($punches as $p){ if($p->punch_type==='in'){ if(isset($open[$p->user_id])) $missing[]=$open[$p->user_id]; $open[$p->user_id]=$p; } else { if(isset($open[$p->user_id])){ $pairs[]=[$open[$p->user_id],$p]; unset($open[$p->user_id]); } } }
        foreach($open as $o){ if($this->local_date($o->work_time) < wp_date('Y-m-d',time(),wp_timezone()) || $end < wp_date('Y-m-d',time(),wp_timezone())) $missing[]=$o; }
        return compact('pending','pairs','missing');
    }
    public function ajax_report(){ check_ajax_referer('pf_tc','nonce'); if(!$this->current_can_manage()) wp_send_json_error(['message'=>'Access denied.'],403); $s=sanitize_text_field($_POST['start']??'');$e=sanitize_text_field($_POST['end']??'');$uid=absint($_POST['employee_id']??0); if(!$this->valid_dates($s,$e)) wp_send_json_error(['message'=>'Choose a valid date range.']); wp_send_json_success(['html'=>$this->report_html($s,$e,$uid)]); }
    private function valid_dates($s,$e){ return preg_match('/^\d{4}-\d{2}-\d{2}$/',$s)&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$e)&&$s<=$e; }
    private function report_html($s,$e,$uid=0){ $d=$this->report_data($s,$e,$uid); $issues=[]; foreach($d['pending'] as $p){ $kind=$p->source==='employee_manual_edit'?'Employee edited last '.strtoupper($p->punch_type):($p->source==='employee_manual_add'?'Employee added missing '.strtoupper($p->punch_type):'Manual '.strtoupper($p->punch_type)); $issues[]='<tr><td>'.esc_html($p->display_name).'</td><td>'.esc_html($kind).' — '.esc_html($this->display_dt($p->work_time,'D, M j — g:i A')).'</td><td><button class="pf-link pf-review" data-id="'.$p->id.'" data-mode="accept">Accept</button> · <button class="pf-link pf-edit-review" data-id="'.$p->id.'" data-time="'.esc_attr($this->display_dt($p->work_time,'H:i')).'">Edit</button> · <button class="pf-link pf-details" data-id="'.$p->id.'">Details</button></td></tr>'; }
        foreach($d['missing'] as $p){ global $wpdb; $next_in=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->punches} WHERE user_id=%d AND punch_type='in' AND review_status!='ignored' AND (work_time>%s OR (work_time=%s AND id>%d)) ORDER BY work_time,id LIMIT 1",$p->user_id,$p->work_time,$p->work_time,$p->id)); $cutoff=$next_in ? ' (must be before '.$this->display_dt($next_in->work_time,'g:i A').')' : ''; $issues[]='<tr><td>'.esc_html($p->display_name).'</td><td>Missing clock out — '.esc_html($this->display_dt($p->work_time,'D, M j')).esc_html($cutoff).'</td><td><button class="pf-link pf-manager-add-out" data-in-id="'.$p->id.'" data-date="'.esc_attr($this->local_date($p->work_time)).'">Add Missing Clock Out</button> · <button class="pf-link pf-ignore-unmatched" data-in-id="'.$p->id.'">Ignore Record</button> · <button class="pf-link pf-details" data-id="'.$p->id.'">Details</button></td></tr>'; }
        if($issues) return '<div class="pf-warning"><h3>⚠ '.count($issues).' '.(count($issues)===1?'entry needs':'entries need').' review before totals can be generated</h3><table class="pf-table"><thead><tr><th>Employee</th><th>Entry requiring review</th><th>Actions</th></tr></thead><tbody>'.implode('',$issues).'</tbody></table><p class="pf-muted">Totals and exports remain locked until all exceptions in this report are resolved.</p></div>';
        $daily=[];$totals=[]; foreach($d['pairs'] as [$in,$out]){ $sec=max(0,$this->utc_ts($out->work_time)-$this->utc_ts($in->work_time));$hrs=$sec/3600;$day=$this->local_date($in->work_time);$name=$in->display_name; $daily[]=['name'=>$name,'date'=>$day,'in'=>$in->work_time,'out'=>$out->work_time,'in_id'=>(int)$in->id,'out_id'=>(int)$out->id,'hours'=>$hrs]; $totals[$name]=($totals[$name]??0)+$hrs; }
        ksort($totals); $h='<div class="pf-success"><h3>✓ Report Ready</h3></div><h3>Employee Totals</h3><table class="pf-table"><thead><tr><th>Employee</th><th>Total Hours</th></tr></thead><tbody>'; foreach($totals as $n=>$hrs)$h.='<tr><td>'.esc_html($n).'</td><td>'.number_format($hrs,2).'</td></tr>'; if(!$totals)$h.='<tr><td colspan="2">No completed time records in this period.</td></tr>'; $h.='</tbody></table><h3>Daily Breakdown</h3><table class="pf-table"><thead><tr><th>Employee</th><th>Date</th><th>Clock In</th><th>Clock Out</th><th>Hours</th><th>Actions</th></tr></thead><tbody>'; foreach($daily as $r)$h.='<tr><td>'.esc_html($r['name']).'</td><td>'.esc_html(wp_date('D, M j, Y',strtotime($r['date'].' 12:00:00'),wp_timezone())).'</td><td>'.esc_html($this->display_dt($r['in'],'g:i A')).'</td><td>'.esc_html($this->display_dt($r['out'],'g:i A')).'</td><td>'.number_format($r['hours'],2).'</td><td class="pf-row-actions"><button class="pf-link pf-edit-pair" data-in-id="'.$r['in_id'].'" data-out-id="'.$r['out_id'].'" data-in-time="'.esc_attr($this->display_dt($r['in'],'H:i')).'" data-out-time="'.esc_attr($this->display_dt($r['out'],'H:i')).'">Edit</button> · <button class="pf-link pf-ignore-pair" data-in-id="'.$r['in_id'].'" data-out-id="'.$r['out_id'].'">Ignore Record</button> · <button class="pf-link pf-details" data-id="'.$r['in_id'].'" data-related-id="'.$r['out_id'].'">Details</button></td></tr>'; if(!$daily)$h.='<tr><td colspan="6">No completed time records in this period.</td></tr>'; $h.='</tbody></table>';
        $base=admin_url('admin-post.php'); $args=['start'=>$s,'end'=>$e,'employee_id'=>absint($uid),'_wpnonce'=>wp_create_nonce('pf_tc_export')]; $h.='<div class="pf-exports"><a class="pf-btn" href="'.esc_url(add_query_arg(array_merge($args,['action'=>'pf_tc_export_csv']),$base)).'">Export CSV</a> <a class="pf-btn" href="'.esc_url(add_query_arg(array_merge($args,['action'=>'pf_tc_export_xlsx']),$base)).'">Export XLSX Time Card</a></div>'; return $h;
    }

    public function ajax_manager_pair_action(){
        check_ajax_referer('pf_tc','nonce'); if(!$this->current_can_manage()) wp_send_json_error(['message'=>'Access denied.'],403); global $wpdb;
        $in_id=absint($_POST['in_id']??0); $out_id=absint($_POST['out_id']??0); $mode=sanitize_key($_POST['mode']??''); $note=sanitize_text_field($_POST['note']??'');
        $in=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->punches} WHERE id=%d AND punch_type='in'",$in_id));
        $out=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->punches} WHERE id=%d AND punch_type='out'",$out_id));
        if(!$in||!$out||$in->user_id!=$out->user_id) wp_send_json_error(['message'=>'Time record not found.']);
        if($mode==='ignore'){
            $oldin=['work_time'=>$in->work_time,'review_status'=>$in->review_status]; $oldout=['work_time'=>$out->work_time,'review_status'=>$out->review_status];
            $wpdb->update($this->punches,['review_status'=>'ignored','note'=>$note],['id'=>$in_id]); $wpdb->update($this->punches,['review_status'=>'ignored','note'=>$note],['id'=>$out_id]);
            $this->audit($in_id,$in->user_id,get_current_user_id(),'manager_ignore_record',$oldin,['review_status'=>'ignored'],$note); $this->audit($out_id,$out->user_id,get_current_user_id(),'manager_ignore_record',$oldout,['review_status'=>'ignored'],$note);
            wp_send_json_success(['message'=>'Record ignored. It remains in the audit history but is excluded from totals and exports.']);
        }
        if($mode==='edit'){
            $date=$this->local_date($in->work_time); $in_time=sanitize_text_field($_POST['in_time']??''); $out_time=sanitize_text_field($_POST['out_time']??'');
            $newin=$this->local_input_to_utc($date,$in_time); $newout=$this->local_input_to_utc($date,$out_time);
            if(!$newin||!$newout) wp_send_json_error(['message'=>'Enter valid clock-in and clock-out times.']);
            if($this->utc_ts($newout)<=$this->utc_ts($newin)) wp_send_json_error(['message'=>'Clock-out must be after clock-in.']);
            $oldin=['work_time'=>$in->work_time,'review_status'=>$in->review_status]; $oldout=['work_time'=>$out->work_time,'review_status'=>$out->review_status];
            $wpdb->update($this->punches,['work_time'=>$newin,'source'=>'manager_correction','review_status'=>'approved','note'=>$note],['id'=>$in_id]); $wpdb->update($this->punches,['work_time'=>$newout,'source'=>'manager_correction','review_status'=>'approved','note'=>$note],['id'=>$out_id]);
            $this->audit($in_id,$in->user_id,get_current_user_id(),'manager_edit_record',$oldin,['work_time'=>$newin,'review_status'=>'approved'],$note); $this->audit($out_id,$out->user_id,get_current_user_id(),'manager_edit_record',$oldout,['work_time'=>$newout,'review_status'=>'approved'],$note);
            wp_send_json_success(['message'=>'Time record updated.']);
        }
        wp_send_json_error(['message'=>'Invalid action.']);
    }

    public function ajax_manager_ignore_unmatched(){
        check_ajax_referer('pf_tc','nonce'); if(!$this->current_can_manage()) wp_send_json_error(['message'=>'Access denied.'],403); global $wpdb;
        $id=absint($_POST['in_id']??0); $note=sanitize_text_field($_POST['note']??'');
        $p=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->punches} WHERE id=%d AND punch_type='in'",$id));
        if(!$p) wp_send_json_error(['message'=>'Clock-in record not found.']);
        $old=['work_time'=>$p->work_time,'review_status'=>$p->review_status];
        $wpdb->update($this->punches,['review_status'=>'ignored','note'=>$note],['id'=>$id]);
        $this->audit($id,$p->user_id,get_current_user_id(),'manager_ignore_unmatched',$old,['review_status'=>'ignored'],$note);
        wp_send_json_success(['message'=>'Unmatched clock-in ignored. It remains in the audit history but is excluded from state, reports, totals, and exports.']);
    }

    public function ajax_manager_add_missing_out(){
        check_ajax_referer('pf_tc','nonce'); if(!$this->current_can_manage()) wp_send_json_error(['message'=>'Access denied.'],403); global $wpdb;
        $in_id=absint($_POST['in_id']??0); $time=sanitize_text_field($_POST['time']??''); $note=sanitize_text_field($_POST['note']??'');
        $in=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->punches} WHERE id=%d AND punch_type='in'",$in_id)); if(!$in) wp_send_json_error(['message'=>'Missing clock-in record not found.']);
        $date=$this->local_date($in->work_time); $work=$this->local_input_to_utc($date,$time); if(!$work) wp_send_json_error(['message'=>'Invalid clock-out time.']);
        if($this->utc_ts($work)<=$this->utc_ts($in->work_time)) wp_send_json_error(['message'=>'Clock-out must be after clock-in.']);
        $next=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->punches} WHERE user_id=%d AND work_time>%s AND work_time<=%s AND punch_type='in' ORDER BY work_time,id LIMIT 1",$in->user_id,$in->work_time,$work));
        if($next) wp_send_json_error(['message'=>'That clock-out would occur after another clock-in. Choose an earlier time.']);
        $now=$this->now_mysql(); $wpdb->insert($this->punches,['user_id'=>$in->user_id,'punch_type'=>'out','work_time'=>$work,'submitted_at'=>$now,'source'=>'manager_correction','review_status'=>'approved','related_punch_id'=>$in->id,'created_by'=>get_current_user_id(),'note'=>$note],['%d','%s','%s','%s','%s','%s','%d','%d','%s']);
        $id=$wpdb->insert_id; $this->audit($id,$in->user_id,get_current_user_id(),'manager_add_missing_out',null,['punch_type'=>'out','work_time'=>$work,'related_punch_id'=>$in->id,'status'=>'approved'],$note); wp_send_json_success(['message'=>'Missing clock-out added and approved.']);
    }

    public function ajax_details(){
        check_ajax_referer('pf_tc','nonce'); if(!$this->current_can_manage()) wp_send_json_error(['message'=>'Access denied.'],403); global $wpdb;
        $id=absint($_POST['punch_id']??0); $related=absint($_POST['related_id']??0);
        $p=$wpdb->get_row($wpdb->prepare("SELECT p.*,u.display_name FROM {$this->punches} p JOIN {$wpdb->users} u ON u.ID=p.user_id WHERE p.id=%d",$id)); if(!$p) wp_send_json_error(['message'=>'Time record not found.']);
        $ids=[$id]; if($related){$rp=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->punches} WHERE id=%d AND user_id=%d",$related,$p->user_id));if($rp)$ids[]=$related;} if(!$related&&$p->related_punch_id)$ids[]=(int)$p->related_punch_id; $ids=array_values(array_unique(array_filter(array_map('absint',$ids))));
        $ph=implode(',',array_fill(0,count($ids),'%d')); $aud=$wpdb->get_results($wpdb->prepare("SELECT a.*,u.display_name actor_name FROM {$this->audit} a LEFT JOIN {$wpdb->users} u ON u.ID=a.actor_id WHERE a.punch_id IN ($ph) ORDER BY a.created_at,a.id",...$ids));
        $near=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->punches} WHERE user_id=%d AND work_time BETWEEN DATE_SUB(%s,INTERVAL 3 DAY) AND DATE_ADD(%s,INTERVAL 3 DAY) ORDER BY work_time,id",$p->user_id,$p->work_time,$p->work_time)); $label=function($v){return ucwords(str_replace('_',' ',(string)$v));};
        $h='<div class="pf-detail-head"><h3>Time Record Details — '.esc_html($p->display_name).'</h3><button type="button" class="pf-detail-close" aria-label="Close">×</button></div><div class="pf-detail-summary"><div><strong>Record</strong><br>'.esc_html(strtoupper($p->punch_type)).' — '.esc_html($this->display_dt($p->work_time)).'</div><div><strong>Source</strong><br>'.esc_html($label($p->source)).'</div><div><strong>Status</strong><br>'.esc_html($label($p->review_status)).'</div><div><strong>Submitted</strong><br>'.esc_html($this->display_dt($p->submitted_at)).'</div></div>';
        if($p->note)$h.='<p><strong>Note:</strong> '.esc_html($p->note).'</p>'; $h.='<h4>Nearby Punches</h4><table class="pf-table pf-detail-table"><thead><tr><th>Date / Time</th><th>Type</th><th>Source</th><th>Status</th></tr></thead><tbody>';
        foreach($near as $n){$focus=in_array((int)$n->id,$ids,true)?' class="pf-detail-focus"':'';$h.='<tr'.$focus.'><td>'.esc_html($this->display_dt($n->work_time)).'</td><td>'.esc_html(strtoupper($n->punch_type)).'</td><td>'.esc_html($label($n->source)).'</td><td>'.esc_html($label($n->review_status)).'</td></tr>';} $h.='</tbody></table><h4>Audit History</h4>';
        if(!$aud)$h.='<p class="pf-muted">No audit entries are recorded for this punch.</p>'; else{$h.='<div class="pf-audit-list">';foreach($aud as $a){$actor=$a->actor_name?:($a->actor_id?'User #'.$a->actor_id:'System');$h.='<div class="pf-audit-item"><strong>'.esc_html($this->display_dt($a->created_at)).'</strong> — '.esc_html($label($a->action)).' <span class="pf-muted">by '.esc_html($actor).'</span>';if($a->note)$h.='<div>'.esc_html($a->note).'</div>';$h.='</div>';}$h.='</div>';} wp_send_json_success(['html'=>$h]);
    }

    public function ajax_review(){ check_ajax_referer('pf_tc','nonce'); if(!$this->current_can_manage()) wp_send_json_error(['message'=>'Access denied.'],403); global $wpdb; $id=absint($_POST['punch_id']??0); $p=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->punches} WHERE id=%d",$id)); if(!$p||$p->review_status!=='pending') wp_send_json_error(['message'=>'Review item not found.']); $mode=sanitize_key($_POST['mode']??'accept'); $old=['work_time'=>$p->work_time,'status'=>$p->review_status]; $newtime=$p->work_time;
        if($mode==='edit'){ $t=sanitize_text_field($_POST['time']??''); $date=$this->local_date($p->work_time); $newtime=$this->local_input_to_utc($date,$t); if(!$newtime) wp_send_json_error(['message'=>'Invalid time.']); $in=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->punches} WHERE id=%d",$p->related_punch_id)); if($in&&$this->utc_ts($newtime)<=$this->utc_ts($in->work_time)) wp_send_json_error(['message'=>'Clock-out must be after clock-in.']); }
        $wpdb->update($this->punches,['work_time'=>$newtime,'review_status'=>'approved','note'=>sanitize_text_field($_POST['note']??$p->note)],['id'=>$id]); $this->audit($id,$p->user_id,get_current_user_id(),$mode==='edit'?'manager_edit_approve':'manager_accept',$old,['work_time'=>$newtime,'status'=>'approved'],sanitize_text_field($_POST['note']??'')); wp_send_json_success(['message'=>'Entry approved.']); }
    private function export_guard(){ if(!$this->current_can_manage()) wp_die('Access denied.'); check_admin_referer('pf_tc_export'); $s=sanitize_text_field($_GET['start']??'');$e=sanitize_text_field($_GET['end']??'');$uid=absint($_GET['employee_id']??0); if(!$this->valid_dates($s,$e)) wp_die('Invalid dates.'); $d=$this->report_data($s,$e,$uid); if($d['pending']||$d['missing']) wp_die('This report still contains entries requiring review.'); return [$s,$e,$uid,$d]; }
    private function export_rows($d){ $rows=[['Employee','Date','Clock In','Clock Out','Hours']]; foreach($d['pairs'] as [$in,$out]){ $hrs=($this->utc_ts($out->work_time)-$this->utc_ts($in->work_time))/3600; $rows[]=[$in->display_name,$this->local_date($in->work_time),$this->display_dt($in->work_time,'g:i A'),$this->display_dt($out->work_time,'g:i A'),number_format($hrs,2,'.','')]; } return $rows; }
    public function export_csv(){ [$s,$e,$uid,$d]=$this->export_guard(); $suffix=$uid?'-employee-'.$uid:''; nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="pf-time-clock'.$suffix.'-'.$s.'-to-'.$e.'.csv"'); $o=fopen('php://output','w'); foreach($this->export_rows($d) as $r) fputcsv($o,$r); fclose($o); exit; }
    public function export_xlsx(){ [$s,$e,$uid,$d]=$this->export_guard(); $this->timecard_xlsx($s,$e,$uid,$d,'pf-time-cards-'.$s.'-to-'.$e.'.xlsx'); }
    private function xml($v){ return htmlspecialchars((string)$v,ENT_XML1|ENT_QUOTES,'UTF-8'); }
    private function safe_sheet_name($name,$used=[]){ $name=str_replace(['\\','/','?','*','[',']',':'],'-',trim((string)$name)); $name=trim($name," '"); if($name==='')$name='Employee'; $cut=function($v,$len){ return function_exists('mb_substr') ? mb_substr($v,0,$len) : substr($v,0,$len); }; $strlen=function($v){ return function_exists('mb_strlen') ? mb_strlen($v) : strlen($v); }; $name=$cut($name,31); $base=$name;$i=2; while(in_array(strtolower($name),$used,true)){ $suffix=' '.$i++; $name=$cut($base,31-$strlen($suffix)).$suffix; } return $name; }
    private function sheet_xml($rows,$widths=[18,18,18,18],$title_rows=0){
        $xml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"/></sheetViews><cols>'; foreach($widths as $i=>$w)$xml.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.$w.'" customWidth="1"/>'; $xml.='</cols><sheetData>';
        foreach($rows as $ri=>$row){ $r=$ri+1;$xml.='<row r="'.$r.'">'; foreach($row as $ci=>$cell){ $ref=$this->col($ci).$r; $val=is_array($cell)?($cell['v']??''):$cell; $style=is_array($cell)?($cell['s']??0):0; $type=is_array($cell)?($cell['t']??'s'):'s'; if($type==='n') $xml.='<c r="'.$ref.'" s="'.$style.'"><v>'.(float)$val.'</v></c>'; else $xml.='<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t>'.$this->xml($val).'</t></is></c>'; } $xml.='</row>'; }
        $last=max(1,count($rows)); $xml.='</sheetData>'; if($title_rows>0)$xml.='<mergeCells count="2"><mergeCell ref="A1:D1"/><mergeCell ref="A2:D2"/></mergeCells>'; $xml.='<printOptions horizontalCentered="1"/><pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup orientation="portrait" fitToWidth="1" fitToHeight="0" paperSize="9"/></worksheet>'; return $xml; }
    private function timecard_xlsx($s,$e,$uid,$d,$filename){
        if(!class_exists('ZipArchive')) wp_die('XLSX export requires the PHP ZipArchive extension. CSV export is still available.');
        $by=[]; foreach($d['pairs'] as [$in,$out]){ $id=(int)$in->user_id; if(!isset($by[$id]))$by[$id]=['name'=>$in->display_name,'pairs'=>[]]; $by[$id]['pairs'][]=[$in,$out]; }
        if($uid && !isset($by[$uid])){ $u=get_userdata($uid); if($u)$by[$uid]=['name'=>$u->display_name,'pairs'=>[]]; }
        uasort($by,function($a,$b){return strcasecmp($a['name'],$b['name']);}); $sheets=[];
        if(!$uid){ $rows=[[['v'=>'THE PLANT FACTORY — EMPLOYEE TIME SUMMARY','s'=>1],['v'=>''],['v'=>''],['v'=>'']],[['v'=>$s.' through '.$e,'s'=>2],['v'=>''],['v'=>''],['v'=>'']],[],[['v'=>'Employee','s'=>3],['v'=>'Total Hours','s'=>3]]]; foreach($by as $x){$total=0;foreach($x['pairs'] as [$i,$o])$total+=($this->utc_ts($o->work_time)-$this->utc_ts($i->work_time))/3600;$rows[]=[['v'=>$x['name']],['v'=>round($total,2),'t'=>'n','s'=>4]];} $sheets[]=['name'=>'Summary','rows'=>$rows,'widths'=>[28,16,12,12],'title'=>1]; }
        foreach($by as $x){ $rows=[[['v'=>'THE PLANT FACTORY — EMPLOYEE TIME CARD','s'=>1],['v'=>''],['v'=>''],['v'=>'']],[['v'=>$x['name'].'  |  '.$s.' through '.$e,'s'=>2],['v'=>''],['v'=>''],['v'=>'']],[],[['v'=>'Date','s'=>3],['v'=>'Clock In','s'=>3],['v'=>'Clock Out','s'=>3],['v'=>'Daily Hours','s'=>3]]]; $total=0; foreach($x['pairs'] as [$in,$out]){$hrs=max(0,($this->utc_ts($out->work_time)-$this->utc_ts($in->work_time))/3600);$total+=$hrs;$rows[]=[['v'=>$this->display_dt($in->work_time,'D, M j, Y')],['v'=>$this->display_dt($in->work_time,'g:i A')],['v'=>$this->display_dt($out->work_time,'g:i A')],['v'=>round($hrs,2),'t'=>'n','s'=>4]];} $rows[]=[];$rows[]=[['v'=>''],['v'=>''],['v'=>'TOTAL HOURS','s'=>3],['v'=>round($total,2),'t'=>'n','s'=>5]];$rows[]=[];$rows[]=[['v'=>'Generated '.$this->display_dt($this->now_mysql(),'M j, Y g:i A').' — All exceptions reviewed','s'=>6]]; $sheets[]=['name'=>$x['name'],'rows'=>$rows,'widths'=>[22,18,18,18],'title'=>1]; }
        if(!$sheets)$sheets[]=['name'=>'Summary','rows'=>[[['v'=>'THE PLANT FACTORY — EMPLOYEE TIME SUMMARY','s'=>1]],[['v'=>'No completed time records for '.$s.' through '.$e]]],'widths'=>[45,18,18,18],'title'=>0];
        $used=[];foreach($sheets as &$sh){$sh['name']=$this->safe_sheet_name($sh['name'],$used);$used[]=strtolower($sh['name']);}unset($sh);
        $tmp=wp_tempnam($filename);$zip=new ZipArchive();$zip->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE); $types='<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'; foreach($sheets as $i=>$sh)$types.='<Override PartName="/xl/worksheets/sheet'.($i+1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'; $types.='</Types>';$zip->addFromString('[Content_Types].xml',$types);
        $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>'); $wb='<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'; $rels='<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'; foreach($sheets as $i=>$sh){$n=$i+1;$wb.='<sheet name="'.$this->xml($sh['name']).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';$rels.='<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';} $styleRid=count($sheets)+1;$rels.='<Relationship Id="rId'.$styleRid.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';$wb.='</sheets></workbook>';$zip->addFromString('xl/workbook.xml',$wb);$zip->addFromString('xl/_rels/workbook.xml.rels',$rels);
        $styles='<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="16"/><name val="Calibri"/></font><font><i/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="2"><border/><border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="7"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="3" fillId="0" borderId="1" xfId="0"/><xf numFmtId="2" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/><xf numFmtId="2" fontId="3" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"/></cellXfs></styleSheet>';$zip->addFromString('xl/styles.xml',$styles); foreach($sheets as $i=>$sh)$zip->addFromString('xl/worksheets/sheet'.($i+1).'.xml',$this->sheet_xml($sh['rows'],$sh['widths'],$sh['title'])); $zip->close(); nocache_headers();header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Content-Length: '.filesize($tmp));readfile($tmp);unlink($tmp);exit;
    }

    private function col($i){$s='';do{$s=chr(65+($i%26)).$s;$i=intdiv($i,26)-1;}while($i>=0);return $s;}
}
