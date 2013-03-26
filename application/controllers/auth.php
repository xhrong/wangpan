<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Auth extends MY_Controller {

    private $user_data;

    function __construct()
    {
        parent::__construct(false);
        $this->load->library('form_validation');
    }

    public function login()
    {
        $this->form_validation->set_error_delimiters('<span class="login-errorinfo">','</span>');
        
        if($this->session->userdata('id')){ redirect('user/'); }


        if (!$this->input->is_post()){
            // Set backurl.
            if (isset($_SERVER['HTTP_REFERER'])){
                $this->session->set_userdata('http_referer', $_SERVER['HTTP_REFERER']);
            }
        }else{
            $tmpReferer = FALSE;
            switch ($this->input->post('login_path')) {
                case 'index':
                    $tmpReferer = base_url('/');
                    break;
            }

            if( $tmpReferer !== FALSE){
                $this->session->set_userdata('http_referer', $tmpReferer);
            }
        }
        
        if ($this->input->is_post()){
            $this->load->model('user');
            
            $tmpLoginErrorTimes = intval($this->session->userdata('login_error_times'));
            
            if ($tmpLoginErrorTimes >= 3){
                $this->form_validation->set_rules('captcha', '验证码', 'trim|required|exact_length[4]|alpha_numeric|callback_check_captcha');
                $tmpRe = $this->check_captcha($this->input->post('captcha'));
                if ($tmpRe === FALSE){
                    $this->form_validation->error('captcha','','','验证码 错误');
                }else{
                    $this->form_validation->set_rules('username', '帐号', 'trim|required|min_length[4]|max_length[20]|alpha_numeric|callback_check_user_exist');
                    $this->form_validation->set_rules('password', '密码', 'trim|required|min_length[6]|max_length[20]|callback_check_password');
                }
            }else{
                $this->form_validation->set_rules('username', '帐号', 'trim|required|min_length[4]|max_length[20]|alpha_numeric|callback_check_user_exist');
                $this->form_validation->set_rules('password', '密码', 'trim|required|min_length[6]|max_length[20]|callback_check_password');
            }

            $tmpRe = $this->form_validation->run();

            if ($tmpRe === TRUE){
                $user_array = array(
                    'login_ip' => $this->input->ip_address(),
                    'login_time' => time(),
                );
                $this->user->update($user_array, array('id' => $this->user_data['id']));
                
                $user_data = array(
                    'id' => $this->user_data['id'],
                    'fid' => $this->user_data['fid'],
                    'email' => $this->user_data['email'],
                    'mobile' => $this->user_data['mobile'],
                    'username' => $this->user_data['username'],
                    'login_error_times' => 0
                );

                // 处理：日志，活跃用户。
                $this->load->helpers('date');
                $tmpTimeEnd = human_to_unix(mdate('%Y-%m-%d', time()).' 0:0:0');
                $tmpTimeStart = $tmpTimeEnd - 24*3600;

                if ( $this->user_data['create_time'] >= $tmpTimeStart AND $this->user_data['create_time'] <= $tmpTimeEnd ){
                    $user_data['regtime'] = $this->user_data['create_time'];
                }

                $this->session->set_userdata($user_data);
                
                if( $this->input->post('autologin') == 'yes' ){
                    $this->input->set_cookie('uid', $user_data['id'], 3600*24*7, '.xy.com');
                    $this->input->set_cookie('token', md5($user_data['id'].$this->user_data['password'].$this->config->config['sign_key']), 3600*12, '.xy.com');
                }
				$this->input->set_cookie('lastuname', $user_data['username'], 3600*24*7, '.xy.com');#最后登录用户名
                
                $this->_doSendLoginLog($user_data['id']);
                $this->_doDiscuzLogin($this->input->post('username'), $this->input->post('password'));

                // Get backurl
                $tmpBackurl = $this->session->userdata('http_referer');

                if ($tmpBackurl !== FALSE){
                    $this->session->unset_userdata('http_referer');

                    // 针对直接从论坛点过来的登陆操作，提供中间页调用api地址。
                    if (strpos($tmpBackurl, 'b.xy.com') !== FALSE){
                        if ($this->session->userdata('uc_user_synlogin')){
                            $tmpSynHTML = $this->session->userdata('uc_user_synlogin');
                            $this->session->unset_userdata('uc_user_synlogin');
                            $tmpPrint = '
                                正在跳转……
                                <div class="hide">
                                '.$tmpSynHTML.'
                                </div>
                                <script type="text/javascript">
                                window.onload = function(){
                                    location.href = "'.$tmpBackurl.'";
                                };
                                </script>
                            ';
                            echo $tmpPrint;
                            exit;
                        }
                    }
                    
                    redirect($tmpBackurl);
                }else{
					redirect('/account');
                }
            }else{
                $this->session->set_userdata('login_error_times', intval($this->session->userdata('login_error_times'))+1);
            }
        }

        $tmpLoginErrorTimes = intval($this->session->userdata('login_error_times'));

        //var_dump(intval($this->session->userdata('login_error_times')));
        $this->load->view('account/login', array('LoginErrorTimes'=>$tmpLoginErrorTimes));
    }
    
    public function ajax_login() {
        $this->load->model('user');
        $username = htmlspecialchars(trim($this->input->get("username")));
        $password = htmlspecialchars(trim($this->input->get('password')));

        $user = $this->user->checkLogin($username, $password);
        if ($user AND $this->valid_keep_user($username) !== TRUE) {
            $user_array = array(
                'login_ip' => $this->input->ip_address(),
                'login_time' => time(),
            );
            $this->user->update($user_array, array('id' => $user['id']));
            $user_data = array(
                'id' => $user['id'],
                'fid' => $user['fid'],
                'email' => $user['email'],
                'mobile' => $user['mobile'],
                'username' => $user['username'],
                'login_error_times' => 0
            );
            
            // 处理：日志，活跃用户。   
            $this->load->helpers('date');             
            $tmpTimeEnd = human_to_unix(mdate('%Y-%m-%d', time()).' 0:0:0');
            $tmpTimeStart = $tmpTimeEnd - 24*3600;

            if ( $this->user_data['create_time'] >= $tmpTimeStart AND $this->user_data['create_time'] <= $tmpTimeEnd ){
                $user_data['regtime'] = $this->user_data['create_time'];
            }

            $this->session->set_userdata($user_data);
    
            if( $this->input->get('autologin') == 'yes' ){
                $this->input->set_cookie('uid', $user_data['id'], 3600*24*7, '.xy.com');
                $this->input->set_cookie('token', md5($user_data['id'].$user['password'].$this->config->config['sign_key']), 3600*12, '.xy.com');
            }
            $this->input->set_cookie('lastuname', $user_data['username'], 3600*24*7, '.xy.com');#最后登录用户名

            $this->_doSendLoginLog($user_data['id']);
            $this->_doDiscuzLogin($username, $password);
        
            echo $_GET['callback'] . "(" . json_encode(array('status' => 'success')) . ")";
        } else {
            echo $_GET['callback'] . "(" . json_encode(array('status' => 'error')) . ")";
        }
    }
    
    public function logout()
    {
        $tmpUid = $this->session->userdata('id');
        $user_data = array(
            'id' => '',
            'fid' => '',
            'email' => '',
            'mobile' => '',
            'username' => ''
        );
        $this->session->unset_userdata($user_data);

        $this->input->set_cookie('uid', '', '', '.xy.com');
        $this->input->set_cookie('token', '', '', '.xy.com');
        //$this->input->set_cookie('lastuname', '', '', '.xy.com');
        
        $refurl = urldecode($this->input->get('ref'));
        if(empty($refurl)){
            redirect(base_url('/'));
        }else{
            redirect($refurl);
        }
    }
    
    public function register()
    {
        $this->form_validation->set_error_delimiters('<div for="%s" class="ui-reg-info ui-reg-info-error"><span><em>','</em></span></div>');
        
        if($this->session->userdata('id')){ redirect('account/'); }
        
        if ($this->input->is_post()){
            $this->load->model('user');

            $this->form_validation->set_rules('pact', '通行证协议', 'trim|callback_check_pact');
            $this->form_validation->set_rules('username', '帐号', 'trim|required|min_length[4]|max_length[20]|alpha_numeric|callback_check_user_register');
            $this->form_validation->set_rules('password', '密码', 'trim|required|min_length[6]|max_length[20]');
            $this->form_validation->set_rules('passconf', '确认密码', 'required|matches[password]');

            if ($this->form_validation->run() === TRUE){
                // for table: user
                $user_array = array(
                    'username' => $this->input->post('username'),
                    'password' => md5($this->input->post('password')),
                    'create_time' => time(),
                    'create_ip' => $this->input->ip_address(),
                    'login_time' => time(),
                    'login_ip' => $this->input->ip_address(),
                        
                    'real_name' => '',
                    'real_card' => '',
                    'email' => '',
                    'mobile' => ''
                );
                $userid = $this->user->create($user_array);

                if (isset($_COOKIE['advid'])){
                    $this->load->library('redis');
                    $this->redis->push('reg|'.time().'|'.$userid.'|adv_'.$_COOKIE['advid'].'|gid_'.$_COOKIE['gid'].'|site_0|');
                }
                
                $this->session->set_userdata(array(
                    'id' => $userid,
                    'regtime' => time(),
                    'username' => $user_array['username']
                ));
                
                $this->_doSendRegisterLog($userid, $user_array['username'], $user_array['password']);
                $this->_doSendLoginLog($userid);
                $this->_doDiscuzLogin($this->input->post('username'), $this->input->post('password'));
                
                // Get backurl
                $tmpBackurl = $this->session->userdata('http_referer');
                if ($tmpBackurl){
                    $this->session->unset_userdata('http_referer');
                    redirect($tmpBackurl);
                }else{
                    redirect('account/');
                }
            }
        }
        $this->load->view('account/register');
    }

    public function ajax_register() {
        $this->load->model('user');
        $username = htmlspecialchars(trim($this->input->get("username")));
        $password = htmlspecialchars(trim($this->input->get('password')));
        $siteid = intval($this->input->get('siteid'));
        $advid = intval($this->input->get('advid'));
        $gid = intval($this->input->get('gid'));

        $this->setadvid($advid, $gid);

        if(strlen($username) < 4 OR strlen($username) > 20){
            $this->_doJsonCallback(array('status' => 'error1'));
            exit;
        }
        
        if(strlen($password) < 6 OR strlen($password) > 20){
            $this->_doJsonCallback(array('status' => 'error2'));
            exit;
        }
        
        if (!$this->form_validation->alpha_numeric($username)){
            $this->_doJsonCallback(array('status' => 'error3'));
            exit;
        }

        if (!$this->check_user_register($username)){
            $this->_doJsonCallback(array('status' => 'error4'));
            exit;
        }
        
        $user_array = array(
            'username' => $username,
            'password' => md5($password),
            'create_time' => time(),
            'create_ip' => $this->input->ip_address(),
            'login_time' => time(),
            'login_ip' => $this->input->ip_address(),
                
            'real_name' => '',
            'real_card' => '',
            'email' => '',
            'mobile' => '',
            
            'advid' => $advid
        );
        $userid = $this->user->create($user_array);

        if ($advid > 0){
            $this->load->library('redis');
            $this->redis->push('reg|'.time().'|'.$userid.'|adv_'.$advid.'|gid_'.$gid.'|site_'.$siteid.'|');
        }

        $this->session->set_userdata(array(
            'id' => $userid,
            'regtime' => time(),
            'username' => $user_array['username']
        ));
        
        // Fuck IE!!! By Wangyu.
        $tmpJsonData = array(
            'status'=>'success',
            'uid'=>$userid,
            'token'=>md5($userid.$user_array['password'].$this->config->config['sign_key'])
        );
        
        $this->_doJsonCallback($tmpJsonData);
    }

    private function _doJsonCallback($strAry){
        echo $_GET['jsoncallback'] . "(" . json_encode($strAry) . ")";
    }

    public function forgotpass($type=false)
    {
        if($this->session->userdata('id')){ redirect('account/'); }
        $this->load->model('user');
        $this->load->helper('string');
        $this->load->library('sendmobile');
        
        $resp = array();
        
        $step = $this->session->userdata('_step');
        if (!$step){ $step = 1; }

        if($type=='sendmobile'){
            if (strlen($this->session->userdata('mobile')) == 11 ){

                if ($this->session->userdata('mobile_checkcode')){
                    $tmpRandomCode = $this->session->userdata('mobile_checkcode');
                }else{
                    $tmpRandomCode = mt_rand(1000, 9999);
                }

                $this->session->set_userdata(array('mobile_checkcode' => $tmpRandomCode));
                
                $tmpRe = $this->sendmobile->doSendSMS($this->session->userdata('mobile'), '您的短信验证码为：'.$tmpRandomCode.' [XY游戏 http://www.xy.com]');

                echo $tmpRe;
                exit;
            }
        }
        
        if($type=='checkcode'){
            if( intval($this->session->userdata('checkcode_error_times')) > 10 ){ echo 'false'; exit; }
            if($this->check_mobile_checkcode($this->input->get('checkcode'))){
                echo 'true';
                $this->session->set_userdata('_forgotpass_mobile_check', 'yes');
            }else{
                echo 'false';
                $this->session->set_userdata('_forgotpass_mobile_check', 'no');
                $this->session->set_userdata('checkcode_error_times', intval($this->session->userdata('checkcode_error_times'))+1);
            }
            exit;
        }
        
        if($type=='mobilesetpass'){

            if( $this->session->userdata('_forgotpass_mobile_check') == 'yes' ){
                $time = time();
                $sign = md5($this->session->userdata('_username') . $time . $this->config->config['sign_key']);
                
                $url = site_url('account/setpass?username='.$this->session->userdata('_username').'&time='.$time.'&sign='.$sign);
                
                redirect($url);
            }
            
            redirect('/');
        }

        if ($this->input->is_post()){
            $this->form_validation->set_error_delimiters('<div for="%s" class="ui-reg-info ui-reg-info-error ml8"><span><em>','</em></span></div>');

            $this->form_validation->set_rules('username', '帐号', 'trim|required|min_length[4]|max_length[20]|alpha_numeric|callback_check_user_exist');
            if ($this->form_validation->run() === TRUE){
                $tmpAryData = array(
                    'email' => $this->user_data['email'],
                    'mobile' => $this->user_data['mobile'],
                    '_username' => $this->user_data['username'],
                    '_forgotpass' => 'yes',
                );
                $this->session->set_userdata($tmpAryData);

                $this->session->set_userdata('_step', $step++);
            }
        }else{
            $step = 1;
            $this->session->set_userdata('_step', $step);
        }
        
        if ($type){
            if ( $this->session->userdata('_forgotpass') == 'yes' ){
                $step = 3;
                $resp['type'] = $type;
            }
        }
        
        switch ($step){
            case 2:
                
                $user_data = array();
                $user_data = $this->user->get($this->user_data['id']);
                $resp['user_data'] = $user_data;
                break;
            case 3:
                if ($type=='email'){
                    $time = time();
                    $sign = md5($this->session->userdata('_username') . $time . $this->config->config['sign_key']);
                    
                    $url = site_url('account/setpass?username='.$this->session->userdata('_username').'&time='.$time.'&sign='.$sign);

                
                    $title = 'XY游戏 —— 邮箱找回密码';
                    $content = '
                        <b>亲爱的XY游戏用户：</b><br />
                        &nbsp;&nbsp;&nbsp;&nbsp;您好！<br />
                        &nbsp;&nbsp;&nbsp;&nbsp;感谢您使用XY游戏平台密码找回功能，请点击以下链接重新设置密码：<br /><br />
                        &nbsp;&nbsp;&nbsp;&nbsp;'.$url.'<br />
                        &nbsp;&nbsp;&nbsp;&nbsp;<font color=gray>(如果您无法点击此链接，请将它复制到浏览器地址栏后访问)</font><br /><br />
                        &nbsp;&nbsp;&nbsp;&nbsp;为了保证您帐号的安全，该链接有效期为24小时<br />
                        &nbsp;&nbsp;&nbsp;&nbsp;如非本人操作，可能是有用户误输入您的邮箱地址，您可以忽略此邮件，由此给您带来的不便敬请谅解！<br />
                        <br />
                        XY游戏平台<br />
                        '.mdate('%Y年%m月%d日').'<br />
                    ';
                    
                    
                    if($this->_send_email($this->session->userdata('email'), $title, $content)){
                        //$message['message'] = 'Success! check your email inbox.';
                    }else{
                        //$message['message'] = 'Failure! please re-post.';
                    }
                    
                    $resp['mail_web'] = 'http://mail.'.substr($this->session->userdata('email'), strpos($this->session->userdata('email'),'@')+1);
                }elseif($type='mobile'){


                }
                break;
        }

        $resp['step'] = $step;

        $this->load->view('account/forgotpass', $resp);
    }
    
    // callback
    public function check_user_exist($str){
        if (!$this->_check_user_exist($str)){
            $this->form_validation->set_message('check_user_exist', '%s 不存在.');
            return false;
        }
        return true;
    }
    
    public function check_email_exist($str){
        if (!$this->_check_email_exist($str)){
            $this->form_validation->set_message('check_email_exist', '%s 不存在.');
            return false;
        }
        return true;
    }

    public function check_email_checkcode($str){
        $this->user_data = $this->user->get($this->session->userdata('id'));
        if (count($this->user_data) === 0){ return false; }
        $tmpCheckCode = $this->user_data['email'];
        if ($str !== $tmpCheckCode){
            $this->form_validation->set_message('check_email_checkcode', '%s 校验错误.');
            return false;
        }
        return true;
    }
    
    public function check_mobile_checkcode($str){
        $tmpCheckCode = $this->session->userdata('mobile_checkcode');
        if (intval($str) !== $tmpCheckCode){
            $this->form_validation->set_message('check_mobile_checkcode', '%s 校验错误.');
            return false;
        }
        return true;
    }
    
    /**
     * ajax validate username
     */
    public function check($type){
        $this->load->model('user');
        switch($type){
            case 'username':
                $username = strip_tags($this->input->get('username'));
                if ($this->input->get('jsoncallback')){
                    if($this->_check_user_exist(strtolower($username))){
                        $this->_doJsonCallback(array('status' => 'error'));
                    }else{
                        $this->_doJsonCallback(array('status' => 'success'));
                    }
                    exit;
                }else{
                    if($this->_check_user_exist(strtolower($username))) die("false"); else die("true");
                }
            case 'email':
                $email = strip_tags($this->input->get('email'));
                if($this->_check_email_exist($email)) die("false"); else die("true");
            case 'nemail':
                $email = strip_tags($this->input->get('email'));
                if($this->_check_email_exist($email)) die("true"); else die("false");
            case 'cap':
                $captcha = strip_tags($this->input->get('captcha'));
                $capt = strtolower($this->session->userdata('captcha_word'));
                if($captcha == $capt) die("true"); else die("false");
            case 'nname':
                $username = strip_tags($this->input->get('username'));
                if($this->_check_user_exist(strtolower($username))) die("true"); else die("false");
            case 'cpasswd':
                $password = strip_tags($this->input->get('oldpass'));
                if($this->check_password($password)) die("true"); else die("false");
            case 'ccpasswd':
                $password = strip_tags($this->input->get('oldpass'));
                if($this->check_child_password($password)) die("true"); else die("false");
        }
    }

    public function check_pact($str){
        if ($str !== 'yes'){
            $this->form_validation->set_message('check_pact', '%s 必须勾选.');
            return false;
        }
        return true;
    }
    
    public function check_username_format($str){
        if ( $this->form_validation->valid_email($str) OR $this->form_validation->alpha_numeric($str) ){ return true; }
        $this->form_validation->set_message('check_username_format', '%s 格式只能是邮箱或英文数字.');
        return false;
    }
    
    public function check_user_register($str){
        if ($this->_check_user_exist($str)){
            $this->form_validation->set_message('check_user_register', '%s 已经存在.');
            return false;
        }
        return true;
    }
    
    public function check_email_register($str){
        if ($this->_check_email_exist($str)){
            $this->form_validation->set_message('check_email_register', '%s 已经存在.');
            return false;
        }
        return true;
    }
    
    private function _check_email_exist($str){
        $this->user_data = $this->user->get($str, 'email');
        if (count($this->user_data) === 0){ return false; }
        return true;
    }
    
    private function _check_user_exist($str){
        if ($this->valid_keep_user($str)){ return true; }

        $this->user_data = $this->user->get($str, 'username');
        if (count($this->user_data) === 0){ return false; }
        return true;
    }
    
    private function valid_keep_user($str)
    {
        if (strtolower($str) === 'admin') { return true; }
        return ( preg_match("/^([0-9]{4,6}|(6|8|9){4,20}|(51|5)(8|9){3,8}|1(0){4,10})$/i", $str)) ? TRUE : FALSE;
    }
    
    public function check_password($str){
        if (!is_array($this->user_data)){ $this->user_data = $this->user->get($this->session->userdata('id')); }
        if (count($this->user_data)>1){
            if (md5($str) === $this->user_data['password']){
                return true;
            }else{
                $this->form_validation->set_message('check_password', '%s 错误.');
                return false;
            }
        }
    }
    
    public function check_captcha($str){
        $capt = strtolower($this->session->userdata('captcha_word'));
        if (strtolower($str) !== $capt){
            $this->form_validation->set_message('check_captcha', '%s 错误.');
            return false;
        }
        
        $this->session->set_userdata('login_error_times', 0);
        return true;
    }
    
    private function _send_email($email, $title, $content){
        $this->load->library('email');
        $this->email->from('kingnettest@163.com', 'Kingnet Services');
        $this->email->to($email); 
        
        $this->email->subject($title);
        $this->email->message($content); 
        
        return $this->email->send();
        //echo $this->email->print_debugger();
    }

}

/* End of file  */
